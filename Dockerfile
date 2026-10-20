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

# composer.lock MUST be in the build context (see .dockerignore) — without it
# composer resolves the newest releases, which currently require PHP >= 8.4 and
# the generated vendor/composer/platform_check.php then aborts every artisan run
# on this PHP 8.2 image. Never re-add --ignore-platform-reqs: it hides the
# mismatch instead of failing early, producing a container that cannot boot.
#
# Do NOT add `composer dump-autoload` here. `dump-autoload` infers the dev mode
# from the previous install (`--no-dev`) and that pass drops the whole orphaned
# laravel/reverb dependency subtree (laravel/reverb, react/*, ratchet/rfc6455,
# pusher/pusher-php-server, evenement/evenement, clue/*) from
# vendor/composer/autoload_psr4.php + autoload_classmap.php — reverb is present
# in composer.lock but required by nobody. The post-autoload-dump hook then
# reruns `artisan package:discover`, reads the manifest written by the first
# (correct) pass and dies with
#   Class "Laravel\Reverb\ApplicationManagerServiceProvider" not found
# (exit code 1, masked as a bare "docker build failed").
# `composer install` already writes a fully optimized autoloader
# (config.optimize-autoloader=true) and is all this image needs.
RUN composer install --no-dev --no-interaction --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data

# ---------------------------------------------------------------------------
# T18 self-healing: snapshot of image-shipped plugins/theme so entrypoint can
# restore them on first boot if user bind-mounts empty ./plugins ./theme dirs.
# Admin SPA snapshot too: Russian-region VPS hosts often block github.com
# during container runtime → entrypoint can restore admin-panel offline from
# this baked-in snapshot even if git clone fails at boot.
# Never remove this block — it prevents "404 plugin not found" and "white blank admin panel" bugs.
# ---------------------------------------------------------------------------
RUN set -e; \
    mkdir -p /www/.image-src; \
    [ -d /www/plugins ]       && cp -a /www/plugins       /www/.image-src/plugins       || true; \
    [ -d /www/plugins-core ]  && cp -a /www/plugins-core  /www/.image-src/plugins-core  || true; \
    [ -d /www/theme ]         && cp -a /www/theme         /www/.image-src/theme         || true; \
    [ -d /www/public/assets/admin ] && cp -a /www/public/assets/admin /www/.image-src/admin || true; \
    ls -la /www/.image-src/ 2>/dev/null || true

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
# Strip potential Windows CRLF line endings.  Alpine's musl `execve()` treats
# `#!/bin/sh\r` (CRLF shebang) as "no such interpreter" → container crash loop
# with `exec /entrypoint.sh: no such file or directory`.  Fix is idempotent.
RUN sed -i 's/\r$//g' /entrypoint.sh \
 && chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
