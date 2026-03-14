#!/usr/bin/env bash
# ============================================================
# SWORD Site Installation Script
# Site:   {{ $site->domain }} (ID: {{ $site->id }})
# Server: {{ $server->name }} (ID: {{ $server->id }})
# Generated: {{ now()->toIso8601String() }}
# ============================================================

set -euo pipefail

CALLBACK_URL="{{ $callbackUrl }}"
DOMAIN="{{ $site->domain }}"
PHP_VERSION="{{ $site->php_version }}"
DB_NAME="{{ $site->db_name }}"
DB_USER="{{ $site->db_user }}"
DB_PASS="{{ $site->db_password }}"
SITE_DIR="/srv/sword/sites/${DOMAIN}"
STACK_DIR="/srv/sword/stacks/${DOMAIN}"
WP_DIR="${SITE_DIR}/wordpress"

# ── Helpers ────────────────────────────────────────────────

installPing() {
    echo "=== $1 ==="
    curl -s --insecure -d "status=${2:-installing}&step=$1" \
        -X POST "${CALLBACK_URL}" || true
}

failPing() {
    curl -s --insecure -d "status=failed&step=$1" \
        -X POST "${CALLBACK_URL}" || true
}

trap 'failPing "Unexpected error on line $LINENO"' ERR

# ── Root check ─────────────────────────────────────────────

installPing "Checking root access"
if [ "$(id -u)" -ne 0 ]; then
    failPing "Not running as root"
    echo "ERROR: This script must be run as root." >&2
    exit 1
fi

# ── Create directories ─────────────────────────────────────

installPing "Creating site directories"
mkdir -p "${SITE_DIR}"
mkdir -p "${STACK_DIR}"
mkdir -p "${WP_DIR}"
chown -R sword:sword "${SITE_DIR}"

# ── Create MySQL database and user ─────────────────────────

installPing "Creating MySQL database"
docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

installPing "Creating MySQL user"
docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';"
docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"

# ── Write Dockerfile for PHP container ────────────────────

installPing "Writing PHP Dockerfile"
cat > "${STACK_DIR}/Dockerfile" <<DOCKERFILEEOF
FROM php:${PHP_VERSION}-fpm-alpine

# Install PHP extensions required by WordPress
RUN apk add --no-cache \
        freetype libpng libjpeg-turbo freetype-dev libpng-dev libjpeg-turbo-dev \
        libzip-dev icu-dev icu-libs libintl oniguruma-dev curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j\$(nproc) \
        gd mysqli pdo pdo_mysql zip intl mbstring exif opcache \
    && apk del freetype-dev libpng-dev libjpeg-turbo-dev icu-dev

# Install WP-CLI
RUN curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Raise PHP memory limit for WP-CLI and WordPress
RUN echo 'memory_limit = 256M' > /usr/local/etc/php/conf.d/sword.ini
DOCKERFILEEOF

# ── Write Docker Compose file ──────────────────────────────

