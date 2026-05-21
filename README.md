# NFC Tuckshop System (School)

Offline-friendly prepaid tuckshop POS: students tap an NFC card, the server looks up their wallet balance, items are added on a touch-friendly screen, and the purchase is logged locally.

## Features

- **NFC tap → wallet lookup** — Card stores only UID; money lives on the server
- **POS terminals** (1–3 counters) — Large product buttons, cart, confirm/cancel, receipt
- **Admin dashboard** — Students, NFC binding, top-ups, products, daily sales, CSV export
- **Spending limits** — Max daily spend, max drinks/pastries per day (configurable)
- **Inventory** — Admin sees items available; stock reduces on each sale
- **Seller portal** — Sellers view inventory and transactions only (no edits)
- **Local LAN only** — No internet required for daily use

## Requirements

- PHP 8.0+ with PDO MySQL
- MySQL 5.7+ or MariaDB
- Modern browser on each POS PC
- USB NFC reader (keyboard wedge — sends UID + Enter)

## Quick setup (Windows / Ubuntu)

### 1. Database

```bash
# Edit config/config.php or copy config.example.php → config.local.php
php install.php
```

Or manually:

```bash
mysql -u root -p < sql/schema.sql
```

### 2. Configure

Copy `config/config.example.php` to `config/config.local.php` and set:

- Database host, user, password
- **Change `admin_password`** before production

Default admin password (if using stock `config/config.php`): `tuckshop2026`

### 3. Run server

**Development (single machine):**

```bash
cd TuckshopProjectApp
php -S 0.0.0.0:8080
```

Open:

- Home: `http://localhost:8080`
- POS: `http://localhost:8080/pos/`
- Admin: `http://localhost:8080/admin/`
- Seller: `http://localhost:8080/seller/`

### Existing database upgrade

If you already imported `schema.sql` before inventory/sellers were added, run in phpMyAdmin:

`sql/migration_inventory_sellers.sql`

(If you see “duplicate column”, the stock column already exists — run only the `CREATE TABLE sellers` part.)

**School LAN (recommended):**

- Install Apache or Nginx + PHP on a machine with static IP e.g. `192.168.10.10`
- Point document root to this folder
- Each counter opens `http://192.168.10.10/pos/` in full-screen browser (F11)

### 4. NFC readers

Most cheap USB NFC readers act as a **keyboard wedge**: tapping a card types the UID into the focused field. The POS screen auto-reads the NFC input when Enter is pressed or UID length ≥ 8.

To bind a new card:

1. Admin → Students → **Bind NFC** on the student row, or
2. Add student with UID on first registration

## Daily workflow

| Role | Steps |
|------|--------|
| **Finance** | Record cash top-ups in Admin (per student) |
| **Counter** | Student taps card → add items → Confirm purchase |
| **End of day** | Admin → Transactions → Export CSV |

## API endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET api/nfc.php?uid=` | Look up student by card |
| `POST api/nfc.php` | Bind card to student (admin) |
| `GET api/products.php` | Menu items |
| `POST api/purchase.php` | Checkout |
| `GET api/transactions.php?date=` | Sales list |
| `GET api/export.php?date=` | CSV download (admin token) |
| `POST api/topups.php` | Wallet top-up (admin) |

## Backup

```bash
mysqldump -u root -p tuckshop > backup-$(date +%F).sql
```

Copy the `.sql` file to USB weekly.

## Hardware (per your proposal)

- 1 central PC/server (MySQL + PHP)
- 1–3 POS PCs with monitor + USB NFC reader each
- Student cards/fobs (NTAG or MIFARE — UID only)

## Security notes

- Change default admin password immediately
- Keep server on private school VLAN only
- Do not expose POS to the public internet without hardening

## License

Built for school tuckshop use — customize freely for your institution.
