<?php
// public/api/booking_create.php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['error' => 'Method not allowed'], 405);
}

$body = json_input();

/**
 * Validate and normalize input
 */
$roomId   = isset($body['room_id']) ? (int)$body['room_id'] : 0;
$guests   = isset($body['guests']) ? max(1, (int)$body['guests']) : 1;
$checkIn  = $body['check_in']  ?? null; // YYYY-MM-DD
$checkOut = $body['check_out'] ?? null;

$guest = [
  'first_name' => trim($body['guest']['first_name'] ?? ''),
  'last_name'  => trim($body['guest']['last_name'] ?? ''),
  'email'      => trim($body['guest']['email'] ?? ''),
  'phone'      => trim($body['guest']['phone'] ?? ''),
];

function is_valid_date($d) {
  if (!$d) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
}

if ($roomId <= 0)             json_response(['error' => 'Invalid room_id'], 400);
if (!is_valid_date($checkIn)) json_response(['error' => 'Invalid check_in (YYYY-MM-DD)'], 400);
if (!is_valid_date($checkOut))json_response(['error' => 'Invalid check_out (YYYY-MM-DD)'], 400);

$dtIn  = new DateTime($checkIn);
$dtOut = new DateTime($checkOut);
if ($dtOut <= $dtIn)          json_response(['error' => 'check_out must be after check_in'], 400);
$nights = (int)$dtIn->diff($dtOut)->days;

if ($guest['email'] && !filter_var($guest['email'], FILTER_VALIDATE_EMAIL)) {
  json_response(['error' => 'Invalid email'], 400);
}

try {
  $pdo->beginTransaction();

  // 1) Fetch room and validate capacity + active
  $stmt = $pdo->prepare("
    SELECT id, name, capacity, price_cents, currency, is_active
    FROM rooms
    WHERE id = :id AND is_active = 1
    LIMIT 1
  ");
  $stmt->execute([':id' => $roomId]);
  $room = $stmt->fetch();
  if (!$room) {
    throw new Exception('Room not found or inactive.');
  }
  if ($guests > (int)$room['capacity']) {
    throw new Exception('Guest count exceeds room capacity.');
  }

  // 2) Check availability (overlap with existing non-cancelled bookings)
  $overlap = $pdo->prepare("
    SELECT 1
    FROM bookings
    WHERE room_id = :rid
      AND status IN ('pending','requires_payment','paid')
      AND NOT (check_out <= :ci OR check_in >= :co)
    LIMIT 1
  ");
  $overlap->execute([
    ':rid' => $roomId,
    ':ci'  => $checkIn,
    ':co'  => $checkOut
  ]);
  if ($overlap->fetchColumn()) {
    throw new Exception('Selected dates are not available for this room.');
  }

  // 3) Compute total (price per night * nights)
  $priceCents   = (int)$room['price_cents'];
  $currency     = $room['currency'] ?: 'USD';
  $totalCents   = $priceCents * max(1, $nights); // minimum 1 night

  // 4) Create booking (requires payment)
  $insB = $pdo->prepare("
    INSERT INTO bookings (room_id, check_in, check_out, guests, status)
    VALUES (:rid, :ci, :co, :guests, 'requires_payment')
  ");
  $insB->execute([
    ':rid'    => $roomId,
    ':ci'     => $checkIn,
    ':co'     => $checkOut,
    ':guests' => $guests
  ]);
  $bookingId = (int)$pdo->lastInsertId();

  // 5) Insert guest record
  $insG = $pdo->prepare("
    INSERT INTO guests (booking_id, first_name, last_name, email, phone)
    VALUES (:bid, :fn, :ln, :em, :ph)
  ");
  $insG->execute([
    ':bid' => $bookingId,
    ':fn'  => $guest['first_name'],
    ':ln'  => $guest['last_name'],
    ':em'  => $guest['email'],
    ':ph'  => $guest['phone']
  ]);

  $pdo->commit();

  // 6) Respond with booking + pricing summary (client will create PaymentIntent next)
  json_response([
    'booking_id'    => $bookingId,
    'room' => [
      'id'          => (int)$room['id'],
      'name'        => $room['name'],
      'capacity'    => (int)$room['capacity'],
      'price_cents' => $priceCents,
      'currency'    => $currency,
    ],
    'check_in'      => $checkIn,
    'check_out'     => $checkOut,
    'guests'        => $guests,
    'nights'        => $nights,
    'amount_cents'  => $totalCents,
    'currency'      => $currency,
    'status'        => 'requires_payment'
  ], 201);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $debug = filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);
  json_response(['error' => 'Booking creation failed', 'detail' => $debug ? $e->getMessage() : null], 400);
}
