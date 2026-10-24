<?php
// 1. Reads metadata.json from all installed extensions in /extensions/ and enables them in both system & user config.php
// 2. Restores all feed favicons using FreshRSS's exact hash formula: hash('crc32b', $salt . $feed_url)

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
    $salt = $sysConfig['salt'] ?? '';
    $db = $sysConfig['db'] ?? [];

    // --- PART 1: AUTO-ENABLE ALL INSTALLED EXTENSIONS ---
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
        if (!isset($sysConfig['extensions_enabled']) || !is_array($sysConfig['extensions_enabled'])) {
            $sysConfig['extensions_enabled'] = [];
        }
        foreach ($enabledExtensions as $extName => $status) {
            $sysConfig['extensions_enabled'][$extName] = true;
        }
        $sysContent = "<?php\nreturn " . var_export($sysConfig, true) . ";\n";
        file_put_contents($sysConfigFile, $sysContent);
        @chown($sysConfigFile, 'www-data');

        if (!empty($user)) {
            $userConfigFile = $dataPath . '/users/' . $user . '/config.php';
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
    if (empty($user) || empty($db['host']) || empty($db['base'])) {
        exit(0);
    }

    if (!is_dir($favDir)) {
        mkdir($favDir, 0775, true);
    }

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
    $table = $prefix . $user . '_feed';
    $quote = ($dbType === 'mysql') ? '`' : '"';

    $stmt = $pdo->query("SELECT id, url, website FROM {$quote}{$table}{$quote}");

    $restoredCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $feedUrl = $row['url'] ?? '';
        $website = !empty($row['website']) ? $row['website'] : $feedUrl;
        if (empty($feedUrl) || empty($website)) {
            continue;
        }

        // Exact FreshRSS Feed::hashFavicon() formula: hash('crc32b', $salt . $this->url)
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
    echo "⚠️ Post-boot restore error: " . $e->getMessage() . "\n";
}
