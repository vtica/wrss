FROM freshrss/freshrss:latest

# Overwrite the default container command with a custom boot script
CMD sh -c " \
  if [ ! -f /var/www/FreshRSS/data/config.php ]; then \
    echo 'Building configuration file dynamically...'; \
    php /var/www/FreshRSS/cli/do-install.php \
      --api-enabled \
      --base-url https://\$RENDER_EXTERNAL_HOSTNAME \
      --db-type pgsql \
      --db-host \$DB_HOST \
      --db-user \$DB_USER \
      --db-password \$DB_PASSWORD \
      --db-base \$DB_BASE \
      --default-user admin || true; \
    php /var/www/FreshRSS/cli/create-user.php \
      --user admin \
      --password \$ADMIN_PASSWORD \
      --api-password \$ADMIN_API_PASSWORD || true; \
  fi && \
  exec ./Docker/entrypoint.sh"

