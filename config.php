<?php
/*
|--------------------------------------------------------------------------
| CONFIG — Supabase (Postgres) connection
|--------------------------------------------------------------------------
| Get these values from your Supabase project:
|   Project Settings -> Database -> Connection string / Connection info
|
| Two connection options:
|
| 1) Direct connection (port 5432) — simplest, works fine for a small
|    admin app like this one that isn't running behind serverless
|    functions with lots of short-lived connections.
|
| 2) Session pooler (port 5432 via pooler host) or Transaction pooler
|    (port 6543) — use this if your host opens/closes many connections
|    per request (e.g. shared hosting spinning up PHP-FPM workers a lot)
|    to avoid exhausting Postgres' connection limit.
|
| For a typical single PHP server, option 1 (direct) is fine.
*/

define('DB_HOST', 'db.YOUR-PROJECT-REF.supabase.co'); // Project Settings -> Database -> Host
define('DB_PORT', '5432');                            // 5432 direct, or 6543 for transaction pooler
define('DB_NAME', 'postgres');                          // Supabase's default database name
define('DB_USER', 'postgres');                          // Supabase's default database user
define('DB_PASS', 'YOUR-DATABASE-PASSWORD');            // the password you set when creating the project

// App settings (unchanged from the original)
$DEFAULT_LOW_STOCK = 200;
$EXPIRY_WARNING_DAYS = 30;
