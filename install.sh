#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# SWORD Self-Hosted Installer
# Usage: curl -sL <url> | bash
# ============================================================

REPO="https://github.com/SynioBE/SWORD.git"
BRANCH="${SWORD_BRANCH:-main}"
SWORD_DIR="/srv/sword"
CLONE_DIR=""

# ── Colors ──────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[SWORD]${NC} $1"; }
ok()    { echo -e "${GREEN}[SWORD]${NC} $1"; }
warn()  { echo -e "${YELLOW}[SWORD]${NC} $1"; }
fail()  { echo -e "${RED}[SWORD]${NC} $1"; exit 1; }

# ── Cleanup trap ────────────────────────────────────────

cleanup() {
    if [ -n "$CLONE_DIR" ] && [ -d "$CLONE_DIR" ]; then
        rm -rf "$CLONE_DIR"
    fi
    # Remove secrets temp file if it exists
    rm -f /tmp/sword-init-config.json 2>/dev/null || true
}
trap cleanup EXIT

# ── Root & OS check ────────────────────────────────────

if [ "$(id -u)" -ne 0 ]; then
    fail "This script must be run as root."
fi

if [ ! -f /etc/os-release ]; then
    fail "Cannot detect OS. /etc/os-release not found."
fi

. /etc/os-release
if [ "$ID" != "ubuntu" ] || [ "$VERSION_ID" != "24.04" ]; then
    fail "This installer requires Ubuntu 24.04. Detected: $ID $VERSION_ID"
fi

# ── Prompts ─────────────────────────────────────────────

echo ""
echo -e "${CYAN}╔══════════════════════════════════════╗${NC}"
echo -e "${CYAN}║        SWORD Installer               ║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════╝${NC}"
echo ""

read -rp "Domain for SWORD (e.g. sword.example.com): " SWORD_DOMAIN < /dev/tty
[ -z "$SWORD_DOMAIN" ] && fail "Domain is required."

# Validate domain format
if ! echo "$SWORD_DOMAIN" | grep -qP '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'; then
    fail "Invalid domain format: $SWORD_DOMAIN"
fi

read -rp "Email for Let's Encrypt certificates: " LE_EMAIL < /dev/tty
[ -z "$LE_EMAIL" ] && fail "Email is required."

# Validate email format
if ! echo "$LE_EMAIL" | grep -qP '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'; then
    fail "Invalid email format: $LE_EMAIL"
fi

read -rp "Admin name: " ADMIN_NAME < /dev/tty
[ -z "$ADMIN_NAME" ] && fail "Admin name is required."

read -rp "Admin email: " ADMIN_EMAIL < /dev/tty
[ -z "$ADMIN_EMAIL" ] && fail "Admin email is required."

if ! echo "$ADMIN_EMAIL" | grep -qP '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'; then
    fail "Invalid email format: $ADMIN_EMAIL"
fi

