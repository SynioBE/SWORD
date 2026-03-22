# ── Stage 1: Build frontend assets ──────────────────────
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

COPY vite.config.* tsconfig*.json eslint* ./
COPY resources/ resources/
COPY public/ public/
RUN npm run build

# ── Stage 2: Production PHP image ──────────────────────
FROM ubuntu:24.04

LABEL maintainer="Synio"

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV LANG=C.UTF-8

WORKDIR /var/www/html

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN echo "Acquire::http::Pipeline-Depth 0;" > /etc/apt/apt.conf.d/99custom && \
    echo "Acquire::http::No-Cache true;" >> /etc/apt/apt.conf.d/99custom && \
    echo "Acquire::BrokenProxy    true;" >> /etc/apt/apt.conf.d/99custom

RUN apt-get update && apt-get upgrade -y \
    && mkdir -p /etc/apt/keyrings \
    && apt-get install -y gnupg curl ca-certificates zip unzip supervisor nginx \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y \
        php8.5-fpm \
        php8.5-cli \
        php8.5-mysql \
        php8.5-gd \
        php8.5-curl \
        php8.5-mbstring \
        php8.5-xml \
        php8.5-zip \
        php8.5-bcmath \
        php8.5-intl \
        php8.5-readline \
        php8.5-redis \
        php8.5-igbinary \
        php8.5-msgpack \
        php8.5-soap \
        php8.5-ldap \
        php8.5-imagick \
        php8.5-sqlite3 \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Remove default nginx site
RUN rm -f /etc/nginx/sites-enabled/default

# Copy configs
COPY docker/production/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/production/php-fpm.conf /etc/php/8.5/fpm/pool.d/www.conf
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy application code
COPY --chown=www-data:www-data . /var/www/html

# Copy compiled frontend assets from stage 1
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Install PHP dependencies (production only)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && rm -rf /root/.composer

# Ensure storage and cache dirs exist with correct permissions
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Create directory for PHP-FPM socket/pid
RUN mkdir -p /run/php

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
