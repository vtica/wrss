<?php
// Runs after do-install.php and create-user.php:
// 1. Reads metadata.json from all installed extensions in /extensions/ and enables them in both system & user config.php
// 2. Uses FreshRSS's native FeedDAO and faviconPrepare() to restore all feed favicons

require_once('/var/www/FreshRSS/constants.php');
require_once(LIB_PATH . '/lib_rss.php');

$sysConfigFile = DATA_PATH . '/config.php';
if (!file_exists($sysConfigFile)) {
    exit(0);
}

try {
    // --- PART 1: AUTO-ENABLE ALL INSTALLED EXTENSIONS ---
    $sysConfig = include($sysConfigFile);
    $user = $sysConfig['default_user'] ?? '';

    $extDir = EXTENSIONS_PATH;
    $enabledExtensions = [];

    if (is_dir($extDir)) {
        $entries = scandir($extDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $metaFile = $extDir . '/' . $entry . '/metadata.json';
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if (!empty($meta['name'])) {
                    $enabledExtensions[$meta['name']] = true;
                }
            }
        }
    }

    if (!empty($enabledExtensions)) {
        // 1A. Write enabled extensions to System config.php
        if (!isset($sysConfig['extensions_enabled']) || !is_array($sysConfig['extensions_enabled'])) {
            $sysConfig['extensions_enabled'] = [];
        }
        foreach ($enabledExtensions as $extName => $status) {
            $sysConfig['extensions_enabled'][$extName] = true;
        }
        $sysContent = "<?php\nreturn " . var_export($sysConfig, true) . ";\n";
        file_put_contents($sysConfigFile, $sysContent);
        @chown($sysConfigFile, 'www-data');

        // 1B. Write enabled extensions to User config.php
        if (!empty($user)) {
            $userConfigFile = USERS_PATH . '/' . $user . '/config.php';
            if (file_exists($userConfigFile)) {
                $userConfig = include($userConfigFile);
                if (!isset($userConfig['extensions_enabled']) || !is_array($userConfig['extensions_enabled'])) {
                    $userConfig['extensions_enabled'] = [];
                }
                foreach ($enabledExtensions as $extName => $status) {
                    $userConfig['extensions_enabled'][$extName] = true;
                }
                $userContent = "<?php\nreturn " . var_export($userConfig, true) . ";\n";
                file_put_contents($userConfigFile, $userContent);
                @chown($userConfigFile, 'www-data');
            }
        }
        echo "✅ Enabled extensions: " . implode(', ', array_keys($enabledExtensions)) . "\n";
    }

    // --- PART 2: RESTORE FAVICONS ---
    if (empty($user)) {
        exit(0);
    }

    FreshRSS_Context::initSystem(true);
    FreshRSS_Context::initUser($user);
    if (FreshRSS_Context::$user_conf === null) {
        exit(0);
    }

    $feedDao = FreshRSS_Factory::createFeedDao($user);
    $feeds = $feedDao->listFeeds();

    $restoredCount = 0;
    foreach ($feeds as $feed) {
        $feed->faviconPrepare();
        $hash = method_exists($feed, 'hashFavicon') ? $feed->hashFavicon() : $feed->hash();
        $icoFile = FAVICONS_DIR . $hash . '.ico';
        $txtFile = FAVICONS_DIR . $hash . '.txt';

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
    echo "⚠️ Post-boot restore error: " . $e->getMessage() . "\n";
}
