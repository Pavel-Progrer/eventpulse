# docker/php/Dockerfile
#
# DEV image only.
#
# Philosophy:
#   - Install system deps and PHP extensions needed to run Laravel + EventPulse.
#   - Do NOT copy source code. Source arrives via bind-mount at runtime.
#   - Do NOT run composer install. That runs against the mounted volume.
#   - Xdebug is included here because this image never goes to production.
#
# Production uses a separate multi-stage Dockerfile (docker/php/Dockerfile.prod)
# introduced in Phase 2 (v0.2.0). See ADR-0006.

FROM php:8.3-fpm-alpine

# ── System dependencies ───────────────────────────────────────────────────────
# autoconf + g++ + make → required by PECL to compile extensions from source
# oniguruma-dev         → mbstring
# libpq-dev             → pgsql, pdo_pgsql
# libzip-dev            → zip (Composer needs it)
# icu-dev               → intl
# linux-headers         → required by Xdebug on Alpine
RUN apk add --no-cache \
        autoconf \
        g++ \
        make \
        oniguruma-dev \
        libpq-dev \
        libzip-dev \
        icu-dev \
        linux-headers \
        git \
        unzip \
        curl

# ── PHP extensions ────────────────────────────────────────────────────────────
RUN docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        zip \
        intl \
        pcntl \
        bcmath \
        opcache

# ── Redis extension (via PECL) ────────────────────────────────────────────────
RUN pecl install redis \
    && docker-php-ext-enable redis

# ── Xdebug (dev only — never in prod image) ───────────────────────────────────
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# ── Composer ──────────────────────────────────────────────────────────────────
# Copy the binary from the official Composer image so we don't pin a version
# in curl/wget. Composer updates independently of PHP.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Working directory ─────────────────────────────────────────────────────────
# Must match the bind-mount target in docker-compose.yml
WORKDIR /var/www/html

# Run as www-data so files created inside the container are writable by Nginx.
# The bind-mount on the host side should be owned by your user (uid 1000 on
# most Linux machines). If you hit permission errors, see docs/dev-setup.md.
USER www-data

EXPOSE 9000
CMD ["php-fpm"]