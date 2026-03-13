<?php
/**
 * Scheduling Widget — PHP backend (no Composer / no external libs).
 * Mirrors the Python stdlib server feature-for-feature.
 *
 * Extensions used (all bundled with standard PHP):
 *   pdo_sqlite, openssl (stream_socket_enable_crypto), curl
 */

// ── .env loader ────────────────────────────────────────────────────────────────
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) putenv($line);
    }
}

// ── Config ─────────────────────────────────────────────────────────────────────
define('ROOT',         getenv('SCHEDULER_ROOT') ?: __DIR__ . '/..');
define('DB_PATH',      getenv('SCHEDULER_DB')   ?: '/tmp/scheduling_php.db');
define('BASE_URL',     getenv('BASE_URL')        ?: 'http://localhost:8081');
define('ADMIN_SECRET', getenv('ADMIN_SECRET')    ?: '');
define('SMTP_HOST',    getenv('SMTP_HOST')       ?: '');
define('SMTP_PORT',    (int)(getenv('SMTP_PORT') ?: '587'));
define('SMTP_USER',    getenv('SMTP_USER')       ?: '');
define('SMTP_PASS',    getenv('SMTP_PASS')       ?: '');
define('FROM_EMAIL',   getenv('FROM_EMAIL')      ?: '');

// ── Database ───────────────────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    return $pdo;
}

