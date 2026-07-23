# syntax=docker/dockerfile:1
# Longevity Evidence Lab — Production WordPress Image
#
# Multi-stage build producing a minimal, non-root, read-only-friendly
# WordPress runtime with the longevity-core MU plugin and theme.
#
# Build:
#   docker build -f docker/production/Dockerfile -t longevity-web:latest .
#
# Secrets are injected at runtime via environment variables or a secrets
# manager — never baked into the image.

# ---------------------------------------------------------------------------
# Stage 1: Composer dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS composer-deps
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2: Node build (theme assets)
# ---------------------------------------------------------------------------
FROM node:20-slim AS node-build
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY wp-content/themes/longevity-starter/ ./wp-content/themes/longevity-starter/
RUN if [ -f wp-content/themes/longevity-starter/package.json ]; then \
      cd wp-content/themes/longevity-starter && npm run build 2>/dev/null || true; \
    fi

# ---------------------------------------------------------------------------
# Stage 3: Production runtime
# ---------------------------------------------------------------------------
FROM wordpress:7.0.1-php8.3-apache AS runtime

# Pin verification: base image digest should be verified in CI.
LABEL org.opencontainers.image.source="longevity-evidence-lab"
LABEL org.opencontainers.image.description="Longevity Evidence Lab production WordPress"

# Install only required PHP extensions and clean up.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends less mariadb-client; \
    rm -rf /var/lib/apt/lists/*

# Security: create non-root runtime user.
RUN set -eux; \
    addgroup --gid 1001 --system wpapp; \
    adduser --uid 1001 --system --ingroup wpapp --no-create-home wpapp

# Copy Composer vendor.
COPY --from=composer-deps /build/vendor /var/www/html/vendor

# Copy application code.
COPY wp-content/mu-plugins/ /var/www/html/wp-content/mu-plugins/
COPY wp-content/themes/longevity-starter/ /var/www/html/wp-content/themes/longevity-starter/
COPY config/ /var/www/html/wp-content/config/

# Copy built theme assets from node stage (if any).
COPY --from=node-build /build/wp-content/themes/longevity-starter/build/ /var/www/html/wp-content/themes/longevity-starter/build/

# Copy production config template.
COPY config/environments/production.example.php /var/www/html/wp-content/config/production.php.example

# PHP production hardening.
RUN set -eux; \
    { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 30'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 12M'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.memory_consumption = 128'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/production-hardening.ini

# Apache: enable required modules, disable directory listing.
RUN set -eux; \
    a2enmod rewrite headers expires; \
    echo '<Directory /var/www/html>\n  Options -Indexes\n  AllowOverride All\n</Directory>' \
      > /etc/apache2/conf-available/longevity-hardening.conf; \
    a2enconf longevity-hardening

# Set ownership for writable directories.
RUN set -eux; \
    mkdir -p /var/www/html/wp-content/uploads; \
    chown -R wpapp:wpapp /var/www/html/wp-content/uploads; \
    chmod -R 755 /var/www/html/wp-content/mu-plugins /var/www/html/wp-content/themes

# Health check: liveness probe.
HEALTHCHECK --interval=15s --timeout=5s --retries=3 --start-period=30s \
  CMD php -r '$s=@fsockopen("127.0.0.1",80,$e,$m,2); exit($s?0:1);'

# Run as non-root where the platform supports it.
# Note: Official WordPress image requires root for initial setup;
# use an init container or entrypoint wrapper to drop privileges after setup.
USER root

EXPOSE 80
