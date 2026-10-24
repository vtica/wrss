<?php
// Uses FreshRSS's native FeedDAO and faviconPrepare() so filename hashes match 100%
require_once('/var/www/FreshRSS/constants.php');
require_once(LIB_PATH . '/lib_rss.php');

$configFile = DATA_PATH . '/config.php';
if (!file_exists($configFile)) {
    exit(0);
}

try {
    FreshRSS_Context::initSystem();
    $user = FreshRSS_Context::systemConf()->default_user;
    if (empty($user)) {
        exit(0);
    }

    FreshRSS_Context::initUser($user);
    if (FreshRSS_Context::$user_conf === null) {
        exit(0);
    }

    $feedDao = FreshRSS_Factory::createFeedDao($user);
    $feeds = $feedDao->listFeeds();

    $restoredCount = 0;
    foreach ($feeds as $feed) {
        // 1. Let FreshRSS natively generate the exact /data/favicons/<hash>.txt pointer
        $feed->faviconPrepare();

        // 2. Get the exact hash FreshRSS expects for this feed
        $hash = method_exists($feed, 'hashFavicon') ? $feed->hashFavicon() : $feed->hash();
        $icoFile = FAVICONS_DIR . $hash . '.ico';
        $txtFile = FAVICONS_DIR . $hash . '.txt';

        // 3. Pre-download the .ico into that exact filename if it doesn't exist yet
        if (!file_exists($icoFile) && file_exists($txtFile)) {
            $website = trim(@file_get_contents($txtFile));
            $feedHost = parse_url($website, PHP_URL_HOST);
            if ($feedHost) {
                $ddgUrl = "https://icons.duckduckgo.com/ip3/{$feedHost}.ico";
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 3,
                        'ignore_errors' => false,
                        'header' => "User-Agent: Mozilla/5.0\r\n"
                    ]
                ]);
                $icoData = @file_get_contents($ddgUrl, false, $ctx);
                if ($icoData !== false && strlen($icoData) > 0) {
                    file_put_contents($icoFile, $icoData);
                    @chown($icoFile, 'www-data');
                    $restoredCount++;
                }
            }
        }
        @chown($txtFile, 'www-data');
    }
    echo "✅ Favicons restored natively ({$restoredCount} icons fetched).\n";
} catch (Throwable $e) {
    echo "⚠️ Favicon restore skipped: " . $e->getMessage() . "\n";
}
