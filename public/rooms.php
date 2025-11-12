<?php
// public/rooms.php
// Standalone Rooms page (read-only gallery with "Book" links back to index.php)

// Load minimal env to build URLs for assets (same pattern as index.php)
$envFile = __DIR__ . '/../.env';
$ENV = file_exists($envFile) ? (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: []) : [];
$appUrl = rtrim(($ENV['APP_URL'] ?? (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . '/fili-booking') : '')), '/');
$publicBase = $appUrl . '/public';
$apiRooms = $publicBase . '/api/rooms.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Fili — Rooms</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#0b2240"/>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?=$publicBase?>/assets/css/style.css" />
  <style>
    .room .meta .actions{ margin-top: 10px; }
    .room .meta .btn{ width: 100%; }
  </style>
</head>
<body>
  <!-- Top Navigation -->
  <nav class="nav">
    <div class="brand">Fili</div>
    <div class="links">
      <a href="<?=$publicBase?>/index.php" aria-label="Book a room">Book</a>
      <a href="<?=$publicBase?>/rooms.php" aria-current="page" aria-label="View rooms">Rooms</a>
    </div>
  </nav>

  <main class="container" role="main">
    <section class="card">
      <header class="card-hd">
        <h2 style="margin:0 0 6px">Our Rooms</h2>
        <p class="note" style="margin:0">Explore available room types. Click “Book this room” to start the booking flow.</p>
      </header>

      <!-- Filter row (optional, client-side only) -->
      <div class="form" style="margin: 10px 0 14px;">
        <div class="row">
          <label>
            Guests
            <input type="number" id="f-guests" min="1" value="1">
          </label>
          <label>
            Check-in
            <input type="date" id="f-ci">
          </label>
          <label>
            Check-out
            <input type="date" id="f-co">
          </label>
        </div>
        <div class="actions">
          <button class="btn" id="f-apply">Apply filters</button>
          <button class="btn ghost" id="f-clear" type="button">Clear</button>
        </div>
      </div>

      <div id="rooms-grid" class="grid" aria-live="polite">
        <!-- Cards injected by JS -->
      </div>
    </section>
  </main>

  <footer class="foot">© <?=date('Y');?> Fili</footer>

  <script>
    const API_ROOMS = "<?=htmlspecialchars($apiRooms, ENT_QUOTES)?>";
    const INDEX_URL = "<?=htmlspecialchars($publicBase, ENT_QUOTES)?>/index.php";

    const $ = (s) => document.querySelector(s);

    // Simple currency formatter
    const money = (cents, code='USD') =>
      new Intl.NumberFormat(undefined, { style: 'currency', currency: code })
        .format((Number(cents)||0)/100);

    const todayISO = () => new Date().toISOString().slice(0,10);

    // Defaults for date inputs
    (function initDates(){
      const ci = $('#f-ci'); const co = $('#f-co');
      if (!ci.value) ci.value = todayISO();
      if (!co.value) {
        const t = new Date(); t.setDate(t.getDate()+1);
        co.value = t.toISOString().slice(0,10);
      }
    })();

    // Load and render rooms
    async function loadRooms() {
      const grid = $('#rooms-grid');
      grid.innerHTML = skeletons(6);

      const guests = Number($('#f-guests').value || 1);
      const ci = $('#f-ci').value;
      const co = $('#f-co').value;

      const params = new URLSearchParams();
      if (guests) params.set('guests', guests);
      if (ci) params.set('check_in', ci);
      if (co) params.set('check_out', co);

      const url = API_ROOMS + (params.toString() ? `?${params.toString()}` : '');

      try {
        const res = await fetch(url);
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Failed to load rooms');
        renderRooms(json.data || []);
      } catch (e) {
        grid.innerHTML = `<div class="card"><div class="note error">Error: ${e.message}</div></div>`;
      }
    }

    function skeletons(n){
      return Array.from({length:n}).map(()=>`
        <article class="room" aria-hidden="true" style="pointer-events:none">
          <div class="img" style="background:linear-gradient(90deg,#eaeaf0 25%,#f4f5f9 50%,#eaeaf0 75%);background-size:200% 100%;animation:shimmer 1.2s linear infinite;height:160px;"></div>
          <div class="meta">
            <div style="height:16px;width:70%;background:#eee;border-radius:8px;margin:6px 0;"></div>
            <div style="height:12px;width:90%;background:#eee;border-radius:8px;margin:6px 0;"></div>
            <div style="height:12px;width:75%;background:#eee;border-radius:8px;margin:6px 0;"></div>
            <div style="height:16px;width:40%;background:#eee;border-radius:8px;margin:10px 0;"></div>
          </div>
        </article>`).join('');
    }
    const shimmer = document.createElement('style');
    shimmer.textContent = `@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}`;
    document.head.appendChild(shimmer);

    function renderRooms(list){
      const grid = $('#rooms-grid');
      if (!list.length){
        grid.innerHTML = `<div class="card"><div class="note">No rooms found for your filters.</div></div>`;
        return;
      }
      grid.innerHTML = list.map(r => `
        <article class="room">
          <img class="img" src="${r.image_url || 'https://picsum.photos/640/360?blur=3'}" alt="${esc(r.name)}">
          <div class="meta">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <h3 style="margin:0">${esc(r.name)}</h3>
              <span class="badge">Up to ${Number(r.capacity)}</span>
            </div>
            <p style="min-height:40px">${esc(r.description || '')}</p>
            <div class="price">${money(Number(r.price_cents), r.currency)}</div>
            <div class="actions">
              <a class="btn" href="${INDEX_URL}" data-room='${encodeURIComponent(JSON.stringify(r))}'>Book this room</a>
            </div>
          </div>
        </article>
      `).join('');

      // Enhance "Book" links: stash preselect to localStorage, then go to index
      document.querySelectorAll('[data-room]').forEach(a => {
        a.addEventListener('click', (e) => {
          try {
            const room = JSON.parse(decodeURIComponent(a.getAttribute('data-room')));
            localStorage.setItem('preselect_room_id', String(room.id));
          } catch {}
          // allow navigation to index.php
        });
      });
    }

    function esc(s){
      return String(s || '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
    }

    // Filters
    document.getElementById('f-apply').addEventListener('click', (e) => {
      e.preventDefault();
      const ci = $('#f-ci').value, co = $('#f-co').value;
      if (ci && co && new Date(ci) >= new Date(co)) {
        alert('Check-out must be after check-in.');
        return;
      }
      loadRooms();
    });
    document.getElementById('f-clear').addEventListener('click', (e) => {
      e.preventDefault();
      $('#f-guests').value = 1;
      $('#f-ci').value = todayISO();
      const t = new Date(); t.setDate(t.getDate()+1);
      $('#f-co').value = t.toISOString().slice(0,10);
      loadRooms();
    });

    // Initial load
    document.addEventListener('DOMContentLoaded', loadRooms);
  </script>
</body>
</html>