function init_db(): void {
    $db = get_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS operators (
            id         TEXT PRIMARY KEY,
            api_key    TEXT UNIQUE NOT NULL,
            name       TEXT NOT NULL DEFAULT 'Admin',
            email      TEXT NOT NULL DEFAULT '',
            is_active  INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS workers (
            id          TEXT PRIMARY KEY,
            operator_id TEXT NOT NULL,
            name        TEXT NOT NULL,
            title       TEXT NOT NULL DEFAULT '',
            bio         TEXT NOT NULL DEFAULT '',
            is_active   INTEGER NOT NULL DEFAULT 1,
            created_at  TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS availability (
            id         TEXT PRIMARY KEY,
            worker_id  TEXT NOT NULL,
            date       TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time   TEXT NOT NULL,
            is_booked  INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT '',
            UNIQUE(worker_id, date, start_time)
        );
        CREATE TABLE IF NOT EXISTS bookings (
            id              TEXT PRIMARY KEY,
            worker_id       TEXT NOT NULL,
            availability_id TEXT UNIQUE NOT NULL,
            visitor_name    TEXT NOT NULL,
            visitor_email   TEXT NOT NULL,
            visitor_phone   TEXT NOT NULL DEFAULT '',
            notes           TEXT NOT NULL DEFAULT '',
            status          TEXT NOT NULL DEFAULT 'CONFIRMED',
            crm_contact_id  TEXT NOT NULL DEFAULT '',
            crm_deal_id     TEXT NOT NULL DEFAULT '',
            created_at      TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS pending_bookings (
            id            TEXT PRIMARY KEY,
            code          TEXT NOT NULL,
            avail_id      TEXT NOT NULL,
            visitor_name  TEXT NOT NULL,
            visitor_email TEXT NOT NULL,
            visitor_phone TEXT NOT NULL,
            notes         TEXT NOT NULL DEFAULT '',
            expires_at    TEXT NOT NULL,
            created_at    TEXT NOT NULL
        );
    ");

    // ── operators column migrations ─────────────────────────────────────────────
    $op_cols = array_column($db->query('PRAGMA table_info(operators)')->fetchAll(), 'name');
    $op_migrations = [
        'email'          => "TEXT NOT NULL DEFAULT ''",
        'is_active'      => "INTEGER NOT NULL DEFAULT 1",
        'created_at'     => "TEXT NOT NULL DEFAULT ''",
        'email_verified' => "INTEGER NOT NULL DEFAULT 1",
        'verify_token'   => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($op_migrations as $col => $defn) {
        if (!in_array($col, $op_cols, true)) {
            $db->exec("ALTER TABLE operators ADD COLUMN $col $defn");
        }
    }

    // ── settings table — migrate to per-operator schema ─────────────────────────
    $s_cols = array_column($db->query('PRAGMA table_info(settings)')->fetchAll(), 'name');
    if (!in_array('operator_id', $s_cols, true)) {
        $old = $s_cols ? $db->query('SELECT key, value FROM settings')->fetchAll() : [];
        $first_op = $db->query('SELECT id FROM operators LIMIT 1')->fetch();
        $first_op_id = $first_op ? $first_op['id'] : '';
        $db->exec('DROP TABLE IF EXISTS settings');
        $db->exec("
            CREATE TABLE settings (
                id          TEXT PRIMARY KEY,
                operator_id TEXT NOT NULL,
                key         TEXT NOT NULL,
                value       TEXT NOT NULL DEFAULT '',
                UNIQUE(operator_id, key)
            )
        ");
        if ($first_op_id) {
            $ins = $db->prepare('INSERT OR IGNORE INTO settings (id, operator_id, key, value) VALUES (?,?,?,?)');
            foreach ($old as $row) {
                $ins->execute([uuid4(), $first_op_id, $row['key'], $row['value']]);
            }
        }
    } else {
        $db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id          TEXT PRIMARY KEY,
                operator_id TEXT NOT NULL,
                key         TEXT NOT NULL,
                value       TEXT NOT NULL DEFAULT '',
                UNIQUE(operator_id, key)
            )
        ");
    }

    // ── bookings column migrations ──────────────────────────────────────────────
    $b_cols = array_column($db->query('PRAGMA table_info(bookings)')->fetchAll(), 'name');
    foreach (['crm_contact_id' => "TEXT NOT NULL DEFAULT ''", 'crm_deal_id' => "TEXT NOT NULL DEFAULT ''"] as $col => $defn) {
        if (!in_array($col, $b_cols, true)) {
            $db->exec("ALTER TABLE bookings ADD COLUMN $col $defn");
        }
    }

    // ── seed default operator (first-run only) ──────────────────────────────────
    $row = $db->query('SELECT id FROM operators LIMIT 1')->fetch();
    if (!$row) {
        $op_id  = uuid4();
        $api_key = uuid4();
        $now    = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO operators (id, api_key, name, created_at) VALUES (?,?,?,?)')
           ->execute([$op_id, $api_key, 'Admin', $now]);
        $db->prepare('INSERT INTO workers (id, operator_id, name, title, bio, created_at) VALUES (?,?,?,?,?,?)')
           ->execute([uuid4(), $op_id, 'Alice Johnson', 'Senior Stylist', 'Specialist in color, cut and styling.', $now]);
        error_log("[INIT] Default operator created. API key: $api_key");
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function uuid4(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function utcnow(): string {
    return gmdate('Y-m-d H:i:s');
}

function json_ok(mixed $data, int $status = 200): void {
    cors_headers();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function json_err(string $msg, int $status = 400): void {
    json_ok(['error' => $msg], $status);
}

function cors_headers(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type,X-Api-Key,X-Admin-Key');
}

function body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

function qs(string $key, mixed $default = null): mixed {
    return $_GET[$key] ?? $default;
}

// ── Email (SMTP STARTTLS, no external lib) ─────────────────────────────────────
function send_email(string $to, string $subject, string $html_body): void {
    if (!SMTP_HOST || !SMTP_USER || !SMTP_PASS || !FROM_EMAIL || !$to) return;

    $read = function ($sock): string {
        $resp = '';
        while ($line = fgets($sock, 512)) {
            $resp .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $resp;
    };

    try {
        $sock = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 10);
        if (!$sock) throw new RuntimeException("Connect failed: $errstr ($errno)");
        stream_set_timeout($sock, 10);

        $read($sock);                               // 220 greeting
        fwrite($sock, "EHLO localhost\r\n");
        $read($sock);                               // EHLO response (multi-line)

        fwrite($sock, "STARTTLS\r\n");
        $r = $read($sock);
        if (strpos($r, '220') === false) throw new RuntimeException("STARTTLS failed: $r");

        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
            throw new RuntimeException("TLS upgrade failed");
        }

        fwrite($sock, "EHLO localhost\r\n");
        $read($sock);

        fwrite($sock, "AUTH LOGIN\r\n");
        $read($sock);                               // 334 VXNlcm5hbWU6
        fwrite($sock, base64_encode(SMTP_USER) . "\r\n");
        $read($sock);                               // 334 UGFzc3dvcmQ6
        fwrite($sock, base64_encode(SMTP_PASS) . "\r\n");
        $auth = $read($sock);
        if (strpos($auth, '235') === false) throw new RuntimeException("Auth failed: $auth");

        fwrite($sock, "MAIL FROM: <" . FROM_EMAIL . ">\r\n");
        $read($sock);
        fwrite($sock, "RCPT TO: <$to>\r\n");
        $read($sock);
        fwrite($sock, "DATA\r\n");
        $read($sock);   // 354

        $msg  = "From: " . FROM_EMAIL . "\r\n";
        $msg .= "To: $to\r\n";
        $msg .= "Subject: $subject\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "\r\n";
        $msg .= $html_body . "\r\n.\r\n";
        fwrite($sock, $msg);
        $send_resp = $read($sock);

        fwrite($sock, "QUIT\r\n");
        fclose($sock);

        if (strpos($send_resp, '250') !== false) {
            error_log("[EMAIL] Sent \"$subject\" to $to");
        } else {
            error_log("[EMAIL] Send error: $send_resp");
        }
    } catch (Throwable $e) {
        error_log("[EMAIL] Failed: " . $e->getMessage());
    }
}

// ── Operator settings ──────────────────────────────────────────────────────────
function get_op_settings(string $op_id): array {
    $rows = get_db()->prepare('SELECT key, value FROM settings WHERE operator_id=?');
    $rows->execute([$op_id]);
    $out = [];
    foreach ($rows->fetchAll() as $r) $out[$r['key']] = $r['value'];
    return $out;
}

// ── SendPulse helpers ──────────────────────────────────────────────────────────
function sp_call(string $method, string $path, ?array $body = null, string $bearer = ''): array {
    $url = 'https://api.sendpulse.com' . $path;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Accept: application/json'];
    if ($bearer) $headers[] = "Authorization: Bearer $bearer";
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException("curl error");
    $decoded = json_decode($resp, true);
    if ($status >= 400) throw new RuntimeException("HTTP $status: $resp");
    return $decoded ?? [];
}

function sp_get_token(string $cid, string $secret): array {
    $ch = curl_init('https://api.sendpulse.com/oauth/access_token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $cid,
        'client_secret' => $secret,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true) ?? [];
}

function normalize_ua_phone(string $phone): ?string {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 9)  $digits = '380' . $digits;
    elseif (strlen($digits) === 10 && $digits[0] === '0') $digits = '38' . $digits;
    elseif (strlen($digits) === 11 && substr($digits, 0, 2) === '80') $digits = '3' . $digits;
    if (substr($digits, 0, 3) === '380' && strlen($digits) === 12) return $digits;
    return null;
}

function sendpulse_sync(
    string $op_id, string $booking_id,
    string $visitor_name, string $visitor_email, string $visitor_phone,
    string $worker_name, string $date, string $start_time,
    string $notes = '', bool $call_requested = false
): void {
    try {
        $s       = get_op_settings($op_id);
        $cid     = trim($s['sp_client_id']     ?? '');
        $secret  = trim($s['sp_client_secret'] ?? '');
        $pip     = trim($s['sp_pipeline_id']   ?? '');
        $step    = trim($s['sp_step_id']       ?? '');
        $resp_id = trim($s['sp_responsible_id']?? '');

        if (!$cid || !$secret || !$pip || !$step) {
            error_log('SendPulse: credentials not fully configured — skipping sync.');
            return;
        }

        $tok = sp_get_token($cid, $secret)['access_token'] ?? '';
        if (!$tok) { error_log('SendPulse: no access token'); return; }

        $parts   = explode(' ', $visitor_name, 2);
        $contact = ['firstName' => $parts[0], 'lastName' => $parts[1] ?? ''];
        if ($resp_id) $contact['responsibleId'] = (int)$resp_id;

        $c_resp     = sp_call('POST', '/crm/v1/contacts/create', $contact, $tok);
        $contact_id = ($c_resp['data']['id'] ?? null) ?? ($c_resp['id'] ?? null);
        error_log("SendPulse contact created: id=$contact_id");

        if ($contact_id) {
            if ($visitor_email) {
                try { sp_call('POST', "/crm/v1/contacts/$contact_id/emails", ['emails' => [['email' => $visitor_email, 'isMain' => true]]], $tok); }
                catch (Throwable $e) { error_log("SendPulse add-email error: " . $e->getMessage()); }
            }
            if ($visitor_phone) {
                try { sp_call('POST', "/crm/v1/contacts/$contact_id/phones", ['phone' => $visitor_phone], $tok); }
                catch (Throwable $e) { error_log("SendPulse add-phone error: " . $e->getMessage()); }
            }
        }

        $deal = [
            'pipelineId' => (int)$pip,
            'stepId'     => (int)$step,
            'name'       => "Appointment: $visitor_name with $worker_name on $date at $start_time",
        ];
        if ($contact_id) $deal['contact'] = [(int)$contact_id];
        if ($resp_id)    $deal['responsibleId'] = (int)$resp_id;

        $d_resp  = sp_call('POST', '/crm/v1/deals', $deal, $tok);
        $d_data  = $d_resp['data'] ?? [];
        $deal_id = (is_array($d_data) ? ($d_data['id'] ?? null) : null) ?? ($d_resp['id'] ?? null);
        error_log("SendPulse deal created: id=$deal_id");

        if ($deal_id && $notes) {
            try { sp_call('POST', "/crm/v1/deals/$deal_id/comments", ['message' => "Booking notes: $notes"], $tok); }
            catch (Throwable $e) { error_log("SendPulse deal comment error: " . $e->getMessage()); }
        }
        if ($deal_id && $call_requested) {
            try { sp_call('POST', "/crm/v1/deals/$deal_id/comments", ['message' => "☎️ Call requested: +$visitor_phone"], $tok); }
            catch (Throwable $e) { error_log("SendPulse call comment error: " . $e->getMessage()); }
        }

        if ($contact_id !== null || $deal_id !== null) {
            $db = get_db();
            $db->prepare('UPDATE bookings SET crm_contact_id=?, crm_deal_id=? WHERE id=?')
               ->execute([(string)($contact_id ?? ''), (string)($deal_id ?? ''), $booking_id]);
            error_log("SendPulse sync complete for booking $booking_id.");
        }
    } catch (Throwable $e) {
        error_log("SendPulse sync error: " . $e->getMessage());
    }
}

// ── Auth ───────────────────────────────────────────────────────────────────────
function require_operator(): ?array {
    $key = trim($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (!$key) { json_err('Missing X-Api-Key header', 401); }
    $st = get_db()->prepare('SELECT * FROM operators WHERE api_key=? AND is_active=1');
    $st->execute([$key]);
    $op = $st->fetch();
    if (!$op) { json_err('Invalid or inactive API key', 401); }
    return $op;
}

function require_admin(): void {
    if (!ADMIN_SECRET) { json_err('Admin access not configured (set ADMIN_SECRET env var)', 503); }
    $key = trim($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
    if (!hash_equals(ADMIN_SECRET, $key)) { json_err('Invalid admin key', 403); }
}

// ── Static page serving ────────────────────────────────────────────────────────
function serve_page(string $path): void {
    $mapping = [
        ''          => 'index.html',
        '/ua'       => 'ua/index.html',
        '/settings' => 'settings/index.html',
        '/register' => 'register/index.html',
        '/verify'   => 'verify/index.html',
        '/admin'    => 'admin/index.html',
    ];
    $rel = $mapping[$path] ?? ltrim($path, '/');
    $fp  = ROOT . '/' . $rel;
    if (is_dir($fp)) $fp .= '/index.html';
    if (!is_file($fp)) { http_response_code(404); echo 'Not Found'; exit; }
    $mime = mime_content_type($fp) ?: 'application/octet-stream';
    // Fix: mime_content_type returns text/plain for .js/.css on some systems
    if (str_ends_with($fp, '.js'))  $mime = 'application/javascript';
    if (str_ends_with($fp, '.css')) $mime = 'text/css';
    if (str_ends_with($fp, '.html')) $mime = 'text/html; charset=utf-8';
    header("Content-Type: $mime");
    readfile($fp);
    exit;
}

// ── Route handlers ─────────────────────────────────────────────────────────────

// GET /api/operator
function r_operator(): void {
    $op = require_operator();
    json_ok(['id' => $op['id'], 'name' => $op['name'], 'email' => $op['email'],
             'api_key' => $op['api_key'], 'created_at' => $op['created_at']]);
}

// GET /api/workers
function r_workers_list(): void {
    $op   = require_operator();
    $st   = get_db()->prepare('SELECT * FROM workers WHERE operator_id=? ORDER BY created_at');
    $st->execute([$op['id']]);
    json_ok($st->fetchAll());
}

// POST /api/workers
function r_workers_create(): void {
    $op   = require_operator();
    $b    = body();
    $name = trim($b['name'] ?? '');
    if (!$name) json_err('name is required');
    $wid  = uuid4();
    $now  = utcnow();
    $db   = get_db();
    $db->prepare('INSERT INTO workers (id, operator_id, name, title, bio, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$wid, $op['id'], $name, $b['title'] ?? '', $b['bio'] ?? '', $now]);
    $row = $db->prepare('SELECT * FROM workers WHERE id=?');
    $row->execute([$wid]);
    json_ok($row->fetch(), 201);
}

// PUT /api/workers/:id
function r_workers_update(string $wid): void {
    $op  = require_operator();
    $b   = body();
    $db  = get_db();
    $st  = $db->prepare('SELECT * FROM workers WHERE id=? AND operator_id=?');
    $st->execute([$wid, $op['id']]);
    $cur = $st->fetch();
    if (!$cur) json_err('Not found', 404);
    $db->prepare('UPDATE workers SET name=?, title=?, bio=?, is_active=? WHERE id=?')
       ->execute([
           $b['name']      ?? $cur['name'],
           $b['title']     ?? $cur['title'],
           $b['bio']       ?? $cur['bio'],
           (int)($b['is_active'] ?? $cur['is_active']),
           $wid,
       ]);
    $st2 = $db->prepare('SELECT * FROM workers WHERE id=?');
    $st2->execute([$wid]);
    json_ok($st2->fetch());
}

// DELETE /api/workers/:id
function r_workers_delete(string $wid): void {
    $op = require_operator();
    get_db()->prepare('UPDATE workers SET is_active=0 WHERE id=? AND operator_id=?')
            ->execute([$wid, $op['id']]);
    json_ok(['ok' => true]);
}

// GET /api/availability
function r_avail_list(): void {
    $op   = require_operator();
    $wid  = qs('workerId');
    $date = qs('date');
    $db   = get_db();
    if ($wid && $date) {
        $st = $db->prepare(
            'SELECT a.*, b.visitor_name FROM availability a
             LEFT JOIN bookings b ON b.availability_id=a.id
             JOIN workers w ON w.id=a.worker_id
             WHERE a.worker_id=? AND a.date=? AND w.operator_id=? ORDER BY a.start_time');
        $st->execute([$wid, $date, $op['id']]);
    } elseif ($wid) {
        $st = $db->prepare(
            'SELECT a.* FROM availability a JOIN workers w ON w.id=a.worker_id
             WHERE a.worker_id=? AND w.operator_id=? ORDER BY a.date, a.start_time');
        $st->execute([$wid, $op['id']]);
    } else {
        $st = $db->prepare(
            'SELECT a.* FROM availability a JOIN workers w ON w.id=a.worker_id
             WHERE w.operator_id=? ORDER BY a.date, a.start_time');
        $st->execute([$op['id']]);
    }
    json_ok($st->fetchAll());
}

// POST /api/availability/bulk
function r_avail_bulk(): void {
    $op    = require_operator();
    $b     = body();
    $wid   = $b['workerId'] ?? null;
    $slots = $b['slots'] ?? [];
    if (!$wid || !$slots) json_err('workerId and slots required');
    $db  = get_db();
    $w   = $db->prepare('SELECT id FROM workers WHERE id=? AND operator_id=?');
    $w->execute([$wid, $op['id']]);
    if (!$w->fetch()) json_err('Worker not found', 404);
    $ins   = $db->prepare('INSERT INTO availability (id, worker_id, date, start_time, end_time) VALUES (?,?,?,?,?)');
    $added = 0;
    foreach ($slots as $s) {
        try {
            $ins->execute([uuid4(), $wid, $s['date'], $s['startTime'], $s['endTime']]);
            $added++;
        } catch (PDOException $e) { /* ignore duplicate */ }
    }
    json_ok(['added' => $added], 201);
}

// POST /api/availability/generate
function r_avail_generate(): void {
    $op  = require_operator();
    $b   = body();
    $wid = $b['workerId'] ?? null;
    $from_date  = $b['from'] ?? null;
    $to_date    = $b['to']   ?? null;
    $start_time = $b['startTime'] ?? null;
    $end_time   = $b['endTime']   ?? null;
    $duration   = (int)($b['slotDuration'] ?? 30);
    $days       = $b['days'] ?? [0, 1, 2, 3, 4];
    if (!$wid || !$from_date || !$to_date || !$start_time || !$end_time) json_err('Missing required fields');
    $db = get_db();
    $w  = $db->prepare('SELECT id FROM workers WHERE id=? AND operator_id=?');
    $w->execute([$wid, $op['id']]);
    if (!$w->fetch()) json_err('Worker not found', 404);

    [$sh, $sm] = array_map('intval', explode(':', $start_time));
    [$eh, $em] = array_map('intval', explode(':', $end_time));
    $start_m   = $sh * 60 + $sm;
    $end_m     = $eh * 60 + $em;
    $ins       = $db->prepare('INSERT INTO availability (id, worker_id, date, start_time, end_time) VALUES (?,?,?,?,?)');
    $cur       = new DateTime($from_date, new DateTimeZone('UTC'));
    $end_dt    = new DateTime($to_date,   new DateTimeZone('UTC'));
    $added     = 0;

    while ($cur <= $end_dt) {
        // PHP: 0=Mon…6=Sun but Python weekday() 0=Mon…6=Sun — same mapping
        $dow = (int)$cur->format('N') - 1; // N gives 1=Mon, convert to 0=Mon
        if (in_array($dow, $days, true)) {
            $t = $start_m;
            while ($t + $duration <= $end_m) {
                $rh = (int)($t / 60); $rm = $t % 60;
                $te = $t + $duration;
                $eh2 = (int)($te / 60); $em2 = $te % 60;
                try {
                    $ins->execute([
                        uuid4(), $wid, $cur->format('Y-m-d'),
                        sprintf('%02d:%02d', $rh, $rm),
                        sprintf('%02d:%02d', $eh2, $em2),
                    ]);
                    $added++;
                } catch (PDOException $e) { /* duplicate */ }
                $t += $duration;
            }
        }
        $cur->modify('+1 day');
    }
    json_ok(['added' => $added], 201);
}

// DELETE /api/availability/:id
function r_avail_delete(string $aid): void {
    $op  = require_operator();
    $db  = get_db();
    $st  = $db->prepare(
        'SELECT a.is_booked FROM availability a JOIN workers w ON w.id=a.worker_id
         WHERE a.id=? AND w.operator_id=?');
    $st->execute([$aid, $op['id']]);
    $row = $st->fetch();
    if (!$row) json_err('Not found', 404);
    if ($row['is_booked']) json_err('Cannot delete a booked slot', 409);
    $db->prepare('DELETE FROM availability WHERE id=?')->execute([$aid]);
    json_ok(['ok' => true]);
}

// GET /api/bookings
function r_bookings_list(): void {
    $op = require_operator();
    $st = get_db()->prepare(
        'SELECT b.*, w.name AS worker_name, a.date, a.start_time, a.end_time
         FROM bookings b
         JOIN workers w ON w.id=b.worker_id
         JOIN availability a ON a.id=b.availability_id
         WHERE w.operator_id=?
         ORDER BY a.date DESC, a.start_time DESC');
    $st->execute([$op['id']]);
    json_ok($st->fetchAll());
}

// PATCH /api/bookings/:id/cancel
function r_booking_cancel(string $bid): void {
    $op  = require_operator();
    $db  = get_db();
    $st  = $db->prepare('SELECT b.* FROM bookings b JOIN workers w ON w.id=b.worker_id WHERE b.id=? AND w.operator_id=?');
    $st->execute([$bid, $op['id']]);
    $row = $st->fetch();
    if (!$row) json_err('Not found', 404);
    $db->prepare("UPDATE bookings SET status='CANCELLED' WHERE id=?")->execute([$bid]);
    $db->prepare('UPDATE availability SET is_booked=0 WHERE id=?')->execute([$row['availability_id']]);
    json_ok(['ok' => true]);
}

// PATCH /api/bookings/:id/complete
function r_booking_complete(string $bid): void {
    $op = require_operator();
    $db = get_db();
    $st = $db->prepare('SELECT b.id FROM bookings b JOIN workers w ON w.id=b.worker_id WHERE b.id=? AND w.operator_id=?');
    $st->execute([$bid, $op['id']]);
    if (!$st->fetch()) json_err('Not found', 404);
    $db->prepare("UPDATE bookings SET status='COMPLETED' WHERE id=?")->execute([$bid]);
    json_ok(['ok' => true]);
}

// GET /api/settings
function r_settings_get(): void {
    $op = require_operator();
    $s  = get_op_settings($op['id']);
    json_ok([
        'sp_client_id'      => $s['sp_client_id']      ?? '',
        'sp_pipeline_id'    => $s['sp_pipeline_id']    ?? '',
        'sp_step_id'        => $s['sp_step_id']        ?? '',
        'sp_responsible_id' => $s['sp_responsible_id'] ?? '',
        'sp_has_secret'     => (bool)trim($s['sp_client_secret'] ?? ''),
        'sms_enabled'       => $s['sms_enabled']   ?? '0',
        'sms_sender_id'     => $s['sms_sender_id'] ?? '',
        'public_url'        => $s['public_url']    ?? '',
        'operator_id'       => $op['id'],
    ]);
}

// POST /api/settings
function r_settings_save(): void {
    $op   = require_operator();
    $b    = body();
    $db   = get_db();
    $keys = ['sp_client_id','sp_client_secret','sp_pipeline_id','sp_step_id','sp_responsible_id',
             'wgt_primary','wgt_gradient','wgt_font','wgt_radius','wgt_view',
             'wgt_bg','wgt_text','wgt_shadow','sms_enabled','sms_sender_id','public_url'];
    $ins  = $db->prepare(
        'INSERT INTO settings (id, operator_id, key, value) VALUES (?,?,?,?)
         ON CONFLICT(operator_id, key) DO UPDATE SET value=excluded.value');
    foreach ($keys as $key) {
        if (array_key_exists($key, $b)) {
            $ins->execute([uuid4(), $op['id'], $key, trim((string)$b[$key])]);
        }
    }
    json_ok(['ok' => true]);
}

// GET /api/widget/config
function r_widget_config(): void {
    $op_id = qs('operatorId');
    $db    = get_db();
    if ($op_id) {
        $st = $db->prepare('SELECT id FROM operators WHERE id=? AND is_active=1');
        $st->execute([$op_id]);
        $op = $st->fetch();
    } else {
        $op = $db->query('SELECT id FROM operators WHERE is_active=1 LIMIT 1')->fetch();
    }
    if (!$op) json_err('Operator not found', 404);
    $s = get_op_settings($op['id']);
    json_ok([
        'primaryColor'    => $s['wgt_primary']  ?? '#2863E0',
        'headerGradient'  => ($s['wgt_gradient'] ?? '1') === '1',
        'fontFamily'      => $s['wgt_font']     ?? 'system',
        'borderRadius'    => (int)($s['wgt_radius']  ?? '12'),
        'viewMode'        => $s['wgt_view']     ?? 'month',
        'smsEnabled'      => ($s['sms_enabled'] ?? '0') === '1',
        'bgColor'         => $s['wgt_bg']       ?? '#ffffff',
        'textColor'       => $s['wgt_text']     ?? '#1C1C28',
        'shadowIntensity' => $s['wgt_shadow']   ?? 'default',
    ]);
}

// GET /api/sendpulse/test
function r_sp_test(): void {
    $op    = require_operator();
    $s     = get_op_settings($op['id']);
    $cid   = trim($s['sp_client_id']    ?? '');
    $secret= trim($s['sp_client_secret']?? '');
    if (!$cid || !$secret) { json_ok(['ok' => false, 'error' => 'Credentials not configured']); }
    try {
        $r = sp_get_token($cid, $secret);
        if ($r['access_token'] ?? '') json_ok(['ok' => true, 'message' => 'Connected to SendPulse ✓']);
        else json_ok(['ok' => false, 'error' => json_encode($r)]);
    } catch (Throwable $e) { json_ok(['ok' => false, 'error' => $e->getMessage()]); }
}

// GET /api/sendpulse/pipelines
function r_sp_pipelines(): void {
    $op    = require_operator();
    $s     = get_op_settings($op['id']);
    $cid   = trim($s['sp_client_id']    ?? '');
    $secret= trim($s['sp_client_secret']?? '');
    if (!$cid || !$secret) json_err('Credentials not configured');
    try {
        $tok = sp_get_token($cid, $secret)['access_token'] ?? '';
        json_ok(sp_call('GET', '/crm/v1/pipelines', null, $tok));
    } catch (Throwable $e) { json_err($e->getMessage()); }
}

function _sp_tok(string $op_id): array {
    $s      = get_op_settings($op_id);
    $cid    = trim($s['sp_client_id']    ?? '');
    $secret = trim($s['sp_client_secret']?? '');
    if (!$cid || !$secret) return [null, $s];
    try { return [sp_get_token($cid, $secret)['access_token'] ?? null, $s]; }
    catch (Throwable $e) { return [null, $s]; }
}

// GET /api/sendpulse/sms/senders
function r_sp_sms_senders(): void {
    $op = require_operator();
    [$tok] = _sp_tok($op['id']);
    if (!$tok) json_err('Credentials not configured or token error');
    try { json_ok(sp_call('GET', '/sms/senders', null, $tok)); }
    catch (Throwable $e) { json_err($e->getMessage()); }
}

// GET /api/sendpulse/sms/balance
function r_sp_sms_balance(): void {
    $op = require_operator();
    [$tok] = _sp_tok($op['id']);
    if (!$tok) { json_ok(['unavailable' => true]); }
    try {
        $r = sp_call('GET', '/user/balance/detail', null, $tok);
        json_ok($r ?: ['unavailable' => true]);
    } catch (Throwable $e) { json_ok(['error' => $e->getMessage()]); }
}

// GET /api/widget/dates
function r_w_dates(): void {
    $month = qs('month');
    if (!$month) json_err('month required');
    $op_id = _resolve_operator_id();
    if (!$op_id) json_err('Operator not found', 404);
    $st = get_db()->prepare(
        'SELECT DISTINCT a.date FROM availability a
         JOIN workers w ON w.id=a.worker_id
         WHERE a.is_booked=0 AND a.date LIKE ? AND w.is_active=1 AND w.operator_id=?
         ORDER BY a.date');
    $st->execute(["$month%", $op_id]);
    json_ok(array_column($st->fetchAll(), 'date'));
}

// GET /api/widget/workers
function r_w_workers(): void {
    $date = qs('date');
    if (!$date) json_err('date required');
    $op_id = _resolve_operator_id();
    if (!$op_id) json_err('Operator not found', 404);
    $st = get_db()->prepare(
        'SELECT DISTINCT w.* FROM workers w
         JOIN availability a ON a.worker_id=w.id
         WHERE a.date=? AND a.is_booked=0 AND w.is_active=1 AND w.operator_id=?');
    $st->execute([$date, $op_id]);
    json_ok($st->fetchAll());
}

// GET /api/widget/slots
function r_w_slots(): void {
    $wid  = qs('workerId');
    $date = qs('date');
    if (!$wid || !$date) json_err('workerId and date required');
    $st = get_db()->prepare(
        'SELECT id, start_time, end_time FROM availability
         WHERE worker_id=? AND date=? AND is_booked=0 ORDER BY start_time');
    $st->execute([$wid, $date]);
    json_ok($st->fetchAll());
}

function _resolve_operator_id(): ?string {
    $op_id = qs('operatorId');
    if ($op_id) return $op_id;
    $op = get_db()->query('SELECT id FROM operators WHERE is_active=1 LIMIT 1')->fetch();
    return $op ? $op['id'] : null;
}

// POST /api/widget/bookings
function r_w_booking_create(): void {
    $b    = body();
    $aid  = $b['availabilityId'] ?? null;
    $name = trim($b['visitorName']  ?? '');
    $email= trim($b['visitorEmail'] ?? '');
    if (!$aid || !$name || !$email) json_err('availabilityId, visitorName, visitorEmail required');
    $db   = get_db();
    $st   = $db->prepare(
        'SELECT a.*, w.name AS worker_name, w.operator_id FROM availability a
         JOIN workers w ON w.id=a.worker_id
         WHERE a.id=? AND a.is_booked=0');
    $st->execute([$aid]);
    $slot = $st->fetch();
    if (!$slot) json_err('Slot not available or already booked', 409);
    $bid  = uuid4();
    $now  = utcnow();
    $db->prepare('UPDATE availability SET is_booked=1 WHERE id=?')->execute([$aid]);
    $db->prepare(
        'INSERT INTO bookings (id,worker_id,availability_id,visitor_name,visitor_email,visitor_phone,notes,created_at)
         VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$bid, $slot['worker_id'], $aid, $name, $email,
                  $b['visitorPhone'] ?? '', $b['notes'] ?? '', $now]);
    $row = $db->prepare('SELECT * FROM bookings WHERE id=?');
    $row->execute([$bid]);
    $booking = $row->fetch();
    // Sync to CRM asynchronously (best-effort, no true async in PHP CLI server)
    sendpulse_sync($slot['operator_id'], $bid, $name, $email,
                   $b['visitorPhone'] ?? '', $slot['worker_name'],
                   $slot['date'], $slot['start_time'], $b['notes'] ?? '');
    json_ok($booking, 201);
}

// POST /api/widget/send-code
function r_w_send_code(): void {
    $b         = body();
    $aid       = $b['availabilityId'] ?? null;
    $name      = trim($b['visitorName']  ?? '');
    $email_val = trim($b['visitorEmail'] ?? '');
    $phone     = trim($b['visitorPhone'] ?? '');
    $notes     = $b['notes'] ?? '';
    if (!$aid || !$name || !$email_val || !$phone) json_err('Missing required fields');
    $digits = normalize_ua_phone($phone);
    if (!$digits) json_err('Only Ukrainian phone numbers (+380) are supported for SMS verification');
    $db   = get_db();
    $st   = $db->prepare('SELECT a.id, w.operator_id FROM availability a JOIN workers w ON w.id=a.worker_id WHERE a.id=? AND a.is_booked=0');
    $st->execute([$aid]);
    $slot = $st->fetch();
    if (!$slot) json_err('Slot not available', 409);
    $s      = get_op_settings($slot['operator_id']);
    if (($s['sms_enabled'] ?? '0') !== '1') json_err('SMS confirmation is not enabled');
    $cid    = trim($s['sp_client_id']    ?? '');
    $secret = trim($s['sp_client_secret']?? '');
    $sender = trim($s['sms_sender_id']   ?? '');
    if (!$cid || !$secret || !$sender) json_err('SMS not fully configured');
    $code  = sprintf('%04d', random_int(0, 9999));
    $pid   = uuid4();
    $now_s = utcnow();
    $exp   = gmdate('Y-m-d H:i:s', time() + 600);
    $db->prepare("DELETE FROM pending_bookings WHERE expires_at < ?")->execute([$now_s]);
    $db->prepare('INSERT INTO pending_bookings (id,code,avail_id,visitor_name,visitor_email,visitor_phone,notes,expires_at,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$pid, $code, $aid, $name, $email_val, $digits, $notes, $exp, $now_s]);
    try {
        $tok = sp_get_token($cid, $secret)['access_token'] ?? '';
        sp_call('POST', '/sms/send', ['sender' => $sender, 'body' => "Ваш код бронювання: $code", 'phones' => [$digits], 'route' => ['UA' => 'national']], $tok);
        error_log("SMS sent to $digits, pendingId=$pid");
    } catch (Throwable $e) {
        error_log("SMS send error: " . $e->getMessage());
        json_err('Failed to send SMS');
    }
    $fmt = '+' . substr($digits,0,3) . ' ' . substr($digits,3,2) . ' ' . substr($digits,5,3) . ' ' . substr($digits,8,2) . ' ' . substr($digits,10);
    json_ok(['pendingId' => $pid, 'phone' => $fmt]);
}

// POST /api/widget/verify-code
function r_w_verify_code(): void {
    $b    = body();
    $pid  = $b['pendingId'] ?? null;
    $code = trim($b['code'] ?? '');
    if (!$pid || !$code) json_err('Missing pendingId or code');
    $db    = get_db();
    $now_s = utcnow();
    $st    = $db->prepare('SELECT * FROM pending_bookings WHERE id=? AND expires_at > ?');
    $st->execute([$pid, $now_s]);
    $pend  = $st->fetch();
    if (!$pend) json_err('Code expired or not found. Please start over.', 410);
    if ($pend['code'] !== $code) json_err('Incorrect code. Please try again.');
    complete_pending_booking($db, $pend, false);
}

// POST /api/widget/request-call
function r_w_request_call(): void {
    $b   = body();
    $pid = $b['pendingId'] ?? null;
    if (!$pid) json_err('Missing pendingId');
    $db    = get_db();
    $now_s = utcnow();
    $st    = $db->prepare('SELECT * FROM pending_bookings WHERE id=? AND expires_at > ?');
    $st->execute([$pid, $now_s]);
    $pend  = $st->fetch();
    if (!$pend) json_err('Session expired. Please start over.', 410);
    complete_pending_booking($db, $pend, true);
}

function complete_pending_booking(PDO $db, array $pend, bool $call_requested): void {
    $st   = $db->prepare('SELECT a.*, w.name AS worker_name, w.operator_id FROM availability a JOIN workers w ON w.id=a.worker_id WHERE a.id=? AND a.is_booked=0');
    $st->execute([$pend['avail_id']]);
    $slot = $st->fetch();
    if (!$slot) json_err('Slot no longer available', 409);
    $bid   = uuid4();
    $now_s = utcnow();
    $db->prepare('UPDATE availability SET is_booked=1 WHERE id=?')->execute([$pend['avail_id']]);
    $db->prepare('INSERT INTO bookings (id,worker_id,availability_id,visitor_name,visitor_email,visitor_phone,notes,created_at) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$bid, $slot['worker_id'], $pend['avail_id'], $pend['visitor_name'], $pend['visitor_email'], $pend['visitor_phone'], $pend['notes'], $now_s]);
    $db->prepare('DELETE FROM pending_bookings WHERE id=?')->execute([$pend['id']]);
    $row = $db->prepare('SELECT * FROM bookings WHERE id=?');
    $row->execute([$bid]);
    $booking = $row->fetch();
    sendpulse_sync($slot['operator_id'], $bid, $pend['visitor_name'], $pend['visitor_email'],
                   $pend['visitor_phone'], $slot['worker_name'],
                   $slot['date'], $slot['start_time'], $pend['notes'], $call_requested);
    json_ok($booking, 201);
}

// POST /api/register
function r_register(): void {
    $b    = body();
    $name = trim($b['name']  ?? '');
    $em   = trim($b['email'] ?? '');
    if (!$name) json_err('name is required');
    if (!$em)   json_err('email is required');

    $op_id        = uuid4();
    $api_key      = uuid4();
    $verify_token = uuid4();
    $now          = utcnow();
    $db           = get_db();
    $db->prepare('INSERT INTO operators (id,api_key,name,email,is_active,email_verified,verify_token,created_at) VALUES (?,?,?,?,0,0,?,?)')
       ->execute([$op_id, $api_key, $name, $em, $verify_token, $now]);
    $db->prepare('INSERT INTO workers (id,operator_id,name,title,bio,created_at) VALUES (?,?,?,?,?,?)')
       ->execute([uuid4(), $op_id, 'Your First Worker', 'Add a title', 'Add a bio', $now]);

    $verify_url = BASE_URL . '/verify?token=' . $verify_token;
    $html_body  = <<<HTML
<!DOCTYPE html><html>
<head><meta charset="utf-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;margin:0;padding:32px 16px">
  <div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px">
    <div style="font-size:22px;font-weight:800;color:#2563eb;margin-bottom:24px">Scheduler<span style="color:#7c3aed">.</span></div>
    <h2 style="margin:0 0 8px;font-size:20px;color:#1e293b">Confirm your email</h2>
    <p style="color:#64748b;font-size:14px;line-height:1.6;margin:0 0 24px">
      Hi {$name}, thanks for signing up! Click the button below to confirm your email address and get your API key.
    </p>
    <a href="{$verify_url}"
       style="display:inline-block;background:#2563eb;color:#fff;font-weight:700;font-size:15px;text-decoration:none;padding:12px 28px;border-radius:9px">
      Confirm my account →
    </a>
    <p style="color:#94a3b8;font-size:12px;margin:24px 0 0;line-height:1.5">
      Or paste this link in your browser:<br>
      <a href="{$verify_url}" style="color:#2563eb;word-break:break-all">{$verify_url}</a>
    </p>
    <p style="color:#cbd5e1;font-size:11px;margin:16px 0 0">
      If you didn't sign up for Scheduler, you can ignore this email.
    </p>
  </div>
</body></html>
HTML;
    send_email($em, 'Confirm your Scheduler account', $html_body);
    error_log("[REGISTER] Pending operator: $name ($em) id=$op_id");
    json_ok(['message' => 'Check your email to confirm your account.'], 201);
}

// GET /api/verify
function r_verify(): void {
    $token = trim(qs('token', ''));
    if (!$token) json_err('Missing token');
    $db  = get_db();
    $st  = $db->prepare('SELECT * FROM operators WHERE verify_token=? AND email_verified=0');
    $st->execute([$token]);
    $op  = $st->fetch();
    if (!$op) json_err('Invalid or already used verification link.', 400);
    $db->prepare('UPDATE operators SET is_active=1, email_verified=1, verify_token="" WHERE id=?')
       ->execute([$op['id']]);
    $op_id   = $op['id'];
    $api_key = $op['api_key'];
    $name    = $op['name'];
    $snippet = <<<SNIP
<div id="scheduling-widget"></div>
<script
  src="{BASE_URL}/widget.js"
  data-container="#scheduling-widget"
  data-api-url="{BASE_URL}"
  data-operator-id="{$op_id}">
</script>
SNIP;
    $snippet = str_replace('{BASE_URL}', BASE_URL, $snippet);
    error_log("[VERIFY] Operator verified: $name ({$op['email']}) id=$op_id");
    json_ok([
        'operatorId'   => $op_id,
        'apiKey'       => $api_key,
        'snippet'      => $snippet,
        'dashboardUrl' => BASE_URL . '/settings',
    ]);
}

// GET /api/admin/operators
function r_admin_operators_list(): void {
    require_admin();
    $db   = get_db();
    $rows = $db->query('SELECT id,name,email,is_active,created_at,api_key FROM operators ORDER BY created_at')->fetchAll();
    foreach ($rows as &$op) {
        $wc = $db->prepare('SELECT COUNT(*) AS c FROM workers WHERE operator_id=? AND is_active=1');
        $wc->execute([$op['id']]);
        $bc = $db->prepare("SELECT COUNT(*) AS c FROM bookings b JOIN workers w ON w.id=b.worker_id WHERE w.operator_id=? AND b.status='CONFIRMED'");
        $bc->execute([$op['id']]);
        $op['worker_count']  = (int)$wc->fetch()['c'];
        $op['booking_count'] = (int)$bc->fetch()['c'];
    }
    json_ok($rows);
}

// GET /api/admin/stats
function r_admin_stats(): void {
    require_admin();
    $db = get_db();
    json_ok([
        'total_operators'  => (int)$db->query('SELECT COUNT(*) AS c FROM operators')->fetch()['c'],
        'active_operators' => (int)$db->query('SELECT COUNT(*) AS c FROM operators WHERE is_active=1')->fetch()['c'],
        'total_bookings'   => (int)$db->query('SELECT COUNT(*) AS c FROM bookings')->fetch()['c'],
        'active_workers'   => (int)$db->query('SELECT COUNT(*) AS c FROM workers WHERE is_active=1')->fetch()['c'],
    ]);
}

// POST /api/admin/operators
function r_admin_operators_create(): void {
    require_admin();
    $b    = body();
    $name = trim($b['name']  ?? '');
    $em   = trim($b['email'] ?? '');
    if (!$name) json_err('name is required');
    $op_id  = uuid4();
    $api_key= uuid4();
    $now    = utcnow();
    $db     = get_db();
    $db->prepare('INSERT INTO operators (id,api_key,name,email,is_active,created_at) VALUES (?,?,?,?,1,?)')
       ->execute([$op_id, $api_key, $name, $em, $now]);
    json_ok(['id' => $op_id, 'api_key' => $api_key, 'name' => $name, 'email' => $em], 201);
}

// PATCH /api/admin/operators/:id
function r_admin_operators_update(string $op_id): void {
    require_admin();
    $b   = body();
    $db  = get_db();
    $st  = $db->prepare('SELECT * FROM operators WHERE id=?');
    $st->execute([$op_id]);
    $op  = $st->fetch();
    if (!$op) json_err('Not found', 404);
    $db->prepare('UPDATE operators SET name=?, is_active=? WHERE id=?')
       ->execute([$b['name'] ?? $op['name'], (int)($b['is_active'] ?? $op['is_active']), $op_id]);
    $st2 = $db->prepare('SELECT id,name,email,is_active,created_at FROM operators WHERE id=?');
    $st2->execute([$op_id]);
    json_ok($st2->fetch());
}

// Tunnel stubs (no-op in PHP version)
function r_tunnel_status(): void { json_ok(['status' => 'stopped', 'url' => null, 'error' => 'Tunnel not supported in PHP server']); }
function r_tunnel_start(): void  { json_ok(['ok' => false, 'error' => 'Tunnel not supported in PHP server']); }
function r_tunnel_stop(): void   { json_ok(['ok' => true]); }

// ── Main router ────────────────────────────────────────────────────────────────
init_db();

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    cors_headers();
    http_response_code(204);
    exit;
}

$uri    = $_SERVER['REQUEST_URI'];
$path   = rtrim(parse_url($uri, PHP_URL_PATH), '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    match (true) {
        $path === '/api/operator'              => r_operator(),
        $path === '/api/workers'               => r_workers_list(),
        $path === '/api/availability'          => r_avail_list(),
        $path === '/api/bookings'              => r_bookings_list(),
        $path === '/api/settings'              => r_settings_get(),
        $path === '/api/widget/config'         => r_widget_config(),
        $path === '/api/widget/dates'          => r_w_dates(),
        $path === '/api/widget/workers'        => r_w_workers(),
        $path === '/api/widget/slots'          => r_w_slots(),
        $path === '/api/sendpulse/test'        => r_sp_test(),
        $path === '/api/sendpulse/pipelines'   => r_sp_pipelines(),
        $path === '/api/sendpulse/sms/senders' => r_sp_sms_senders(),
        $path === '/api/sendpulse/sms/balance' => r_sp_sms_balance(),
        $path === '/api/tunnel/status'         => r_tunnel_status(),
        $path === '/api/admin/operators'       => r_admin_operators_list(),
        $path === '/api/admin/stats'           => r_admin_stats(),
        $path === '/api/verify'                => r_verify(),
        default                                => serve_page($path),
    };
}

// ── POST ───────────────────────────────────────────────────────────────────────
elseif ($method === 'POST') {
    match ($path) {
        '/api/workers'                => r_workers_create(),
        '/api/availability/bulk'      => r_avail_bulk(),
        '/api/availability/generate'  => r_avail_generate(),
        '/api/settings'               => r_settings_save(),
        '/api/widget/bookings'        => r_w_booking_create(),
        '/api/widget/send-code'       => r_w_send_code(),
        '/api/widget/verify-code'     => r_w_verify_code(),
        '/api/widget/request-call'    => r_w_request_call(),
        '/api/tunnel/start'           => r_tunnel_start(),
        '/api/tunnel/stop'            => r_tunnel_stop(),
        '/api/register'               => r_register(),
        '/api/admin/operators'        => r_admin_operators_create(),
        default                       => json_err('Not found', 404),
    };
}

// ── PUT ────────────────────────────────────────────────────────────────────────
elseif ($method === 'PUT') {
    if (str_starts_with($path, '/api/workers/')) {
        r_workers_update(substr($path, strlen('/api/workers/')));
    } else {
        json_err('Not found', 404);
    }
}

// ── DELETE ─────────────────────────────────────────────────────────────────────
elseif ($method === 'DELETE') {
    if (str_starts_with($path, '/api/workers/')) {
        r_workers_delete(substr($path, strlen('/api/workers/')));
    } elseif (str_starts_with($path, '/api/availability/')) {
        r_avail_delete(substr($path, strlen('/api/availability/')));
    } else {
        json_err('Not found', 404);
    }
}

// ── PATCH ──────────────────────────────────────────────────────────────────────
elseif ($method === 'PATCH') {
    if (str_ends_with($path, '/cancel')) {
        r_booking_cancel(substr($path, strlen('/api/bookings/'), -strlen('/cancel')));
    } elseif (str_ends_with($path, '/complete')) {
        r_booking_complete(substr($path, strlen('/api/bookings/'), -strlen('/complete')));
    } elseif (str_starts_with($path, '/api/admin/operators/')) {
        r_admin_operators_update(substr($path, strlen('/api/admin/operators/')));
    } else {
        json_err('Not found', 404);
    }
}

else {
    json_err('Method not allowed', 405);
}
