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

// Таблица для Excel: data/zayavki.xlsx, все заявки из requests-*.jsonl, новые сверху.
// Пересобирается после каждой заявки; скачивается из менеджера файлов хостинга.
function zayavki_xlsx(string $dataDir): bool
{
    if (!class_exists('ZipArchive')) return false;
    $rows = [];
    foreach (glob($dataDir . '/requests-*.jsonl') ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            if (is_array($r)) $rows[] = $r;
        }
    }
    usort($rows, function ($a, $b) { return strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')); });

    $x = function (string $s): string {
        return htmlspecialchars(str_replace("\r\n", "\n", $s), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $cell = function (string $ref, string $v, int $style) use ($x): string {
        return "<c r=\"$ref\" t=\"inlineStr\" s=\"$style\"><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
    };
    $head = ['№', 'Дата', 'Время', 'Имя', 'Контакт', 'Кто пишет', 'Комментарий'];
    $cols = range('A', 'G');
    $sheet = '<row r="1">';
    foreach ($head as $i => $h) $sheet .= $cell($cols[$i] . '1', $h, 2);
    $sheet .= '</row>';
    $n = count($rows);
    foreach ($rows as $i => $r) {
        $row = $i + 2;
        try { $t = new DateTime((string)($r['time'] ?? '')); } catch (Exception $e) { $t = null; }
        $vals = [$t ? $t->format('d.m.Y') : '', $t ? $t->format('H:i') : '', (string)($r['name'] ?? ''),
            (string)($r['contact'] ?? ''), (string)($r['who'] ?? ''), (string)($r['msg'] ?? '')];
        $sheet .= "<row r=\"$row\"><c r=\"A$row\" s=\"1\"><v>" . ($n - $i) . '</v></c>';   // номер по порядку прихода
        foreach ($vals as $k => $v) $sheet .= $cell($cols[$k + 1] . $row, $v, 1);
        $sheet .= '</row>';
    }
    $last = $n + 1;
    $widths = [6, 12, 8, 20, 24, 16, 70];
    $colsXml = '';
    foreach ($widths as $i => $w) $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . "\" width=\"$w\" customWidth=\"1\"/>";
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $nsR = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/workbook.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><workbook $ns $nsR><sheets><sheet name=\"Заявки\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
            . "<definedNames><definedName name=\"_xlnm._FilterDatabase\" localSheetId=\"0\" hidden=\"1\">'Заявки'!\$A\$1:\$G\$$last</definedName></definedNames></workbook>",
        'xl/styles.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><styleSheet $ns>"
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1E7DD"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?><worksheet $ns $nsR>"
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . "<sheetFormatPr defaultRowHeight=\"15\"/><cols>$colsXml</cols><sheetData>$sheet</sheetData><autoFilter ref=\"A1:G$last\"/></worksheet>",
    ];
    $tmp = $dataDir . '/zayavki.tmp';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) return false;
    foreach ($files as $name => $xml) $zip->addFromString($name, $xml);
    if (!$zip->close()) return false;
    return @rename($tmp, $dataDir . '/zayavki.xlsx');
}

$private = dirname(__DIR__, 2) . '/shtorme-private';
if (PHP_SAPI === 'cli') exit(zayavki_xlsx($private . '/data') ? 0 : 1);   // пересобрать таблицу вручную: php send.php

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') done(false, 405);

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
elseif (!zayavki_xlsx($dataDir)) $log('xlsx failed');

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

// 3. Telegram. Режим signal (по умолчанию): только сигнал без имени, телефона и комментария, трансграничной передачи нет.
//    Режим full: заявка целиком (тогда нужно уведомление в РКН о трансграничной передаче)
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
if (($cfg['telegram_mode'] ?? 'signal') !== 'full') {
    // направление берём, только если комментарий начинается с подстановки из карточки, и только из известного списка
    $dirs = ['портьеры и шторы', 'римские шторы', 'жалюзи и рулонные шторы', 'декоративный текстиль', 'мягкая мебель на заказ'];
    $dir = preg_match('/^Интересует: (' . implode('|', array_map('preg_quote', $dirs)) . ')/u', $msg, $m) ? $m[1] : '';
    $text = "<b>Новая заявка с сайта</b>\n\n<i>Кто пишет:</i>  $who" . ($dir !== '' ? "\n<i>Интересует:</i>  $dir" : '')
        . ($mailed ? "\n\nИмя, телефон и комментарий в почте " . $h((string)$cfg['mail_to'])
                   : "\n\nПисьмо на почту не отправилось. Заявка сохранена на сервере, сообщите разработчику")
        . "\n\n<i>$when · shtorme.ru</i>";
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