read -srp "Admin password (min 8 characters): " ADMIN_PASSWORD < /dev/tty
echo ""
[ -z "$ADMIN_PASSWORD" ] && fail "Admin password is required."
[ ${#ADMIN_PASSWORD} -lt 8 ] && fail "Admin password must be at least 8 characters."

info "Starting installation..."

# ── Detect public IP ───────────────────────────────────

SERVER_IP=$(curl -s4 https://ifconfig.me || curl -s4 https://api.ipify.org || true)

# Validate we got a public IP (not empty, not private range)
if [ -z "$SERVER_IP" ]; then
    fail "Could not detect public IP address. Check your network connection."
fi

if echo "$SERVER_IP" | grep -qP '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|127\.)'; then
    warn "Detected IP $SERVER_IP appears to be a private address."
    read -rp "Enter the public IP of this server: " SERVER_IP < /dev/tty
    [ -z "$SERVER_IP" ] && fail "Public IP is required."
fi

info "Detected public IP: $SERVER_IP"

# ── Apt helpers ─────────────────────────────────────────

export DEBIAN_FRONTEND=noninteractive

waitForApt() {
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do sleep 2; done
    while fuser /var/lib/dpkg/lock >/dev/null 2>&1; do sleep 2; done
    while fuser /var/lib/apt/lists/lock >/dev/null 2>&1; do sleep 2; done
}

# ── Install Docker ──────────────────────────────────────

if ! command -v docker >/dev/null 2>&1; then
    info "Installing Docker..."

    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --yes --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list

    waitForApt
    apt-get update

    waitForApt
    apt-get install -y -qq \
        docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin

    mkdir -p /etc/docker
    cat > /etc/docker/daemon.json <<'DOCKEREOF'
{
    "log-driver": "json-file",
    "log-opts": {
        "max-size": "50m",
        "max-file": "3"
    },
    "live-restore": true,
    "default-address-pools": [
      {
        "base": "10.240.0.0/16",
        "size": 24
      }
    ]
}
DOCKEREOF

    systemctl enable --now docker
    ok "Docker installed."
else
    ok "Docker already installed."
fi

# ── Install git if missing ──────────────────────────────

if ! command -v git >/dev/null 2>&1; then
    waitForApt
    apt-get install -y -qq git
fi

# ── Create sword user ──────────────────────────────────

info "Setting up sword user..."

SUDO_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)

if ! id sword &>/dev/null; then
    useradd -m -s /bin/bash sword
fi

groupadd -f docker
usermod -aG docker sword
usermod -aG sudo sword

echo "sword:${SUDO_PASSWORD}" | chpasswd

# Generate SSH keypair for sword user
mkdir -p /home/sword/.ssh
chmod 700 /home/sword/.ssh

if [ ! -f /home/sword/.ssh/id_ed25519 ]; then
    ssh-keygen -t ed25519 -f /home/sword/.ssh/id_ed25519 -N "" -C "sword-localhost"
fi

# Authorize sword's public key in root's authorized_keys
mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

SWORD_PUBKEY=$(cat /home/sword/.ssh/id_ed25519.pub)
if ! grep -qF "${SWORD_PUBKEY}" /root/.ssh/authorized_keys; then
    echo "${SWORD_PUBKEY}" >> /root/.ssh/authorized_keys
fi

chown -R sword:sword /home/sword/.ssh

# Add host key to known_hosts so SSH doesn't prompt
ssh-keyscan -H "$SERVER_IP" >> /home/sword/.ssh/known_hosts 2>/dev/null || true
ssh-keyscan -H localhost >> /home/sword/.ssh/known_hosts 2>/dev/null || true
chown sword:sword /home/sword/.ssh/known_hosts

ok "sword user ready."

# ── Directory structure ─────────────────────────────────

info "Creating directory structure..."

mkdir -p "$SWORD_DIR"/{shared/mysql/data,app/storage,sites,stacks,letsencrypt}

# ── Docker network ──────────────────────────────────────

if ! docker network ls -q -f name=^sword_network$ | grep -q .; then
    docker network create sword_network
fi

# ── Generate secrets ────────────────────────────────────

APP_KEY="base64:$(openssl rand -base64 32)"
DB_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)
MYSQL_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)
REDIS_PASSWORD=$(openssl rand -base64 24 | tr -dc 'a-zA-Z0-9' | head -c 32)

# ── Shared infra .env ──────────────────────────────────

cat > "$SWORD_DIR/shared/.env" <<EOF
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
EOF
chmod 600 "$SWORD_DIR/shared/.env"

# ── MySQL config ────────────────────────────────────────

cat > "$SWORD_DIR/shared/mysql/my.cnf" <<'SQLEOF'
[mysqld]
user=mysql
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci

innodb_buffer_pool_size=1G
innodb_buffer_pool_instances=1
innodb_log_file_size=256M
innodb_flush_log_at_trx_commit=1
innodb_file_per_table=1
innodb_flush_method=O_DIRECT

max_connections=150
thread_cache_size=50
max_allowed_packet=64M
wait_timeout=60
interactive_timeout=60

table_open_cache=2000
tmp_table_size=64M
max_heap_table_size=64M

host_cache_size=0
skip-name-resolve
skip-log-bin

[mysql]
default-character-set=utf8mb4

[client]
default-character-set=utf8mb4
SQLEOF

# ── Shared infra docker-compose ─────────────────────────