installPing "Writing Docker Compose file"
cat > "${STACK_DIR}/docker-compose.yml" <<COMPOSEEOF
services:
  php:
    build:
      context: .
      dockerfile: Dockerfile
    image: sword_php_${PHP_VERSION}
    container_name: sword_${DB_NAME}_php
    restart: unless-stopped
    volumes:
      - ${WP_DIR}:/var/www/html
    environment:
      - PHP_FPM_POOL_NAME=${DB_NAME}
    networks:
      - sword_network

  nginx:
    image: nginx:alpine
    container_name: sword_${DB_NAME}_nginx
    restart: unless-stopped
    command: >
      sh -c "mkdir -p /var/cache/nginx/fastcgi && exec nginx -g 'daemon off;'"
    volumes:
      - ${WP_DIR}:/var/www/html:ro
      - ${STACK_DIR}/nginx.conf:/etc/nginx/conf.d/default.conf:ro
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.${DB_NAME}.rule=Host(\`${DOMAIN}\`)"
      - "traefik.http.routers.${DB_NAME}.entrypoints=websecure"
      - "traefik.http.routers.${DB_NAME}.tls.certresolver=letsencrypt"
    networks:
      - sword_network

networks:
  sword_network:
    external: true
COMPOSEEOF

# ── Write Nginx config ─────────────────────────────────────

installPing "Writing Nginx config"
cat > "${STACK_DIR}/nginx.conf" <<NGINXEOF
fastcgi_cache_path /var/cache/nginx/fastcgi levels=1:2 keys_zone=WORDPRESS:10m inactive=60m;
fastcgi_cache_key "\$http_host\$request_method\$request_uri";

server {
    listen 80;
    server_name ${DOMAIN};
    root /var/www/html;
    index index.php;

    # FastCGI cache settings
    set \$skip_cache 0;

    if (\$request_method = POST)        { set \$skip_cache 1; }
    if (\$query_string != "")           { set \$skip_cache 1; }
    if (\$request_uri ~* "/wp-admin/|/xmlrpc.php|/wp-login.php") { set \$skip_cache 1; }
    if (\$http_cookie ~* "comment_author|wordpress_[a-f0-9]+|wp-postpass|wordpress_no_cache|wordpress_logged_in") { set \$skip_cache 1; }

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php\$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;

        fastcgi_cache WORDPRESS;
        fastcgi_cache_valid 200 60m;
        fastcgi_cache_bypass \$skip_cache;
        fastcgi_no_cache \$skip_cache;
        add_header X-FastCGI-Cache \$upstream_cache_status;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2)\$ {
        expires max;
        log_not_found off;
    }

    location = /favicon.ico { log_not_found off; access_log off; }
    location = /robots.txt  { log_not_found off; access_log off; }

    location ~* ^/wp-config\.php { deny all; }
    location ~ /\. { deny all; }
}
NGINXEOF

# ── Start containers ───────────────────────────────────────

installPing "Starting containers"
docker compose -f "${STACK_DIR}/docker-compose.yml" up -d --build

# Give PHP-FPM a moment to become ready
sleep 3

# ── Download WordPress ─────────────────────────────────────

installPing "Downloading WordPress"
docker exec "sword_${DB_NAME}_php" wp core download \
    --path=/var/www/html \
    --allow-root \
    --locale=en_US \
    --force

# ── Create wp-config.php ───────────────────────────────────

installPing "Creating wp-config.php"
docker exec "sword_${DB_NAME}_php" wp config create \
    --path=/var/www/html \
    --dbname="${DB_NAME}" \
    --dbuser="${DB_USER}" \
    --dbpass="${DB_PASS}" \
    --dbhost="sword_mysql" \
    --allow-root \
    --force \
    --extra-php <<'WPEXTRAEOF'
/** Redis object cache */
define( 'WP_REDIS_HOST', 'sword_redis' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_CACHE', true );

/** Disable file editing in dashboard */
define( 'DISALLOW_FILE_EDIT', true );

/** FastCGI cache purge support */
define( 'RT_WP_NGINX_HELPER_CACHE_PATH', '/var/cache/nginx/fastcgi' );
WPEXTRAEOF

# ── Run WordPress install ──────────────────────────────────

installPing "Installing WordPress"
set +e
WP_ADMIN_PASS="$(tr -dc 'A-Za-z0-9!@#%^&*' < /dev/urandom | head -c 20)"
set -e
docker exec "sword_${DB_NAME}_php" wp core install \
    --path=/var/www/html \
    --url="https://${DOMAIN}" \
    --title="${DOMAIN}" \
    --admin_user="sword_admin" \
    --admin_password="${WP_ADMIN_PASS}" \
    --admin_email="admin@${DOMAIN}" \
    --skip-email \
    --allow-root

echo ""
echo "  WordPress admin credentials:"
echo "  URL:      https://${DOMAIN}/wp-admin"
echo "  User:     sword_admin"
echo "  Password: ${WP_ADMIN_PASS}"
echo ""

# ── Fix permissions ────────────────────────────────────────

installPing "Fixing file permissions"
chown -R sword:www-data "${WP_DIR}"
find "${WP_DIR}" -type d -exec chmod 755 {} \;
find "${WP_DIR}" -type f -exec chmod 644 {} \;

# ── Remove default plugins ─────────────────────────────────

installPing "Removing Hello Dolly plugin"
docker exec "sword_${DB_NAME}_php" wp plugin delete hello \
    --path=/var/www/html \
    --allow-root || true

# ── Install & activate Redis object cache plugin ───────────

installPing "Installing Redis object cache plugin"
docker exec "sword_${DB_NAME}_php" wp plugin install redis-cache \
    --path=/var/www/html \
    --allow-root

#docker exec "sword_${DB_NAME}_php" wp plugin activate redis-cache \
#    --path=/var/www/html \
#    --allow-root

#docker exec "sword_${DB_NAME}_php" wp redis enable \
#    --path=/var/www/html \
#    --allow-root

# ── Install & activate Nginx Helper (FastCGI cache purging) ─

installPing "Installing Nginx Helper plugin"
docker exec "sword_${DB_NAME}_php" wp plugin install nginx-helper \
    --path=/var/www/html \
    --allow-root

docker exec "sword_${DB_NAME}_php" wp plugin activate nginx-helper \
    --path=/var/www/html \
    --allow-root

docker exec "sword_${DB_NAME}_php" wp option update rt_wp_nginx_helper_options \
    '{"enable_purge":"1","cache_method":"enable_fastcgi","purge_method":"unlink_files","enable_map":null,"enable_log":null,"log_level":"ERROR","log_filesize":"5","enable_stamp":null,"purge_homepage_on_del":null,"purge_homepage_on_new":"1","purge_homepage_on_mod":"1","purge_archive_on_del":null,"purge_archive_on_new":"1","purge_archive_on_mod":"1","purge_archive_on_type_del":null,"purge_archive_on_type_new":"1","purge_archive_on_type_mod":"1","purge_page_on_mod":"1","purge_page_on_del":"1","purge_tags_on_change":null}' \
    --format=json \
    --path=/var/www/html \
    --allow-root

# ── Restart Ofelia to pick up any new cron jobs ────────────

installPing "Restarting Ofelia"
docker restart sword_ofelia || true

# ── Done ───────────────────────────────────────────────────

installPing "WordPress installation complete" "installed"
echo ""
echo "============================================"
echo " SWORD site installation complete!"
echo " Domain: ${DOMAIN}"
echo "============================================"
