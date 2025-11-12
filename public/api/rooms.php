<?php
// public/api/rooms.php
require_once __DIR__ . '/config.php';

try {
  // Optional filters: guests (min capacity), availability by date range
  $guests    = isset($_GET['guests']) ? max(1, (int)$_GET['guests']) : null;
  $checkIn   = $_GET['check_in']  ?? null; // YYYY-MM-DD
  $checkOut  = $_GET['check_out'] ?? null; // YYYY-MM-DD
  $onlyAvail = ($checkIn && $checkOut);    // availability filter active only if both dates given

  $sql = "
    SELECT
      r.id, r.name, r.description, r.capacity,
      r.price_cents, r.currency, r.image_url, r.is_active
    FROM rooms r
    WHERE r.is_active = 1
  ";

  $params = [];

  if (!is_null($guests)) {
    $sql .= " AND r.capacity >= :guests";
    $params[':guests'] = $guests;
  }

  if ($onlyAvail) {
    // Exclude rooms that have any overlapping booking (pending / requires_payment / paid).
    // Overlap condition: NOT (existing.check_out <= :check_in OR existing.check_in >= :check_out)
    $sql .= "
      AND NOT EXISTS (
        SELECT 1
        FROM bookings b
        WHERE b.room_id = r.id
          AND b.status IN ('pending','requires_payment','paid')
          AND NOT (b.check_out <= :ci OR b.check_in >= :co)
      )
    ";
    $params[':ci'] = $checkIn;
    $params[':co'] = $checkOut;
  }

  $sql .= " ORDER BY r.price_cents ASC, r.capacity ASC";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rooms = $stmt->fetchAll();

  // Shape response
  $resp = [
    'filters' => [
      'guests'    => $guests,
      'check_in'  => $checkIn,
      'check_out' => $checkOut,
    ],
    'count' => count($rooms),
    'data'  => $rooms,
  ];

  json_response($resp, 200);

} catch (Throwable $e) {
  $debug = filter_var(env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOLEAN);
  json_response(['error' => 'Failed to fetch rooms', 'detail' => $debug ? $e->getMessage() : null], 500);
}
