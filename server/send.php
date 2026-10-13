<?php
// Приём заявок с формы shtorme.ru.
// Порядок: проверки → запись на сервере в РФ (требование 152-ФЗ) → письмо на почту → сообщение в Telegram.
// Настройки и токен бота лежат вне папки сайта: ~/shtorme-private/config.php (пример: config.example.php).
// Ответ: {"ok": true|false}. Заявка считается принятой, если дошла хотя бы куда-то (файл, почта или Telegram).
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function done(bool $ok, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') done(false, 405);

$private = dirname(__DIR__, 2) . '/shtorme-private';
$cfg = @include $private . '/config.php';
if (!is_array($cfg)) done(false, 500);

$dataDir = $private . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0700, true);
$log = function (string $what) use ($dataDir): void {        // только факт сбоя, без персональных данных
    @file_put_contents($dataDir . '/errors.log', date('c') . ' ' . $what . "\n", FILE_APPEND | LOCK_EX);
};

// Запрос должен прийти со своего сайта
$origin = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
if ($origin && !in_array(strtolower($origin), $cfg['origins'] ?? [], true)) done(false, 403);

// Ловушки для ботов: скрытое поле заполнено или форму «заполнили» быстрее 3 секунд. Боту отвечаем «ок» и ничего не делаем
if (trim((string)($_POST['website'] ?? '')) !== '' || (int)($_POST['t'] ?? 0) < 3) done(true);

$field = function (string $key, int $max): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);   // служебные символы
    return mb_substr($v, 0, $max);
};
$name = $field('name', 80);
$contact = $field('contact', 80);
$msg = $field('msg', 2000);
$who = ($_POST['who'] ?? '') === 'designer' ? 'дизайнер' : 'частный клиент';
if (mb_strlen($name) < 2 || mb_strlen($contact) < 5 || empty($_POST['agree'])) done(false, 422);

// Не больше 5 заявок за 10 минут с одного адреса (адрес храним только в виде хеша)
$now = time();
$ipKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($cfg['salt'] ?? ''));
$rateFile = $dataDir . '/rate.json';
$rate = json_decode((string)@file_get_contents($rateFile), true);
$rate = is_array($rate) ? $rate : [];
foreach ($rate as $k => $times) {
    $rate[$k] = array_values(array_filter((array)$times, function ($t) use ($now) { return $t > $now - 600; }));
    if (!$rate[$k]) unset($rate[$k]);
}
if (count($rate[$ipKey] ?? []) >= 5) done(false, 429);
$rate[$ipKey][] = $now;
@file_put_contents($rateFile, json_encode($rate), LOCK_EX);

$at = new DateTime('now', new DateTimeZone('Europe/Moscow'));
$when = $at->format('d.m.Y, H:i') . ' МСК';

// 1. Запись на сервере (первичная запись в РФ). Хранится не дольше 3 лет, см. политику
$record = ['time' => $at->format('c'), 'name' => $name, 'contact' => $contact, 'who' => $who, 'msg' => $msg];
$saved = @file_put_contents($dataDir . '/requests-' . $at->format('Y-m') . '.jsonl',
    json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
if (!$saved) $log('save failed');

// 2. Копия на почту
$mailed = false;
if (!empty($cfg['mail_to'])) {
    $b64 = function (string $s): string { return '=?UTF-8?B?' . base64_encode($s) . '?='; };
    $body = "Новая заявка с сайта shtorme.ru\n\nИмя: $name\nСвязь: $contact\nКто пишет: $who\nКомментарий: "
        . ($msg !== '' ? $msg : 'нет') . "\n\n$when";
    $headers = 'From: ' . $b64('Сайт Шторме') . ' <' . $cfg['mail_from'] . ">\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64";
    $mailed = @mail($cfg['mail_to'], $b64('Новая заявка с сайта: ' . $name), chunk_split(base64_encode($body)), $headers, '-f' . $cfg['mail_from']);
    if (!$mailed) $log('mail failed');
}

// 3. Telegram. Режим full: заявка целиком; signal: только сигнал без контактов (если Роскомнадзор возразит против передачи)
$post = function (string $url, array $fields): ?array {
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
        $r = curl_exec($c);
        curl_close($c);
    } else {
        $r = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body, 'ignore_errors' => true]]));
    }
    $j = is_string($r) ? json_decode($r, true) : null;
    return is_array($j) ? $j : null;
};
$h = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
if (($cfg['telegram_mode'] ?? 'full') === 'signal') {
    $text = "<b>Новая заявка с сайта</b>\n<i>Кто пишет:</i>  $who\n\nКонтакты и комментарий в почте " . $h((string)$cfg['mail_to']);
} else {
    $text = "<b>Новая заявка с сайта</b>\n\n<i>Имя:</i>  " . $h($name) . "\n<i>Связь:</i>  " . $h($contact)
        . "\n<i>Кто пишет:</i>  $who" . ($msg !== '' ? "\n<i>Комментарий:</i>  " . $h($msg) : '')
        . "\n\n<i>$when · shtorme.ru</i>";
}
$sentTg = false;
if (!empty($cfg['tg_token'])) {
    $chatFile = $dataDir . '/chat_id.txt';                    // группа могла стать супергруппой: тогда id меняется
    $chat = trim((string)@file_get_contents($chatFile)) ?: (string)$cfg['tg_chat'];
    $api = 'https://api.telegram.org/bot' . $cfg['tg_token'] . '/sendMessage';
    $send = function (string $chat) use ($post, $api, $text): ?array {
        return $post($api, ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
    };
    $res = $send($chat);
    $moved = $res['parameters']['migrate_to_chat_id'] ?? null;
    if (empty($res['ok']) && $moved) {
        $chat = (string)$moved;
        @file_put_contents($chatFile, $chat, LOCK_EX);
        $res = $send($chat);
    }
    $sentTg = !empty($res['ok']);
    if (!$sentTg) $log('telegram failed: ' . ($res['description'] ?? 'no response'));
}

done($saved || $mailed || $sentTg, $saved || $mailed || $sentTg ? 200 : 502);
