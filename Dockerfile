# ============================================================================
# Phlix Hub — application image (Alpine)
# ----------------------------------------------------------------------------
# Builds on the shared base image (ghcr.io/detain/phlix-base) which already
# contains PHP + Swoole + UV + Composer. Only the cheap application layers live
# here, so editing this file does NOT recompile the PHP extensions.
#
# The base image is built and published by the phlix-server repository's
# .github/workflows/docker.yml (the `docker-base` job). To build the hub image
# locally without recompiling extensions, either pull the published base or
# build it once from phlix-server/docker/Dockerfile.base and tag it
# ghcr.io/detain/phlix-base:latest. PHLIX_BASE_IMAGE overrides the reference.
# ============================================================================
ARG PHLIX_BASE_IMAGE=ghcr.io/detain/phlix-base:latest
FROM ${PHLIX_BASE_IMAGE}

# PHP overrides (Alpine layout)
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-phlix.ini

COPY docker/nginx.conf /etc/nginx/http.d/default.conf

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p /var/www/html /var/phlix/{config,data,logs,backups} \
    && chown -R nobody:nobody /var/www/html /var/phlix

WORKDIR /var/www/html

# Composer install in two stages so the vendor layer caches across builds —
# only invalidated when composer.{json,lock} change, not on every source edit.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs

COPY . /var/www/html/

RUN composer dump-autoload --no-dev --optimize

EXPOSE 80 443

CMD ["sh", "/docker-entrypoint.sh"]
