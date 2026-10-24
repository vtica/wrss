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

        $content = (string)$entry->content();

        // Fetch up to 512KB of the webpage HTML to capture <head>, JSON-LD VideoObjects, and player posters
        $ch = curl_init($link);
        if ($ch === false) {
            return $entry;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_RANGE => '0-524288',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (empty($html) || !is_string($html)) {
            return $entry;
        }

        $heroImage = $this->extractBestImage($html, $link);

        if (empty($heroImage)) {
            return $entry;
        }

        // Upgrade thumbnail resolution parameters for known CDNs (e.g. CNBC, WordPress)
        $heroImage = $this->optimizeImageQuality($heroImage);

        // Set the native FreshRSS entry thumbnail
        if (method_exists($entry, '_thumbnail')) {
            $entry->_thumbnail([
                'url' => $heroImage,
            ]);
        }

        // Prepend the <img> tag only if the article content does not already contain this image URL
        if (strpos($content, $heroImage) === false) {
            $escapedUrl = htmlspecialchars($heroImage, ENT_QUOTES, 'UTF-8');
            $heroHtml = '<figure class="freshrss-og-image"><img src="' . $escapedUrl . '" alt="" loading="lazy" style="max-width:100%;height:auto;display:block;margin-bottom:1em;border-radius:6px;" /></figure>';
            $entry->_content($heroHtml . "\n" . $content);
        }

        return $entry;
    }

    private function extractBestImage(string $html, string $baseUrl): string {
        // 1. Check standard and video-specific <meta> tags (og:image, twitter:player:image, thumbnailUrl, etc.)
        $metaPatterns = [
            '/<meta[^>]+(?:property|name|itemprop)=["\'](?:og:image(?::secure_url|:url)?|twitter:image(?::src)?|twitter:player:image|og:video:thumbnail|thumbnailUrl)["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name|itemprop)=["\'](?:og:image(?::secure_url|:url)?|twitter:image(?::src)?|twitter:player:image|og:video:thumbnail|thumbnailUrl)["\']/i',
        ];

        foreach ($metaPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $rawCandidate) {
                    $candidate = html_entity_decode(trim($rawCandidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $resolvedUrl = null;
                    if ($this->isValidHeroImage($candidate, $baseUrl, $resolvedUrl)) {
                        return $resolvedUrl;
                    }
                }
            }
        }

        // 2. Check JSON-LD (<script type="application/ld+json">) for VideoObject.thumbnailUrl or NewsArticle.image
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scriptMatches)) {
            foreach ($scriptMatches[1] as $jsonRaw) {
                $data = json_decode(trim($jsonRaw), true);
                if (is_array($data)) {
                    $candidates = [];
                    $this->collectJsonLdImages($data, $candidates);
                    foreach ($candidates as $rawCandidate) {
                        $candidate = html_entity_decode(trim($rawCandidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $resolvedUrl = null;
                        if ($this->isValidHeroImage($candidate, $baseUrl, $resolvedUrl)) {
                            return $resolvedUrl;
                        }
                    }
                }
            }
        }

        // 3. Check embedded JSON state (e.g., Next.js / CNBC video player state) and HTML5 <video poster="...">
        $fallbackPatterns = [
            '/"thumbnailUrl"\s*:\s*"([^"]+)"/i',
            '/"poster(?:Url|Image)?"\s*:\s*"([^"]+)"/i',
            '/<video[^>]+poster=["\']([^"\']+)["\']/i',
            '/<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']/i',
        ];

        foreach ($fallbackPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $rawCandidate) {
                    $unescaped = str_replace('\/', '/', $rawCandidate);
                    $candidate = html_entity_decode(trim($unescaped), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $resolvedUrl = null;
                    if ($this->isValidHeroImage($candidate, $baseUrl, $resolvedUrl)) {
                        return $resolvedUrl;
                    }
                }
            }
        }

        return '';
    }

    private function collectJsonLdImages(array $node, array &$results): void {
        // Prioritize VideoObject thumbnailUrl first, then general image/thumbnailUrl fields
        $priorityKeys = ['thumbnailUrl', 'thumbnail', 'image'];

        foreach ($priorityKeys as $key) {
            if (!empty($node[$key])) {
                $val = $node[$key];
                if (is_string($val)) {
                    $results[] = $val;
                } elseif (is_array($val)) {
                    if (!empty($val['url']) && is_string($val['url'])) {
                        $results[] = $val['url'];
                    } elseif (!empty($val['contentUrl']) && is_string($val['contentUrl'])) {
                        $results[] = $val['contentUrl'];
                    } else {
                        foreach ($val as $subItem) {
                            if (is_string($subItem)) {
                                $results[] = $subItem;
                            } elseif (is_array($subItem) && !empty($subItem['url']) && is_string($subItem['url'])) {
                                $results[] = $subItem['url'];
                            }
                        }
                    }
                }
            }
        }

        // Recursively inspect nested objects (such as @graph, video, associatedMedia)
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $this->collectJsonLdImages($v, $results);
            }
        }
    }

    private function optimizeImageQuality(string $url): string {
        // Upgrade CNBC image & video thumbnail dimensions to high-resolution 1920x1080
        if (strpos($url, 'cnbcfm.com') !== false || strpos($url, 'cnbc.com') !== false) {
            $url = preg_replace('/([?&])w=\d+/i', '$1w=1920', $url);
            $url = preg_replace('/([?&])h=\d+/i', '$1h=1080', $url);
        }
        return $url;
    }

    private function isValidHeroImage(string $url, string $baseUrl, ?string &$resolvedUrl = null): bool {
        if (empty($url) || strpos($url, 'data:') === 0) {
            return false;
        }

        // Resolve protocol-relative and root-relative URLs
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $parts = parse_url($baseUrl);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $url = $parts['scheme'] . '://' . $parts['host'] . $url;
            }
        }

        // Reject favicons, SVGs, avatars, default placeholder logos, or tracking pixel filenames
        $lower = strtolower($url);
        if (preg_match('/\.(ico|svg|mp4|m3u8|webm)(\?|$)/i', $lower)) {
            return false;
        }
        if (preg_match('/(favicon|apple-touch-icon|avatar|author-thumb|sprite|pixel|1x1|blank\.gif|logo-icon|badge|cnbc-logo|default-promo)/i', $lower)) {
            return false;
        }

        $resolvedUrl = $url;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
