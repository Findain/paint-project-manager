<?php
/* =====================================================================
   PPM CLOUD SYNC SERVER  (single file)  -  version CE (online signing, customer messages)
   Upload this file as  /ppm-api/index.php  (replace the old one).
   Check it works:  https://arcanedigitalmining.com/ppm-api/index.php?a=check
   Email test:      https://arcanedigitalmining.com/ppm-api/index.php?a=mailtest&to=you@example.com

   If you changed 'mail_from' or 'debug' in your old copy, set them again below.
   ===================================================================== */
declare(strict_types=1);
ob_start();                       // anything PHP prints by accident is thrown away so replies stay valid JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);
if (!function_exists('mb_strlen')) { function mb_strlen($s) { return strlen($s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $b = null) { return $b === null ? substr($s, $a) : substr($s, $a, $b); } }

$cfg = [
  // SQLite database file (created automatically). The data folder is protected automatically.
  'db_path'        => __DIR__ . '/data/ppm-sync.sqlite',

  // Origins allowed to call the API (web version, GitHub beta copy, bundled Play Store app).
  // An origin is ONLY the host: no folder name and no trailing slash.
  'allowed_origins' => [
    'https://arcanedigitalmining.com',
    'https://www.arcanedigitalmining.com',
    'https://findain.github.io',        // the GitHub Pages beta copy
    'https://localhost',
    'http://localhost',
  ],

  // Password-reset emails. IMPORTANT: this should be a REAL mailbox on your domain
  // (create one in your host's email settings, e.g. support@arcanedigitalmining.com).
  'mail_from'      => 'no-reply@arcanedigitalmining.com',
  'app_url'        => 'https://arcanedigitalmining.com/ppm/', // reset link goes here (?reset=TOKEN)

  // While setting up: true shows real error text, enables ?a=check and ?a=mailtest, and shows email diagnostics.
  // Set to false once everything works.
  'debug'          => true,

  'token_days'           => 180,
  'reset_minutes'        => 60,
  'min_password_len'     => 8,
  'max_records_per_push' => 200,
  'max_record_bytes'     => 2000000,
  'max_login_attempts'   => 8,

  // ---- online signing ----
  'sign_max_snapshot_bytes' => 250000,   // the quote the customer reads
  'sign_max_image_bytes'    => 150000,   // the drawn signature (a small JPEG)
  'sign_keep_signed_days'   => 365,      // a signed copy is deleted this long after signing, if the job was never marked complete
  'sign_keep_unsigned_days' => 30,       // an unsigned quote copy is deleted this long after its link expires
  'sign_no_expiry_days'     => 365,
  // ---- customer messages from the signing page (Question / Decline) ----
  'reply_keep_days'         => 90,        // a customer message is deleted this long after it was sent, if the job was never marked complete
  'reply_notify_email'      => true,      // also email a copy to the account email, so you hear about it before you open the app      // a quote with no "valid for" days stays open this long
];

// ---------- database ----------
function ppm_db(array $cfg): PDO {
  $dir = dirname($cfg['db_path']);
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
  if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
    @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    @file_put_contents($dir . '/index.html', '');
  }
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL UNIQUE,
      pass_hash TEXT NOT NULL,
      created_at INTEGER NOT NULL,
      seq_counter INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS tokens(
      token_hash TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      device_label TEXT,
      created_at INTEGER NOT NULL,
      last_used INTEGER NOT NULL,
      expires_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS resets(
      token_hash TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      expires_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS records(
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      type TEXT NOT NULL,
      rec_id TEXT NOT NULL,
      data TEXT,
      updated_at INTEGER NOT NULL,
      deleted INTEGER NOT NULL DEFAULT 0,
      server_seq INTEGER NOT NULL,
      PRIMARY KEY(user_id, type, rec_id)
    );
    CREATE INDEX IF NOT EXISTS idx_records_seq ON records(user_id, server_seq);
    CREATE TABLE IF NOT EXISTS attempts(
      k TEXT NOT NULL,
      at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_attempts ON attempts(k, at);
    CREATE TABLE IF NOT EXISTS signs(
      token TEXT PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      project_id TEXT NOT NULL,
      ref TEXT NOT NULL,
      content_hash TEXT NOT NULL,
      snapshot TEXT NOT NULL,
      options INTEGER NOT NULL DEFAULT 1,
      totals TEXT NOT NULL,
      scope_key TEXT NOT NULL DEFAULT '',
      created_at INTEGER NOT NULL,
      expires_at INTEGER NOT NULL,
      signed_at INTEGER,
      sig_name TEXT,
      sig_img TEXT,
      sig_w INTEGER,
      sig_h INTEGER,
      sig_text TEXT,
      sig_option INTEGER,
      sig_total INTEGER
    );
    CREATE INDEX IF NOT EXISTS idx_signs_user ON signs(user_id, signed_at);
    CREATE INDEX IF NOT EXISTS idx_signs_proj ON signs(user_id, project_id);
    CREATE TABLE IF NOT EXISTS sign_replies(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      token TEXT NOT NULL,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      project_id TEXT NOT NULL,
      ref TEXT NOT NULL,
      kind TEXT NOT NULL,
      message TEXT NOT NULL DEFAULT '',
      name TEXT NOT NULL DEFAULT '',
      contact TEXT NOT NULL DEFAULT '',
      created_at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_replies_user ON sign_replies(user_id, id);
    CREATE INDEX IF NOT EXISTS idx_replies_proj ON sign_replies(user_id, project_id);
    CREATE INDEX IF NOT EXISTS idx_replies_token ON sign_replies(token);
  ");
  return $pdo;
}

// ---------- helpers ----------
function out($data, int $code = 200): void {
  while (ob_get_level() > 0) { ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

set_exception_handler(function ($e) use ($cfg) {
  $msg = 'The sync server has a setup problem.';
  if (!empty($cfg['debug'])) { $msg .= ' [' . get_class($e) . ': ' . $e->getMessage() . ']'; }
  out(['ok' => false, 'error' => $msg], 500);
});
register_shutdown_function(function () use ($cfg) {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
    $msg = 'The sync server hit a PHP error.';
    if (!empty($cfg['debug'])) { $msg .= ' [' . $e['message'] . ']'; }
    out(['ok' => false, 'error' => $msg], 500);
  }
});

function cors(array $cfg): void {
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== '' && in_array($origin, $cfg['allowed_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Auth-Token');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') return [];
  $j = json_decode($raw, true);
  if (!is_array($j)) fail('Invalid JSON');
  return $j;
}

function rand_token(): string { return bin2hex(random_bytes(32)); }
function hash_token(string $t): string { return hash('sha256', $t); }

function bearer(): ?string {
  global $in;   // the sign-in token travels inside the request body, because many hosts strip the Authorization header
  if (is_array($in) && isset($in['token']) && is_string($in['token']) && preg_match('/^[a-f0-9]{64}$/i', $in['token'])) {
    return strtolower($in['token']);
  }
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if ($h === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) { if (strtolower($k) === 'authorization') $h = $v; }
  }
  if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $h, $m)) return strtolower($m[1]);
  $x = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
  if (preg_match('/^[a-f0-9]{64}$/i', $x)) return strtolower($x);
  return null;
}

function auth(PDO $db, array $cfg): array {
  $t = bearer();
  if (!$t) fail('Not signed in: no sign-in token reached the server.', 401);
  $now = time();
  $st = $db->prepare('SELECT t.user_id, u.email FROM tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>?');
  $st->execute([hash_token($t), $now]);
  $row = $st->fetch();
  if (!$row) fail('Session expired. Please sign in again.', 401);
  $db->prepare('UPDATE tokens SET last_used=? WHERE token_hash=?')->execute([$now, hash_token($t)]);
  return ['id' => (int)$row['user_id'], 'email' => $row['email'], 'token' => $t];
}

function clean_email(string $e): string {
  $e = strtolower(trim($e));
  if (!filter_var($e, FILTER_VALIDATE_EMAIL) || strlen($e) > 254) fail('Please enter a valid email address.');
  return $e;
}

function throttle(PDO $db, array $cfg, string $key): void {
  $now = time();
  $db->prepare('DELETE FROM attempts WHERE at < ?')->execute([$now - 900]);
  $st = $db->prepare('SELECT COUNT(*) c FROM attempts WHERE k=? AND at>=?');
  $st->execute([$key, $now - 900]);
  if ((int)$st->fetch()['c'] >= $cfg['max_login_attempts']) fail('Too many attempts. Try again in 15 minutes.', 429);
}
function note_attempt(PDO $db, string $key): void {
  $db->prepare('INSERT INTO attempts(k,at) VALUES(?,?)')->execute([$key, time()]);
}
// A limit with its own ceiling and window (the window can be at most 15 minutes).
function throttle_n(PDO $db, string $key, int $max, int $window): void {
  $now = time();
  $db->prepare('DELETE FROM attempts WHERE at < ?')->execute([$now - 900]);
  $st = $db->prepare('SELECT COUNT(*) c FROM attempts WHERE k=? AND at>=?');
  $st->execute([$key, $now - min($window, 900)]);
  if ((int)$st->fetch()['c'] >= $max) fail('Too many requests. Please wait a few minutes and try again.', 429);
}

function issue_token(PDO $db, array $cfg, int $uid, string $label): string {
  $t = rand_token(); $now = time();
  $db->prepare('INSERT INTO tokens(token_hash,user_id,device_label,created_at,last_used,expires_at) VALUES(?,?,?,?,?,?)')
     ->execute([hash_token($t), $uid, substr($label, 0, 60), $now, $now, $now + $cfg['token_days'] * 86400]);
  return $t;
}

// Sends one plain-text email. Returns [bool accepted_by_php, string note].
function send_mail(array $cfg, string $to, string $subject, string $text): array {
  if (!function_exists('mail')) return [false, 'mail() is not available on this host'];
  $from = $cfg['mail_from'];
  $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
  $headers = [
    'From: Paint Project Manager <' . $from . '>',
    'Reply-To: ' . $from,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=utf-8',
    'Date: ' . date('r'),
    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
    'X-Mailer: PPM-Sync',
  ];
  $ok = @mail($to, $subject, $text, implode("\r\n", $headers), '-f' . $from);
  $note = 'sent with envelope sender';
  if (!$ok) { $ok = @mail($to, $subject, $text, implode("\r\n", $headers)); $note = 'sent without envelope sender'; }
  if (!$ok) { $err = error_get_last(); $note = 'mail() returned false' . ($err ? ': ' . $err['message'] : ''); }
  return [(bool)$ok, $note];
}

// ---------- online signing helpers ----------
function sign_money(int $cents): string { return '$' . number_format($cents / 100, 2, '.', ','); }

// Deletes signing copies that have outlived the retention rules. Runs now and then, not on every request.
function sign_purge(PDO $db, array $cfg): void {
  $now = time();
  $db->prepare('DELETE FROM signs WHERE signed_at IS NOT NULL AND signed_at < ?')->execute([$now - $cfg['sign_keep_signed_days'] * 86400]);
  $db->prepare('DELETE FROM signs WHERE signed_at IS NULL AND expires_at < ?')->execute([$now - $cfg['sign_keep_unsigned_days'] * 86400]);
  $db->prepare('DELETE FROM sign_replies WHERE created_at < ?')->execute([$now - $cfg['reply_keep_days'] * 86400]);
}

// The page the customer sees tells them how to reach the business, so a little contact info is always returned.
// Rev CB: an invoice can carry a payment link. Only a plain web address is ever passed on.
function sign_pay(array $snap): array {
  if (($snap['kind'] ?? '') !== 'invoice' || !isset($snap['pay']) || !is_array($snap['pay'])) return ['link' => '', 'text' => ''];
  $l = (string)($snap['pay']['link'] ?? '');
  if (!preg_match('#^https?://[\x21-\x7e]{4,190}$#i', $l)) $l = '';
  return ['link' => $l, 'text' => mb_substr((string)($snap['pay']['text'] ?? ''), 0, 300)];
}
// Text typed by a customer: control characters removed, length capped. A message may keep its line breaks.
function sign_clean_text(string $v, int $max, bool $multi): string {
  $v = str_replace("\r\n", "\n", $v); $v = str_replace("\r", "\n", $v);
  $v = preg_replace($multi ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $v);
  if (!is_string($v)) return '';
  $v = trim($v);
  if ($multi) { $v2 = preg_replace("/\n{3,}/", "\n\n", $v); if (is_string($v2)) $v = $v2; }
  return mb_substr($v, 0, $max);
}
function sign_kind(array $snap): string { return (($snap['kind'] ?? '') === 'invoice') ? 'invoice' : 'quote'; }
function sign_contact(array $snap): array {
  $b = (isset($snap['biz']) && is_array($snap['biz'])) ? $snap['biz'] : [];
  return ['name' => (string)($b['name'] ?? ''), 'phone' => (string)($b['phone'] ?? ''), 'email' => (string)($b['email'] ?? '')];
}

// ---------- routing ----------
cors($cfg);

// Setup self-check (only while 'debug' is true)
if (($_GET['a'] ?? '') === 'check') {
  if (empty($cfg['debug'])) fail('Diagnostics are off.', 404);
  $info = ['ok' => true, 'php' => PHP_VERSION, 'pdo_sqlite' => extension_loaded('pdo_sqlite'),
           'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'mail_function' => function_exists('mail'),
           'mail_from' => $cfg['mail_from'], 'sendmail_path' => (string)ini_get('sendmail_path')];
  try {
    $d = ppm_db($cfg);
    $v = (string)$d->query('SELECT sqlite_version() v')->fetch()['v'];
    $info['database'] = 'ok';
    $info['sqlite_version'] = $v;
    $info['sqlite_ok_for_sync'] = version_compare($v, '3.24.0', '>=');
    $info['accounts'] = (int)$d->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
    $info['signed_in_devices'] = (int)$d->query('SELECT COUNT(*) c FROM tokens')->fetch()['c'];
    $info['records_stored'] = (int)$d->query('SELECT COUNT(*) c FROM records')->fetch()['c'];
    $info['signing_table'] = 'ok';
    $info['signing_quotes_open'] = (int)$d->query('SELECT COUNT(*) c FROM signs WHERE signed_at IS NULL')->fetch()['c'];
    $info['signing_signed_waiting'] = (int)$d->query('SELECT COUNT(*) c FROM signs WHERE signed_at IS NOT NULL')->fetch()['c'];
  } catch (Throwable $e) {
    $info['database'] = 'FAILED: ' . $e->getMessage();
  }
  $dir = dirname($cfg['db_path']);
  $info['data_folder_exists'] = is_dir($dir);
  $info['data_folder_writable'] = is_writable($dir);
  $info['sign_page_present'] = is_file(__DIR__ . '/sign.html');
  $info['reply_page_present'] = is_file(__DIR__ . '/reply.html');
  $info['github_origin_allowed'] = in_array('https://findain.github.io', $cfg['allowed_origins'], true);
  try { $info['replies_stored'] = (int)ppm_db($cfg)->query('SELECT COUNT(*) c FROM sign_replies')->fetch()['c']; } catch (Throwable $e) { $info['replies_stored'] = 'table missing'; }
  $info['reply_email_copy'] = !empty($cfg['reply_notify_email']);
  $info['server_version'] = 'CE (online signing, customer messages)';
  out($info);
}

// Email test (only while 'debug' is true, and only to an email that already has an account).
if (($_GET['a'] ?? '') === 'mailtest') {
  if (empty($cfg['debug'])) fail('Diagnostics are off.', 404);
  $to = strtolower(trim((string)($_GET['to'] ?? '')));
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Add ?to=your@email to the address.');
  $d = ppm_db($cfg);
  throttle($d, $cfg, 'mailtest|' . ($_SERVER['REMOTE_ADDR'] ?? '0'));
  note_attempt($d, 'mailtest|' . ($_SERVER['REMOTE_ADDR'] ?? '0'));
  $st = $d->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$to]);
  if (!$st->fetch()) fail('That email has no account here, so no test was sent.');
  [$sent, $note] = send_mail($cfg, $to, 'Paint Project Manager email test', "If you can read this, password-reset emails can reach you.\r\nSent " . date('c'));
  out(['ok' => true, 'to' => $to, 'from' => $cfg['mail_from'], 'mail_accepted_by_php' => $sent, 'detail' => $note,
       'tip' => 'If this says true but nothing arrives, check Junk/Spam, then create a real mailbox for the from address on your host.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST only', 405);

$action = $_GET['a'] ?? '';
$in = body();
$db = ppm_db($cfg);
$ip = $_SERVER['REMOTE_ADDR'] ?? '0';

switch ($action) {

  case 'register': {
    $email = clean_email((string)($in['email'] ?? ''));
    $pw = (string)($in['password'] ?? '');
    if (strlen($pw) < $cfg['min_password_len']) fail('Password must be at least ' . $cfg['min_password_len'] . ' characters.');
    throttle($db, $cfg, "reg|$ip");
    note_attempt($db, "reg|$ip");
    $st = $db->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$email]);
    if ($st->fetch()) fail('That email already has an account. Try signing in.', 409);
    $db->prepare('INSERT INTO users(email,pass_hash,created_at) VALUES(?,?,?)')
       ->execute([$email, password_hash($pw, PASSWORD_DEFAULT), time()]);
    $uid = (int)$db->lastInsertId();
    $tok = issue_token($db, $cfg, $uid, (string)($in['device'] ?? 'device'));
    out(['ok' => true, 'token' => $tok, 'email' => $email]);
  }

  case 'login': {
    $email = clean_email((string)($in['email'] ?? ''));
    $pw = (string)($in['password'] ?? '');
    $key = "login|$email|$ip";
    throttle($db, $cfg, $key);
    $st = $db->prepare('SELECT id, pass_hash FROM users WHERE email=?'); $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($pw, $u['pass_hash'])) {
      note_attempt($db, $key);
      fail('Email or password is incorrect.', 401);
    }
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT)) {
      $db->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    }
    $tok = issue_token($db, $cfg, (int)$u['id'], (string)($in['device'] ?? 'device'));
    out(['ok' => true, 'token' => $tok, 'email' => $email]);
  }

  case 'logout': {
    $u = auth($db, $cfg);
    $db->prepare('DELETE FROM tokens WHERE token_hash=?')->execute([hash_token($u['token'])]);
    out(['ok' => true]);
  }

  case 'me': {
    $u = auth($db, $cfg);
    out(['ok' => true, 'email' => $u['email']]);
  }

  case 'forgot': {
    $email = clean_email((string)($in['email'] ?? ''));
    throttle($db, $cfg, "forgot|$ip");
    note_attempt($db, "forgot|$ip");
    $st = $db->prepare('SELECT id FROM users WHERE email=?'); $st->execute([$email]);
    $diag = ['account_found' => false];
    if ($u = $st->fetch()) {
      $t = rand_token();
      $db->prepare('DELETE FROM resets WHERE user_id=?')->execute([$u['id']]);
      $db->prepare('INSERT INTO resets(token_hash,user_id,expires_at) VALUES(?,?,?)')
         ->execute([hash_token($t), $u['id'], time() + $cfg['reset_minutes'] * 60]);
      $link = $cfg['app_url'] . '?reset=' . $t;
      $msg = "Someone asked to reset the password for your Paint Project Manager Cloud Sync account.\r\n\r\n"
           . "Open this link within " . $cfg['reset_minutes'] . " minutes to choose a new password:\r\n" . $link . "\r\n\r\n"
           . "If you didn't ask for this, you can ignore this email.";
      [$sent, $note] = send_mail($cfg, $email, 'Reset your Paint Project Manager password', $msg);
      $diag = ['account_found' => true, 'mail_accepted_by_php' => $sent, 'detail' => $note, 'from' => $cfg['mail_from']];
    }
    $reply = ['ok' => true, 'message' => 'If that email has an account, a reset link is on its way.'];
    if (!empty($cfg['debug'])) $reply['debug'] = $diag;
    out($reply);
  }

  case 'reset': {
    $t = strtolower((string)($in['token'] ?? ''));
    $pw = (string)($in['password'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) fail('This reset link is not valid.');
    if (strlen($pw) < $cfg['min_password_len']) fail('Password must be at least ' . $cfg['min_password_len'] . ' characters.');
    throttle($db, $cfg, "reset|$ip");
    note_attempt($db, "reset|$ip");
    $st = $db->prepare('SELECT user_id FROM resets WHERE token_hash=? AND expires_at>?');
    $st->execute([hash_token($t), time()]);
    $r = $st->fetch();
    if (!$r) fail('This reset link has expired. Please request a new one.');
    $db->beginTransaction();
    $db->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $r['user_id']]);
    $db->prepare('DELETE FROM resets WHERE user_id=?')->execute([$r['user_id']]);
    $db->prepare('DELETE FROM tokens WHERE user_id=?')->execute([$r['user_id']]); // sign out everywhere
    $db->commit();
    out(['ok' => true]);
  }

  case 'delete_account': {
    $u = auth($db, $cfg);
    $pw = (string)($in['password'] ?? '');
    $st = $db->prepare('SELECT pass_hash FROM users WHERE id=?'); $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!$row || !password_verify($pw, $row['pass_hash'])) fail('Password is incorrect.', 401);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$u['id']]); // cascades to tokens, resets, records and signing copies
    out(['ok' => true]);
  }

  // Push local changes and pull remote ones in one round trip. `data` is the app's own JSON text, stored untouched.
  case 'sync': {
    $u = auth($db, $cfg);
    $since = (int)($in['since'] ?? 0);
    $changes = $in['changes'] ?? [];
    if (!is_array($changes)) fail('Bad changes list');
    if (count($changes) > $cfg['max_records_per_push']) fail('Too many changes at once; send smaller batches.', 413);

    $rejected = [];
    $nowMs = (int)(microtime(true) * 1000);
    $db->beginTransaction();
    try {
      $st = $db->prepare('SELECT seq_counter FROM users WHERE id=?'); $st->execute([$u['id']]);
      $seq = (int)$st->fetch()['seq_counter'];

      $get = $db->prepare('SELECT updated_at FROM records WHERE user_id=? AND type=? AND rec_id=?');
      $put = $db->prepare('INSERT INTO records(user_id,type,rec_id,data,updated_at,deleted,server_seq) VALUES(?,?,?,?,?,?,?)
                           ON CONFLICT(user_id,type,rec_id) DO UPDATE SET data=excluded.data, updated_at=excluded.updated_at,
                           deleted=excluded.deleted, server_seq=excluded.server_seq');

      foreach ($changes as $c) {
        if (!is_array($c)) continue;
        $type = substr((string)($c['type'] ?? ''), 0, 40);
        $rid  = substr((string)($c['id'] ?? ''), 0, 120);
        $upd  = (int)($c['updated_at'] ?? 0);
        $del  = !empty($c['deleted']) ? 1 : 0;
        if ($type === '' || $rid === '' || $upd <= 0) continue;
        if ($upd > $nowMs + 86400000) $upd = $nowMs;
        $data = null;
        if (!$del) {
          if (!isset($c['data']) || !is_string($c['data'])) { $rejected[] = ['type' => $type, 'id' => $rid]; continue; }
          $data = $c['data'];
          if (strlen($data) > $cfg['max_record_bytes']) { $rejected[] = ['type' => $type, 'id' => $rid]; continue; }
        }

        $get->execute([$u['id'], $type, $rid]);
        $existing = $get->fetch();
        if ($existing && (int)$existing['updated_at'] >= $upd) continue;   // newest edit wins
        $seq++;
        $put->execute([$u['id'], $type, $rid, $data, $upd, $del, $seq]);
      }
      $db->prepare('UPDATE users SET seq_counter=? WHERE id=?')->execute([$seq, $u['id']]);
      $db->commit();
    } catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      fail('Server error while saving. Nothing was lost on your device.' . (!empty($cfg['debug']) ? ' [' . $e->getMessage() . ']' : ''), 500);
    }

    $limit = 200;
    $st = $db->prepare('SELECT type,rec_id,data,updated_at,deleted,server_seq FROM records WHERE user_id=? AND server_seq>? ORDER BY server_seq ASC LIMIT ' . ($limit + 1));
    $st->execute([$u['id'], $since]);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    $records = [];
    $maxSeq = $since;
    $bytes = 0;
    foreach ($rows as $r) {
      $bytes += strlen((string)$r['data']);
      if ($bytes > 4000000 && count($records) > 0) { $more = true; break; }
      $records[] = [
        'type' => $r['type'], 'id' => $r['rec_id'], 'data' => $r['data'],
        'updated_at' => (int)$r['updated_at'], 'deleted' => (bool)$r['deleted'], 'seq' => (int)$r['server_seq'],
      ];
      $maxSeq = (int)$r['server_seq'];
    }
    out(['ok' => true, 'seq' => $maxSeq, 'records' => $records, 'rejected' => $rejected, 'more' => $more]);
  }

  // ============ ONLINE SIGNING ============

  // The app puts a quote online so the customer can read and sign it. Signed in.
  // Same quote again (same content) -> the same link. A changed quote -> a new link; the old unsigned copy is removed.
  case 'sign_publish': {
    $u = auth($db, $cfg);
    $pid = substr(trim((string)($in['project_id'] ?? '')), 0, 120);
    $ref = substr(trim((string)($in['ref'] ?? '')), 0, 60);
    $snapJson = $in['snapshot'] ?? '';
    if ($pid === '' || $ref === '') fail('Missing project or quote number.');
    if (!is_string($snapJson) || $snapJson === '' || strlen($snapJson) > $cfg['sign_max_snapshot_bytes']) fail('That quote is too large to put online.', 413);
    $snap = json_decode($snapJson, true);
    if (!is_array($snap)) fail('The quote could not be read.');
    $kind = sign_kind($snap);
    // Invoices are stored under the reference INV:<number>, so an invoice and a quote can never be mixed up.
    if ($kind === 'invoice' && strncmp($ref, 'INV:', 4) !== 0) fail('Bad invoice reference.');
    if ($kind === 'quote' && strncmp($ref, 'INV:', 4) === 0) fail('Bad quote reference.');
    $nOpt = (int)($in['options'] ?? 1); if ($nOpt < 1 || $nOpt > 3) $nOpt = 1;
    $totals = $in['totals'] ?? [];
    if (!is_array($totals) || count($totals) !== $nOpt) fail('The quote totals are missing.');
    $tot = [];
    foreach ($totals as $t) { $c = (int)$t; if ($c <= 0 || $c > 100000000000) fail('The quote total is not valid.'); $tot[] = $c; }
    $scopeKey = preg_replace('/[^0-9a-f]/i', '', (string)($in['scope_key'] ?? '')); $scopeKey = substr((string)$scopeKey, 0, 16);
    $now = time();
    $expMs = (int)($in['expires_at'] ?? 0);
    $exp = $expMs > 0 ? (int)floor($expMs / 1000) : ($now + $cfg['sign_no_expiry_days'] * 86400);
    $maxExp = $now + $cfg['sign_no_expiry_days'] * 86400;
    if ($exp > $maxExp) $exp = $maxExp;
    if ($exp < $now + 3600) $exp = $now + 3600;

    $core = $snap; unset($core['date'], $core['validUntil'], $core['reply'], $core['rf']); // the day it was made, or the reply buttons, do not make it a different quote
    if ($kind === 'invoice') {                                               // a payment, a new due date or a new payment link does not make it a different invoice
      unset($core['due'], $core['balance'], $core['pay']);
      if (isset($core['totals']) && is_array($core['totals'])) unset($core['totals']['rows']);
    }
    $hash = hash('sha256', json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $maybe = random_int(1, 20) === 1; if ($maybe) sign_purge($db, $cfg);

    $st = $db->prepare('SELECT token, signed_at FROM signs WHERE user_id=? AND project_id=? AND ref=? AND content_hash=? ORDER BY created_at DESC LIMIT 1');
    $st->execute([$u['id'], $pid, $ref, $hash]);
    if ($row = $st->fetch()) {
      if ($row['signed_at'] !== null) out(['ok' => true, 'token' => $row['token'], 'signed' => true]);
      // Same content, same link. The unsigned copy is refreshed so the balance, payment link and reply buttons stay current.
      $db->prepare('UPDATE signs SET expires_at=MAX(expires_at, ?), snapshot=? WHERE token=? AND signed_at IS NULL')->execute([$exp, $snapJson, $row['token']]);
      out(['ok' => true, 'token' => $row['token'], 'signed' => false]);
    }
    $db->beginTransaction();
    $db->prepare('DELETE FROM signs WHERE user_id=? AND project_id=? AND ref=? AND signed_at IS NULL')->execute([$u['id'], $pid, $ref]);
    $token = rand_token();
    $db->prepare('INSERT INTO signs(token,user_id,project_id,ref,content_hash,snapshot,options,totals,scope_key,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$token, $u['id'], $pid, $ref, $hash, $snapJson, $nOpt, json_encode($tot), $scopeKey, $now, $exp]);
    $db->commit();
    out(['ok' => true, 'token' => $token, 'signed' => false]);
  }

  // The customer opens the link. Public (the secret link is the key). Nothing is returned that the link holder was not meant to see.
  case 'sign_get': {
    $t = strtolower((string)($in['t'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) out(['ok' => true, 'state' => 'missing']);
    throttle_n($db, "signget|$ip", 60, 600); note_attempt($db, "signget|$ip");
    $st = $db->prepare('SELECT snapshot, options, totals, expires_at, signed_at, sig_name FROM signs WHERE token=?');
    $st->execute([$t]);
    $row = $st->fetch();
    if (!$row) out(['ok' => true, 'state' => 'missing']);
    $snap = json_decode((string)$row['snapshot'], true); if (!is_array($snap)) out(['ok' => true, 'state' => 'missing']);
    $contact = sign_contact($snap);
    if ($row['signed_at'] !== null) {
      out(['ok' => true, 'state' => 'signed', 'kind' => sign_kind($snap), 'pay' => sign_pay($snap), 'contact' => $contact, 'number' => (string)($snap['number'] ?? ''), 'name' => (string)$row['sig_name'], 'at' => (int)$row['signed_at'] * 1000]);
    }
    if ((int)$row['expires_at'] < time()) {
      out(['ok' => true, 'state' => 'expired', 'kind' => sign_kind($snap), 'pay' => sign_pay($snap), 'contact' => $contact, 'number' => (string)($snap['number'] ?? ''), 'expired_at' => (int)$row['expires_at'] * 1000]);
    }
    out(['ok' => true, 'state' => 'open', 'kind' => sign_kind($snap), 'snapshot' => $snap, 'options' => (int)$row['options'], 'totals' => json_decode((string)$row['totals'], true), 'expires_at' => (int)$row['expires_at'] * 1000]);
  }

  // The customer signs. Public. The words they agreed to are put together here from the quote itself.
  case 'sign_submit': {
    $t = strtolower((string)($in['t'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) fail('This signing link is not valid.');
    throttle_n($db, "signsub|$ip", 12, 600); note_attempt($db, "signsub|$ip");
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 80) fail('Type your printed name.');
    if (empty($in['agree'])) fail('Check the box to confirm you agree.');
    $img = (string)($in['img'] ?? '');
    $prefix = 'data:image/jpeg;base64,';
    if (strlen($img) > 230000 || strncmp($img, $prefix, strlen($prefix)) !== 0) fail('The signature could not be read. Please sign again.');
    $bin = base64_decode(substr($img, strlen($prefix)), true);
    if ($bin === false || strlen($bin) < 300 || strlen($bin) > $cfg['sign_max_image_bytes'] || substr($bin, 0, 3) !== "\xFF\xD8\xFF") fail('The signature could not be read. Please sign again.');
    $w = (int)($in['w'] ?? 0); $h = (int)($in['h'] ?? 0);
    if ($w < 40 || $w > 800 || $h < 20 || $h > 400) fail('The signature could not be read. Please sign again.');

    $db->beginTransaction();
    $st = $db->prepare('SELECT snapshot, options, totals, expires_at, signed_at FROM signs WHERE token=?');
    $st->execute([$t]);
    $row = $st->fetch();
    if (!$row) { $db->rollBack(); fail('This signing link is no longer available.', 404); }
    if ($row['signed_at'] !== null) { $db->rollBack(); fail('This has already been signed.', 409); }
    if ((int)$row['expires_at'] < time()) { $db->rollBack(); fail('This signing link has expired.', 410); }
    $snap = json_decode((string)$row['snapshot'], true); $totals = json_decode((string)$row['totals'], true);
    $n = (int)$row['options'];
    $opt = -1; $optLabel = '';
    if ($n > 1) {
      $opt = (int)($in['option'] ?? -1);
      if ($opt < 0 || $opt >= $n) { $db->rollBack(); fail('Choose which option you are signing for.'); }
      $names = (isset($snap['options']) && is_array($snap['options'])) ? $snap['options'] : [];
      $nm = isset($names[$opt]['name']) ? trim((string)$names[$opt]['name']) : '';
      $optLabel = ' for option ' . chr(65 + $opt) . ($nm !== '' ? ' (' . $nm . ')' : '');
    }
    $total = (int)($totals[$n > 1 ? $opt : 0] ?? 0);
    if ($total <= 0) { $db->rollBack(); fail('This cannot be signed.'); }
    $kind = sign_kind($snap);
    $agree = trim((string)($snap['agreement'] ?? '')); if ($agree === '') $agree = 'I agree to the work and charges described in this document.';
    $text = trim(preg_replace('/\s+/', ' ', $agree . ($kind === 'invoice' ? ' Invoice ' : ' Quote ') . (string)($snap['number'] ?? '') . $optLabel . ', total ' . sign_money($total) . '.'));
    $text = mb_substr($text, 0, 300);
    $db->prepare('UPDATE signs SET signed_at=?, sig_name=?, sig_img=?, sig_w=?, sig_h=?, sig_text=?, sig_option=?, sig_total=? WHERE token=? AND signed_at IS NULL')
       ->execute([time(), $name, $img, $w, $h, $text, $opt, $total, $t]);
    $db->commit();
    $c = sign_contact($snap);
    out(['ok' => true, 'kind' => $kind, 'pay' => sign_pay($snap), 'contact' => $c, 'number' => (string)($snap['number'] ?? ''), 'at' => time() * 1000]);
  }

  // The app asks for signatures that have come in. Signed in. Nothing is deleted here.
  case 'sign_pull': {
    $u = auth($db, $cfg);
    $since = (int)($in['since'] ?? 0);   // milliseconds; only signatures newer than this are sent
    $st = $db->prepare('SELECT project_id, ref, content_hash, scope_key, signed_at, sig_name, sig_img, sig_w, sig_h, sig_text, sig_option, sig_total FROM signs WHERE user_id=? AND signed_at IS NOT NULL AND signed_at > ? ORDER BY signed_at ASC LIMIT 25');
    $st->execute([$u['id'], (int)floor($since / 1000)]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
      $ref = (string)$r['ref']; $kind = 'quote';
      if (strncmp($ref, 'INV:', 4) === 0) { $kind = 'invoice'; $ref = substr($ref, 4); }
      $items[] = ['project_id' => $r['project_id'], 'kind' => $kind, 'number' => $ref, 'scope_key' => $r['scope_key'], 'at' => (int)$r['signed_at'] * 1000,
                  'name' => $r['sig_name'], 'img' => $r['sig_img'], 'w' => (int)$r['sig_w'], 'h' => (int)$r['sig_h'],
                  'text' => $r['sig_text'], 'option' => (int)$r['sig_option'], 'total' => (int)$r['sig_total']];
    }
    if (random_int(1, 20) === 1) sign_purge($db, $cfg);
    out(['ok' => true, 'items' => $items]);
  }

  // The job is complete (or lost): remove everything the server holds for those jobs. Signed in.
  // Rev CE: the customer sends a question, a change request or a decline from the signing page. Public (the secret link is the key).
  case 'sign_reply': {
    $t = strtolower((string)($in['t'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) fail('This signing link is not valid.');
    throttle_n($db, "signrep|$ip", 10, 600); note_attempt($db, "signrep|$ip");
    throttle_n($db, "signrept|$t", 6, 900); note_attempt($db, "signrept|$t");
    $rk = (string)($in['kind'] ?? '');
    if ($rk !== 'question' && $rk !== 'decline') fail('Choose Question or Decline.');
    $message = sign_clean_text((string)($in['message'] ?? ''), 800, true);
    $name = sign_clean_text((string)($in['name'] ?? ''), 80, false);
    $contact = sign_clean_text((string)($in['contact'] ?? ''), 120, false);
    if ($rk === 'question' && mb_strlen($message) < 2) fail('Please type your question or the changes you want.');
    $st = $db->prepare('SELECT user_id, project_id, ref, snapshot, expires_at, signed_at FROM signs WHERE token=?');
    $st->execute([$t]);
    $row = $st->fetch();
    if (!$row) fail('This link is no longer available. Please contact the business directly.', 404);
    $snap = json_decode((string)$row['snapshot'], true);
    if (!is_array($snap)) fail('This link could not be read.');
    if ($row['signed_at'] !== null) fail('This has already been signed. Please contact the business directly if you need a change.', 409);
    if ((int)$row['expires_at'] < time()) fail('This link has expired. Please contact the business directly.', 410);
    if (empty($snap['rf'])) fail('Messages are not turned on for this one. Please use the contact details shown on the page.', 403);
    $cnt = $db->prepare('SELECT COUNT(*) c FROM sign_replies WHERE token=?'); $cnt->execute([$t]);
    if ((int)$cnt->fetch()['c'] >= 10) fail('You have sent several messages already. Please contact the business directly.', 429);
    $now = time();
    $db->prepare('INSERT INTO sign_replies(token,user_id,project_id,ref,kind,message,name,contact,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
       ->execute([$t, $row['user_id'], $row['project_id'], $row['ref'], $rk, $message, $name, $contact, $now]);
    if (!empty($cfg['reply_notify_email'])) {          // a copy to the account email; never blocks or fails the customer's message
      try {
        $cn = $db->prepare('SELECT COUNT(*) c FROM sign_replies WHERE user_id=? AND created_at>=?'); $cn->execute([$row['user_id'], $now - 3600]);
        if ((int)$cn->fetch()['c'] <= 20) {
          $ue = $db->prepare('SELECT email FROM users WHERE id=?'); $ue->execute([$row['user_id']]);
          $to = (string)(($ue->fetch() ?: [])['email'] ?? '');
          if ($to !== '') {
            $rf = (string)$row['ref']; $isInv = strncmp($rf, 'INV:', 4) === 0; $num = $isInv ? substr($rf, 4) : $rf;
            $what = ($isInv ? 'Invoice ' : 'Quote ') . $num;
            $subj = preg_replace('/[\r\n]+/', ' ', ($rk === 'decline' ? 'Customer declined ' : 'Customer message on ') . $what);
            $cname = sign_clean_text((string)($snap['customer']['name'] ?? ''), 80, false);
            $jl = sign_clean_text((string)($snap['job']['label'] ?? ''), 60, false);
            $lines = [($rk === 'decline' ? 'A customer declined ' : 'A customer sent a question or change request on ') . $what . '.', ''];
            if ($cname !== '') $lines[] = 'Customer: ' . $cname;
            if ($jl !== '') $lines[] = 'Job: ' . $jl;
            if ($name !== '' && $name !== $cname) $lines[] = 'Name typed: ' . $name;
            if ($contact !== '') $lines[] = 'Reach them at: ' . $contact;
            $lines[] = ''; $lines[] = $message !== '' ? $message : '(No message was typed.)'; $lines[] = '';
            $lines[] = 'Open Paint Project Manager and tap Sync now. The message then shows on the ' . ($isInv ? 'invoice' : 'quote') . '.';
            send_mail($cfg, $to, $subj, implode("\n", $lines));
          }
        }
      } catch (Throwable $e) { /* the message is saved either way */ }
    }
    out(['ok' => true, 'kind' => sign_kind($snap), 'contact' => sign_contact($snap)]);
  }

  // The app pulls the customer messages that came in (newest id wins; ids never repeat).
  case 'reply_pull': {
    $u = auth($db, $cfg);
    $since = (int)($in['since_id'] ?? 0);
    $st = $db->prepare('SELECT id, project_id, ref, kind, message, name, contact, created_at FROM sign_replies WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 50');
    $st->execute([$u['id'], $since]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
      $rf = (string)$r['ref']; $k = 'quote';
      if (strncmp($rf, 'INV:', 4) === 0) { $k = 'invoice'; $rf = substr($rf, 4); }
      $items[] = ['id' => (int)$r['id'], 'project_id' => $r['project_id'], 'kind' => $k, 'rkind' => $r['kind'], 'number' => $rf,
                  'message' => $r['message'], 'name' => $r['name'], 'contact' => $r['contact'], 'at' => (int)$r['created_at'] * 1000];
    }
    out(['ok' => true, 'items' => $items]);
  }

  case 'sign_clear': {
    $u = auth($db, $cfg);
    $ids = $in['project_ids'] ?? [];
    if (!is_array($ids)) fail('Bad list.');
    $n = 0;
    $del = $db->prepare('DELETE FROM signs WHERE user_id=? AND project_id=?');
    $delR = $db->prepare('DELETE FROM sign_replies WHERE user_id=? AND project_id=?');
    foreach (array_slice($ids, 0, 200) as $id) {
      $id = substr(trim((string)$id), 0, 120); if ($id === '') continue;
      $del->execute([$u['id'], $id]); $n += $del->rowCount();
      $delR->execute([$u['id'], $id]);
    }
    out(['ok' => true, 'removed' => $n]);
  }

  default:
    fail('Unknown action', 404);
}
