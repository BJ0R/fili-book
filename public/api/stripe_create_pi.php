<?php
// public/api/stripe_create_pi.php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['error' => 'Method not allowed'], 405);
}

$body       = json_input();
$bookingId  = isset($body['booking_id']) ? (int)$body['booking_id'] : 0;

if ($bookingId <= 0) {
  json_response(['error' => 'Invalid booking_id'], 400);
}

try {
  // 1) Fetch booking + room to compute authoritative amount (never trust client)
  $stmt = $pdo->prepare("
    SELECT
      b.id AS booking_id, b.check_in, b.check_out, b.status, b.guests,
      r.id AS room_id, r.name AS room_name, r.price_cents, r.currency
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    WHERE b.id = :bid
    LIMIT 1
  ");
  $stmt->execute([':bid' => $bookingId]);
  $row = $stmt->fetch();

  if (!$row) {
    json_response(['error' => 'Booking not found'], 404);
  }
  if (!in_array($row['status'], ['pending','requires_payment'], true)) {
    // Allow re-creating PI only if not paid/cancelled
    if ($row['status'] === 'paid') {
      json_response(['error' => 'Booking already paid'], 400);
    }
  }

  // Compute nights & total amount in cents
  $ci = new DateTime($row['check_in']);
  $co = new DateTime($row['check_out']);
  $nights = max(1, (int)$ci->diff($co)->days);
  $amountCents = (int)$row['price_cents'] * $nights;
  $currency    = $row['currency'] ?: env('DEFAULT_CURRENCY', 'USD');

  // 2) Try to reuse an existing active PaymentIntent for this booking
  $findPay = $pdo->prepare("
    SELECT stripe_pi_id, status
    FROM payments
    WHERE booking_id = :bid
    ORDER BY id DESC
    LIMIT 1
  ");
  $findPay->execute([':bid' => $bookingId]);
  $existing = $findPay->fetch();

  if ($existing && !empty($existing['stripe_pi_id'])) {
    try {
      $pi = \Stripe\PaymentIntent::retrieve($existing['stripe_pi_id']);

      // Reuse if amount/currency match and intent is still payable
      if (
        $pi &&
        $pi->amount == $amountCents &&
        strtolower($pi->currency) === strtolower($currency) &&
        in_array($pi->status, ['requires_payment_method','requires_confirmation','requires_action','processing'], true)
      ) {
        // Keep DB in sync
        $upd = $pdo->prepare("UPDATE payments SET status = ? WHERE stripe_pi_id = ?");
        $upd->execute([$pi->status, $pi->id]);
        json_response([
          'booking_id'     => $bookingId,
          'amount_cents'   => $amountCents,
          'currency'       => strtoupper($currency),
          'client_secret'  => $pi->client_secret,
          'payment_intent' => $pi->id,
          'reused'         => true,
        ]);
      }
      // If mismatched or not reusable, we'll create a fresh PI below
    } catch (\Stripe\Exception\ApiErrorException $e) {
      // If retrieval fails (deleted/invalid), fall through to create a new PI
    }
  }

  // 3) Create a fresh PaymentIntent
  $pi = \Stripe\PaymentIntent::create([
    'amount' => $amountCents,
    'currency' => strtolower($currency),
    'automatic_payment_methods' => ['enabled' => true],
    'metadata' => [
      'booking_id' => (string)$bookingId,
      'room_id'    => (string)$row['room_id'],
      'nights'     => (string)$nights,
    ],
  ], [
    // Optional idempotency key to avoid dupes on network retries
    'idempotency_key' => 'booking_'.$bookingId.'_'.md5($amountCents.$currency),
  ]);

  // 4) Upsert payments row
  if ($existing && !empty($existing['stripe_pi_id'])) {
    $up = $pdo->prepare("UPDATE payments SET stripe_pi_id = ?, amount_cents = ?, currency = ?, status = ? WHERE booking_id = ?");
    $up->execute([$pi->id, $amountCents, strtoupper($currency), $pi->status, $bookingId]);
  } else {
    $ins = $pdo->prepare("INSERT INTO payments (booking_id, stripe_pi_id, amount_cents, currency, status) VALUES (?,?,?,?,?)");
    $ins->execute([$bookingId, $pi->id, $amountCents, strtoupper($currency), $pi->status]);
  }

  // Ensure booking is marked as awaiting payment
  $bupd = $pdo->prepare("UPDATE bookings SET status = 'requires_payment' WHERE id = ?");
  $bupd->execute([$bookingId]);

  json_response([
    'booking_id'     => $bookingId,
    'amount_cents'   => $amountCents,
    'currency'       => strtoupper($currency),
    'client_secret'  => $pi->client_secret,
    'payment_intent' => $pi->id,
    'reused'         => false,
  ], 201);

} catch (\Stripe\Exception\ApiErrorException $e) {
  $debug = filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);
  json_response(['error' => 'Stripe API error', 'detail' => $debug ? $e->getMessage() : null], 400);
} catch (Throwable $e) {
  $debug = filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);
  json_response(['error' => 'Failed to create PaymentIntent', 'detail' => $debug ? $e->getMessage() : null], 500);
}
