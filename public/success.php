<?php
// public/success.php

// Read optional booking id from query (?b=123)
$bookingId = isset($_GET['b']) ? preg_replace('/\D+/', '', $_GET['b']) : '';

// Load minimal env to build asset URLs (same pattern as index.php)
$envFile = __DIR__ . '/../.env';
$ENV = file_exists($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];
$appUrl = rtrim(($ENV['APP_URL'] ?? (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . '/fili-booking') : '')), '/');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Fili — Booking Confirmed</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?=$appUrl?>/public/assets/css/style.css" />
</head>
<body>
  <main class="container">
    <section class="card" style="text-align:center; padding:28px;">
      <h2 style="margin-top:0;">Thank you! 🎉</h2>
      <?php if ($bookingId): ?>
        <p class="note">Your booking <strong>#<?=htmlspecialchars($bookingId, ENT_QUOTES)?></strong> has been received.</p>
      <?php else: ?>
        <p class="note">Your booking has been received.</p>
      <?php endif; ?>
      <p class="note">A payment confirmation and receipt will be sent to your email shortly.</p>

      <div class="actions" style="justify-content:center; margin-top:18px;">
        <a class="btn" href="<?=$appUrl?>/public/index.php">Back to home</a>
      </div>

      <p class="note" style="margin-top:16px; font-size:.9rem; color:#6b7280;">
        If this page was opened from a redirect, it may take a few seconds for our system to finalize your booking status.
      </p>
    </section>
  </main>

  <footer class="foot">© <?=date('Y');?> Fili</footer>
</body>
</html>
