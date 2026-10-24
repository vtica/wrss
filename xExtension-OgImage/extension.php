<?php

class OgImageExtension extends Minz_Extension {
    public function init(): void {
        $this->registerHook('entry_before_insert', [$this, 'injectOgImage']);
    }

    public function injectOgImage($entry) {
        if ($entry === null) {
            return null;
        }

        $link = $entry->link();
        if (empty($link) || strpos($link, 'youtube.com') !== false || strpos($link, 'youtu.be') !== false) {
            return $entry;
        }

        $content = $entry->content();

        // Fetch the webpage HTML to extract <meta property="og:image">
        $ch = curl_init($link);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_RANGE => '0-65535', // Only download the first 64KB (<head> section) for speed
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (empty($html) || !is_string($html)) {
            return $entry;
        }

        $ogImage = '';

        // Match og:image, og:image:secure_url, or twitter:image in either attribute order
        $patterns = [
            '/<meta[^>]+(?:property|name)=["\'](?:og:image(?::secure_url)?|twitter:image(?::src)?)["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image(?::secure_url)?|twitter:image(?::src)?)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $candidate = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($this->isValidHeroImage($candidate, $link, $candidateUrl)) {
                    $ogImage = $candidateUrl;
                    break;
                }
            }
        }

        if (empty($ogImage)) {
            return $entry;
        }

        // Set the native FreshRSS entry thumbnail
        if (method_exists($entry, '_thumbnail')) {
            $entry->_thumbnail([
                'url' => $ogImage,
            ]);
        }

        // Only prepend the <img> tag if the article doesn't already contain this image URL
        if (strpos($content, $ogImage) === false) {
            // Strip any tiny 1x1 tracking pixels or small icons from the start of content if needed, and prepend the clean OG hero image
            $heroHtml = '<p><img src="' . htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-width:100%;height:auto;border-radius:6px;" /></p>';
            $entry->_content($heroHtml . "\n" . $content);
        }

        return $entry;
    }

    private function isValidHeroImage(string $url, string $baseUrl, ?string &$resolvedUrl = null): bool {
        if (empty($url) || strpos($url, 'data:') === 0) {
            return false;
        }

        // Resolve relative URLs if necessary
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $parts = parse_url($baseUrl);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $url = $parts['scheme'] . '://' . $parts['host'] . $url;
            }
        }

        // Reject favicons, SVGs, avatars, or tiny badge filenames
        $lower = strtolower($url);
        if (preg_match('/\.(ico|svg)(\?|$)/i', $lower)) {
            return false;
        }
        if (preg_match('/(favicon|apple-touch-icon|avatar|author-thumb|sprite|pixel|1x1|blank\.gif)/i', $lower)) {
            return false;
        }

        $resolvedUrl = $url;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
