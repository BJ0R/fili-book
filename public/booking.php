<?php
// public/booking.php
// Standalone "Booking" page that starts at Guest Details (Step 2).
// If a ?room_id= is provided, it preselects that room and proceeds with booking → payment.

// Load minimal env for URLs and Stripe publishable key
$envFile = __DIR__ . '/../.env';
$ENV = file_exists($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];
function envv($k, $d = null, $E = []) { return $E[$k] ?? $d; }

$appUrl   = rtrim(envv('APP_URL', (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . '/fili-booking') : ''), $ENV), '/');
$stripePK = envv('STRIPE_PUBLISHABLE', 'pk_test_xxx', $ENV);

$publicBase = $appUrl . '/public';
$apiRooms   = $publicBase . '/api/rooms.php';
$apiBook    = $publicBase . '/api/booking_create.php';
$apiPI      = $publicBase . '/api/stripe_create_pi.php';

$roomIdParam = isset($_GET['room_id']) ? preg_replace('/\D+/', '', $_GET['room_id']) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Fili — Book a Room</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#0b2240"/>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?=$publicBase?>/assets/css/style.css" />
  <script src="https://js.stripe.com/v3/" defer></script>
  <style>
    /* Hide Step 1 on this page; we start from Step 2 */
    #step-1{ display:none !important; }
  </style>
