<?php

/*
|--------------------------------------------------------------------------
| PHARMACY MEDICINE INVENTORY SYSTEM  —  CONFIGURATION FILE
|--------------------------------------------------------------------------
| Edit the values below to match your environment. This file is
| included by index.php and should never be exposed publicly on
| a production server (keep it outside the web root if possible,
| or block it via .htaccess / server config).
|--------------------------------------------------------------------------
*/

/* ============================================================
   DATABASE CONNECTION SETTINGS
   >>> EDIT THESE FOUR VALUES TO MATCH YOUR MYSQL SERVER <<<
============================================================ */

define('DB_HOST', 'localhost');
define('DB_NAME', 'pharmacy_inventory');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', '3306');


/* ============================================================
   BASIC SETTINGS
============================================================ */

date_default_timezone_set('Asia/Manila');

$DEFAULT_LOW_STOCK = 200;
$EXPIRY_WARNING_DAYS = 30;
