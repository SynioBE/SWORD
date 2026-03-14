#!/bin/bash

# Variables
HOME_DIR="/home/fly"
SITE_DIR="$HOME_DIR/testing-haby6k.flywp.xyz"
CREATE_DATABASE=1
STACK="nginx"
WP_CLI_IMAGE="php"
PLUGIN_LIST="redis-cache flywp"

# Extra PHP Configuration
EXTRA_PHP=$(
    cat <<'EOF'
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' ) {
    $_SERVER['HTTPS'] = 'on';
}

define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
define( 'CONCATENATE_SCRIPTS', false );
define( 'SAVEQUERIES', false );
define( 'WP_AUTO_UPDATE_CORE', true );
define( 'DISALLOW_FILE_MODS', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'FLYWP_API_KEY', 'site_key_pUpoG9Rbbdt3kJCcUC7F5IYY8k2TNoQqoadrOfR0' );
EOF
)

# Add stack-specific PHP configuration
EXTRA_PHP+=$(
    cat <<'EOF'
define( 'WP_REDIS_HOST', 'redis' );
define( 'WP_REDIS_PREFIX', 'site_24204_' );
define( 'WP_REDIS_PASSWORD', [ 'site_24204', 'PMSep3xUmywgAOZw' ] );
define( 'WP_REDIS_DISABLE_BANNERS', true );
define( 'WP_REDIS_DISABLE_METRICS', true );


EOF
)

function provisionPing {
    curl -s --insecure -d "status=creating&step=$1" -X POST "https://app.flywp.com/callback/sites/24204/create?signature=SIGNATURE"
}

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Create the user if it doesn't exist
function create_user {
    if ! id -u fly >/dev/null 2>&1; then
        log "(1/13) Creating user"

        # If docker group doesn't exist, create it
        if ! grep -q docker /etc/group; then
            groupadd -f docker
        fi

        # Create the user
        useradd -s /bin/bash -d $HOME_DIR -m -G docker fly

        # Create the user's base directories
        mkdir -p "$HOME_DIR/.ssh"
        mkdir -p "$HOME_DIR/.fly"
        mkdir -p "$HOME_DIR/.provisions"

        # Copy bash profiles and SSH keys
        cp /root/.profile "$HOME_DIR/.profile"
        cp /root/.bashrc "$HOME_DIR/.bashrc"
        cp /root/.ssh/authorized_keys "$HOME_DIR/.ssh/authorized_keys"

        # Create SSH keys
        ssh-keygen -t rsa -b 4096 -f "$HOME_DIR/.ssh/id_rsa" -q -N ""

        # Copy Source Control Public Keys Into Known Hosts File
        ssh-keyscan -H github.com >>"$HOME_DIR/.ssh/known_hosts"
        ssh-keyscan -H bitbucket.org >>"$HOME_DIR/.ssh/known_hosts"
        ssh-keyscan -H gitlab.com >>"$HOME_DIR/.ssh/known_hosts"

        # Configure Git Settings
        git config --global user.name "Synio"
        git config --global user.email "wesley@syn.io"

        # Fix Directory Permissions
        chown -R fly:fly $HOME_DIR
        chmod -R 755 $HOME_DIR
        chmod 700 "$HOME_DIR/.ssh/id_rsa"
    fi

    provisionPing create_user
}

function fix_site_permission {
    log "Fixing site permission"
    chown -R fly:fly $SITE_DIR
}

function create_site_base_directories {
    log "(1/13) Creating base directories"

    if [ -d "$SITE_DIR" ]; then
        log "Directory exists, exiting"
        exit 1
    fi

    mkdir "$SITE_DIR"
    cd "$SITE_DIR"

    # Create stack-specific directories
    # current stack is nginx.
        create_nginx_directories

    log "(2/13) Creating configurations"
    git clone https://github.com/flywp/config.git config

    # Create stack-specific configurations
        create_nginx_configurations

    provisionPing directory_setup
}


function create_nginx_directories {
    mkdir -p logs logs/nginx logs/php app app/logs backups data/php-fpm data/nginx/cache data/nginx/temp data/nginx/.letsencrypt
    touch app/logs/debug.log logs/php/error.log
}

