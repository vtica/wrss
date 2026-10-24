
<?php

$configFile = '/var/www/FreshRSS/data/config.php';

if (!is_file($configFile) || !is_readable($configFile) || !is_writable($configFile)) {
    fwrite(STDERR, "FreshRSS auth repair: config.php is missing or inaccessible.\n");
    exit(0);
}

$contents = file_get_contents($configFile);

if ($contents === false) {
    fwrite(STDERR, "FreshRSS auth repair: could not read config.php.\n");
    exit(0);
}

$updated = preg_replace(
    '/^([ \t]*[\'"]auth_type[\'"][ \t]*=>[ \t]*)[\'"][^\'"]*[\'"]/m',
    "$1'form'",
    $contents,
    1,
    $count
);

if ($count === 1 && $updated !== $contents) {
    if (file_put_contents($configFile, $updated, LOCK_EX) === false) {
        fwrite(STDERR, "FreshRSS auth repair: could not write config.php.\n");
    } else {
        fwrite(STDOUT, "FreshRSS auth repair: authentication set to form login.\n");
    }
} elseif ($count !== 1) {
    fwrite(STDERR, "FreshRSS auth repair: auth_type setting was not found.\n");
}
