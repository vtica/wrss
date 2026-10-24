<?php
// Handles:
// 1. Persisting & restoring full User + System configurations (themes, custom CSS, extension configs, reading prefs) in Aiven PostgreSQL
// 2. Auto-enabling all installed extensions in /extensions/
// 3. Restoring all feed favicons using FreshRSS's exact hash formula: hash('crc32b', $salt . $feed_url)

$mode = $argv[1] ?? '--restore';

$dataPath = '/var/www/FreshRSS/data';
$extDir = '/var/www/FreshRSS/extensions';
$favDir = $dataPath . '/favicons/';
$sysConfigFile = $dataPath . '/config.php';

if (!file_exists($sysConfigFile)) {
    exit(0);
}

try {
    $sysConfig = include($sysConfigFile);
    $user = $sysConfig['default_user'] ?? '';
    $db = $sysConfig['db'] ?? [];

    if (empty($user) || empty($db['host']) || empty($db['base'])) {
        exit(0);
    }

    $userConfigFile = $dataPath . '/users/' . $user . '/config.php';

    // Connect to Aiven Database
    $dbType = $db['type'] ?? 'pgsql';
    $rawHost = $db['host'];
    $host = $rawHost;
    $port = '';

    if (strpos($rawHost, ':') !== false) {
        list($host, $port) = explode(':', $rawHost, 2);
    }

    $dsn = "{$dbType}:host={$host};" . ($port !== '' ? "port={$port};" : "") . "dbname={$db['base']}";
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $prefix = $db['prefix'] ?? '';
    $quote = ($dbType === 'mysql') ? '`' : '"';
    $stateTable = $prefix . 'persistent_state';

    // Ensure the persistent state table exists in Aiven
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$quote}{$stateTable}{$quote} (
        config_key VARCHAR(64) PRIMARY KEY,
        config_json TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Helper function to save current configs to Aiven
    $saveStateToDb = function ($pdo, $quote, $stateTable, $key, $dataArray) {
        $json = json_encode($dataArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO {$quote}{$stateTable}{$quote} (config_key, config_json, updated_at)
            VALUES (:k, :v, CURRENT_TIMESTAMP)
            ON CONFLICT (config_key) DO UPDATE SET config_json = EXCLUDED.config_json, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([':k' => $key, ':v' => $json]);
    };

    // =========================================================================
    // MODE A: REAL-TIME BACKGROUND SYNC (--sync)
    // Runs when you change your theme, Custom CSS, or settings in the Web UI
    // =========================================================================
    if ($mode === '--sync') {
        $lastSysMtime = file_exists($sysConfigFile) ? filemtime($sysConfigFile) : 0;
        $lastUserMtime = file_exists($userConfigFile) ? filemtime($userConfigFile) : 0;

        while (true) {
            sleep(5);
            clearstatcache();

            if (file_exists($sysConfigFile)) {
                $curSysMtime = filemtime($sysConfigFile);
                if ($curSysMtime > $lastSysMtime) {
                    $lastSysMtime = $curSysMtime;
                    $currentSys = include($sysConfigFile);
                    if (is_array($currentSys)) {
                        $saveStateToDb($pdo, $quote, $stateTable, 'system_config', $currentSys);
                        echo "💾 [Sync] System configuration backed up to Aiven DB.\n";
                    }
                }
            }

            if (file_exists($userConfigFile)) {
                $curUserMtime = filemtime($userConfigFile);
                if ($curUserMtime > $lastUserMtime) {
                    $lastUserMtime = $curUserMtime;
                    $currentUser = include($userConfigFile);
                    if (is_array($currentUser)) {
                        $saveStateToDb($pdo, $quote, $stateTable, 'user_config_' . $user, $currentUser);
                        echo "💾 [Sync] User configuration (theme/settings) backed up to Aiven DB.\n";
                    }
                }
            }
        }
        exit(0);
    }

    // =========================================================================
    // MODE B: BOOT-TIME RESTORE (--restore)
    // Runs on every Render deploy before Apache starts
    // =========================================================================

    // 1. Scan all installed extensions in /extensions/
    $installedExtensions = [];
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
                    $installedExtensions[$meta['name']] = true;
                }
            }
        }
    }

    // 2. Restore System Config from Aiven DB (if a backup exists)
    $stmtSys = $pdo->prepare("SELECT config_json FROM {$quote}{$stateTable}{$quote} WHERE config_key = :k");
    $stmtSys->execute([':k' => 'system_config']);
    $savedSysRow = $stmtSys->fetch(PDO::FETCH_ASSOC);

    if ($savedSysRow && !empty($savedSysRow['config_json'])) {
        $savedSys = json_decode($savedSysRow['config_json'], true);
        if (is_array($savedSys)) {
            // Preserve live DB connection and base_url from Render env vars, restore everything else (including salt!)
            $savedSys['db'] = $sysConfig['db'];
            $savedSys['default_user'] = $sysConfig['default_user'];
            if (!empty($sysConfig['base_url'])) {
                $savedSys['base_url'] = $sysConfig['base_url'];
            }
            $sysConfig = array_replace_recursive($sysConfig, $savedSys);
            echo "✅ Restored System config (and persistent login salt) from Aiven DB.\n";
        }
    }

    // Ensure all installed extensions are enabled in System config
    if (!isset($sysConfig['extensions_enabled']) || !is_array($sysConfig['extensions_enabled'])) {
        $sysConfig['extensions_enabled'] = [];
    }
    foreach ($installedExtensions as $extName => $status) {
        if (!isset($sysConfig['extensions_enabled'][$extName])) {
            $sysConfig['extensions_enabled'][$extName] = true;
        }
    }

    file_put_contents($sysConfigFile, "<?php\nreturn " . var_export($sysConfig, true) . ";\n");
    @chown($sysConfigFile, 'www-data');
    $saveStateToDb($pdo, $quote, $stateTable, 'system_config', $sysConfig);

    // 3. Restore User Config (Theme, Custom CSS, Reading Prefs, Extension Settings) from Aiven DB
    if (file_exists($userConfigFile)) {
        $userConfig = include($userConfigFile);
        $envPasswordHash = $userConfig['passwordHash'] ?? '';

        $stmtUser = $pdo->prepare("SELECT config_json FROM {$quote}{$stateTable}{$quote} WHERE config_key = :k");
        $stmtUser->execute([':k' => 'user_config_' . $user]);
        $savedUserRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($savedUserRow && !empty($savedUserRow['config_json'])) {
            $savedUser = json_decode($savedUserRow['config_json'], true);
            if (is_array($savedUser)) {
                $userConfig = array_replace_recursive($userConfig, $savedUser);
                // Always keep the passwordHash from FRESHRSS_USER env var so you never get locked out
                if (!empty($envPasswordHash)) {
                    $userConfig['passwordHash'] = $envPasswordHash;
                }
                echo "✅ Restored User config (theme: " . ($userConfig['theme'] ?? 'default') . ") from Aiven DB.\n";
            }
        }

        // Enable any newly added extensions that aren't in the user config yet
        if (!isset($userConfig['extensions_enabled']) || !is_array($userConfig['extensions_enabled'])) {
            $userConfig['extensions_enabled'] = [];
        }
        foreach ($installedExtensions as $extName => $status) {
            if (!isset($userConfig['extensions_enabled'][$extName])) {
                $userConfig['extensions_enabled'][$extName] = true;
            }
        }

        file_put_contents($userConfigFile, "<?php\nreturn " . var_export($userConfig, true) . ";\n");
        @chown($userConfigFile, 'www-data');
        $saveStateToDb($pdo, $quote, $stateTable, 'user_config_' . $user, $userConfig);
    }

    // 4. Restore Favicons using the restored persistent salt
    $salt = $sysConfig['salt'] ?? '';
    if (!is_dir($favDir)) {
        mkdir($favDir, 0775, true);
    }

    $feedTable = $prefix . $user . '_feed';
    $stmtFeeds = $pdo->query("SELECT id, url, website FROM {$quote}{$feedTable}{$quote}");

    $restoredCount = 0;
    while ($row = $stmtFeeds->fetch(PDO::FETCH_ASSOC)) {
        $feedUrl = $row['url'] ?? '';
        $website = !empty($row['website']) ? $row['website'] : $feedUrl;
        if (empty($feedUrl) || empty($website)) {
            continue;
        }

        $hash = hash('crc32b', $salt . $feedUrl);
        $txtFile = $favDir . $hash . '.txt';
        $icoFile = $favDir . $hash . '.ico';

        if (!file_exists($txtFile)) {
            file_put_contents($txtFile, $website);
            @chown($txtFile, 'www-data');
        }

        if (!file_exists($icoFile)) {
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
    }
    echo "✅ Favicons restored ({$restoredCount} icons fetched).\n";
} catch (Throwable $e) {
    echo "⚠️ Post-boot restore/sync error: " . $e->getMessage() . "\n";
}
