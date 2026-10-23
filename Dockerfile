FROM freshrss/freshrss:latest

# Install git and clone the 3 extensions into /var/www/FreshRSS/extensions/
RUN apt-get update && apt-get install -y --no-install-recommends git ca-certificates \
    && git clone --depth 1 https://github.com/FreshRSS/Extensions.git /tmp/official-ext \
    && cp -r /tmp/official-ext/xExtension-YouTube /var/www/FreshRSS/extensions/ \
    && git clone --depth 1 https://github.com/Niehztog/freshrss-af-readability.git /var/www/FreshRSS/extensions/xExtension-af_readability \
    && git clone --depth 1 https://github.com/Eisa01/FreshRSS---Auto-Refresh-Extension.git /tmp/autorefresh \
    && if [ -d "/tmp/autorefresh/xExtension-AutoRefresh" ]; then \
         cp -r /tmp/autorefresh/xExtension-AutoRefresh /var/www/FreshRSS/extensions/; \
       else \
         cp -r /tmp/autorefresh /var/www/FreshRSS/extensions/xExtension-AutoRefresh; \
       fi \
    && chown -R www-data:www-data /var/www/FreshRSS/extensions/ \
    && rm -rf /tmp/official-ext /tmp/autorefresh /var/lib/apt/lists/*

# Pre-seed system and user configurations so extensions stay enabled across rebuilds
COPY config.custom.php /var/www/FreshRSS/data/config.custom.php
COPY config.default.php /var/www/FreshRSS/data/users/_/config.default.php
