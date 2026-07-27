# syntax=docker/dockerfile:1
# Development WordPress image with the Redis PHP extension for persistent
# object caching. Extends the same digest-pinned base as production.
# wordpress:7.0.1-php8.3-apache
FROM wordpress@sha256:d40b86dbdfcfad808a2029acf6543c670c4a61c29f70b9d24605e7d0b31ab83d

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev libssl-dev; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    rm -rf /var/lib/apt/lists/* /tmp/pear