cat > "$SWORD_DIR/shared/docker-compose.yml" <<'COMPOSEEOF'
services:
  traefik:
    image: traefik:v3
    container_name: sword_traefik
    restart: unless-stopped
    command:
      - "--providers.docker=true"
      - "--providers.docker.exposedbydefault=false"
      - "--entrypoints.web.address=:80"
      - "--entrypoints.web.http.redirections.entrypoint.to=websecure"
      - "--entrypoints.web.http.redirections.entrypoint.scheme=https"
      - "--entrypoints.web.http.redirections.entrypoint.permanent=true"
      - "--entrypoints.websecure.address=:443"
      - "--certificatesresolvers.letsencrypt.acme.httpchallenge=true"
      - "--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=web"
      - "--certificatesresolvers.letsencrypt.acme.email=__LE_EMAIL__"
      - "--certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json"
      - "--api.dashboard=false"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - /srv/sword/letsencrypt:/letsencrypt
    networks:
      - sword_network

  mysql:
    image: mysql:8.4
    container_name: sword_mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    volumes:
      - /srv/sword/shared/mysql/data:/var/lib/mysql
      - /srv/sword/shared/mysql/my.cnf:/etc/my.cnf
    networks:
      - sword_network

  ofelia:
    image: mcuadros/ofelia:v3.3.3
    container_name: sword_ofelia
    restart: unless-stopped
    command: "daemon --docker"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    networks:
      - sword_network

networks:
  sword_network:
    name: sword_network
    external: true
COMPOSEEOF

sed -i "s|__LE_EMAIL__|${LE_EMAIL}|g" "$SWORD_DIR/shared/docker-compose.yml"

# ── Start shared infra ──────────────────────────────────

info "Starting shared infrastructure..."
docker compose -f "$SWORD_DIR/shared/docker-compose.yml" up -d

# Wait for MySQL to be fully ready (including init scripts)
info "Waiting for MySQL to be ready..."
for i in $(seq 1 60); do
    if docker exec sword_mysql sh -c 'mysqladmin ping -p"${MYSQL_ROOT_PASSWORD}" --silent' 2>/dev/null; then
        # Also verify we can actually authenticate (init may still be running)
        if docker exec sword_mysql sh -c 'mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1"' >/dev/null 2>&1; then
            break
        fi
    fi
    sleep 2
done

# Verify MySQL is actually ready
if ! docker exec sword_mysql sh -c 'mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1"' >/dev/null 2>&1; then
    fail "MySQL failed to start within 120 seconds."
fi

ok "MySQL is ready."

# ── Create SWORD database and user ──────────────────────

info "Creating SWORD database..."

# Write SQL to a temp file inside the container to avoid secrets in process args
docker exec sword_mysql sh -c "cat > /tmp/init.sql <<'INITSQL'
CREATE DATABASE IF NOT EXISTS sword CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
INITSQL
"

# The DB_PASSWORD needs interpolation, so we write it separately
docker exec sword_mysql sh -c "echo \"CREATE USER IF NOT EXISTS 'sword'@'%' IDENTIFIED BY '${DB_PASSWORD}';\" >> /tmp/init.sql"
docker exec sword_mysql sh -c "echo \"GRANT ALL PRIVILEGES ON sword.* TO 'sword'@'%';\" >> /tmp/init.sql"
docker exec sword_mysql sh -c "echo 'FLUSH PRIVILEGES;' >> /tmp/init.sql"

docker exec sword_mysql sh -c 'mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" < /tmp/init.sql'
docker exec sword_mysql rm -f /tmp/init.sql

ok "Database ready."

# ── Write SWORD .env ────────────────────────────────────

cat > "$SWORD_DIR/app/.env" <<EOF
APP_NAME=SWORD
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${SWORD_DOMAIN}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=sword_mysql
DB_PORT=3306
DB_DATABASE=sword
DB_USERNAME=sword
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=sword_redis
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379

MAIL_MAILER=log

TRUSTED_PROXIES=*

SWORD_DOMAIN=${SWORD_DOMAIN}
EOF
chmod 600 "$SWORD_DIR/app/.env"

# ── Clone repo and build image ──────────────────────────

info "Cloning SWORD repository..."
CLONE_DIR=$(mktemp -d)
chmod 700 "$CLONE_DIR"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$CLONE_DIR"

info "Building SWORD Docker image (this may take a few minutes)..."
docker build -t sword-app:latest -f "$CLONE_DIR/docker/production/Dockerfile" "$CLONE_DIR"

