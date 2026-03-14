function provisionPing {
    curl -s --insecure -d "status=creating&step=$1" -X POST "https://app.flywp.com/callback/servers/7353/provision?signature=SIGNATURE"
}

if [[ $EUID -ne 0 ]]; then
    echo "This script must be run as root."

    exit 1
fi

provisionPing "provision_started"

apt_wait() {
    while fuser /var/lib/dpkg/lock >/dev/null 2>&1; do
        echo "Waiting: dpkg/lock is locked..."
        sleep 5
    done

    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; do
        echo "Waiting: dpkg/lock-frontend is locked..."
        sleep 5
    done

    while fuser /var/lib/apt/lists/lock >/dev/null 2>&1; do
        echo "Waiting: lists/lock is locked..."
        sleep 5
    done

    if [ -f /var/log/unattended-upgrades/unattended-upgrades.log ]; then
        while fuser /var/log/unattended-upgrades/unattended-upgrades.log >/dev/null 2>&1; do
            echo "Waiting: unattended-upgrades is locked..."
            sleep 5
        done
    fi
}

install_docker() {
    local attempts=0
    local max_attempts=4

    while ((attempts < max_attempts)); do
        if ! command -v docker >/dev/null 2>&1; then
            # Add Docker's official GPG key
            mkdir -m 0755 -p /etc/apt/keyrings
            curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

            # Add Docker's repository to APT sources
            echo \
                "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
                $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

            # Update package list again and install Docker
            apt-get update
            apt_wait

            # Install Docker
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

            # Check if Docker is installed successfully
            if command -v docker >/dev/null 2>&1; then
                echo "Docker installed successfully."
                return 0
            else
                echo "Failed to install Docker."
            fi
        else
            echo "Docker is already installed."
            return 0
        fi

        attempts=$((attempts + 1))
        echo "Retrying Docker installation... Attempt $attempts of $max_attempts"
        sleep 5 # Wait for 5 seconds before the next attempt
    done

    echo "Docker installation failed after $max_attempts attempts."
    return 1
}

update_docker_config() {
    echo "Updating Docker daemon..."
    # Create the Docker configuration file if it doesn't exist
    if [ ! -f /etc/docker/daemon.json ]; then
        mkdir -p /etc/docker
        touch /etc/docker/daemon.json
    fi

    # Update the Docker configuration file
    cat >/etc/docker/daemon.json <<EOF
{
    "storage-driver": "overlay2",
    "default-address-pools": [
        {
            "base": "172.80.0.0/16",
            "size": 24
        }
    ],
    "log-driver": "json-file",
    "log-opts": {
        "max-size": "100m",
        "max-file": "3"
    }
}
EOF
    echo "Docker daemon updated successfully."

    # Restart Docker
    echo "Restarting Docker..."
    systemctl restart docker
    echo "Docker restarted successfully."
}

echo "Checking apt-get availability..."

apt_wait

sudo sed -i "s/#precedence ::ffff:0:0\/96  100/precedence ::ffff:0:0\/96  100/" /etc/gai.conf

# Configure Swap Disk
# Get the total RAM size in gigabytes and round to the nearest whole number
total_ram_gb=$(awk '/^MemTotal:/{print $2/1024/1024}' /proc/meminfo)
total_ram_gb=$(printf "%.0f" $total_ram_gb)

# Calculate swap size based on the table provided (without hibernation)
if ((total_ram_gb >= 8)); then
    swap_size_gb=$(echo "$total_ram_gb * 0.375" | bc)
else
    case $total_ram_gb in
    1 | 2) swap_size_gb=1 ;;
    3 | 4 | 6) swap_size_gb=2 ;;
    *) swap_size_gb=1 ;;
    esac
fi

# Display swap size setting
echo "Total RAM: ${total_ram_gb}GB"
echo "Setting swap size to ${swap_size_gb}GB"

# Create and enable swap if it doesn't exist
if [ -f /swapfile ]; then
    echo "Swap file already exists."
else
    echo "Creating swap file of size ${swap_size_gb}GB"
    fallocate -l "${swap_size_gb}G" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo "/swapfile none swap sw 0 0" >>/etc/fstab
    echo "vm.swappiness=30" >>/etc/sysctl.conf
    echo "vm.vfs_cache_pressure=50" >>/etc/sysctl.conf
    echo "Swap file created and enabled."
fi

provisionPing "configure_swap"

# Upgrade The Base Packages

export DEBIAN_FRONTEND=noninteractive

apt-get update
apt_wait
apt-get upgrade -y
apt_wait

