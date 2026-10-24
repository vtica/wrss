<?php
// Lightweight keep-alive & non-blocking background feed actualizer endpoint
// Accessible at: https://rss.web2.news/ping.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
http_response_code(200);

$lockFile = '/tmp/freshrss_last_actualize.lock';
$intervalSeconds = 600; // Trigger background feed refresh at most once every 10 minutes
$now = time();
$lastRun = file_exists($lockFile) ? (int)@file_get_contents($lockFile) : 0;
$triggeredRefresh = false;

if (($now - $lastRun) >= $intervalSeconds) {
    @file_put_contents($lockFile, (string)$now);
    // Run FreshRSS feed actualizer asynchronously in the background so the HTTP response is instant
    exec('php /var/www/FreshRSS/app/actualize_script.php > /tmp/freshrss_actualize.log 2>&1 &');
    $triggeredRefresh = true;
}

echo json_encode([
    'status' => 'ok',
    'service' => 'FreshRSS',
    'timestamp' => $now,
    'actualize_triggered' => $triggeredRefresh,
], JSON_UNESCAPED_SLASHES);
