<?php
/* =====================================================================
   Приём заявок с сайта TOTAL → Telegram, MAX и почта.

   НАСТРОЙКА (заполнить после создания ботов):
   1. TG_TOKEN      — токен Telegram-бота от @BotFather.
   2. TG_CHAT_IDS   — кому слать. Не знаете? Оставьте пустым, напишите боту
                      (или в группу с ботом) любое сообщение и откройте
                      https://ВАШ-ДОМЕН/send.php?setup=tg — там будут ID.
   3. MAX_TOKEN     — токен бота MAX (business.max.ru → Чат-боты → Интеграция).
   4. MAX_USER_IDS  — ID получателя в MAX. Напишите боту в MAX и откройте
                      https://ВАШ-ДОМЕН/send.php?setup=max
   5. MAIL_TO       — почта для копии заявок (пусто = не слать).
   Подсказка ?setup=… работает, только пока список получателей пустой.
   ===================================================================== */

const TG_TOKEN      = '';
const TG_CHAT_IDS   = [];          // например: ['123456789'] или ['-1001234567890']
const MAX_TOKEN     = '';
const MAX_USER_IDS  = [];          // например: [123456789]
const MAX_CHAT_IDS  = [];          // для группового чата в MAX (необязательно)
const MAIL_TO       = 'TOTALGROUP38@gmail.com';
const SITE_NAME     = 'TOTAL';

/* --------------------------------------------------------------------- */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function http_json($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res ? json_decode($res, true) : null];
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ---------- Помощник настройки: показать ID чатов ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $setup = $_GET['setup'] ?? '';
    if ($setup === 'tg' && TG_TOKEN && !TG_CHAT_IDS) {
        [, $r] = http_json('GET', 'https://api.telegram.org/bot' . TG_TOKEN . '/getUpdates');
        $chats = [];
        foreach (($r['result'] ?? []) as $u) {
            $m = $u['message'] ?? $u['my_chat_member'] ?? $u['channel_post'] ?? null;
            if (!$m || empty($m['chat'])) continue;
            $c = $m['chat'];
            $chats[$c['id']] = trim(($c['title'] ?? '') . ' ' . ($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '') . ' (' . $c['type'] . ')');
        }
        out(['подсказка' => 'Скопируйте нужный ID в TG_CHAT_IDS. Пусто? Напишите боту /start и обновите страницу.', 'чаты' => $chats]);
    }
    if ($setup === 'max' && MAX_TOKEN && !MAX_USER_IDS && !MAX_CHAT_IDS) {
        [, $r] = http_json('GET', 'https://platform-api.max.ru/updates', ['Authorization: ' . MAX_TOKEN]);
        $ids = [];
        foreach (($r['updates'] ?? []) as $u) {
            $snd = $u['message']['sender'] ?? $u['user'] ?? null;
            if ($snd && isset($snd['user_id'])) $ids['user_id ' . $snd['user_id']] = $snd['name'] ?? ($snd['first_name'] ?? '');
            $chat = $u['message']['recipient']['chat_id'] ?? $u['chat_id'] ?? null;
            if ($chat) $ids['chat_id ' . $chat] = 'чат';
        }
        out(['подсказка' => 'user_id → в MAX_USER_IDS (или chat_id → в MAX_CHAT_IDS). Пусто? Напишите боту в MAX и обновите страницу.', 'id' => $ids]);
    }
    out(['ok' => false, 'error' => 'Используйте форму на сайте.'], 405);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false], 405);

/* ---------- Разбор заявки ---------- */
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(['ok' => false, 'error' => 'Неверный формат'], 400);

// Ловушка для спам-ботов: скрытое поле должно быть пустым
if (!empty($in['website'])) out(['ok' => true]);

$clean = fn($v, $n = 500) => mb_substr(trim(strip_tags((string)$v)), 0, $n);
$form   = $clean($in['form'] ?? 'Заявка', 100);
$fields = [];
foreach ((array)($in['fields'] ?? []) as $p) {
    if (!is_array($p) || count($p) < 2) continue;
    $k = $clean($p[0], 80); $v = $clean($p[1], 1000);
    if ($k !== '' && $v !== '') $fields[$k] = $v;
}
$phone = preg_replace('/\D/', '', $fields['Телефон'] ?? '');
if (strlen($phone) < 10 || empty($fields['Имя'])) out(['ok' => false, 'error' => 'Укажите имя и телефон'], 422);

// Защита от повторной отправки: не чаще 1 заявки в 20 секунд с одного адреса
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0';
$lock = sys_get_temp_dir() . '/total_lead_' . md5($ip);
if (is_file($lock) && time() - filemtime($lock) < 20) out(['ok' => false, 'error' => 'Подождите немного и отправьте ещё раз'], 429);
@touch($lock);

/* ---------- Текст сообщения ---------- */
date_default_timezone_set('Asia/Irkutsk');
$lines = ["📩 Новая заявка с сайта " . SITE_NAME, "Форма: $form", ''];
foreach ($fields as $k => $v) $lines[] = "$k: $v";
$lines[] = '';
$lines[] = 'Время: ' . date('d.m.Y H:i') . ' (Иркутск)';
$text = implode("\n", $lines);

/* ---------- Отправка ---------- */
$sent = 0; $tried = 0;

if (TG_TOKEN) foreach (TG_CHAT_IDS as $id) {
    $tried++;
    [$c] = http_json('POST', 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage',
        [], ['chat_id' => $id, 'text' => $text, 'disable_web_page_preview' => true]);
    if ($c === 200) $sent++;
}

if (MAX_TOKEN) {
    foreach (MAX_USER_IDS as $id) {
        $tried++;
        [$c] = http_json('POST', 'https://platform-api.max.ru/messages?user_id=' . urlencode($id),
            ['Authorization: ' . MAX_TOKEN], ['text' => $text]);
        if ($c >= 200 && $c < 300) $sent++;
    }
    foreach (MAX_CHAT_IDS as $id) {
        $tried++;
        [$c] = http_json('POST', 'https://platform-api.max.ru/messages?chat_id=' . urlencode($id),
            ['Authorization: ' . MAX_TOKEN], ['text' => $text]);
        if ($c >= 200 && $c < 300) $sent++;
    }
}

if (MAIL_TO) {
    $tried++;
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $subj = '=?UTF-8?B?' . base64_encode("Заявка с сайта: $form") . '?=';
    $hdrs = "From: " . SITE_NAME . " <no-reply@$host>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    if (@mail(MAIL_TO, $subj, $text, $hdrs)) $sent++;
}

if (!$tried) out(['ok' => false, 'error' => 'Отправка заявок ещё не настроена'], 503);
if (!$sent)  out(['ok' => false, 'error' => 'Не удалось отправить заявку'], 502);
out(['ok' => true]);