# Base Packages
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq software-properties-common apt-transport-https ca-certificates curl zip unzip gzip tar gnupg lsb-release whois jq bsdextrautils rsync wget git bc openssl cron

# Ensure cron is running (minimal Ubuntu images may not include it by default)
systemctl enable cron.service >/dev/null 2>&1 || true
systemctl start cron.service >/dev/null 2>&1 || true

provisionPing "upgrade_packages"

install_docker

update_docker_config
provisionPing "install_docker"

# Disable Password Authentication Over SSH

sed -i "/PasswordAuthentication yes/d" /etc/ssh/sshd_config
echo "" | sudo tee -a /etc/ssh/sshd_config
echo "" | sudo tee -a /etc/ssh/sshd_config
echo "PasswordAuthentication no" | sudo tee -a /etc/ssh/sshd_config

# Restart SSH

ssh-keygen -A
service ssh restart

# Set The Hostname If Necessary

sed -i 's/127\.0\.1\.1.*entrancing-manticore entrancing-manticore/127.0.0.1 entrancing-manticore.localdomain entrancing-manticore/' /etc/hosts

# Set The Timezone

# ln -sf /usr/share/zoneinfo/UTC /etc/localtime
ln -sf /usr/share/zoneinfo/UTC /etc/localtime

# Create The Root SSH Directory If Necessary

if [ ! -d /root/.ssh ]; then
    mkdir -p /root/.ssh
    touch /root/.ssh/authorized_keys
fi

# FlyWP specific folders
if [ ! -d ~/.fly ]; then
    mkdir ~/.fly
    mkdir ~/.provisions
fi

# Setup Fly User

if ! id -u fly >/dev/null 2>&1; then
    useradd fly
    mkdir -p /home/fly/.ssh
    mkdir -p /home/fly/.fly
    mkdir -p /home/fly/.provisions
    mkdir -p /home/fly/.certificates

    usermod -aG sudo fly
    groupadd -f docker
    usermod -aG docker fly
fi

# Setup Bash For Fly User

chsh -s /bin/bash fly
cp /root/.profile /home/fly/.profile
cp /root/.bashrc /home/fly/.bashrc

# Set The Sudo Password For Fly User
PASSWORD=$(mkpasswd --method=SHA-512 3jrLUss5V0jZKr1X4GgB)
usermod --password $PASSWORD fly

append_ssh_key_if_not_exists() {
    local key="$1"
    local file="$2"
    grep -qF "$key" "$file" || {
        # If the key does not exist, append the comment and the key
        sudo tee -a "$file" >/dev/null <<EOF
# FlyWP
$key
EOF
    }
}

# Append the SSH key and comment to root's authorized_keys file
append_ssh_key_if_not_exists "ssh-rsa BASE64== worker@flywp.com" /root/.ssh/authorized_keys

cp /root/.ssh/authorized_keys /home/fly/.ssh/authorized_keys

# Create The Server SSH Key
if [ ! -f /home/fly/.ssh/id_rsa.pub ]; then
    ssh-keygen -t rsa -b 4096 -f /home/fly/.ssh/id_rsa -N ""
fi

# Copy Source Control Public Keys Into Known Hosts File

ssh-keyscan -H github.com >>/home/fly/.ssh/known_hosts
ssh-keyscan -H bitbucket.org >>/home/fly/.ssh/known_hosts
ssh-keyscan -H gitlab.com >>/home/fly/.ssh/known_hosts

# Configure Git Settings

git config --global user.name "Wesley Stessens"
git config --global user.email "wesley@syn.io"

# Setup Fly Home Directory Permissions

chown -R fly:fly /home/fly
chmod -R 755 /home/fly
chmod 700 /home/fly/.ssh/id_rsa

provisionPing "configure_user"

# Setup UFW Firewall

ufw allow 22
ufw allow 80
ufw allow 443
ufw --force enable

apt_wait

provisionPing "firewall_setup"

apt-get install -y --force-yes unattended-upgrades

cat >/etc/apt/apt.conf.d/50unattended-upgrades <<EOF
Unattended-Upgrade::Allowed-Origins {
    "\${distro_id}:\${distro_codename}-security";
};
Unattended-Upgrade::Package-Blacklist {
    //
};
EOF

cat >/etc/apt/apt.conf.d/10periodic <<EOF
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
APT::Periodic::Unattended-Upgrade "1";
EOF

# Configure Docker to start on boot with systemd
systemctl enable docker.service
systemctl enable containerd.service

provisionPing "unattended_upgrades"
