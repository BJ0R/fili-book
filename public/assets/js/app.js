/* =========================================
   Fili Booking App JS
   File: public/assets/js/app.js
   Stack: PHP + MySQL + AJAX + Stripe Elements
   ========================================= */

(() => {
  // ---------- Config ----------
  const APP_URL = window.APP_URL || (location.origin + '/fili-booking/public');
  const API = {
    ROOMS: `${APP_URL}/api/rooms.php`,
    BOOK: `${APP_URL}/api/booking_create.php`,
    PI: `${APP_URL}/api/stripe_create_pi.php`,
  };
  const STRIPE_PK = window.STRIPE_PK || 'pk_test_xxx'; // set in index.php for real

  // ---------- DOM Helpers ----------
  const $ = (s) => document.querySelector(s);
  const $$ = (s) => Array.from(document.querySelectorAll(s));
  const on = (el, ev, fn) => el && el.addEventListener(ev, fn);

  // ---------- State ----------
  const S = {
    selectedRoom: null,
    bookingId: null,
    priceCents: 0,
    currency: 'USD',
    nights: 1,
    stripe: null,
    elements: null,
    paymentElement: null,
    clientSecret: null,
  };

  // ---------- Utils ----------
  const fmtMoney = (cents, code = 'USD') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: code })
      .format((cents || 0) / 100);

  const todayISO = () => new Date().toISOString().slice(0, 10);

  const nightsBetween = (a, b) => {
    try {
      const d1 = new Date(a + 'T00:00:00');
      const d2 = new Date(b + 'T00:00:00');
      const diff = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
      return Math.max(1, diff);
    } catch {
      return 1;
    }
  };

  const setStepActive = (n) => {
    ['#step-1', '#step-2', '#step-3'].forEach((id, i) => {
      $(id).classList.toggle('hidden', i !== n - 1);
    });
    $$('#stepper li').forEach((li, i) => li.classList.toggle('active', i === n - 1));
  };

  const toast = (msg) => {
    const n = document.createElement('div');
    n.textContent = msg;
    Object.assign(n.style, {
      position: 'fixed', left: '50%', bottom: '24px', transform: 'translateX(-50%)',
      background: '#1a1f2b', color: '#fff', padding: '10px 14px', borderRadius: '12px',
      boxShadow: '0 10px 24px rgba(0,0,0,.18)', zIndex: 9999, opacity: 0, transition: 'opacity .2s ease'
    });
    document.body.appendChild(n);
    requestAnimationFrame(() => (n.style.opacity = 1));
    setTimeout(() => {
      n.style.opacity = 0;
      setTimeout(() => n.remove(), 250);
    }, 2200);
  };

  // ---------- Rooms ----------
  async function loadRooms(opts = {}) {
    const grid = $('#rooms-grid');
    grid.innerHTML = cardSkeletons(6);

    const params = new URLSearchParams();
    if (opts.guests) params.set('guests', opts.guests);
    if (opts.check_in) params.set('check_in', opts.check_in);
    if (opts.check_out) params.set('check_out', opts.check_out);

    const url = `${API.ROOMS}${params.toString() ? `?${params.toString()}` : ''}`;

    try {
      const res = await fetch(url);
      const json = await res.json();
      if (!res.ok) throw new Error(json.error || 'Failed to load rooms');
      grid.innerHTML = json.data.map(roomCard).join('') || emptyState();
      bindRoomSelection();
      // Enable/disable continue button
      $('#next-to-details').disabled = !S.selectedRoom;
    } catch (e) {
      grid.innerHTML = errorState(e.message);
    }
  }

  function roomCard(r) {
    const data = encodeURIComponent(JSON.stringify(r));
    return `
      <article class="room" data-room="${data}" tabindex="0">
        <img class="img" src="${r.image_url || 'https://picsum.photos/640/360?blur=3'}" alt="${escapeHtml(r.name)}">
        <div class="meta">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0">${escapeHtml(r.name)}</h3>
            <span class="badge">Up to ${Number(r.capacity)}</span>
          </div>
          <p style="min-height:40px">${escapeHtml(r.description || '')}</p>
          <div class="price">${fmtMoney(Number(r.price_cents), r.currency)}</div>
        </div>
      </article>
    `;
  }

  function cardSkeletons(n) {
    return Array.from({ length: n }).map(() => `
      <article class="room" aria-hidden="true" style="pointer-events:none">
        <div class="img" style="background:linear-gradient(90deg,#eaeaf0 25%,#f4f5f9 50%,#eaeaf0 75%);background-size:200% 100%;animation:shimmer 1.2s linear infinite;"></div>
        <div class="meta">
          <div style="height:16px;width:70%;background:#eee;border-radius:8px;margin:6px 0;"></div>
          <div style="height:12px;width:90%;background:#eee;border-radius:8px;margin:6px 0;"></div>
          <div style="height:12px;width:75%;background:#eee;border-radius:8px;margin:6px 0;"></div>
          <div style="height:16px;width:40%;background:#eee;border-radius:8px;margin:10px 0;"></div>
        </div>
      </article>
    `).join('');
  }
  const shimmer = document.createElement('style');
  shimmer.textContent = `@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}`;
  document.head.appendChild(shimmer);

  function emptyState() {
    return `<div class="card"><div class="note">No rooms match your filters. Try different dates or guest count.</div></div>`;
  }

  function errorState(msg) {
    return `<div class="card"><div class="note error">Error: ${escapeHtml(msg)}</div></div>`;
  }

  function bindRoomSelection() {
    on($('#rooms-grid'), 'click', (e) => {
      const card = e.target.closest('.room');
      if (!card) return;
      $$('.room').forEach((x) => x.classList.remove('selected'));
      card.classList.add('selected');
      try {
        S.selectedRoom = JSON.parse(decodeURIComponent(card.dataset.room));
        S.priceCents = Number(S.selectedRoom.price_cents);
        S.currency = S.selectedRoom.currency || 'USD';
        $('#next-to-details').disabled = false;
      } catch {
        // ignore
      }
    });

    // Keyboard support
    $$('#rooms-grid .room').forEach((el) => {
      on(el, 'keydown', (ev) => {
        if (ev.key === 'Enter' || ev.key === ' ') {
          ev.preventDefault();
          el.click();
        }
      });
    });
  }

  // ---------- Filters in Step 2 feed back to Step 1 ----------
  function bindFiltersToRooms() {
    const form = $('#guest-form');
    ['guests', 'check_in', 'check_out'].forEach((name) => {
      on(form[name], 'change', () => {
        const guests = Number(form.guests.value || 1);
        const ci = form.check_in.value;
        const co = form.check_out.value;
        if (ci && co && new Date(ci) >= new Date(co)) return; // wait until valid
        loadRooms({ guests, check_in: ci, check_out: co });
      });
    });
  }

  // ---------- Step Navigation ----------
  function bindStepperNav() {
    on($('#next-to-details'), 'click', () => {
      if (!S.selectedRoom) return toast('Please select a room.');
      setStepActive(2);
    });
    on($('#back-to-rooms'), 'click', () => setStepActive(1));
  }

  // ---------- Guest Details & Booking Creation ----------
  function bindGuestForm() {
    const form = $('#guest-form');

    // sensible defaults
    if (!form.check_in.value) form.check_in.value = todayISO();
    if (!form.check_out.value) {
      const t = new Date();
      t.setDate(t.getDate() + 1);
      form.check_out.value = t.toISOString().slice(0, 10);
    }

    on(form, 'submit', async (e) => {
      e.preventDefault();
      if (!S.selectedRoom) return toast('Please select a room first.');

      const payload = {
        room_id: Number(S.selectedRoom.id),
        check_in: form.check_in.value,
        check_out: form.check_out.value,
        guests: Number(form.guests.value || 1),
        guest: {
          first_name: form.first_name.value.trim(),
          last_name: form.last_name.value.trim(),
          email: form.email.value.trim(),
          phone: form.phone.value.trim(),
        },
      };

      // quick validation
      if (new Date(payload.check_in) >= new Date(payload.check_out)) {
        return toast('Check-out must be after check-in.');
      }

      try {
        const res = await fetch(API.BOOK, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.error || 'Booking failed');

        S.bookingId = json.booking_id;
        S.priceCents = Number(json.amount_cents);
        S.currency = json.currency || 'USD';
        S.nights = Number(json.nights || nightsBetween(payload.check_in, payload.check_out));

        // Update summary and move to payment
        renderSummary(payload);
        await initStripeAndElement();
        setStepActive(3);
      } catch (err) {
        toast(err.message || 'Error creating booking');
      }
    });
  }

  function renderSummary(p) {
    $('#summary').innerHTML = `
      <div class="note">
        <strong>${escapeHtml(S.selectedRoom.name || 'Room')}</strong><br/>
        ${p.check_in} → ${p.check_out} · ${p.guests} guest(s) · ${S.nights} night(s)<br/>
        Total: <strong>${fmtMoney(S.priceCents, S.currency)}</strong>
        ${S.nights > 1 ? ` (${fmtMoney(Number(S.selectedRoom.price_cents), S.currency)} / night)` : ''}
      </div>
    `;
  }

  // ---------- Stripe: PaymentIntent + Payment Element ----------
  async function initStripeAndElement() {
    // Create (or reuse) PaymentIntent on server
    const res = await fetch(API.PI, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ booking_id: S.bookingId }),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Failed to create payment');

    S.clientSecret = json.client_secret;
    // Initialize Stripe
    S.stripe = Stripe(STRIPE_PK);
    S.elements = S.stripe.elements({ clientSecret: json.client_secret, appearance: { theme: 'stripe' } });

    if (S.paymentElement) {
      try { S.paymentElement.unmount(); } catch {}
    }
    S.paymentElement = S.elements.create('payment');
    S.paymentElement.mount('#payment-element');

    // Bind submit
    const form = $('#payment-form');
    form.onsubmit = onPaySubmit;
  }

  async function onPaySubmit(ev) {
    ev.preventDefault();
    setPayLoading(true);

    try {
      const { error } = await S.stripe.confirmPayment({
        elements: S.elements,
        confirmParams: {
          return_url: `${APP_URL}/success.php?b=${S.bookingId}`,
        },
      });

      if (error) {
        showPaymentMessage(error.message || 'Payment failed. Please try again.');
        setPayLoading(false);
      }
      // On success with redirect, browser leaves the page
    } catch (e) {
      showPaymentMessage('Something went wrong. Please try again.');
      setPayLoading(false);
    }
  }

  function setPayLoading(isLoading) {
    $('#submit-payment .lbl').style.opacity = isLoading ? 0.5 : 1;
    $('#submit-payment .spinner').classList.toggle('hidden', !isLoading);
  }

  function showPaymentMessage(msg) {
    const el = $('#payment-message');
    el.textContent = msg;
    el.classList.remove('hidden');
  }

  // ---------- Accessibility / Micro-interactions ----------
  function bindMicroInteractions() {
    // Soft hover ripple on buttons (CSS-free)
    $$('.btn').forEach((btn) => {
      on(btn, 'mouseenter', () => btn.animate([{ transform: 'translateY(0)' }, { transform: 'translateY(-1px)' }], { duration: 120, fill: 'forwards' }));
      on(btn, 'mouseleave', () => btn.animate([{ transform: 'translateY(-1px)' }, { transform: 'translateY(0)' }], { duration: 120, fill: 'forwards' }));
    });
  }

  // ---------- Escape HTML ----------
  function escapeHtml(s) {
    return String(s || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  // ---------- Init ----------
  document.addEventListener('DOMContentLoaded', async () => {
    // Step 1 rooms
    await loadRooms();

    // Stepper, filters, forms
    bindStepperNav();
    bindGuestForm();
    bindFiltersToRooms();
    bindMicroInteractions();

    // Nav links to jump between views
    on($('[data-nav="rooms"]'), 'click', (e) => { e.preventDefault(); setStepActive(1); });
    on($('[data-nav="book"]'), 'click', (e) => { e.preventDefault(); setStepActive(1); });

    // Keep continue disabled until room is chosen
    $('#next-to-details').disabled = !S.selectedRoom;
  });
})();
