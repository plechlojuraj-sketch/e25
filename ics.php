<?php
/*
 * ics.php — Villa E25 availability feed
 * ---------------------------------------------------------------
 * Upload this file next to index.html on e25.at.
 *
 * Why it exists: a web page is not allowed to read Google's calendar
 * directly (the browser blocks it as a cross-site request). This file sits
 * on your own server, fetches the calendar there, and hands it to the page.
 *
 * TWO MODES
 *
 *   Without an API key  — reads Google's public .ics feed. Simple, but
 *                         Google regenerates that file only every few
 *                         minutes, so changes take 15-30 min to appear.
 *
 *   With an API key     — reads the Calendar API instead. No feed lag:
 *                         a date you block in Google Calendar shows up on
 *                         the website within seconds.
 *
 * To switch to the fast mode, paste your key into $API_KEY below.
 * The key stays here on your server — it is never sent to visitors.
 * ---------------------------------------------------------------
 */

$CALENDAR_ID   = '44902e7f5bd40973d3b0b790a16b0a3ff71838b79903b77756726e6cc9207a8d@group.calendar.google.com';

$API_KEY       = '';    // <-- paste your key here, OR better: put it in
                        //     ics-key.php (see below) so it is never committed

/* If a file called ics-key.php sits next to this one, it wins. Keep your key
   there — it is git-ignored, so it can never end up in a public repository.
   The whole file is just:      <?php $API_KEY = 'AIza...';                  */
if (is_readable(__DIR__ . '/ics-key.php')) { include __DIR__ . '/ics-key.php'; }

$CACHE_SECONDS = 10;    // how long to hold a copy. 0 = never cache.
                        // 60 is fine with a key; without one, 900 is plenty
                        // since Google's own feed is slower than that anyway.

/* ------------------------------------------------------------------ */

$mode = $API_KEY ? 'api' : 'feed';
if ($mode === 'feed' && $CACHE_SECONDS < 900) { $CACHE_SECONDS = 900; }

$cacheFile = sys_get_temp_dir() . '/e25-' . md5($CALENDAR_ID . $mode) . '.ics';
$ping      = isset($_GET['ping']);

function http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'e25.at availability',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code === 200) return $body;
        if ($body !== false) return ['error' => $code, 'body' => $body];
    }
    if (ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'e25.at availability', 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) return $body;
    }
    return false;
}

/* Turn Calendar API JSON into the same .ics text the page already reads. */
function api_to_ics($json) {
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['items'])) return false;
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//e25.at//availability//EN\r\n";
    foreach ($data['items'] as $ev) {
        if (isset($ev['status']) && $ev['status'] === 'cancelled') continue;
        $sum = isset($ev['summary']) ? str_replace(["\r", "\n", ','], ['', ' ', '\\,'], $ev['summary']) : '';
        $out .= "BEGIN:VEVENT\r\n";
        if (isset($ev['start']['date'])) {
            $out .= 'DTSTART;VALUE=DATE:' . str_replace('-', '', $ev['start']['date']) . "\r\n";
            $end  = isset($ev['end']['date']) ? $ev['end']['date'] : $ev['start']['date'];
            $out .= 'DTEND;VALUE=DATE:' . str_replace('-', '', $end) . "\r\n";
        } elseif (isset($ev['start']['dateTime'])) {
            $s = gmdate('Ymd\THis\Z', strtotime($ev['start']['dateTime']));
            $e = gmdate('Ymd\THis\Z', strtotime(isset($ev['end']['dateTime']) ? $ev['end']['dateTime'] : $ev['start']['dateTime']));
            $out .= "DTSTART:$s\r\nDTEND:$e\r\n";
        } else {
            $out .= "END:VEVENT\r\n";
            continue;
        }
        $out .= "SUMMARY:$sum\r\nSTATUS:CONFIRMED\r\nEND:VEVENT\r\n";
    }
    return $out . "END:VCALENDAR\r\n";
}

/* ---- serve from cache when fresh ---- */
if (!$ping && $CACHE_SECONDS > 0 && is_readable($cacheFile) && (time() - filemtime($cacheFile) < $CACHE_SECONDS)) {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: public, max-age=' . $CACHE_SECONDS);
    header('X-E25-Source: cache-' . $mode);
    readfile($cacheFile);
    exit;
}

/* ---- fetch fresh ---- */
$err = null;
if ($mode === 'api') {
    $from = gmdate('Y-m-d\TH:i:s\Z', strtotime('-1 month'));
    $to   = gmdate('Y-m-d\TH:i:s\Z', strtotime('+18 months'));
    $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($CALENDAR_ID)
          . '/events?key=' . rawurlencode($API_KEY)
          . '&singleEvents=true&orderBy=startTime&maxResults=2500'
          . '&timeMin=' . rawurlencode($from) . '&timeMax=' . rawurlencode($to);
    $raw = http_get($url);
    if (is_array($raw)) {
        $msg = json_decode($raw['body'], true);
        $err = 'Calendar API said ' . $raw['error'] . ': '
             . (isset($msg['error']['message']) ? $msg['error']['message'] : 'rejected');
        $data = false;
    } else {
        $data = $raw === false ? false : api_to_ics($raw);
        if ($data === false && $raw !== false) $err = 'API replied but the answer could not be read.';
    }
} else {
    $url  = 'https://calendar.google.com/calendar/ical/' . rawurlencode($CALENDAR_ID) . '/public/basic.ics';
    $raw  = http_get($url);
    if (is_array($raw)) { $err = 'Google said HTTP ' . $raw['error']; $data = false; }
    else { $data = $raw; }
}

if ($data !== false && strpos($data, 'BEGIN:VCALENDAR') === false) { $data = false; $err = $err ?: 'The reply was not a calendar.'; }

/* ---- status page: /ics.php?ping ---- */
if ($ping) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Villa E25 calendar feed\n";
    echo "mode:      $mode" . ($mode === 'api' ? '  (fast — updates within seconds)' : '  (public feed — 15-30 min behind)') . "\n";
    echo "cache:     {$CACHE_SECONDS}s\n";
    echo "php curl:  " . (function_exists('curl_init') ? 'yes' : 'no') . "\n";
    if ($data !== false) {
        echo "result:    OK, " . substr_count($data, 'BEGIN:VEVENT') . " events, " . strlen($data) . " bytes\n";
    } else {
        echo "result:    FAILED — " . ($err ?: 'no answer from Google') . "\n";
        echo "\nIf the calendar is not shared publicly, nothing can read it:\n";
        echo "Google Calendar > Settings > this calendar > Access permissions >\n";
        echo "tick 'Make available to public'.\n";
    }
    exit;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Cache-Control: public, max-age=' . max($CACHE_SECONDS, 30));
header('X-E25-Source: ' . $mode);

if ($data === false) {
    if (is_readable($cacheFile)) { header('X-E25-Source: stale-' . $mode); readfile($cacheFile); exit; }
    http_response_code(502);
    echo "ERROR: could not read the calendar.\n" . ($err ? $err . "\n" : '');
    echo "Open /ics.php?ping in a browser for details.\n";
    exit;
}

if ($CACHE_SECONDS > 0) @file_put_contents($cacheFile, $data);
echo $data;
