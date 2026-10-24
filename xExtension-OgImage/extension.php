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

        // Fetch up to 512KB of the webpage HTML with full browser headers
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

        // Upgrade thumbnail resolution parameters for known CDNs (CNBC, Politico, Quartz/Kinja, E! Online)
        $heroImage = $this->optimizeImageQuality($heroImage);

        // Set the native FreshRSS entry thumbnail to the single high-quality image
        if (method_exists($entry, '_thumbnail')) {
            $entry->_thumbnail([
                'url' => $heroImage,
            ]);
        }

        // Remove low-quality lead thumbnails, duplicate lazy-load placeholders, and multi-image RSS gallery dumps
        $cleanContent = $this->deduplicateLeadImages($content, $heroImage, $link);

        $escapedUrl = htmlspecialchars($heroImage, ENT_QUOTES, 'UTF-8');
        $heroHtml = '<figure class="freshrss-og-image"><img src="' . $escapedUrl . '" alt="" loading="lazy" style="max-width:100%;height:auto;display:block;margin-bottom:1em;border-radius:6px;" /></figure>';
        $entry->_content($heroHtml . "\n" . $cleanContent);

        return $entry;
    }

    /**
     * Strips leading low-res RSS thumbnails, duplicate lazy-load placeholder images,
     * and multi-image RSS gallery dumps (such as E! Online's embedded slideshows)
     * so only the single high-res hero image appears at the top of the article.
     */
    private function deduplicateLeadImages(string $content, string $heroImage, string $articleUrl = ''): string {
        if (empty($content)) {
            return '';
        }

        // 1. Remove any previous freshrss-og-image wrapper if reloading an already processed article
        $content = preg_replace('/<figure\s+class=["\']freshrss-og-image["\'][^>]*>.*?<\/figure>\s*/is', '', $content);

        // 2. Unwrap <noscript> tags around images so real images inside <noscript> aren't lost when stripping placeholders
        $content = preg_replace('/<noscript>\s*(<(?:img|picture|figure)\b.*?)\s*<\/noscript>/is', '$1', $content);

        // 3. Remove tiny blurred lazy-load placeholder <img> tags (e.g. Kinja/Quartz w_30, e_blur, data:image, or 1x1)
        $content = preg_replace('/<img[^>]+?\bsrc=["\'](?:data:image\/[^"\']+|[^"\']*?(?:w_30|w=30|e_blur|blur=|1x1|placeholder)[^"\']*?)["\'][^>]*>/i', '', $content);

        // 4. If the feed item is from a site that dumps entire photo galleries into the RSS body (like eonline.com)
        // or if the RSS item contains a flood of 4+ images, strip all embedded RSS images/figures so only the clean text
        // and our single top OG hero image remain.
        $imgCount = preg_match_all('/<img\b[^>]*>/i', $content);
        $isGallerySpamSite = (strpos($articleUrl, 'eonline.com') !== false);

        if ($isGallerySpamSite || $imgCount >= 4) {
            // Strip <figure> and <picture> blocks completely
            $content = preg_replace('/<(?:figure|picture)\b[^>]*>.*?<\/(?:figure|picture)>/is', '', $content);
            // Strip standalone <img> tags (and any wrapping <a> that only wrapped the image)
            $content = preg_replace('/<a\b[^>]*>\s*<img\b[^>]*>\s*<\/a>/is', '', $content);
            $content = preg_replace('/<img\b[^>]*>/i', '', $content);
            // Clean up empty <p> or <div> tags left behind by removed gallery images
            $content = preg_replace('/<(?:p|div)\b[^>]*>\s*(?:&nbsp;|\s|<br\s*\/?>)*<\/(?:p|div)>/is', '', $content);
            return trim($content);
        }

        // 5. For normal articles (1-3 images), strip all consecutive leading <figure>, <picture>, or <img> blocks at the start
        $leadPatterns = [
            '/^\s*(?:<(?:div|p)\b[^>]*>\s*)*<(?:figure|picture)\b[^>]*>.*?<\/(?:figure|picture)>\s*(?:<\/(?:div|p)>\s*)*/is',
            '/^\s*<(?:p|div)\b[^>]*>\s*(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>\s*)?<\/(?:p|div)>\s*/is',
            '/^\s*(?:<a\b[^>]*>\s*)?<img\b[^>]*>\s*(?:<\/a>\s*)?(?:<br\s*\/?>\s*)*/is',
        ];

        $ strippedAny = true;
        while ($strippedAny) {
            $strippedAny = false;
            foreach ($leadPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, '', $content, 1);
                    $strippedAny = true;
                    break;
                }
            }
        }

        // 6. Also check if another <img> with the exact same asset filename/ID still exists in the content and remove it
        $heroKey = $this->extractImageIdentityKey($heroImage);
        if (!empty($heroKey)) {
            $quotedKey = preg_quote($heroKey, '/');
            $content = preg_replace('/<figure\b[^>]*>[^<]*<img[^>]+src=["\'][^"\']*' . $quotedKey . '[^"\']*["\'][^>]*>.*?<\/figure>/is', '', $content);
            $content = preg_replace('/<img[^>]+src=["\'][^"\']*' . $quotedKey . '[^"\']*["\'][^>]*>/i', '', $content);
        }

        return trim($content);
    }

    /**
     * Extracts a unique media identifier or base filename so differently-sized CDN URLs
     * of the same photo (e.g. w_320 vs w_1200) are recognized as the same image.
     */
    private function extractImageIdentityKey(string $url): string {
        if (preg_match('/\/([a-z0-9]{18,22})\.(?:jpg|jpeg|png|webp|gif)/i', $url, $m)) {
            return $m[1];
        }
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        $base = preg_replace('/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $base);
        if (!empty($base) && strlen($base) >= 8 && !in_array(strtolower($base), ['image.jpg', 'default.jpg', 'thumb.jpg', 'index.jpg'], true)) {
            return $base;
        }
        return '';
    }

    private function extractBestImage(string $html, string $baseUrl): string {
        $isGalleryUrl = (bool)preg_match('#(/gallery/|/slideshow/|/photos/|[?&]slide=\d+)#i', $baseUrl);

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
        $cleanHtml = preg_replace('/<(header|nav|footer|aside)\b[^>]*>.*?<\/\1>/is', '', $html);
        if (!is_string($cleanHtml) || empty($cleanHtml)) {
            $cleanHtml = $html;
        }

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
        if (preg_match_all('/\b(?:data-)?srcset=["\']([^"\']+)["\']/i', $block, $srcsetMatches)) {
            foreach ($srcsetMatches[1] as $srcset) {
                $bestFromSrcset = $this->pickBestFromSrcset($srcset);
                if (!empty($bestFromSrcset)) {
                    return $bestFromSrcset;
                }
            }
        }

        $attrPattern = '/<img[^>]+?\b(?:data-lazy-img|data-src|data-original|data-lazy-src|src)=["\']([^"\']+)["\']/i';
        if (preg_match_all($attrPattern, $block, $imgMatches)) {
            foreach ($imgMatches[1] as $rawUrl) {
                $candidate = html_entity_decode(trim($rawUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!empty($candidate) && strpos($candidate, 'data:') !== 0 && !preg_match('/(w_30|w=30|e_blur|1x1)/i', $candidate)) {
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
        // Upgrade Politico Brightspot CDN resize parameters
        if (strpos($url, 'politico.com') !== false) {
            $url = preg_replace('/\/resize\/\d+x\d+\//i', '/resize/1160x/', $url);
            $url = preg_replace('/([?&])w=\d+/i', '$1w=1160', $url);
        }
        // Upgrade Quartz / Kinja (i.kinja-img.com) Cloudinary transformation parameters to w_1200 and remove blur
        if (strpos($url, 'kinja-img.com') !== false || strpos($url, 'qz.com') !== false) {
            $url = preg_replace('/\bw_\d+\b/i', 'w_1200', $url);
            $url = preg_replace('/,?\be_blur:\d+\b/i', '', $url);
        }
        // Upgrade E! Online (akns-images.eonline.com) dimensions to 1200x630
        if (strpos($url, 'eonline.com') !== false) {
            $url = preg_replace('/\/rs_\d+x\d+-[^\/]+\//i', '/rs_1200x630-240101120000-1200-630/', $url);
        }
        return $url;
    }

    private function isValidHeroImage(string $url, string $baseUrl, ?string &$resolvedUrl = null): bool {
        if (empty($url) || strpos($url, 'data:') === 0) {
            return false;
        }

        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        } elseif (strpos($url, '/') === 0) {
            $parts = parse_url($baseUrl);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $url = $parts['scheme'] . '://' . $parts['host'] . $url;
            }
        }

        $lower = strtolower($url);
        if (preg_match('/\.(ico|svg|mp4|m3u8|webm)(\?|$)/i', $lower)) {
            return false;
        }
        if (preg_match('/(favicon|apple-touch-icon|avatar|author-thumb|sprite|pixel|1x1|blank\.gif|logo-icon|badge|cnbc-logo|politico-logo|default-promo|default-social|og-default|placeholder|w_30|e_blur)/i', $lower)) {
            return false;
        }

        $resolvedUrl = $url;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