function create_nginx_configurations {
    mkdir -p config/nginx/custom/before config/nginx/custom/after config/nginx/custom/server

    cat >"$SITE_DIR/config/nginx/custom/before/flywp.conf" <<"EOF"

EOF

    cat >"$SITE_DIR/config/nginx/custom/server/flywp.conf" <<"EOF"
# 7G Firewall
include common/7g.conf;



# WP Blocking rules
include common/block-links-opml.conf;

include common/block-wpincludes.conf;

include common/block-wpcontent.conf;

include common/block-xmlrpc.conf;




include common/block-feed.conf;




# Execute PHP
include common/locations.conf;

include common/php.conf;

EOF

    touch "$SITE_DIR/config/nginx/custom/after/flywp.conf"

    cat >"$SITE_DIR/config/nginx/default.conf" <<"EOF"
# FLYWP CONFIG (DON'T REMOVE)
include custom/before/*.conf;

server {
    listen 8080;
    listen [::]:8080;
    server_name digimove-testing-haby6k.flywp.xyz;

    # disable absolute redirects to avoid redirecting with port
    absolute_redirect off;

    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;

    root /var/www/html/public;

    index index.php index.html;


    # FLYWP CONFIG (DON'T REMOVE)
    include custom/server/*.conf;
}

# FLYWP CONFIG (DON'T REMOVE)
include custom/after/*.conf;


EOF
}

function setup_nginx_permissions {
    docker compose -f docker-compose.yml exec --user='root' $WP_CLI_IMAGE bash -c "usermod -u $uid www-data && groupmod -g $gid www-data"
    docker compose -f docker-compose.yml restart $WP_CLI_IMAGE
    docker compose -f docker-compose.yml exec --user='root' $WP_CLI_IMAGE bash -c "mkdir -p /var/www/html/public && chown -R www-data: /var/www/"
}

function configure_nginx_caching {
    log "(12/13) Enable redis caching"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE sh -c "wp plugin activate $PLUGIN_LIST && wp redis enable"
}

function create_docker_compose {
    log "(3/13) Generating docker-compose.yml file"

    uid=$(id -u fly)
    gid=$(id -g fly)

    cat >"$SITE_DIR/docker-compose.yml" <<EOF
services:
  php:
    image: 'meghsh/php:8.2'
    restart: always
    volumes:
      - './app:/var/www/html'
      - './logs/php:/var/log/php'
      - './data/php-fpm:/run/php-fpm'
      - './data/nginx/cache:/var/run/nginx-cache'
      - './config/php/memory.ini:/usr/local/etc/php/conf.d/memory.ini'
      - './config/php/uploads.ini:/usr/local/etc/php/conf.d/uploads.ini'
      - './config/php/opcache.ini:/usr/local/etc/php/conf.d/opcache.ini'
      - './config/php/variables.ini:/usr/local/etc/php/conf.d/variables.ini'
      - './config/php/custom.ini:/usr/local/etc/php/conf.d/custom.ini'
      - './config/php/zz-docker.conf:/usr/local/etc/php-fpm.d/zz-docker.conf'
    user: '$uid:$gid'
    networks:
      - site-network
      - db-network
    labels:
      ofelia.enabled: 'true'
      ofelia.job-exec.wpcron-24204.schedule: '@every 10m'
      ofelia.job-exec.wpcron-24204.user: www-data
      ofelia.job-exec.wpcron-24204.command: 'wp cron event run --due-now'
  nginx:
    image: 'nginxinc/nginx-unprivileged:alpine'
    restart: always
    environment:
      - VIRTUAL_HOST=digimove-testing-haby6k.flywp.xyz
      - VIRTUAL_PORT=8080
      - CERT_NAME=
      - HTTPS_METHOD=nohttps
    user: '$uid:$gid'
    volumes:
      - './app:/var/www/html'
      - './logs/nginx:/var/log/nginx'
      - './data/nginx/temp:/var/cache/nginx'
      - './data/nginx/cache:/var/run/nginx-cache'
      - './data/php-fpm:/run/php-fpm'
      - './config/nginx/common:/etc/nginx/common'
      - './config/nginx/custom:/etc/nginx/custom'
      - './config/nginx/default.conf:/etc/nginx/conf.d/default.conf'
      - './config/nginx/nginx.conf:/etc/nginx/nginx.conf'
      - '/home/fly/.fly/nginx/html:/usr/share/nginx/html'
    networks:
      - site-network
      - wordpress-sites
    depends_on:
      - php
networks:
  site-network:
    name: digimove-testing-haby6k.flywp.xyz
  wordpress-sites:
    name: wordpress-sites
    external: true
  db-network:
    name: db-network
    external: true

EOF

    # Create stack-specific configurations

    log "(4/13) Starting the containers"
    cd $SITE_DIR
    docker compose up -d

    # Restart ofelia container to pickup the new job
    docker compose -f /home/fly/.fly/docker-compose.yml restart ofelia

    provisionPing docker_container
}

function install_wordpress {
    cat >"$SITE_DIR/app/wp-cli.yml" <<"EOF"
path: public
EOF

    cd $SITE_DIR

    # Setup stack-specific permissions
    log "(5/13) Fixing user and file permissions"
        setup_nginx_permissions

    log "Fixing wp cli cache permission"
    docker compose -f docker-compose.yml exec --user='root' $WP_CLI_IMAGE bash -c "mkdir -p /.wp-cli/cache"
    docker compose -f docker-compose.yml exec --user='root' $WP_CLI_IMAGE bash -c "mkdir -p /home/www-data/.wp-cli/cache && chown -R www-data: /home/www-data/.wp-cli/cache/"

    # Download WordPress
    log "(6/13) Downloading WordPress"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE wp core download --locale='en_US' --version='6.9'

    # Setup database
        log "(7/13) Creating MySQL Database"
    docker exec mysql mysql --user=root --password=YBREnf3J5sL9Qq8I9Tr1 -e 'CREATE USER "site_24204"@"%" IDENTIFIED BY "hfZFoS9PpGf3SIDW"; CREATE DATABASE `site_24204`; GRANT ALL PRIVILEGES ON `site_24204`.* TO "site_24204"@"%"; FLUSH PRIVILEGES;'

    # Setup Redis
    echo "user site_24204 on >PMSep3xUmywgAOZw ~site_24204_* +select +get +flushdb +del +set +setex +info +ping +eval +zadd +incrby +decrby" >>"$HOME_DIR/.fly/config/redis/users.acl"
    docker compose -f "$HOME_DIR/.fly/docker-compose.yml" restart redis

    # Create WP Config
    log "(8/13) Creating wp-config.php"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE wp config create --dbuser='site_24204' --dbname='site_24204' --dbpass='hfZFoS9PpGf3SIDW' --dbhost='mysql' --dbprefix='wp_' --dbcharset=utf8mb4 --skip-check --extra-php="$EXTRA_PHP"

    log "(9/13) Moving wp-config.php one level up"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE mv /var/www/html/public/wp-config.php /var/www/html/wp-config.php

    # Install WordPress
    log "(10/13) Installing WordPress"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE sh -c "wp core install --url='http://digimove-testing-haby6k.flywp.xyz' --title='Digimove Testing' --admin_user='wesley' --admin_password='iFDJfxAkpSVd41YtOj' --admin_email='wesley@syn.io' && wp rewrite structure '/%postname%/'"

    # Powered by FlyWP
    sed -i 's/Designed with %s/Designed with %s. Powered by %s./' $SITE_DIR/app/public/wp-content/themes/twentytwentyfive/patterns/footer*.php
    sed -i "s/'<a href=\"' . esc_url( __( 'https:\/\/wordpress.org', 'twentytwentyfive' ) ) . '\" rel=\"nofollow\">WordPress<\/a>'/'<a href=\"' . esc_url( __( 'https:\/\/wordpress.org', 'twentytwentyfive' ) ) . '\" rel=\"nofollow\">WordPress<\/a>',\n\t\t\t\t\t'<a href=\"' . esc_url( __( 'https:\/\/flywp.com', 'twentytwentyfive' ) ) . '\" rel=\"nofollow\">FlyWP<\/a>'/" $SITE_DIR/app/public/wp-content/themes/twentytwentyfive/patterns/footer*.php

    # Create stack-specific files

    provisionPing install_wordpress

    # Install plugins
    log "(11/13) Installing plugins"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE sh -c "wp plugin delete hello && wp plugin update --all && wp plugin install $PLUGIN_LIST"

    # Configure stack-specific caching
        configure_nginx_caching

    log "(13/14) Set FlyWP Page Cache status"
    docker compose -f docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE sh -c "wp option set flywp_fastcgi_cache '{\"enabled\":false,\"home_created\":true,\"home_deleted\":true}' --format=json && wp plugin auto-updates enable flywp"

    provisionPing install_plugins
}

function apply_blueprint {
    log "(14/14) Applying blueprint configuration"
    cd $SITE_DIR

echo "No blueprint selected"

    provisionPing apply_blueprint
}

# Execute the main workflow
create_user
create_site_base_directories
fix_site_permission
create_docker_compose
install_wordpress
apply_blueprint
fix_site_permission

provisionPing setup_permissions

WP_VERSION=$(docker compose -f $SITE_DIR/docker-compose.yml exec --user='www-data' $WP_CLI_IMAGE wp core version)

# Notifying via callback
log "Notifying via callback"
curl -s --request POST --url "https://app.flywp.com/callback/sites/24204/create?signature=SIGNATURE" --data-urlencode "status=created" --data-urlencode "wp_version=$WP_VERSION"

log "Site created successfully!"
