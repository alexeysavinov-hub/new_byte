<?php
// Byte Play — обработчик формы заявки. Данные сохраняются на этом сервере и отправляются на почту.
// ===== Настройки =====
$TO        = 'admin@byteplay.fun';            // куда приходят заявки
$FROM      = 'noreply@byteplay.fun';          // отправитель (ящик на вашем домене)
$STORE_DIR = dirname(__DIR__) . '/byteplay-leads'; // папка для заявок вне корня сайта
// =====================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($ok, $code = 200) { http_response_code($code); echo json_encode(['success' => $ok]); exit; }
function clean($s, $max) { $s = trim((string)$s); $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s); return mb_substr($s, 0, $max, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 405);

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

// антиспам: скрытое поле и слишком быстрая отправка
if (!empty($in['website'])) out(true);
if (isset($in['elapsed']) && (int)$in['elapsed'] < 2500) out(true);

$name    = clean($in['name'] ?? '', 120);
$email   = clean($in['email'] ?? '', 160);
$company = clean($in['company'] ?? '', 160);
$message = clean($in['message'] ?? '', 5000);
$lang    = ($in['lang'] ?? 'ru') === 'en' ? 'en' : 'ru';
$consent = !empty($in['consent']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, 422);

// ограничение: не чаще 1 заявки в 30 секунд с одного IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rl = sys_get_temp_dir() . '/bp_rl_' . md5($ip);
if (is_file($rl) && time() - filemtime($rl) < 30) out(false, 429);
@touch($rl);

// 1) сохранение на сервере (CSV, по месяцам)
if (!is_dir($STORE_DIR) && !@mkdir($STORE_DIR, 0700, true)) {
  $STORE_DIR = __DIR__ . '/leads';
  if (!is_dir($STORE_DIR)) { @mkdir($STORE_DIR, 0700, true); }
  @file_put_contents($STORE_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
  @file_put_contents($STORE_DIR . '/index.html', '');
}
$row = [date('c'), $name, $email, $company, str_replace(["\r", "\n"], ' ', $message), $lang, $consent ? 'yes' : 'no', $ip];
$file = $STORE_DIR . '/leads-' . date('Y-m') . '.csv';
$new = !is_file($file);
$fh = @fopen($file, 'a');
if ($fh) {
  if ($new) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, ['date', 'name', 'email', 'company', 'message', 'lang', 'consent', 'ip'], ';'); }
  fputcsv($fh, $row, ';'); fclose($fh);
}

// 2) письмо
$subject = 'Заявка с сайта Byte Play' . ($company ? ' — ' . $company : '');
$body = "Имя: " . ($name ?: '—') . "\nEmail: $email\nКомпания: " . ($company ?: '—') . "\nЯзык: $lang\n\n" . ($message ?: '—') . "\n\n—\n" . date('d.m.Y H:i') . " · IP $ip";
$headers = [
  'MIME-Version: 1.0',
  'Content-Type: text/plain; charset=UTF-8',
  'Content-Transfer-Encoding: 8bit',
  'From: =?UTF-8?B?' . base64_encode('Byte Play') . "?= <$FROM>",
  "Reply-To: $email",
];
$sent = @mail($TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), "-f$FROM");

out($sent || $fh ? true : false, ($sent || $fh) ? 200 : 500);
