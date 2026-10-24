<?php
$customConfig = array (
  'curl_options' => array (
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
  ),
  'extensions_enabled' => array (
    'YouTube Video Feed' => true,
    'OG Image Injector' => true,
    'GReader Redate' => true,
    'Extension Manager' => true,
    'Broad Site Icon Fetcher' => true,
    'Auto Refresh' => true,
    'FreshRSS Auto Refresh' => true,
  ),
);

if (!empty(getenv('FRESHRSS_SALT'))) {
  $customConfig['salt'] = getenv('FRESHRSS_SALT');
}

return $customConfig;
