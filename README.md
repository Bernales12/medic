# Pharmacy Medicine Inventory System (MySQL Edition)

A single-app PHP + MySQL inventory system for tracking pharmacy medicine
stock, expiration dates, and dispensing history — with a dark purple
themed dashboard.

## Features

- Medicine inventory stored in a MySQL database
- Add / Edit / Delete medicines
- Per-medicine low-stock threshold
- 30-day expiration warning
- Automatic zeroing of expired medicine stock
- Expiration reports (expired + expiring soon)
- Dispensing / stock-out logging
- Monthly dispensing report with charts
- Excel-compatible inventory export
- Excel-compatible dispensing export

## Files

| File | Purpose |
|---|---|
| `index.php` | Main application — UI, routing, and all business logic |
| `config.php` | Database credentials and basic settings |
| `README.md` | This file |

## Requirements

- PHP 7.4+ with the **PDO MySQL** extension enabled
- MySQL / MariaDB server
- A web server (Apache, Nginx, or PHP's built-in server)

## Setup

1. **Create the database** in MySQL:
   ```sql
   CREATE DATABASE pharmacy_inventory;
   ```
   You don't need to create tables manually — `index.php` calls
   `ensureSchema()` on load, which creates the `medicines` and
   `dispense_logs` tables automatically (and seeds a few sample
   medicines) the first time it connects.

2. **Edit `config.php`** with your MySQL credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'pharmacy_inventory');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_PORT', '3306');
   ```

3. **Place both files in the same folder** on your web server (e.g.
   `htdocs/inventory/` for XAMPP, or `www/inventory/` for WAMP/MAMP).

4. **Open it in a browser**, e.g.:
   ```
   http://localhost/inventory/index.php
   ```

## Notes

- `config.php` is loaded via `require_once` at the top of `index.php` —
  keep both files together in the same directory.
- On a production server, avoid exposing `config.php` directly (e.g.
  block direct access via `.htaccess`, or move it outside the public
  web root and adjust the `require_once` path accordingly).
- Timezone defaults to `Asia/Manila` and the default low-stock
  threshold is `200` units — both configurable in `config.php`.
- Excel exports (`inventory_report.xls`, `dispensing_report.xls`) are
  written to the same directory as `index.php`, so that directory
  must be writable by the web server.
