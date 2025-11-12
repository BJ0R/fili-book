<?php
// public/index.php

// Load minimal env so we can pass APP_URL and STRIPE publishable key to JS.
$envFile = __DIR__ . '/../.env';
$ENV = [];
if (file_exists($envFile)) {
  $ENV = parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [];
}
function envv($k, $d = null, $E = []) { return $E[$k] ?? $d; }

$appUrl   = rtrim(envv('APP_URL', (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . '/fili-booking') : ''), $ENV), '/');
$stripePK = envv('STRIPE_PUBLISHABLE', 'pk_test_xxx', $ENV);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Fili — Book Your Stay</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#0b2240"/>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?=$appUrl?>/public/assets/css/style.css" />
  <!-- Stripe.js (Payment Element) -->
  <script src="https://js.stripe.com/v3/" defer></script>
  <!-- Expose config to app.js -->
  <script>
    window.APP_URL   = "<?=htmlspecialchars($appUrl, ENT_QUOTES)?>/public";
    window.STRIPE_PK = "<?=htmlspecialchars($stripePK, ENT_QUOTES)?>";
  </script>
  <script src="<?=$appUrl?>/public/assets/js/app.js" defer></script>
</head>
<body>
  <!-- Top Navigation -->
  <nav class="nav">
    <div class="brand">Fili</div>
    <div class="links">
      <a href="#" data-nav="book" aria-label="Book a room">Book</a>
      <a href="#" data-nav="rooms" aria-label="View rooms">Rooms</a>
    </div>
  </nav>

  <main class="container" role="main">
    <!-- Stepper -->
    <ol class="stepper" id="stepper" aria-label="Booking progress">
      <li class="active">1. Select Room</li>
      <li>2. Guest Details</li>
      <li>3. Payment</li>
    </ol>

    <!-- STEP 1: Select Room -->
    <section id="step-1" class="card" aria-labelledby="s1-title">
      <header class="card-hd">
        <h2 id="s1-title">Choose your room</h2>
        <p class="note">Pick a room that fits your party size. Availability updates as you set dates and guests.</p>
      </header>

      <div id="rooms-grid" class="grid" aria-live="polite">
        <!-- Cards injected by app.js -->
      </div>

      <div class="actions end">
        <button class="btn ghost" id="next-to-details" disabled>
          Continue
        </button>
      </div>
    </section>

    <!-- STEP 2: Guest Details -->
    <section id="step-2" class="card hidden" aria-labelledby="s2-title">
      <header class="card-hd">
        <h2 id="s2-title">Guest details</h2>
      </header>

      <form id="guest-form" class="form" novalidate>
        <div class="row">
          <label>
            Check-in
            <input type="date" name="check_in" required aria-required="true" />
          </label>
          <label>
            Check-out
            <input type="date" name="check_out" required aria-required="true" />
          </label>
          <label>
            Guests
            <input type="number" name="guests" min="1" value="1" required aria-required="true" />
          </label>
        </div>

        <div class="row">
          <label>
            First name
            <input name="first_name" autocomplete="given-name" required aria-required="true" />
          </label>
          <label>
            Last name
            <input name="last_name" autocomplete="family-name" required aria-required="true" />
          </label>
        </div>

        <div class="row">
          <label>
            Email
            <input type="email" name="email" autocomplete="email" required aria-required="true" />
          </label>
          <label>
            Phone
            <input name="phone" autocomplete="tel" required aria-required="true" />
          </label>
        </div>

        <div class="actions">
          <button type="button" class="btn ghost" id="back-to-rooms">Back</button>
          <button type="submit" class="btn">Proceed to Payment</button>
        </div>
      </form>
    </section>

    <!-- STEP 3: Payment -->
    <section id="step-3" class="card hidden" aria-labelledby="s3-title">
      <header class="card-hd">
        <h2 id="s3-title">Payment</h2>
      </header>

      <!-- Summary injected by JS -->
      <div id="summary" class="mt-3"></div>

      <form id="payment-form" class="mt-3">
        <!-- Stripe Payment Element mounts here -->
        <div id="payment-element" role="group" aria-label="Payment form"></div>

        <button class="btn pay mt-3" id="submit-payment" type="submit">
          <span class="lbl">Pay now</span>
          <span class="spinner hidden" aria-hidden="true"></span>
        </button>

        <div id="payment-message" class="note hidden" role="alert" aria-live="polite"></div>
      </form>
    </section>
  </main>

  <footer class="foot">© <?=date('Y');?> Fili</footer>
</body>
</html>
