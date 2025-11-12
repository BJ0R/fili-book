<?php
// public/webhook.php
// Stripe -> Your server notifications (PaymentIntent events)
// This file is called by Stripe's servers (via Stripe CLI or live webhooks)

require_once __DIR__ . '/api/config.php';

// Read raw payload & signature header
$payload    = @file_get_contents('php://input');
$sigHeader  = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$whSecret   = env('STRIPE_WEBHOOK_SECRET');  // from .env
$debug      = filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);

// Verify and construct the Event
try {
  if ($whSecret) {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $whSecret);
  } else {
    // For local/dev without a secret, accept raw JSON (not recommended for prod)
    $event = json_decode($payload);
    if (!$event || !isset($event->type)) {
      throw new Exception('Invalid payload');
    }
  }
} catch (\Throwable $e) {
  http_response_code(400);
  echo json_encode(['error' => 'Signature verification failed', 'detail' => $debug ? $e->getMessage() : null]);
  exit;
}

// Helper: upsert payment row by PI id
function upsert_payment(PDO $pdo, $bookingId, $piId, $amountCents, $currency, $status) {
  // Try update existing
  $upd = $pdo->prepare("UPDATE payments SET amount_cents=?, currency=?, status=? WHERE stripe_pi_id=?");
  $upd->execute([$amountCents, strtoupper($currency), $status, $piId]);
  if ($upd->rowCount() === 0) {
    // Insert if not found
    $ins = $pdo->prepare("INSERT INTO payments (booking_id, stripe_pi_id, amount_cents, currency, status) VALUES (?,?,?,?,?)");
    $ins->execute([(int)$bookingId, $piId, (int)$amountCents, strtoupper($currency), $status]);
  }
}

// Process event types we care about
try {
  switch ($event->type) {
    case 'payment_intent.succeeded': {
      /** @var \Stripe\PaymentIntent $pi */
      $pi = $event->data->object;

      $bookingId   = $pi->metadata->booking_id ?? null;
      $amountCents = (int)$pi->amount;
      $currency    = $pi->currency ?? env('DEFAULT_CURRENCY', 'USD');

      if ($bookingId) {
        // Update payments table
        upsert_payment($pdo, $bookingId, $pi->id, $amountCents, $currency, $pi->status);

        // Mark booking as paid
        $b = $pdo->prepare("UPDATE bookings SET status='paid', updated_at=NOW() WHERE id=?");
        $b->execute([(int)$bookingId]);
      }
      break;
    }

    case 'payment_intent.payment_failed': {
      $pi = $event->data->object;
      $bookingId   = $pi->metadata->booking_id ?? null;
      $amountCents = (int)$pi->amount;
      $currency    = $pi->currency ?? env('DEFAULT_CURRENCY', 'USD');

      if ($bookingId) {
        upsert_payment($pdo, $bookingId, $pi->id, $amountCents, $currency, $pi->status);

        // Keep booking awaiting payment (or revert to pending)
        $b = $pdo->prepare("UPDATE bookings SET status='requires_payment', updated_at=NOW() WHERE id=?");
        $b->execute([(int)$bookingId]);
      }
      break;
    }

    case 'payment_intent.canceled': {
      $pi = $event->data->object;
      $bookingId   = $pi->metadata->booking_id ?? null;
      $amountCents = (int)$pi->amount;
      $currency    = $pi->currency ?? env('DEFAULT_CURRENCY', 'USD');

      if ($bookingId) {
        upsert_payment($pdo, $bookingId, $pi->id, $amountCents, $currency, $pi->status);
        // Do not auto-cancel booking; keep requires_payment so user can try again
        $b = $pdo->prepare("UPDATE bookings SET status='requires_payment', updated_at=NOW() WHERE id=?");
        $b->execute([(int)$bookingId]);
      }
      break;
    }

    // Optional: reflect refunds if you issue them
    case 'charge.refunded': {
      $charge = $event->data->object;
      // If you need to locate the PaymentIntent and booking:
      $piId = $charge->payment_intent ?? null;
      if ($piId) {
        // Find booking by payments relationship
        $q = $pdo->prepare("SELECT booking_id FROM payments WHERE stripe_pi_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$piId]);
        $bookingId = $q->fetchColumn();
        if ($bookingId) {
          $upd = $pdo->prepare("UPDATE payments SET status='refunded' WHERE stripe_pi_id=?");
          $upd->execute([$piId]);

          // Business choice: mark booking cancelled or keep paid (partial refunds?)
          $b = $pdo->prepare("UPDATE bookings SET status='cancelled', updated_at=NOW() WHERE id=?");
          $b->execute([(int)$bookingId]);
        }
      }
      break;
    }

    default:
      // Ignore other events silently (but acknowledge)
      break;
  }

  http_response_code(200);
  echo json_encode(['received' => true, 'type' => $event->type]);

} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Webhook handling error', 'detail' => $debug ? $e->getMessage() : null]);
}
