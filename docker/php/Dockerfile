# docker/php/Dockerfile

# Use the official FPM variant — includes php-fpm binary, no web server
FROM php:8.3-fpm

# ── SYSTEM DEPENDENCIES ───────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Needed by common PHP extensions
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libxml2-dev \
    # Dev quality-of-life tools inside the container
    git \
    curl \
    unzip \
    vim \
    procps \
    # ps, top — for inspecting FPM workers inside container
    && rm -rf /var/lib/apt/lists/*

# ── PHP EXTENSIONS ────────────────────────────────────────────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        zip \
        gd \
        intl \
        bcmath \
        opcache \
        sockets

# ── XDEBUG ────────────────────────────────────────────────────────────────────
# pecl install gives us the latest stable — pin a version in production
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# ── COMPOSER ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── WORKING DIRECTORY ─────────────────────────────────────────────────────────
WORKDIR /var/www/html

# Don't run as root in dev either — good habit that mirrors prod
# www-data (uid 33) is the default FPM user in this image
USER www-data