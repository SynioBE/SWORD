#!/usr/bin/env bash
# ============================================================
# SWORD Server Provisioning Script
# Server: {{ $server->name }} (ID: {{ $server->id }})
# Generated: {{ now()->toIso8601String() }}
# ============================================================

set -euo pipefail

CALLBACK_URL="{{ $callbackUrl }}"

# ── Helpers ────────────────────────────────────────────────

provisionPing() {
    curl -s --insecure -d "status=${2:-provisioning}&step=$1" \
        -X POST "${CALLBACK_URL}" || true
}

failPing() {
    curl -s --insecure -d "status=failed&step=$1" \
        -X POST "${CALLBACK_URL}" || true
}

trap 'failPing "Unexpected error on line $LINENO"' ERR

# ── Root check ─────────────────────────────────────────────

provisionPing "Checking root access"
if [ "$(id -u)" -ne 0 ]; then
    failPing "Not running as root"
    echo "ERROR: This script must be run as root." >&2
    exit 1
fi

# ── IPv4 preference ────────────────────────────────────────

provisionPing "Setting IPv4 preference"
echo 'precedence ::ffff:0:0/96  100' >> /etc/gai.conf

# ── Wait for apt lock ──────────────────────────────────────

provisionPing "Waiting for package manager"
while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
    sleep 3
done

export DEBIAN_FRONTEND=noninteractive

# ── Swap ───────────────────────────────────────────────────

provisionPing "Configuring swap"
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
sysctl vm.swappiness=10
echo 'vm.swappiness=10' >> /etc/sysctl.conf

# ── OS upgrade ────────────────────────────────────────────

provisionPing "Upgrading OS packages"
apt-get update -qq
apt-get upgrade -y -qq
apt-get autoremove -y -qq

# ── Extra packages ────────────────────────────────────────

provisionPing "Installing extra packages"
apt-get install -y -qq \
    curl wget git unzip ufw fail2ban \
    apt-transport-https ca-certificates gnupg lsb-release \
    software-properties-common net-tools htop jq

# ── Cron ─────────────────────────────────────────────────

provisionPing "Ensuring cron is running"
systemctl enable cron
systemctl start cron

# ── Docker ───────────────────────────────────────────────

provisionPing "Adding Docker GPG key"
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

provisionPing "Adding Docker repository"
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | tee /etc/apt/sources.list.d/docker.list > /dev/null

provisionPing "Installing Docker"
apt-get update -qq
apt-get install -y -qq \
    docker-ce docker-ce-cli containerd.io \
    docker-buildx-plugin docker-compose-plugin

provisionPing "Configuring Docker daemon"
mkdir -p /etc/docker
cat > /etc/docker/daemon.json <<'DOCKEREOF'
{
    "log-driver": "json-file",
    "log-opts": {
        "max-size": "10m",
        "max-file": "3"
    },
    "live-restore": true
}
DOCKEREOF

provisionPing "Restarting Docker"
systemctl enable docker
systemctl restart docker

# ── SSH setup ─────────────────────────────────────────────

provisionPing "Hardening SSH"
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config

provisionPing "Adding Sword app SSH key"
mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
SWORD_PUBKEY="{{ $server->ssh_public_key }}"
if ! grep -qF "${SWORD_PUBKEY}" /root/.ssh/authorized_keys; then
    echo "${SWORD_PUBKEY}" >> /root/.ssh/authorized_keys
fi

provisionPing "Generating SSH key pair"
mkdir -p /root/.ssh
if [ ! -f /root/.ssh/id_ed25519 ]; then
    ssh-keygen -t ed25519 -f /root/.ssh/id_ed25519 -N "" -C "sword-server-{{ $server->id }}"
fi

provisionPing "Restarting SSH"
systemctl restart ssh

provisionPing "Setting hostname"
hostnamectl set-hostname "{{ $server->hostname ?? $server->name }}"

provisionPing "Setting timezone"
timedatectl set-timezone "{{ $server->timezone }}"

# ── Sword user ───────────────────────────────────────────

provisionPing "Creating sword user"
if ! id sword &>/dev/null; then
    useradd -m -s /bin/bash sword
fi

provisionPing "Creating sword directories"
mkdir -p /home/sword/.sword
mkdir -p /home/sword/.ssh

provisionPing "Adding sword to docker and sudo groups"
usermod -aG docker sword
usermod -aG sudo sword

provisionPing "Configuring sudo for sword"
echo 'sword ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/sword
chmod 440 /etc/sudoers.d/sword

provisionPing "Copying SSH keys for sword"
cp /root/.ssh/authorized_keys /home/sword/.ssh/authorized_keys 2>/dev/null || true

provisionPing "Copying known hosts"
for HOST in github.com gitlab.com bitbucket.org; do
    ssh-keyscan -H "$HOST" >> /etc/ssh/ssh_known_hosts 2>/dev/null || true
done

provisionPing "Configuring Git"
git config --global core.autocrlf input
git config --global init.defaultBranch main

provisionPing "Setting ownership of sword home"
chown -R sword:sword /home/sword
chmod 700 /home/sword/.ssh
chmod 600 /home/sword/.ssh/authorized_keys 2>/dev/null || true

# ── Firewall ─────────────────────────────────────────────

provisionPing "Configuring UFW firewall"
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow {{ $server->ssh_port }}/tcp comment "SSH"
ufw allow 80/tcp comment "HTTP"
ufw allow 443/tcp comment "HTTPS"
ufw --force enable

# ── Unattended upgrades ──────────────────────────────────

provisionPing "Configuring unattended security upgrades"
apt-get install -y -qq unattended-upgrades
cat > /etc/apt/apt.conf.d/50unattended-upgrades <<'UUEOF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
};
Unattended-Upgrade::Automatic-Reboot "false";
UUEOF
dpkg-reconfigure --priority=low unattended-upgrades -f noninteractive

# ── Sword global dirs ────────────────────────────────────

provisionPing "Creating global sword directories"
mkdir -p /srv/sword/sites
mkdir -p /srv/sword/nginx/conf.d
chown -R sword:sword /srv/sword

# ── Traefik + shared services ─────────────────────────────

provisionPing "Deploying shared services (Traefik, MySQL, Redis)"
mkdir -p /srv/sword/stacks/shared
cat > /srv/sword/stacks/shared/docker-compose.yml <<'COMPOSEEOF'
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
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-swordmysql}
    volumes:
      - sword_mysql_data:/var/lib/mysql
    networks:
      - sword_network

  redis:
    image: redis:7-alpine
    container_name: sword_redis
    restart: unless-stopped
    volumes:
      - sword_redis_data:/data
    networks:
      - sword_network

  ofelia:
    image: mcuadros/ofelia:latest
    container_name: sword_ofelia
    restart: unless-stopped
    depends_on:
      - mysql
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - /srv/sword/ofelia:/etc/ofelia
    networks:
      - sword_network

networks:
  sword_network:
    name: sword_network
    driver: bridge

volumes:
  sword_mysql_data:
  sword_redis_data:
COMPOSEEOF

provisionPing "Starting shared services"
mkdir -p /srv/sword/ofelia
touch /srv/sword/ofelia/config.ini
docker compose -f /srv/sword/stacks/shared/docker-compose.yml up -d

# ── Docker auto-start ────────────────────────────────────

provisionPing "Enabling Docker auto-start"
systemctl enable docker

# ── Done ─────────────────────────────────────────────────

provisionPing "Server provisioning complete" "provisioned"
echo ""
echo "============================================"
echo " SWORD provisioning complete!"
echo " Server: {{ $server->name }}"
echo "============================================"
