FROM freshrss/freshrss:latest

# Download and install all official community extensions (Auto Refresh, Reading Time, Image Proxy, etc.)
RUN apt-get update && apt-get install -y --no-install-recommends unzip curl ca-certificates \
    && curl -L https://github.com/FreshRSS/Extensions/archive/refs/heads/master.zip -o /tmp/extensions.zip \
    && unzip -q /tmp/extensions.zip -d /tmp/ \
    && cp -r /tmp/Extensions-master/xExtension-* /var/www/FreshRSS/extensions/ \
    && chown -R www-data:www-data /var/www/FreshRSS/extensions/ \
    && rm -rf /tmp/extensions.zip /tmp/Extensions-master /var/lib/apt/lists/*

# Pre-seed system and user configurations
COPY config.custom.php /var/www/FreshRSS/data/config.custom.php
COPY config.default.php /var/www/FreshRSS/data/users/_/config.default.php
