#!/usr/bin/env bash
# ============================================================
# SWORD Site Installation Script
# Site:   {{ $site->domain }} (ID: {{ $site->id }})
# Server: {{ $server->name }} (ID: {{ $server->id }})
# Generated: {{ now()->toIso8601String() }}
# ============================================================

CALLBACK_URL="{{ $callbackUrl }}"
DOMAIN="{{ $site->domain }}"
PHP_VERSION="{{ $site->php_version }}"
DB_NAME="{{ $site->db_name }}"
DB_USER="{{ $site->db_user }}"
DB_PASS="{{ $site->db_password }}"
MYSQL_ROOT_PASSWORD="{{ $site->server->mysql_root_password }}"
SITE_DIR="/srv/sword/sites/${DOMAIN}"
STACK_DIR="/srv/sword/stacks/${DOMAIN}"
WP_DIR="${SITE_DIR}/wordpress"
CACHE_DIR="${STACK_DIR}/nginx-cache"

echo "MYSQL ROOT PASSWORD: ${MYSQL_ROOT_PASSWORD}"

# ── Helpers ──────────────────────────────────────────────

updateProgress() {
    echo "[$(date '+%H:%M:%S')] step: $1 → status: ${2:-installing}"
    curl -s --insecure -d "status=${2:-installing}&step=$1" \
        -X POST "${CALLBACK_URL}" > /dev/null || true
}

notifyFailure() {
    echo "[$(date '+%H:%M:%S')] step: $1 → status: failed"
    curl -s --insecure -d "status=failed&step=$1" \
        -X POST "${CALLBACK_URL}" > /dev/null || true
}

trap 'notifyFailure "Unexpected error on line $LINENO"' ERR

updateProgress "started"

# ── Root check ───────────────────────────────────────────

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root." >&2
    exit 1
fi

# ── Create directories ───────────────────────────────────

mkdir -p "${SITE_DIR}"
mkdir -p "${STACK_DIR}"
mkdir -p "${WP_DIR}"
mkdir -p "${CACHE_DIR}"
chown -R sword:sword "${SITE_DIR}"

# ── Create MySQL database and user ───────────────────────

echo "Creating database..."

docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Creating database user..."

docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';"
docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"

updateProgress "create_database"

# ── Write Dockerfile for PHP container ───────────────────

echo "Creating Dockerfile..."

cat > "${STACK_DIR}/Dockerfile" <<DOCKERFILEEOF
FROM php:${PHP_VERSION}-fpm-alpine

# Install PHP extensions required by WordPress + Redis
RUN apk add --no-cache \
    freetype libpng libjpeg-turbo freetype-dev libpng-dev libjpeg-turbo-dev \
    libzip-dev icu-dev icu-libs libintl oniguruma-dev curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j\$(nproc) \
    gd mysqli pdo pdo_mysql zip intl mbstring exif opcache \
    && apk del freetype-dev libpng-dev libjpeg-turbo-dev icu-dev

# Install phpredis extension
RUN apk add --no-cache --virtual .build-deps \$PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install WP-CLI
RUN curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# PHP tuning: memory, opcache, and upload settings
RUN echo 'memory_limit = 256M' > /usr/local/etc/php/conf.d/sword.ini \
    && echo 'upload_max_filesize = 64M' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'post_max_size = 64M' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'opcache.enable = 1' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'opcache.memory_consumption = 128' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'opcache.interned_strings_buffer = 16' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'opcache.max_accelerated_files = 10000' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'opcache.revalidate_freq = 60' >> /usr/local/etc/php/conf.d/sword.ini \
    && echo 'opcache.fast_shutdown = 1' >> /usr/local/etc/php/conf.d/sword.ini
DOCKERFILEEOF

# ── Write Docker Compose file ────────────────────────────

echo "Deploying containers..."

