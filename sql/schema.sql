-- ================================
-- Fili Booking - MySQL Schema
-- ================================
-- Recommended MySQL 8.0+, InnoDB, utf8mb4
-- Run this whole file once.

-- 1) Create database (skip if you already made it)
CREATE DATABASE IF NOT EXISTS fili
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE fili;

-- 2) Safety drops (only for development)
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS guests;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS rooms;

-- 3) Rooms
CREATE TABLE rooms (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  description  TEXT,
  capacity     INT NOT NULL DEFAULT 2,
  price_cents  INT NOT NULL,                  -- store money as cents
  currency     VARCHAR(3) NOT NULL DEFAULT 'USD',
  image_url    VARCHAR(255),
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_rooms_active (is_active),
  INDEX idx_rooms_price (price_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Bookings
CREATE TABLE bookings (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  room_id      INT NOT NULL,
  check_in     DATE NOT NULL,
  check_out    DATE NOT NULL,
  guests       INT NOT NULL,
  status       ENUM('pending','requires_payment','paid','cancelled') NOT NULL DEFAULT 'pending',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bookings_room
    FOREIGN KEY (room_id) REFERENCES rooms(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CHECK (check_out > check_in),
  CHECK (guests >= 1),
  INDEX idx_bookings_room (room_id),
  INDEX idx_bookings_dates (check_in, check_out),
  INDEX idx_bookings_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5) Guests (1 booking → 1 primary guest record; extend as needed)
CREATE TABLE guests (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  booking_id   INT NOT NULL,
  first_name   VARCHAR(80),
  last_name    VARCHAR(80),
  email        VARCHAR(160),
  phone        VARCHAR(40),
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_guests_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_guests_booking (booking_id),
  INDEX idx_guests_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6) Payments
CREATE TABLE payments (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  booking_id    INT NOT NULL,
  stripe_pi_id  VARCHAR(255) UNIQUE,
  amount_cents  INT NOT NULL,
  currency      VARCHAR(3) NOT NULL,
  status        VARCHAR(40) NOT NULL,     -- mirrors Stripe PI status (e.g., 'succeeded')
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_payments_booking (booking_id),
  INDEX idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7) Seed demo rooms (update image paths if needed)
INSERT INTO rooms (name, description, capacity, price_cents, currency, image_url, is_active)
VALUES
('Premier King',    'City view, 35 m². Breakfast optional.',            2, 13500, 'USD', '/public/assets/img/king.jpg',   1),
('Executive Twin',  'High floor, 32 m². Two single beds.',              3, 15500, 'USD', '/public/assets/img/twin.jpg',   1),
('Family Suite',    'Two rooms, 60 m². Great for families.',            5, 29500, 'USD', '/public/assets/img/family.jpg', 1);

-- 8) Helpful view: recent paid bookings (optional)
DROP VIEW IF EXISTS v_recent_paid_bookings;
CREATE VIEW v_recent_paid_bookings AS
SELECT b.id AS booking_id, r.name AS room_name, g.first_name, g.last_name,
       b.check_in, b.check_out, p.amount_cents, p.currency, p.status, b.created_at
FROM bookings b
JOIN rooms r   ON r.id = b.room_id
LEFT JOIN guests g ON g.booking_id = b.id
LEFT JOIN payments p ON p.booking_id = b.id
WHERE b.status = 'paid'
ORDER BY b.created_at DESC;

-- 9) Done
-- Import complete. You can now hit the API endpoints.
