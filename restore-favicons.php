<?php
// Handles:
// 1. Persisting & restoring full User + System configurations in Aiven PostgreSQL (with strict curl_options and array sanitization)
// 2. Persisting & restoring the entire /data/favicons/ directory in Aiven PostgreSQL
// 3. Auto-enabling all valid installed extensions in /extensions/ and stripping invalid/corrupted keys

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
    if (!is_array($sysConfig)) {
        exit(0);
    }

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
    $favTable = $prefix . 'persistent_favicons';

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$quote}{$stateTable}{$quote} (
        config_key VARCHAR(64) PRIMARY KEY,
        config_json TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$quote}{$favTable}{$quote} (
        filename VARCHAR(128) PRIMARY KEY,
        file_b64 TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Sanitize curl_options so invalid keys (like 0 or 1 from JSON/array re-indexing) never crash curl_setopt_array()
    $sanitizeCurlOptions = function (&$cfg) {
        $validCurlOpts = [
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
        ];
        if (isset($cfg['curl_options']) && is_array($cfg['curl_options'])) {
            $testCh = curl_init();
            foreach ($cfg['curl_options'] as $k => $v) {
                if (is_int($k) && $k > 0 && $testCh !== false && @curl_setopt($testCh, $k, $v)) {
                    $validCurlOpts[$k] = $v;
                }
            }
            if ($testCh !== false) {
                curl_close($testCh);
            }
        }
        $cfg['curl_options'] = $validCurlOpts;
    };

    $saveStateToDb = function ($pdo, $quote, $stateTable, $key, $dataArray) {
        $json = json_encode($dataArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO {$quote}{$stateTable}{$quote} (config_key, config_json, updated_at)
            VALUES (:k, :v, CURRENT_TIMESTAMP)
            ON CONFLICT (config_key) DO UPDATE SET config_json = EXCLUDED.config_json, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([':k' => $key, ':v' => $json]);
    };

    $saveFaviconToDb = function ($pdo, $quote, $favTable, $filename, $fullPath) {
        if (!is_file($fullPath)) {
            return false;
        }
        $raw = @file_get_contents($fullPath);
        if ($raw === false || strlen($raw) === 0) {
            return false;
        }
        $b64 = base64_encode($raw);
        $stmt = $pdo->prepare("INSERT INTO {$quote}{$favTable}{$quote} (filename, file_b64, updated_at)
            VALUES (:f, :b, CURRENT_TIMESTAMP)
            ON CONFLICT (filename) DO UPDATE SET file_b64 = EXCLUDED.file_b64, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([':f' => $filename, ':b' => $b64]);
        return true;
    };

    // =========================================================================
    // MODE A: REAL-TIME BACKGROUND SYNC (--sync)
    // =========================================================================
    if ($mode === '--sync') {
        $lastSysMtime = file_exists($sysConfigFile) ? filemtime($sysConfigFile) : 0;
        $lastUserMtime = file_exists($userConfigFile) ? filemtime($userConfigFile) : 0;
        $knownFavicons = [];

        if (is_dir($favDir)) {
            foreach (scandir($favDir) as $f) {
                if ($f === '.' || $f === '..' || $f === 'index.html') {
                    continue;
                }
                $p = $favDir . $f;
                if (is_file($p) && filesize($p) > 0) {
                    $knownFavicons[$f] = filemtime($p) . ':' . filesize($p);
                }
            }
        }

        while (true) {
            sleep(5);
            clearstatcache();

            if (file_exists($sysConfigFile)) {
                $curSysMtime = filemtime($sysConfigFile);
                if ($curSysMtime > $lastSysMtime) {
                    $currentSys = @include($sysConfigFile);
                    if (is_array($currentSys)) {
                        $sanitizeCurlOptions($currentSys);
                        file_put_contents($sysConfigFile, "<?php\nreturn " . var_export($currentSys, true) . ";\n");
                        @chown($sysConfigFile, 'www-data');
                        clearstatcache();
                        $lastSysMtime = filemtime($sysConfigFile);
                        $saveStateToDb($pdo, $quote, $stateTable, 'system_config', $currentSys);
                        echo "💾 [Sync] System configuration backed up to Aiven DB.\n";
                    } else {
                        $lastSysMtime = $curSysMtime;
                    }
                }
            }

            if (file_exists($userConfigFile)) {
                $curUserMtime = filemtime($userConfigFile);
                if ($curUserMtime > $lastUserMtime) {
                    $lastUserMtime = $curUserMtime;
                    $currentUser = @include($userConfigFile);
                    if (is_array($currentUser)) {
                        $saveStateToDb($pdo, $quote, $stateTable, 'user_config_' . $user, $currentUser);
                        echo "💾 [Sync] User configuration (theme/settings) backed up to Aiven DB.\n";
                    }
                }
            }

            if (is_dir($favDir)) {
                $newFavsSynced = 0;
                foreach (scandir($favDir) as $f) {
                    if ($f === '.' || $f === '..' || $f === 'index.html') {
                        continue;
                    }
                    $p = $favDir . $f;
                    if (!is_file($p)) {
                        continue;
                    }
                    $size = filesize($p);
                    if ($size === 0) {
                        continue;
                    }
                    $sig = filemtime($p) . ':' . $size;
                    if (!isset($knownFavicons[$f]) || $knownFavicons[$f] !== $sig) {
                        if ($saveFaviconToDb($pdo, $quote, $favTable, $f, $p)) {
                            $knownFavicons[$f] = $sig;
                            $newFavsSynced++;
                        }
                    }
                }
                if ($newFavsSynced > 0) {
                    echo "💾 [Sync] Backed up {$newFavsSynced} favicon file(s) to Aiven DB.\n";
                }
            }
        }
        exit(0);
    }

    // =========================================================================
    // MODE B: BOOT-TIME RESTORE (--restore)
    // =========================================================================

    // 1. Scan all currently installed extensions in /extensions/ and verify PHP syntax of extension.php
    $installedExtensions = [];
    if (is_dir($extDir)) {
        $entries = scandir($extDir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $extPath = $extDir . '/' . $entry;
            $metaFile = $extPath . '/metadata.json';
            $phpFile = $extPath . '/extension.php';

            if (file_exists($metaFile) && file_exists($phpFile)) {
                $lintOutput = [];
                $lintExit = 0;
                exec('php -l ' . escapeshellarg($phpFile) . ' 2>&1', $lintOutput, $lintExit);
                if ($lintExit !== 0) {
                    echo "⚠️ Syntax error in {$entry}/extension.php! Disabling broken extension: " . implode(' ', $lintOutput) . "\n";
                    @rename($metaFile, $metaFile . '.broken');
                    continue;
                }

                $meta = json_decode(file_get_contents($metaFile), true);
                if (is_array($meta) && !empty($meta['name']) && is_string($meta['name'])) {
                    $installedExtensions[$meta['name']] = true;
                }
            }
        }
    }

    // 2. Restore System Config from Aiven DB (with strict curl_options and auth_type sanitization)
    $stmtSys = $pdo->prepare("SELECT config_json FROM {$quote}{$stateTable}{$quote} WHERE config_key = :k");
    $stmtSys->execute([':k' => 'system_config']);
    $savedSysRow = $stmtSys->fetch(PDO::FETCH_ASSOC);

    if ($savedSysRow && !empty($savedSysRow['config_json'])) {
        $savedSys = json_decode($savedSysRow['config_json'], true);
        if (is_array($savedSys)) {
            $savedSys['db'] = $sysConfig['db'];
            $savedSys['default_user'] = $sysConfig['default_user'];
            if (!empty($sysConfig['base_url'])) {
                $savedSys['base_url'] = $sysConfig['base_url'];
            }
            if (empty($savedSys['auth_type']) || !in_array($savedSys['auth_type'], ['form', 'http_auth', 'none'], true)) {
                $savedSys['auth_type'] = 'form';
            }
            unset($savedSys['curl_options']);
            $sysConfig = array_replace_recursive($sysConfig, $savedSys);
            echo "✅ Restored System config from Aiven DB.\n";
        }
    }

    // Force-validate curl_options so curl_setopt_array() in httpUtil.php never receives re-indexed keys (0, 1)
    $sanitizeCurlOptions($sysConfig);

    // Rebuild extensions_enabled cleanly as strictly boolean values for valid installed extensions
    $cleanSysExt = [];
    foreach ($installedExtensions as $extName => $_) {
        if (isset($sysConfig['extensions_enabled']) && is_array($sysConfig['extensions_enabled']) && array_key_exists($extName, $sysConfig['extensions_enabled'])) {
            $cleanSysExt[$extName] = (bool)$sysConfig['extensions_enabled'][$extName];
        } else {
            $cleanSysExt[$extName] = true;
        }
    }
    $sysConfig['extensions_enabled'] = $cleanSysExt;

    if (!isset($sysConfig['extensions']) || !is_array($sysConfig['extensions'])) {
        $sysConfig['extensions'] = [];
    }

    file_put_contents($sysConfigFile, "<?php\nreturn " . var_export($sysConfig, true) . ";\n");
    @chown($sysConfigFile, 'www-data');
    $saveStateToDb($pdo, $quote, $stateTable, 'system_config', $sysConfig);

    // 3. Restore User Config from Aiven DB
    if (file_exists($userConfigFile)) {
        $userConfig = include($userConfigFile);
        if (!is_array($userConfig)) {
            $userConfig = [];
        }
        $envPasswordHash = $userConfig['passwordHash'] ?? '';

        $stmtUser = $pdo->prepare("SELECT config_json FROM {$quote}{$stateTable}{$quote} WHERE config_key = :k");
        $stmtUser->execute([':k' => 'user_config_' . $user]);
        $savedUserRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($savedUserRow && !empty($savedUserRow['config_json'])) {
            $savedUser = json_decode($savedUserRow['config_json'], true);
            if (is_array($savedUser)) {
                $userConfig = array_replace_recursive($userConfig, $savedUser);
                if (!empty($envPasswordHash)) {
                    $userConfig['passwordHash'] = $envPasswordHash;
                }
                echo "✅ Restored User config (theme: " . ($userConfig['theme'] ?? 'default') . ") from Aiven DB.\n";
            }
        }

        $cleanUserExt = [];
        foreach ($installedExtensions as $extName => $_) {
            if (isset($userConfig['extensions_enabled']) && is_array($userConfig['extensions_enabled']) && array_key_exists($extName, $userConfig['extensions_enabled'])) {
                $cleanUserExt[$extName] = (bool)$userConfig['extensions_enabled'][$extName];
            } else {
                $cleanUserExt[$extName] = true;
            }
        }
        $userConfig['extensions_enabled'] = $cleanUserExt;

        if (!isset($userConfig['extensions']) || !is_array($userConfig['extensions'])) {
            $userConfig['extensions'] = [];
        }

        file_put_contents($userConfigFile, "<?php\nreturn " . var_export($userConfig, true) . ";\n");
        @chown($userConfigFile, 'www-data');
        $saveStateToDb($pdo, $quote, $stateTable, 'user_config_' . $user, $userConfig);
    }

    // 4. Restore All Saved Favicon Files Directly from Aiven DB to /data/favicons/
    if (!is_dir($favDir)) {
        mkdir($favDir, 0775, true);
    }
    @chown($favDir, 'www-data');

    $stmtRestFav = $pdo->query("SELECT filename, file_b64 FROM {$quote}{$favTable}{$quote}");
    $dbFavCount = 0;
    while ($favRow = $stmtRestFav->fetch(PDO::FETCH_ASSOC)) {
        $fname = basename($favRow['filename']);
        $raw = base64_decode($favRow['file_b64'], true);
        if (!empty($fname) && $raw !== false && strlen($raw) > 0) {
            $dest = $favDir . $fname;
            file_put_contents($dest, $raw);
            @chown($dest, 'www-data');
            $dbFavCount++;
        }
    }
    echo "✅ Restored {$dbFavCount} cached favicon files from Aiven DB.\n";

    // 5. Seed any missing feed favicons using FreshRSS's true Feed::customFaviconId() formula: hash('crc32b', $salt . $id)
    $salt = $sysConfig['salt'] ?? '';
    $feedTable = $prefix . $user . '_feed';
    $stmtFeeds = $pdo->query("SELECT id, url, website FROM {$quote}{$feedTable}{$quote}");

    $fetchedCount = 0;
    while ($row = $stmtFeeds->fetch(PDO::FETCH_ASSOC)) {
        $feedId = $row['id'] ?? '';
        $feedUrl = $row['url'] ?? '';
        $website = !empty($row['website']) ? $row['website'] : $feedUrl;
        if ($feedId === '' || empty($website)) {
            continue;
        }

        $hash = hash('crc32b', $salt . $feedId);
        $txtFile = $favDir . $hash . '.txt';

        if (!file_exists($txtFile)) {
            file_put_contents($txtFile, $website);
            @chown($txtFile, 'www-data');
            $saveFaviconToDb($pdo, $quote, $favTable, $hash . '.txt', $txtFile);
        }

        $existingIcons = glob($favDir . $hash . '.{ico,png,svg,webp,jpg,jpeg,gif}', GLOB_BRACE);
        if (empty($existingIcons)) {
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
                    $icoFile = $favDir . $hash . '.ico';
                    file_put_contents($icoFile, $icoData);
                    @chown($icoFile, 'www-data');
                    $saveFaviconToDb($pdo, $quote, $favTable, $hash . '.ico', $icoFile);
                    $fetchedCount++;
                }
            }
        }
    }

    foreach (scandir($favDir) as $f) {
        if ($f === '.' || $f === '..' || $f === 'index.html') {
            continue;
        }
        $p = $favDir . $f;
        if (is_file($p) && filesize($p) > 0) {
            $saveFaviconToDb($pdo, $quote, $favTable, $f, $p);
        }
    }

    if ($fetchedCount > 0) {
        echo "✅ Fetched & backed up {$fetchedCount} new missing favicons.\n";
    }
} catch (Throwable $e) {
    echo "⚠️ Post-boot restore/sync error: " . $e->getMessage() . "\n";
}