cat > "${STACK_DIR}/docker-compose.yml" <<COMPOSEEOF
services:
  php:
    build:
      context: .
      dockerfile: Dockerfile
    image: sword_php_${PHP_VERSION}
    container_name: sword_{{ $site->id }}_php
    restart: unless-stopped
    volumes:
      - ${WP_DIR}:/var/www/html
    environment:
      - PHP_FPM_POOL_NAME={{ $site->id }}
    networks:
      - sword_network
    labels:
      ofelia.enabled: 'true'
      ofelia.job-exec.wpcron-{{ $site->id }}.schedule: '@every 5m'
      ofelia.job-exec.wpcron-{{ $site->id }}.user: www-data
      ofelia.job-exec.wpcron-{{ $site->id }}.command: 'wp cron event run --due-now'

  redis:
    image: redis:7-alpine
    container_name: sword_{{ $site->id }}_redis
    restart: unless-stopped
    command: >
      redis-server
      --maxmemory 128mb
      --maxmemory-policy allkeys-lru
      --save ""
      --appendonly no
    networks:
      - sword_network

  nginx:
    image: nginx:alpine
    container_name: sword_{{ $site->id }}_nginx
    restart: unless-stopped
    volumes:
      - ${WP_DIR}:/var/www/html:ro
      - ${STACK_DIR}/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - ${CACHE_DIR}:/var/cache/nginx/fastcgi:rw
    labels:
      - traefik.enable=true
      - traefik.http.routers.{{ $site->id }}.rule=Host(\`${DOMAIN}\`)
      - traefik.http.routers.{{ $site->id }}.entrypoints=websecure
      - traefik.http.routers.{{ $site->id }}.tls.certresolver=letsencrypt
    networks:
      - sword_network

networks:
  sword_network:
    name: sword_network
    external: true
COMPOSEEOF

# ── Write Nginx config ───────────────────────────────────

echo "Creating Nginx config..."

cat > "${STACK_DIR}/nginx.conf" <<NGINXEOF
# ── FastCGI cache zone ───────────────────────────────────
# 100m key zone (~800k keys), 1g max cache size, 60m inactive TTL
fastcgi_cache_path /var/cache/nginx/fastcgi
    levels=1:2
    keys_zone=wp_fcgi:100m
    max_size=1g
    inactive=60m
    use_temp_path=off;

fastcgi_cache_key "\$scheme\$request_method\$host\$request_uri";

server {
    listen 80;
    server_name ${DOMAIN};
    root /var/www/html;
    index index.php;

    # ── Cache bypass conditions ──────────────────────────
    # Bypass for logged-in users, WooCommerce sessions, and non-GET requests.
    set \$skip_cache 0;

    if (\$request_method = POST) { set \$skip_cache 1; }
    if (\$query_string != "") { set \$skip_cache 1; }
    if (\$request_uri ~* "/wp-admin/|/xmlrpc.php|wp-.*.php|/feed/|index.php|sitemap(_index)?.xml") { set \$skip_cache 1; }
    if (\$http_cookie ~* "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in") { set \$skip_cache 1; }

    # ── Static assets ────────────────────────────────────
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|otf|eot|webp|avif)\$ {
        expires max;
        log_not_found off;
        add_header Cache-Control "public, immutable";
    }

    location = /favicon.ico { log_not_found off; access_log off; }
    location = /robots.txt  { log_not_found off; access_log off; }

    # ── Security ─────────────────────────────────────────
    location ~* ^/wp-config\.php { deny all; }
    location ~ /\. { deny all; }

    # ── WordPress routing ────────────────────────────────
    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    # ── PHP-FPM + FastCGI cache ──────────────────────────
    location ~ \.php\$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;

        fastcgi_cache             wp_fcgi;
        fastcgi_cache_valid       200 301 302 1h;
        fastcgi_cache_use_stale   error timeout updating invalid_header http_500;
        fastcgi_cache_lock        on;
        fastcgi_cache_bypass      \$skip_cache;
        fastcgi_no_cache          \$skip_cache;

        # Expose cache status for debugging (X-Cache-Status: HIT / MISS / BYPASS)
        add_header X-Cache-Status \$upstream_cache_status always;

        fastcgi_buffers           16 16k;
        fastcgi_buffer_size       32k;
        fastcgi_read_timeout      120s;
        fastcgi_connect_timeout   60s;
        fastcgi_send_timeout      60s;
    }
}
NGINXEOF

# ── Start containers ─────────────────────────────────────

echo "Starting containers..."

docker compose -f "${STACK_DIR}/docker-compose.yml" up -d --build

updateProgress "docker_setup"

# ── Download WordPress ───────────────────────────────────

# Give PHP-FPM a moment to become ready
sleep 3

echo "Downloading WordPress..."

docker exec "sword_{{ $site->id }}_php" wp core download \
    --path=/var/www/html \
    --allow-root \
    --locale=en_US \
    --force

# ── Create wp-config.php ─────────────────────────────────

echo "Creating wp-config.php..."

docker exec -i "sword_{{ $site->id }}_php" wp config create \
    --path=/var/www/html \
    --dbname="${DB_NAME}" \
    --dbuser="${DB_USER}" \
    --dbpass="${DB_PASS}" \
    --dbhost="sword_mysql" \
    --allow-root \
    --force \
    --extra-php <<'WPEXTRAEOF'
/** Disable file editing in dashboard */
define( 'DISALLOW_FILE_EDIT', true );

/** Redis object cache */
define( 'WP_REDIS_HOST', 'sword_{{ $site->id }}_redis' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_TIMEOUT', 1 );
define( 'WP_REDIS_READ_TIMEOUT', 1 );
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_REDIS_PREFIX', '{{ $site->id }}:' );
define( 'WP_REDIS_MAXTTL', 86400 );

/** Nginx FastCGI cache helper */
define( 'RT_WP_NGINX_HELPER_CACHE_PATH', '/var/cache/nginx/fastcgi' );
WPEXTRAEOF

# ── Run WordPress install ────────────────────────────────

echo "Installing WordPress..."

docker exec "sword_{{ $site->id }}_php" wp core install \
    --path=/var/www/html \
    --url="https://${DOMAIN}" \
    --title="${DOMAIN}" \
    --admin_user="{{ $wpAdminUser }}" \
    --admin_password="{{ $wpAdminPassword }}" \
    --admin_email="{{ $adminEmail }}" \
    --skip-email \
    --allow-root

docker exec "sword_{{ $site->id }}_php" wp user update "{{ $wpAdminUser }}" \
    --path=/var/www/html \
    --display_name="{{ $adminDisplayName }}" \
    --allow-root

echo ""
echo "  WordPress admin credentials:"
echo "  URL:      https://${DOMAIN}/wp-admin"
echo "  User:     {{ $wpAdminUser }}"
echo ""

# ── Fix permissions ──────────────────────────────────────

echo "Fixing file permissions..."

chown -R sword:sword "${SITE_DIR}"
docker exec "sword_{{ $site->id }}_php" chown -R www-data:www-data /var/www/html

# ── Remove default plugins ───────────────────────────────

echo "Removing default WP plugins..."

docker exec "sword_{{ $site->id }}_php" wp plugin delete hello \
    --path=/var/www/html \
    --allow-root || true

# ── Install & configure Redis object cache plugin ────────

echo "Installing Redis Cache plugin..."

docker exec "sword_{{ $site->id }}_php" wp plugin install redis-cache \
    --path=/var/www/html \
    --activate \
    --allow-root

docker exec "sword_{{ $site->id }}_php" wp redis enable \
    --path=/var/www/html \
    --skip-flush \
    --allow-root || true

# ── Install & configure Nginx Helper plugin ──────────────

echo "Installing Nginx Helper plugin..."

docker exec "sword_{{ $site->id }}_php" wp plugin install nginx-helper \
    --path=/var/www/html \
    --activate \
    --allow-root

# Configure Nginx Helper: enable FastCGI purge, set cache path
docker exec "sword_{{ $site->id }}_php" wp option update rt_wp_nginx_helper_options \
    '{"enable_purge":"1","cache_method":"enable_fastcgi","purge_method":"get_request","enable_map":null,"enable_log":null,"log_level":"INFO","log_filesize":"5","enable_stamp":null,"purge_homepage_on_edit":"1","purge_homepage_on_del":"1","purge_archive_on_edit":"1","purge_archive_on_del":"1","purge_archive_on_new_comment":"1","purge_archive_on_deleted_comment":"1","purge_page_on_mod":"1","purge_page_on_new_comment":"1","purge_page_on_deleted_comment":"1","redis_hostname":"sword_{{ $site->id }}_redis","redis_port":"6379","redis_prefix":"nginx_cache:","enable_purge_all":null,"nginx_helper_cache_path":"\/var\/cache\/nginx\/fastcgi"}' \
    --format=json \
    --path=/var/www/html \
    --allow-root

updateProgress "install_wordpress"

# ── Restart Ofelia to pick up any new cron jobs ──────────

echo "Restarting Ofelia..."

docker restart sword_ofelia || true

# ── Done ─────────────────────────────────────────────────

updateProgress "installed" "installed"

echo ""
echo "============================================"
echo " SWORD site installation complete!"
echo " Domain: ${DOMAIN}"
echo "============================================"