</head>
<body>
  <!-- Top Navigation -->
  <nav class="nav">
    <div class="brand">Fili</div>
    <div class="links">
      <a href="<?=$publicBase?>/index.php" aria-label="Home">Home</a>
      <a href="<?=$publicBase?>/rooms.php" aria-label="Rooms">Rooms</a>
    </div>
  </nav>

  <main class="container" role="main">
    <!-- Stepper (Step 2 active) -->
    <ol class="stepper" id="stepper" aria-label="Booking progress">
      <li>1. Select Room</li>
      <li class="active">2. Guest Details</li>
      <li>3. Payment</li>
    </ol>

    <!-- STEP 2: Guest Details -->
    <section id="step-2" class="card" aria-labelledby="s2-title">
      <header class="card-hd">
        <h2 id="s2-title">Guest details</h2>
        <p class="note" id="room-brief">Loading room info…</p>
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
          <a class="btn ghost" href="<?=$publicBase?>/rooms.php">Back to Rooms</a>
          <button type="submit" class="btn">Proceed to Payment</button>
        </div>
      </form>
    </section>

    <!-- STEP 3: Payment (hidden until Step 2 completes) -->
    <section id="step-3" class="card hidden" aria-labelledby="s3-title">
      <header class="card-hd">
        <h2 id="s3-title">Payment</h2>
      </header>

      <!-- Summary injected by JS -->
      <div id="summary" class="mt-3"></div>

      <form id="payment-form" class="mt-3">
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

  <script>
    // ---------- Config ----------
    const API = {
      ROOMS: "<?=htmlspecialchars($apiRooms, ENT_QUOTES)?>",
      BOOK:  "<?=htmlspecialchars($apiBook, ENT_QUOTES)?>",
      PI:    "<?=htmlspecialchars($apiPI, ENT_QUOTES)?>",
    };
    const STRIPE_PK = "<?=htmlspecialchars($stripePK, ENT_QUOTES)?>";
    const APP_URL   = "<?=htmlspecialchars($publicBase, ENT_QUOTES)?>";

    const $ = s => document.querySelector(s);
    const $$ = s => Array.from(document.querySelectorAll(s));
    const on = (el, ev, fn) => el && el.addEventListener(ev, fn);

    const S = {
      room: null,
      bookingId: null,
      priceCents: 0,
      currency: 'USD',
      nights: 1,
      stripe: null,
      elements: null,
      paymentElement: null,
      clientSecret: null,
    };

    const fmtMoney = (cents, code='USD') =>
      new Intl.NumberFormat(undefined, { style:'currency', currency:code })
        .format((Number(cents)||0)/100);

    const todayISO = () => new Date().toISOString().slice(0,10);

    function setStepper(n){
      $$('#stepper li').forEach((li,i)=>li.classList.toggle('active', i===n-1));
      $('#step-2').classList.toggle('hidden', n!==2);
      $('#step-3').classList.toggle('hidden', n!==3);
    }

    function showBrief() {
      if (!S.room) {
        $('#room-brief').innerHTML = 'No room selected. <a href="'+APP_URL+'/rooms.php">Browse rooms</a>.';
        return;
      }
      $('#room-brief').innerHTML =
        '<strong>'+esc(S.room.name)+'</strong> · Up to '+Number(S.room.capacity)+' guests · '+fmtMoney(S.room.price_cents, S.room.currency)+' / night';
    }

    function esc(s){
      return String(s || '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
    }

    async function fetchRoomById(id){
      // We don't have a single-room endpoint, so fetch all and filter.
      const res = await fetch(API.ROOMS);
      const json = await res.json();
      const r = (json.data||[]).find(x => String(x.id) === String(id));
      return r || null;
    }

    function toast(msg){
      const n = document.createElement('div');
      n.textContent = msg;
      Object.assign(n.style,{
        position:'fixed',left:'50%',bottom:'24px',transform:'translateX(-50%)',
        background:'#1a1f2b',color:'#fff',padding:'10px 14px',borderRadius:'12px',
        boxShadow:'0 10px 24px rgba(0,0,0,.18)',zIndex:9999,opacity:0,transition:'opacity .2s ease'
      });
      document.body.appendChild(n);
      requestAnimationFrame(()=>n.style.opacity=1);
      setTimeout(()=>{ n.style.opacity=0; setTimeout(()=>n.remove(),250); }, 2200);
    }

    function nightsBetween(a,b){
      try{
        const d1 = new Date(a+'T00:00:00'), d2 = new Date(b+'T00:00:00');
        const diff = Math.round((d2 - d1)/(1000*60*60*24));
        return Math.max(1,diff);
      }catch{ return 1; }
    }

    function renderSummary(p){
      $('#summary').innerHTML = `
        <div class="note">
          <strong>${esc(S.room?.name || 'Room')}</strong><br/>
          ${p.check_in} → ${p.check_out} · ${p.guests} guest(s) · ${S.nights} night(s)<br/>
          Total: <strong>${fmtMoney(S.priceCents, S.currency)}</strong>
          ${S.nights>1 ? ` (${fmtMoney(Number(S.room.price_cents), S.currency)} / night)` : ''}
        </div>
      `;
    }

    async function initStripeAndElement(){
      const res = await fetch(API.PI, {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ booking_id: S.bookingId })
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.error || 'Failed to create payment');

      S.clientSecret = json.client_secret;
      S.stripe = Stripe(STRIPE_PK);
      S.elements = S.stripe.elements({ clientSecret: json.client_secret, appearance: { theme:'stripe' } });
      S.paymentElement = S.elements.create('payment');
      S.paymentElement.mount('#payment-element');

      $('#payment-form').onsubmit = onPaySubmit;
    }

    async function onPaySubmit(ev){
      ev.preventDefault();
      setPayLoading(true);
      const { error } = await S.stripe.confirmPayment({
        elements: S.elements,
        confirmParams: { return_url: `${APP_URL}/success.php?b=${S.bookingId}` }
      });
      if (error){
        showPaymentMessage(error.message || 'Payment failed. Please try again.');
        setPayLoading(false);
      }
    }

    function setPayLoading(v){
      $('#submit-payment .lbl').style.opacity = v ? 0.5 : 1;
      $('#submit-payment .spinner').classList.toggle('hidden', !v);
    }
    function showPaymentMessage(msg){
      const el = $('#payment-message');
      el.textContent = msg;
      el.classList.remove('hidden');
    }

    function bindGuestForm(){
      const f = $('#guest-form');
      if (!f.check_in.value) f.check_in.value = todayISO();
      if (!f.check_out.value) {
        const t = new Date(); t.setDate(t.getDate()+1);
        f.check_out.value = t.toISOString().slice(0,10);
      }

      on(f, 'submit', async (e) => {
        e.preventDefault();
        if (!S.room){ toast('Please select a room on the Rooms page first.'); return; }

        const payload = {
          room_id: Number(S.room.id),
          check_in: f.check_in.value,
          check_out: f.check_out.value,
          guests: Number(f.guests.value || 1),
          guest: {
            first_name: f.first_name.value.trim(),
            last_name:  f.last_name.value.trim(),
            email:      f.email.value.trim(),
            phone:      f.phone.value.trim(),
          }
        };

        if (new Date(payload.check_in) >= new Date(payload.check_out)) {
          return toast('Check-out must be after check-in.');
        }

        try {
          const res = await fetch(API.BOOK, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
          });
          const json = await res.json();
          if (!res.ok) throw new Error(json.error || 'Booking failed');

          S.bookingId  = json.booking_id;
          S.priceCents = Number(json.amount_cents);
          S.currency   = json.currency || 'USD';
          S.nights     = Number(json.nights || nightsBetween(payload.check_in, payload.check_out));

          renderSummary(payload);
          await initStripeAndElement();
          setStepper(3);
        } catch (err) {
          toast(err.message || 'Error creating booking');
        }
      });
    }

    document.addEventListener('DOMContentLoaded', async () => {
      // Preselect room: from ?room_id= or localStorage (set by rooms.php)
      let roomId = "<?=htmlspecialchars($roomIdParam, ENT_QUOTES)?>";
      if (!roomId) {
        const ls = localStorage.getItem('preselect_room_id');
        if (ls) roomId = ls;
      }
      if (roomId) {
        try {
          S.room = await fetchRoomById(roomId);
        } catch {}
      }
      showBrief();

      bindGuestForm();

      // Stepper initial state
      setStepper(2);

      // Clean up preselect so it doesn't stick forever
      localStorage.removeItem('preselect_room_id');
    });
  </script>
</body>
</html>
