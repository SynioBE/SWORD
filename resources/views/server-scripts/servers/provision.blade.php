#!/usr/bin/env bash
# ============================================================
# SWORD Server Provisioning Script
# Server: {{ $server->name }} (ID: {{ $server->id }})
# Generated: {{ now()->toIso8601String() }}
# ============================================================

CALLBACK_URL="{{ $callbackUrl }}"

# ── Helpers ──────────────────────────────────────────────

updateProgress() {
    echo "[$(date '+%H:%M:%S')] step: $1 → status: ${2:-provisioning}"
    curl -s --insecure -d "status=${2:-provisioning}&step=$1" \
        -X POST "${CALLBACK_URL}" > /dev/null || true
}

notifyFailure() {
    echo "[$(date '+%H:%M:%S')] step: $1 → status: failed"
    curl -s --insecure -d "status=failed&step=$1" \
        -X POST "${CALLBACK_URL}" > /dev/null || true
}

ensureConfigLine() {
    local file="$1"
    local match_regex="$2"
    local new_line="$3"

    touch "$file"

    if grep -Eq "$match_regex" "$file"; then
        sed -i -E "s|$match_regex.*|$new_line|" "$file"
    else
        echo "$new_line" >> "$file"
    fi
}

waitForApt() {
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
        echo "Waiting on dpkg/lock-frontend..."
        sleep 3
    done

    while fuser /var/lib/dpkg/lock >/dev/null 2>&1; do
        echo "Waiting on dpkg/lock..."
        sleep 3
    done

    while fuser /var/lib/apt/lists/lock >/dev/null 2>&1; do
        echo "Waiting on lists/lock..."
        sleep 3
    done

    if [ -f /var/log/unattended-upgrades/unattended-upgrades.log ]; then
        while fuser /var/log/unattended-upgrades/unattended-upgrades.log >/dev/null 2>&1; do
            echo "Waiting on unattended-upgrades..."
            sleep 3
        done
    fi
}

trap 'notifyFailure "Unexpected error on line $LINENO"' ERR

updateProgress "started"

# ── Root check ───────────────────────────────────────────

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root." >&2
    exit 1
fi

# ── IPv4 preference ──────────────────────────────────────

sed -i "s/#precedence ::ffff:0:0\/96  100/precedence ::ffff:0:0\/96  100/" /etc/gai.conf

# ── Swap ─────────────────────────────────────────────────

echo "Configuring swapfile..."

ramKB=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
ramGB=$(( (ramKB + 1024*1024 - 1) / (1024*1024) )) # round up to GB

if (( ramGB <= 2 )); then
    swapGB=$(( ramGB * 2 ))
elif (( ramGB <= 8 )); then
    swapGB=$ramGB
elif (( ramGB <= 64 )); then
    swapGB=$(( ramGB / 2 ))
else
    swapGB=32
fi

echo "Total RAM: ${ramGB}GB"
echo "Calculated swap size: ${swapGB}GB"

if [ ! -f /swapfile ]; then
    fallocate -l ${swapGB}G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    ensureConfigLine /etc/fstab '^[[:space:]]*/swapfile[[:space:]]+none[[:space:]]+swap[[:space:]]+' '/swapfile none swap sw 0 0'
else
    echo "Swapfile already exists, skipping creation."
fi

sysctl vm.swappiness=10
ensureConfigLine /etc/sysctl.conf '^[[:space:]]*vm\.swappiness[[:space:]]*=' 'vm.swappiness=10'
ensureConfigLine /etc/sysctl.conf '^[[:space:]]*vm\.vfs_cache_pressure[[:space:]]*=' 'vm.vfs_cache_pressure=50'

updateProgress "configuring_swap"

# ── OS upgrade & Extra packages ──────────────────────────

export DEBIAN_FRONTEND=noninteractive

echo "Upgrading OS..."

waitForApt
apt-get update

waitForApt
apt-get upgrade -y

updateProgress "os_upgrade"

echo "Installing extra packages..."

