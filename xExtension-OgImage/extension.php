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

        // Fetch up to 512KB of the webpage HTML with full browser headers to bypass WAF blocks on sites like Politico
        $ch = curl_init($link);
        if ($ch === false) {
            return $entry;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Upgrade-Insecure-Requests: 1',
            ],
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

        // Upgrade thumbnail resolution parameters for known CDNs (e.g. CNBC, Politico, WordPress)
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
        $isGalleryUrl = (bool)preg_match('#(/gallery/|/slideshow/|/photos/|[?&]slide=\d+)#i', $baseUrl);

        // For explicit gallery/slide URLs (like Politico's ?slide=0), check if we can grab the exact first slide image
        // before falling back to a potentially generic site-wide og:image banner.
        if ($isGalleryUrl) {
            $gallerySlideImage = $this->extractGalleryOrLeadBodyImage($html, $baseUrl);
            if (!empty($gallerySlideImage)) {
                return $gallerySlideImage;
            }
        }

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

        // 2. Check JSON-LD (<script type="application/ld+json">) for VideoObject, ImageGallery, or NewsArticle images
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

        // 3. Check embedded JSON state (Next.js / video player state) and HTML5 <video poster="...">
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

        // 4. Final fallback: scan <figure>, <picture>, and lead gallery/article <img> tags in the HTML body
        return $this->extractGalleryOrLeadBodyImage($html, $baseUrl);
    }

    private function extractGalleryOrLeadBodyImage(string $html, string $baseUrl): string {
        // Strip <header>, <nav>, <footer>, and <aside> blocks first so we don't inspect navbar/sidebar images
        $cleanHtml = preg_replace('/<(header|nav|footer|aside)\b[^>]*>.*?<\/\1>/is', '', $html);
        if (!is_string($cleanHtml) || empty($cleanHtml)) {
            $cleanHtml = $html;
        }

        // Priority A: Look inside <figure> or <picture> blocks (used by Politico galleries and modern editorial sites)
        if (preg_match_all('/<(?:figure|picture)\b[^>]*>(.*?)<\/(?:figure|picture)>/is', $cleanHtml, $figureMatches)) {
            foreach ($figureMatches[1] as $block) {
                $candidate = $this->extractUrlFromMediaBlock($block);
                if (!empty($candidate)) {
                    $resolvedUrl = null;
                    if ($this->isValidHeroImage($candidate, $baseUrl, $resolvedUrl)) {
                        return $resolvedUrl;
                    }
                }
            }
        }

        // Priority B: Look for explicit gallery/carousel or lazy-loaded lead <img> tags
        $imgUrl = $this->extractUrlFromMediaBlock($cleanHtml);
        if (!empty($imgUrl)) {
            $resolvedUrl = null;
            if ($this->isValidHeroImage($imgUrl, $baseUrl, $resolvedUrl)) {
                return $resolvedUrl;
            }
        }

        return '';
    }

    private function extractUrlFromMediaBlock(string $block): string {
        // 1. Check <source srcset="..."> or <img srcset="..."> and pick the largest URL in the srcset
        if (preg_match_all('/\b(?:data-)?srcset=["\']([^"\']+)["\']/i', $block, $srcsetMatches)) {
            foreach ($srcsetMatches[1] as $srcset) {
                $bestFromSrcset = $this->pickBestFromSrcset($srcset);
                if (!empty($bestFromSrcset)) {
                    return $bestFromSrcset;
                }
            }
        }

        // 2. Check lazy-load and standard img attributes (data-lazy-img is used by Politico)
        $attrPattern = '/<img[^>]+?\b(?:data-lazy-img|data-src|data-original|data-lazy-src|src)=["\']([^"\']+)["\']/i';
        if (preg_match_all($attrPattern, $block, $imgMatches)) {
            foreach ($imgMatches[1] as $rawUrl) {
                $candidate = html_entity_decode(trim($rawUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!empty($candidate) && strpos($candidate, 'data:') !== 0) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function pickBestFromSrcset(string $srcset): string {
        $parts = explode(',', $srcset);
        $bestUrl = '';
        $maxWidth = -1;

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            $tokens = preg_split('/\s+/', $part);
            $url = html_entity_decode(trim($tokens[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (empty($url) || strpos($url, 'data:') === 0) {
                continue;
            }
            $width = 0;
            if (isset($tokens[1]) && preg_match('/^(\d+)w$/i', trim($tokens[1]), $wMatch)) {
                $width = (int)$wMatch[1];
            }
            if ($width >= $maxWidth) {
                $maxWidth = $width;
                $bestUrl = $url;
            }
        }

        return $bestUrl;
    }

    private function collectJsonLdImages(array $node, array &$results): void {
        $priorityKeys = ['thumbnailUrl', 'thumbnail', 'image', 'contentUrl'];

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
        // Upgrade Politico Brightspot CDN resize parameters if present
        if (strpos($url, 'politico.com') !== false) {
            $url = preg_replace('/\/resize\/\d+x\d+\//i', '/resize/1160x/', $url);
            $url = preg_replace('/([?&])w=\d+/i', '$1w=1160', $url);
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
        if (preg_match('/(favicon|apple-touch-icon|avatar|author-thumb|sprite|pixel|1x1|blank\.gif|logo-icon|badge|cnbc-logo|politico-logo|default-promo|default-social|og-default|placeholder)/i', $lower)) {
            return false;
        }

        $resolvedUrl = $url;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
