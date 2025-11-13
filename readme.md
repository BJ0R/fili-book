Here's a clear **README.md** you can drop into your project root.

---

# Fili Booking — Stripe Setup

This guide shows how to install Stripe’s PHP SDK and obtain your **Publishable key**, **Secret key**, and **Webhook signing secret** for local development on XAMPP.

## Prerequisites

* PHP 8+ (bundled with XAMPP)
* Composer
* XAMPP (Apache + MySQL running)
* Stripe account (free) with **Test mode** enabled
* Stripe CLI (for local webhooks)

---

## 1) Install the Stripe PHP SDK

Open a terminal in your project root (e.g., `C:\xampp\htdocs\fili-booking`) and run:

```bash
composer require stripe/stripe-php
```

This creates `vendor/` and `composer.json`. Our PHP files autoload Stripe via `vendor/autoload.php`.

---

## 2) Get your API keys (Publishable + Secret)

1. Go to the **Stripe Dashboard** → **Developers** → **API keys**
2. Toggle **Test mode** (top-left).
3. Copy:

   * **Publishable key** (looks like `pk_test_...`)
   * **Secret key** (looks like `sk_test_...`)

Put them into `.env`:

```ini
STRIPE_PUBLISHABLE=pk_test_XXXXXXXXXXXXXXXXXXXX
STRIPE_SECRET=sk_test_XXXXXXXXXXXXXXXXXXXX
```

> **Tip:** Publishable is used in the browser (Stripe.js), Secret is used by your server (PHP).

---

## 3) Create your Webhook signing secret (local dev)

The “pairing code” from `stripe login` is **not** your webhook secret.
Use the Stripe CLI to generate a live listener and get the real `whsec_...`.

### 3.1 Log in to Stripe CLI (one-time)

```bash
stripe login
```

A browser opens → approve access → back to terminal.

### 3.2 Start listening & forward events to your local webhook

> Keep this terminal window **open** while testing.

```bash
stripe listen --forward-to http://localhost/fili-booking/public/webhook.php
```

You’ll see output like:

```
Ready! Your webhook signing secret is whsec_XXXXXXXXXXXXXXXX
Forwarding events to http://localhost/fili-booking/public/webhook.php
```

Copy the **`whsec_...`** and add to `.env`:

```ini
STRIPE_WEBHOOK_SECRET=whsec_XXXXXXXXXXXXXXXX
```

> **Need to print it again later?**
> `stripe listen --print-secret`

---

## 4) Fill out the rest of `.env` (example)

```ini
APP_URL=http://localhost/fili-booking
APP_TZ=Asia/Manila
APP_DEBUG=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=fili
DB_USER=root
DB_PASS=

STRIPE_PUBLISHABLE=pk_test_XXXXXXXXXXXXXXXX
STRIPE_SECRET=sk_test_XXXXXXXXXXXXXXXX
STRIPE_WEBHOOK_SECRET=whsec_XXXXXXXXXXXXXXXX

DEFAULT_CURRENCY=USD
```

---

## 5) Database setup

Create database and tables:

1. Open phpMyAdmin → create DB `fili`.
2. Import `sql/schema.sql`.

---

## 6) Run the app

1. Start **Apache** and **MySQL** in XAMPP.
2. Open: `http://localhost/fili-booking/public/index.php`
3. In another terminal, keep the Stripe listener running (step 3.2).

---

## 7) Test a payment

1. Select a room → fill guest details → proceed to **Payment**.
2. Use test card: `4242 4242 4242 4242` (any future expiry, any CVC, any ZIP).
3. After confirmation, you’ll be redirected to **success**.
4. Check:

   * **Stripe Dashboard → Payments** shows **Succeeded**.
   * Database:

     * `payments.status` updated (e.g., `succeeded`)
     * `bookings.status` becomes **paid** (via webhook handler).

---

## 8) Troubleshooting

* **Composer autoload not found**
  Run `composer require stripe/stripe-php` in the **project root**, not `/public`.

* **Webhook not updating booking**
  Ensure `stripe listen` is running and `.env` has the current `STRIPE_WEBHOOK_SECRET`.
  Each new listen session creates a fresh `whsec_...`.

* **CORS or wrong URLs**
  Confirm `APP_URL` in `.env` is `http://localhost/fili-booking`.
  Frontend pulls it in `index.php` / `booking.php`.

* **Amount mismatch**
  We compute totals **server-side** from room price × nights; don’t send client amounts to Stripe.

---

## 9) Going live (later)

1. Switch Dashboard to **Live mode** and create **live** API keys.
2. Update `.env` with `pk_live_...`, `sk_live_...`.
3. Host your site over **HTTPS**.
4. In Dashboard → **Developers → Webhooks** → **Add endpoint** to your live URL
   (e.g., `https://yourdomain.com/public/webhook.php`) and paste the **live** `whsec_...` into `.env`.
5. Run a small real payment to verify.

---

**That’s it!** With the SDK installed and all three values set (Publishable, Secret, Webhook secret), your booking flow is ready to accept test payments locally.
