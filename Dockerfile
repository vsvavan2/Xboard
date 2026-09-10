FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# ---------------------------------------------------------------------------
# OlcRTC fork: use local source code instead of git clone.
# The repository itself (plugins/OlcRTC included) is the build context.
# ---------------------------------------------------------------------------
ARG CACHEBUST=1

RUN echo "Building from local repository context (OlcRTC fork), CACHEBUST: ${CACHEBUST}"

COPY . /www

RUN rm -rf /www/.git || true

# ---------------------------------------------------------------------------
# Clone admin panel SPA (prebuilt distribution from cedar2025/xboard-admin-dist).
# This is a git submodule (see .gitmodules) which must be present at
# /www/public/assets/admin for the admin route to work.  We do NOT rely on
# actions/checkout submodules because many users build locally via `docker compose build`
# without a git-checked-out source tree, so we always materialise it explicitly here.
# ---------------------------------------------------------------------------
RUN ADMIN_DIST_REPO="${ADMIN_DIST_REPO:-https://github.com/cedar2025/xboard-admin-dist.git}" && \
    rm -rf /www/public/assets/admin && \
    mkdir -p /www/public/assets && \
    git clone --depth=1 "${ADMIN_DIST_REPO}" /www/public/assets/admin && \
    rm -rf /www/public/assets/admin/.git /www/public/assets/admin/.github 2>/dev/null || true && \
    echo "[Dockerfile] Admin SPA materialised at /www/public/assets/admin: $(find /www/public/assets/admin -type f | wc -l) files"

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN composer install --no-cache --no-dev --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