waitForApt
apt-get install -y -qq \
  curl wget git zip unzip gzip tar rsync bc openssl jq gnupg \
  software-properties-common apt-transport-https ca-certificates \
  lsb-release whois cron ufw borgbackup sshpass

updateProgress "install_packages"

# ── Cron ─────────────────────────────────────────────────

systemctl enable cron.service >/dev/null 2>&1 || true
systemctl start cron.service >/dev/null 2>&1 || true

# ── Docker ───────────────────────────────────────────────

echo "Adding Docker repository..."

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
    | gpg --yes --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

ensureConfigLine \
  /etc/apt/sources.list.d/docker.list \
  '^deb .+download\.docker\.com/linux/ubuntu .+ stable$' \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable"

if ! command -v docker >/dev/null 2>&1; then
  echo "Installing Docker..."

  waitForApt
  apt-get update

  waitForApt
  apt-get install -y -qq \
      docker-ce docker-ce-cli containerd.io \
      docker-buildx-plugin docker-compose-plugin
else
  echo "Docker is already installed, skipping installation."
fi

echo "Configuring Docker daemon..."

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

updateProgress "docker_setup"

# ── SSH setup ────────────────────────────────────────────

echo "Configuring SSH..."

# COMMENTED OUT FOR NOW BECAUSE IT IS EASIER TO WORK ON THIS TOGETHER
# DURING THE HACKATHON WHEN WE CAN LOGIN USING A SHARED PASSWORD

#sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
#sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config

ssh-keygen -A
systemctl restart ssh

if ! grep -qF "127.0.0.1 {{ $server->hostname }}.localdomain {{ $server->hostname }}" /etc/hosts; then
  ensureConfigLine \
    /etc/hosts \
    '^127\.0\.1\.1[[:space:]]+' \
    "127.0.0.1 {{ $server->hostname }}.localdomain {{ $server->hostname }}"
fi

echo "Authorizing SWORD SSH key..."

mkdir -p /root/.ssh
chmod 700 /root/.ssh

touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

SWORD_PUBKEY="{{ $server->ssh_public_key }}"
if ! grep -qF "${SWORD_PUBKEY}" /root/.ssh/authorized_keys; then
    echo "${SWORD_PUBKEY}" >> /root/.ssh/authorized_keys
fi

updateProgress "ssh_setup"

# ── Hostname and Timezone ────────────────────────────────

hostnamectl set-hostname "{{ $server->hostname }}"
timedatectl set-timezone "{{ $server->timezone }}"

# ── SWORD user ───────────────────────────────────────────

echo "Creating SWORD user..."

if ! id sword &>/dev/null; then
    useradd -m -s /bin/bash sword
fi

mkdir -p /home/sword/.ssh
chmod 700 /home/sword/.ssh

groupadd -f docker
usermod -aG docker sword
usermod -aG sudo sword

PASSWORD=$(mkpasswd --method=SHA-512 "{{ $server->sudo_password }}")
usermod --password $PASSWORD sword

echo "Generating SSH key pair for user..."

if [ ! -f /home/sword/.ssh/id_ed25519 ]; then
    ssh-keygen -t ed25519 -f /home/sword/.ssh/id_ed25519 -N "" -C "sword-{{ $server->id }}"
    chmod 700 /home/sword/.ssh/id_ed25519
fi

cp /root/.ssh/authorized_keys /home/sword/.ssh/authorized_keys 2>/dev/null || true

for HOST in github.com gitlab.com bitbucket.org; do
    if ! ssh-keygen -F "$HOST" -f /etc/ssh/ssh_known_hosts >/dev/null; then
        ssh-keyscan -H "$HOST" >> /etc/ssh/ssh_known_hosts 2>/dev/null || true
    fi
done

# TODO: Get below values from account settings in SWORD
# Disabled for now. We don't neet Git yet.

#git config --global user.name "Wesley Stessens"
#git config --global user.email "wesley@syn.io"

echo "Updating file permissions..."

chown -R sword:sword /home/sword
chmod 600 /home/sword/.ssh/authorized_keys 2>/dev/null || true

updateProgress "user_setup"

# ── Firewall ─────────────────────────────────────────────

