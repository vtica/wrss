
FROM freshrss/freshrss:latest

# Install Git and certificates, then install all configured extensions.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git ca-certificates \
    && mkdir -p /var/www/FreshRSS/extensions \
    \
    # Official FreshRSS extensions
    && git clone --depth 1 https://github.com/FreshRSS/Extensions.git /tmp/official-ext \
    && cp -a /tmp/official-ext/xExtension-YouTube /var/www/FreshRSS/extensions/ \
    \
    # Article Full Text / Af_Readability
    && git clone --depth 1 https://github.com/Niehztog/freshrss-af-readability.git /var/www/FreshRSS/extensions/xExtension-af_readability \
    \
    # Auto Refresh
    && git clone --depth 1 https://github.com/Eisa01/FreshRSS---Auto-Refresh-Extension.git /tmp/autorefresh \
    && if [ -d /tmp/autorefresh/xExtension-AutoRefresh ]; then \
         cp -a /tmp/autorefresh/xExtension-AutoRefresh /var/www/FreshRSS/extensions/; \
       else \
         cp -a /tmp/autorefresh /var/www/FreshRSS/extensions/xExtension-AutoRefresh; \
       fi \
    \
    # Image Cache
    && git clone --depth 1 https://github.com/Victrid/freshrss-image-cache-plugin.git /tmp/image-cache \
    && cp -a /tmp/image-cache /var/www/FreshRSS/extensions/xExtension-ImageCache \
    \
    # RSS-Bridge
    && git clone --depth 1 https://github.com/DevonHess/FreshRSS-Extensions.git /tmp/rss-bridge \
    && cp -a /tmp/rss-bridge/xExtension-RssBridge /var/www/FreshRSS/extensions/ \
    \
    # YouTube Channel 2 RSS Feed
    && git clone --depth 1 https://github.com/cn-tools/cntools_FreshRssExtensions.git /tmp/cntools \
    && cp -a /tmp/cntools/xExtension-YouTubeChannel2RssFeed /var/www/FreshRSS/extensions/ \
    \
    # Heatmap
    && git clone --depth 1 https://github.com/iovictor/xExtension-Heatmap.git /var/www/FreshRSS/extensions/xExtension-Heatmap \
    \
    # Google Reader Redate
    && git clone --depth 1 https://codeberg.org/javerous/freshrss-greader-redate.git /tmp/greader-redate \
    && cp -a /tmp/greader-redate/xExtension-GReaderRedate /var/www/FreshRSS/extensions/ \
    \
    # Extension Manager
    && git clone --depth 1 https://github.com/featurecreep-cron/freshrss-extensions.git /tmp/extension-manager \
    && cp -a /tmp/extension-manager/xExtension-ExtensionManager /var/www/FreshRSS/extensions/ \
    \
    # Broad Site Icon Fetcher
    && git clone --depth 1 https://github.com/bowencool/xExtension-BroadIconFetcher.git /var/www/FreshRSS/extensions/xExtension-BroadIconFetcher \
    \
    # Fix permissions and remove temporary files
    && chown -R www-data:www-data /var/www/FreshRSS/extensions \
    && rm -rf /tmp/official-ext /tmp/autorefresh /tmp/image-cache /tmp/rss-bridge /tmp/cntools /tmp/greader-redate /tmp/extension-manager \
    && rm -rf /var/lib/apt/lists/*

# Pre-seed system and user configurations.
COPY config.custom.php /var/www/FreshRSS/data/config.custom.php
COPY config.default.php /var/www/FreshRSS/data/users/_/config.default.php

# Restore favicons during container startup.
COPY restore-favicons.php /var/www/FreshRSS/cli/restore-favicons.php

RUN sed -i 's|^exec "\$@"|php /var/www/FreshRSS/cli/restore-favicons.php\nexec "\$@"|' /var/www/FreshRSS/Docker/entrypoint.sh