# Copy the compose file
cp "$CLONE_DIR/docker/production/docker-compose.prod.yml" "$SWORD_DIR/app/docker-compose.prod.yml"

# Write .env for Docker Compose variable interpolation (SWORD_DOMAIN, REDIS_PASSWORD)
cat > "$SWORD_DIR/app/docker-compose.env" <<EOF
SWORD_DOMAIN=${SWORD_DOMAIN}
REDIS_PASSWORD=${REDIS_PASSWORD}
EOF
chmod 600 "$SWORD_DIR/app/docker-compose.env"

rm -rf "$CLONE_DIR"
CLONE_DIR=""

ok "Docker image built."

# ── Initialize storage ──────────────────────────────────

info "Initializing storage directories..."

mkdir -p "$SWORD_DIR/app/storage"/{app/public,framework/{cache/data,sessions,views},logs}
chown -R 33:33 "$SWORD_DIR/app/storage"

# ── Start SWORD ─────────────────────────────────────────

info "Starting SWORD application..."
docker compose --env-file "$SWORD_DIR/app/docker-compose.env" -f "$SWORD_DIR/app/docker-compose.prod.yml" up -d

# Wait for the app container to be running
sleep 5

# ── Run migrations ──────────────────────────────────────

info "Running database migrations..."
docker exec sword_app php artisan migrate --force

# ── Run sword:init ──────────────────────────────────────

info "Initializing SWORD..."

# Write secrets to a temp file (not CLI args) to avoid process list exposure
INIT_CONFIG=$(mktemp)
chmod 600 "$INIT_CONFIG"
cat > "$INIT_CONFIG" <<INITEOF
{
    "admin_name": $(printf '%s' "$ADMIN_NAME" | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))'),
    "admin_email": $(printf '%s' "$ADMIN_EMAIL" | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))'),
    "admin_password": $(printf '%s' "$ADMIN_PASSWORD" | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))'),
    "server_ip": $(printf '%s' "$SERVER_IP" | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))'),
    "mysql_root_password": $(printf '%s' "$MYSQL_ROOT_PASSWORD" | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))'),
    "sudo_password": $(printf '%s' "$SUDO_PASSWORD" | python3 -c 'import sys,json; print(json.dumps(sys.stdin.read()))'),
    "ssh_private_key": $(python3 -c "import sys,json; print(json.dumps(open('/home/sword/.ssh/id_ed25519').read()))"),
    "ssh_public_key": $(python3 -c "import sys,json; print(json.dumps(open('/home/sword/.ssh/id_ed25519.pub').read()))")
}
INITEOF

# Copy config file into container, run init, then remove it
docker cp "$INIT_CONFIG" sword_app:/tmp/sword-init-config.json
rm -f "$INIT_CONFIG"
docker exec sword_app php artisan sword:init /tmp/sword-init-config.json
docker exec sword_app rm -f /tmp/sword-init-config.json

# ── Firewall ────────────────────────────────────────────

info "Configuring firewall..."

if ! command -v ufw >/dev/null 2>&1; then
    waitForApt
    apt-get install -y -qq ufw
fi

ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment "SSH"
ufw allow 80/tcp comment "HTTP"
ufw allow 443/tcp comment "HTTPS"
ufw --force enable
ok "Firewall configured."

# ── Done ────────────────────────────────────────────────

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║            SWORD Installation Complete!              ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  URL:      ${CYAN}https://${SWORD_DOMAIN}${NC}"
echo -e "  Email:    ${CYAN}${ADMIN_EMAIL}${NC}"
echo -e "  Server:   ${CYAN}${SERVER_IP}${NC}"
echo ""
echo -e "  ${YELLOW}Sudo password for 'sword' user:${NC}"
echo -e "  ${CYAN}${SUDO_PASSWORD}${NC}"
echo ""
echo -e "  ${YELLOW}Credentials saved to:${NC}"
echo -e "  ${CYAN}${SWORD_DIR}/app/.env${NC}"
echo -e "  ${CYAN}${SWORD_DIR}/shared/.env${NC}"
echo ""
echo -e "  ${RED}Save the sudo password above — it is not stored elsewhere.${NC}"
echo -e "  ${YELLOW}Please also save your admin password — it is not stored in plaintext.${NC}"
echo ""
