FROM freshrss/freshrss:latest

# Install git and clone extensions into /var/www/FreshRSS/extensions/
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates \
    && mkdir -p /var/www/FreshRSS/extensions \
    && git clone --depth 1 https://github.com/FreshRSS/Extensions.git /tmp/official-ext \
    && cp -a /tmp/official-ext/xExtension-YouTube /var/www/FreshRSS/extensions/ \
    && git clone --depth 1 https://github.com/Eisa01/FreshRSS---Auto-Refresh-Extension.git /tmp/autorefresh \
    && if [ -d "/tmp/autorefresh/xExtension-AutoRefresh" ]; then \
         cp -a /tmp/autorefresh/xExtension-AutoRefresh /var/www/FreshRSS/extensions/; \
       else \
         cp -a /tmp/autorefresh /var/www/FreshRSS/extensions/xExtension-AutoRefresh; \
       fi \
    && git clone --depth 1 https://codeberg.org/javerous/freshrss-greader-redate.git /tmp/greader-redate \
    && cp -a /tmp/greader-redate/xExtension-GReaderRedate /var/www/FreshRSS/extensions/ \
    && git clone --depth 1 https://github.com/featurecreep-cron/freshrss-extensions.git /tmp/extension-manager \
    && cp -a /tmp/extension-manager/xExtension-ExtensionManager /var/www/FreshRSS/extensions/ \
    && git clone --depth 1 https://github.com/bowencool/xExtension-BroadIconFetcher.git /var/www/FreshRSS/extensions/xExtension-BroadIconFetcher \
    && rm -rf /tmp/official-ext /tmp/autorefresh /tmp/greader-redate /tmp/extension-manager /var/lib/apt/lists/*

# Copy custom OG Image Injector extension and set ownership
COPY xExtension-OgImage /var/www/FreshRSS/extensions/xExtension-OgImage
RUN chown -R www-data:www-data /var/www/FreshRSS/extensions/

# Copy public keep-alive & background actualizer endpoint into FreshRSS public root (/p/)
COPY ping.php /var/www/FreshRSS/p/ping.php
RUN chown www-data:www-data /var/www/FreshRSS/p/ping.php

# Pre-seed system and user configurations
COPY config.custom.php /var/www/FreshRSS/data/config.custom.php
COPY config.default.php /var/www/FreshRSS/data/users/_/config.default.php
COPY restore-favicons.php /var/www/FreshRSS/cli/restore-favicons.php

# Run --restore on boot and launch --sync in the background before Apache starts
RUN sed -i 's|^exec "\$@"|php /var/www/FreshRSS/cli/restore-favicons.php --restore\nphp /var/www/FreshRSS/cli/restore-favicons.php --sync \&\nexec "\$@"|' /var/www/FreshRSS/Docker/entrypoint.sh
