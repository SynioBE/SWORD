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

  nginx:
    image: nginx:alpine
    container_name: sword_{{ $site->id }}_nginx
    restart: unless-stopped
    volumes:
      - ${WP_DIR}:/var/www/html:ro
      - ${STACK_DIR}/nginx.conf:/etc/nginx/conf.d/default.conf:ro
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
server {
    listen 80;
    server_name ${DOMAIN};
    root /var/www/html;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php\$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
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

docker exec "sword_{{ $site->id }}_php" wp config create \
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
WPEXTRAEOF

# ── Run WordPress install ────────────────────────────────

echo "Installing WordPress..."

set +e
WP_ADMIN_PASS="$(tr -dc 'A-Za-z0-9!@#%^&*' < /dev/urandom | head -c 20)"
set -e
docker exec "sword_{{ $site->id }}_php" wp core install \
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

# ── Fix permissions ──────────────────────────────────────

echo "Fixing file permissions..."

chown -R sword:sword "${SITE_DIR}"

# ── Remove default plugins ───────────────────────────────

echo "Removing default WP plugins..."

docker exec "sword_{{ $site->id }}_php" wp plugin delete hello \
    --path=/var/www/html \
    --allow-root || true

updateProgress "install_wordpress"

# ── Restart Ofelia to pick up any new cron jobs ──────────

echo "Restarting Ofelia..."

docker restart sword_ofelia || true

# ── Done ─────────────────────────────────────────────────

updateProgress "created" "created"

echo ""
echo "============================================"
echo " SWORD site installation complete!"
echo " Domain: ${DOMAIN}"
echo "============================================"