echo "Configuring UFW firewall..."

ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow {{ $server->ssh_port }}/tcp comment "SSH"
ufw allow 80/tcp comment "HTTP"
ufw allow 443/tcp comment "HTTPS"
ufw --force enable

updateProgress "firewall_setup"

# ── Unattended upgrades ──────────────────────────────────

echo "Enabling unattended security updates..."

waitForApt
apt-get install -y --force-yes unattended-upgrades

cat > /etc/apt/apt.conf.d/20auto-upgrades <<'UUEOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
UUEOF

cat > /etc/apt/apt.conf.d/50unattended-upgrades <<'UUEOF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
Unattended-Upgrade::Automatic-Reboot "false";
UUEOF

updateProgress "security_updates"

# ── Sword global dirs ────────────────────────────────────

mkdir -p /srv/sword/sites
chown -R sword:sword /srv/sword

# ── Traefik + shared services ────────────────────────────

echo "Pulling Docker images..."

docker pull traefik:v3
docker pull mysql:8.0
docker pull mcuadros/ofelia:latest

mkdir -p /srv/sword/shared/mysql/data

echo "Configuring MySQL..."

cat > /srv/sword/shared/.env <<'ENVEOF'
MYSQL_ROOT_PASSWORD={{ $server->mysql_root_password }}
ENVEOF

cat > /srv/sword/shared/mysql/my.cnf <<'SQLEOF'
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

echo "Deploying shared services..."

network_exists=$(docker network ls -q -f name=^sword_network$)
if [ -z "$network_exists" ]; then
    docker network create sword_network
fi

cat > /srv/sword/shared/docker-compose.yml <<'COMPOSEEOF'
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
      - "--certificatesresolvers.letsencrypt.acme.email=domains@syn.io"
      - "--certificatesresolvers.letsencrypt.acme.storage=/letsencrypt/acme.json"
      - "--certificatesresolvers.letsencrypt.acme.caserver=https://acme-staging-v02.api.letsencrypt.org/directory"
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - /srv/sword/letsencrypt:/letsencrypt
    networks:
      - sword_network

  mysql:
    image: mysql:8.0
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
    image: mcuadros/ofelia:latest
    container_name: sword_ofelia
    restart: unless-stopped
    command: 'daemon --docker'
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
    networks:
      - sword_network

  dockhand:
    image: fnsys/dockhand:latest
    container_name: dockhand
    restart: unless-stopped
    ports:
      - "3000:3000"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - dockhand_data:/app/data

  netdata-agent:
    image: netdata/netdata:stable
    container_name: sword_netdata_agent
    pid: host
    network_mode: host
    restart: unless-stopped
    cap_add:
      - SYS_PTRACE
      - SYS_ADMIN
    security_opt:
      - apparmor:unconfined
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - /proc:/host/proc:ro
      - /sys:/host/sys:ro
      - /etc/os-release:/host/etc/os-release:ro
    environment:
      - NETDATA_STREAM_DESTINATION={{ request()->getHost() }}:19999
      - NETDATA_STREAM_API_KEY=$(echo "{{ config('app.key') }}" | tr -dc 'a-zA-Z0-9' | cut -c1-36)
    command: >
      /bin/sh -c "
      printf '[global]\n  memory mode = none\n  web mode = none\n' > /etc/netdata/netdata.conf;
      printf '[stream]\n  enabled = yes\n  destination = {{ request()->getHost() }}:19999\n  api key = \$\$(echo \"{{ config('app.key') }}\" | tr -dc 'a-zA-Z0-9' | cut -c1-36)\n' > /etc/netdata/stream.conf;
      exec /usr/sbin/netdata -D -u netdata"

volumes:
  dockhand_data:

networks:
  sword_network:
    name: sword_network
    external: true
COMPOSEEOF

docker compose -f /srv/sword/shared/docker-compose.yml up -d

updateProgress "provisioned" "provisioned"

echo ""
echo "============================================"
echo " SWORD provisioning complete!"
echo " Server: {{ $server->name }}"
echo "============================================"
