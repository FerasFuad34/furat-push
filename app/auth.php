<?php
/* ===== حماية لوحة التحكم — نفس عقد server.js (hash + توكن HMAC + خنق المحاولات) ===== */
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/store.php';

define('FH_AUTH_PATH', FH_DATA . '/admin-auth.json');
define('FH_COOKIE', 'fh_admin');
define('FH_ATTEMPTS', FH_DATA . '/login-attempts.json');

function fh_defaultPin() { $e = getenv('FH_PIN'); return jT($e ?: '') ? $e : '1234'; }
function fh_hashPin($p) { return hash('sha256', 'furat::' . (string)$p); }

function fh_authObj() {
  if (is_file(FH_AUTH_PATH)) {
    $a = fh_fromJson((string)file_get_contents(FH_AUTH_PATH));
    if (is_array($a) && jT($a['pinHash'] ?? null)) return $a;
  }
  return null;
}
function fh_pinHash() {
  $a = fh_authObj();
  return $a ? $a['pinHash'] : fh_hashPin(fh_defaultPin());
}
function fh_isDefaultPin() { return !is_file(FH_AUTH_PATH); }

function fh_sign($v) { return substr(hash_hmac('sha256', (string)$v, fh_pinHash() . '::fh'), 0, 32); }
function fh_newToken() { $v = (string)fh_ms(); return $v . '.' . fh_sign($v); }
function fh_ms() { return (int)round(microtime(true) * 1000); }

function fh_validToken($t) {
  if (!is_string($t) || strpos($t, '.') === false) return false;
  $i = strpos($t, '.');
  $v = substr($t, 0, $i);
  $s = substr($t, $i + 1);
  if (!preg_match('/^\d+$/', $v)) return false;
  if (!hash_equals(fh_sign($v), $s)) return false;
  return fh_ms() - (int)$v < 1000 * 60 * 60 * 12; /* 12 ساعة */
}

function fh_reqToken() {
  $h = $_SERVER['HTTP_X_FH_TOKEN'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
  if (jT($h)) return (strpos($h, 'Bearer ') === 0) ? substr($h, 7) : $h;
  if (isset($_GET['t']) && $_GET['t'] !== '') return (string)$_GET['t'];
  return '';
}
function fh_isAdmin() { return fh_validToken(fh_reqToken()); }

/* ---------- خنق تسجيل الدخول: 8 فاشلة = 10 دقائق لكل IP ---------- */
function fh_clientIp() {
  $xf = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if (jT($xf)) {
    $first = trim(explode(',', (string)$xf)[0]);
    if (jT($first)) return $first;
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? 'x');
}
function fh_throttleCount($ip) {
  $now = fh_ms();
  $fp = @fopen(FH_ATTEMPTS, 'c+');
  if (!$fp) return 0;
  flock($fp, LOCK_EX);
  $raw = (string)stream_get_contents($fp);
  $map = json_decode($raw, true);
  if (!is_array($map)) $map = [];
  $a = $map[$ip] ?? null;
  if (!$a || $now - (int)($a['t'] ?? 0) > 10 * 60 * 1000) { $n = 0; } else { $n = (int)$a['n']; }
  flock($fp, LOCK_UN);
  fclose($fp);
  return $n;
}
function fh_throttleBump($ip) {
  $now = fh_ms();
  $fp = @fopen(FH_ATTEMPTS, 'c+');
  if (!$fp) return;
  flock($fp, LOCK_EX);
  $raw = (string)stream_get_contents($fp);
  $map = json_decode($raw, true);
  if (!is_array($map)) $map = [];
  $a = $map[$ip] ?? null;
  if (!$a || $now - (int)$a['t'] > 10 * 60 * 1000) $a = ['n' => 0, 't' => $now];
  $a['n'] = (int)$a['n'] + 1;
  $a['t'] = $now;
  $map[$ip] = $a;
  /* تنظيف القدام */
  foreach ($map as $k => $v) { if (!is_array($v) || $now - (int)($v['t'] ?? 0) > 30 * 60 * 1000) unset($map[$k]); }
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($map, JSON_UNESCAPED_UNICODE));
  flock($fp, LOCK_UN);
  fclose($fp);
  return $a['n'];
}
function fh_throttleClear($ip) {
  $fp = @fopen(FH_ATTEMPTS, 'c+');
  if (!$fp) return;
  flock($fp, LOCK_EX);
  $raw = (string)stream_get_contents($fp);
  $map = json_decode($raw, true);
  if (is_array($map) && isset($map[$ip])) {
    unset($map[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($map, JSON_UNESCAPED_UNICODE));
  }
  flock($fp, LOCK_UN);
  fclose($fp);
}

function fh_saveAuth($obj) {
  fh_data_dir();
  $tmp = FH_AUTH_PATH . '.tmp';
  file_put_contents($tmp, fh_pretty($obj));
  rename($tmp, FH_AUTH_PATH);
  @copy(FH_AUTH_PATH, FH_AUTH_PATH . '.safe');
}
