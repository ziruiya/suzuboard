FROM phpswoole/swoole:php8.2-alpine AS runtime

RUN php -r 'if (version_compare(PHP_VERSION, "8.2.35", "<")) { \
    fwrite(STDERR, "PHP >= 8.2.35 is required; found " . PHP_VERSION . "\n"); exit(1); \
}'

COPY --from=mlocati/php-extension-installer:2.12.3 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    IPE_PROCESSOR_COUNT=2 install-php-extensions zip

COPY --from=redis:8-alpine /usr/local/bin/redis-* /usr/local/bin/
RUN apk --no-cache add \
        shadow sqlite mysql-client mysql-dev mariadb-connector-c \
        git patch supervisor libssl3 libcrypto3 caddy && \
    redis-server --version && redis-cli --version && \
    addgroup -S -g 1000 www && \
    adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis) && \
    php -r 'foreach (["swoole", "pcntl", "posix", "bcmath", "pdo_mysql", "pdo_sqlite", "sockets", "zip", "igbinary", "redis"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing: {$ext}\n"); exit(1); } }'

FROM runtime AS app
WORKDIR /www
COPY .docker /
# Build only the checked-out, reviewed repository content. No remote source clone.
COPY . /www/

ARG VCS_REF=unknown
LABEL org.opencontainers.image.title="SuzuBoard" \
      org.opencontainers.image.source="https://github.com/ziruiya/suzuboard" \
      org.opencontainers.image.revision="${VCS_REF}"

RUN test -f /www/composer.json && \
    test -f /www/composer.lock && \
    test -s /www/public/assets/admin/index.html && \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader && \
    php artisan storage:link && \
    chown -R www:www /www && chmod -R 775 /www && \
    mkdir -p /data && chown redis:redis /data

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini
ENV ENABLE_WEB=true ENABLE_HORIZON=true ENABLE_REDIS=true ENABLE_WS_SERVER=true ENABLE_CADDY=true
EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
