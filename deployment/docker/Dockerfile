ARG PHP_IMAGE=php:8.4.26-fpm-bookworm@sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601
FROM ${PHP_IMAGE} AS extensions
WORKDIR /usr/src
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd zip pdo_mysql; \
    rm -rf /var/lib/apt/lists/*

FROM ${PHP_IMAGE} AS runtime
ARG APP_UID=1000
ARG APP_GID=1000
LABEL org.opencontainers.image.title="JAVCMS PHP runtime" \
      org.opencontainers.image.source="https://github.com/avdbapi-com/javcms"
WORKDIR /var/www/javcms
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfreetype6 libjpeg62-turbo libpng16-16 libzip4; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*; \
    rm -f /usr/src/php.tar.xz; \
    groupadd --gid "$APP_GID" app; \
    useradd --uid "$APP_UID" --gid "$APP_GID" --no-create-home --shell /usr/sbin/nologin app; \
    chown app:app /var/www/javcms
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
RUN set -eux; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    printf '%s\n' 'expose_php=Off' 'display_errors=Off' 'log_errors=On' 'error_reporting=E_ALL' 'error_log=/proc/self/fd/2' 'cgi.fix_pathinfo=0' 'session.save_path=/tmp' 'upload_tmp_dir=/tmp' 'upload_max_filesize=32M' 'post_max_size=40M' 'memory_limit=256M' 'max_execution_time=60' 'date.timezone=UTC' 'opcache.enable=1' 'opcache.validate_timestamps=1' > "$PHP_INI_DIR/conf.d/zz-javcms.ini"; \
    sed -ri '/^(user|group)[[:space:]]*=/d' /usr/local/etc/php-fpm.d/www.conf; \
    printf '%s\n' '[global]' 'pid=/tmp/javcms-php-fpm.pid' '[www]' 'listen=9000' 'chdir=/var/www/javcms' 'security.limit_extensions=.php' > /usr/local/etc/php-fpm.d/zz-javcms.conf; \
    php -m > /tmp/javcms-modules 2> /tmp/javcms-module-errors; \
    test ! -s /tmp/javcms-module-errors; \
    ! grep -Ei 'warning|error|deprecated' /tmp/javcms-modules; \
    php -r 'foreach (["PDO", "pdo_mysql", "xml", "gd", "mbstring", "curl", "zip", "fileinfo", "ctype", "openssl"] as $module) { if (!extension_loaded($module)) { throw new RuntimeException("Missing module: " . $module); } }'; \
    rm -f /tmp/javcms-modules /tmp/javcms-module-errors
USER app:app
RUN php-fpm -t
EXPOSE 9000
CMD ["php-fpm", "-F"]
