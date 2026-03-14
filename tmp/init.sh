mkdir -p ~/.fly/nginx
cd ~/.fly/nginx
mkdir certs conf.d html vhost acme

cat >~/.fly/nginx/conf.d/custom.conf <<EOF
server_tokens off;
client_max_body_size 2048M;
EOF

# create mysql and redis directory
mkdir -p ~/.fly/database/mysql
mkdir -p ~/.fly/config/mysql

mkdir -p ~/.fly/database/redis
mkdir -p ~/.fly/config/redis

# create redis config file
cat >~/.fly/config/redis/redis.conf <<EOF
aclfile /etc/redis/users.acl
EOF

cat >~/.fly/config/redis/users.acl <<EOF
user default on >IINuJv3VEwqR1GzKHKM3 ~* &* +@all
EOF

# create mysql config
cat >~/.fly/config/mysql/my.cnf <<EOF
[mysqld]
# Character Set
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci

# InnoDB Settings
innodb_buffer_pool_size=1G
innodb_buffer_pool_instances=1
innodb_log_file_size=256M
innodb_flush_log_at_trx_commit=1
innodb_file_per_table=1
innodb_flush_method=O_DIRECT

# Connection & Thread Settings
max_connections=150
thread_cache_size=50
max_allowed_packet=64M
wait_timeout=60
interactive_timeout=60

# Table Cache Settings
table_open_cache=2000
tmp_table_size=64M
max_heap_table_size=64M

# Security Settings
default-authentication-plugin=mysql_native_password

# Docker-Specific Settings
host_cache_size=0
skip-name-resolve

# Binary Log Settings
skip-log-bin
# binlog_expire_logs_seconds=259200 # 3 days

[mysql]
default-character-set=utf8mb4

[client]
default-character-set=utf8mb4
EOF

# create env
cat >~/.fly/.env <<EOF
MYSQL_ROOT_PASSWORD=bg5zu7lTzKnhcHofJ9Yh
EOF

# fix file permission
chown -R fly:fly ~/.fly/config
chmod -R 775 ~/.fly/config

# Check if the network "wordpress-sites" exists
network_exists=$(docker network ls | grep wordpress-sites)

if [ -z "$network_exists" ]; then
    # Create the network "wordpress-sites" if it does not exist
    echo "Creating Docker network 'wordpress-sites'..."
    docker network create wordpress-sites
else
    echo "Docker network 'wordpress-sites' already exists."
fi

echo "Pulling Docker images..."
docker pull nginxproxy/nginx-proxy:alpine
docker pull mysql:8.0
docker pull redis:7-alpine
docker pull mcuadros/ofelia:latest
docker pull nginxinc/nginx-unprivileged:alpine
docker pull nginxinc/meghsh/php:7.4
docker pull phpmyadmin/phpmyadmin:fpm-alpine

echo "Creating Docker compose file"
cat >~/.fly/docker-compose.yml <<EOF
services:
  proxy:
    image: 'nginxproxy/nginx-proxy:alpine'
    container_name: nginx-proxy
    restart: always
    ports:
      - '80:80'
      - '443:443'
    volumes:
      - '/var/run/docker.sock:/tmp/docker.sock:ro'
      - './nginx/certs:/etc/nginx/certs'
      - './nginx/html:/usr/share/nginx/html'
      - './nginx/conf.d:/etc/nginx/conf.d'
      - './nginx/vhost:/etc/nginx/vhost.d'
    networks:
      - wordpress-sites
  mysql:
    image: 'mysql:8.0'
    container_name: mysql
    restart: always
    volumes:
      - './database/mysql:/var/lib/mysql'
      - './config/mysql/my.cnf:/etc/mysql/my.cnf'
    ports:
      - '127.0.0.1:3306:3306'
    environment:
      - 'MYSQL_ROOT_PASSWORD=\${MYSQL_ROOT_PASSWORD}'
    networks:
      - db-network
  redis:
    image: 'redis:7-alpine'
    container_name: redis
    restart: always
    volumes:
      - './database/redis:/data'
      - './config/redis/users.acl:/etc/redis/users.acl'
      - './config/redis/redis.conf:/usr/local/etc/redis/redis.conf'
    command:
      - redis-server
      - /usr/local/etc/redis/redis.conf
    networks:
      - db-network
  ofelia:
    image: 'mcuadros/ofelia:latest'
    container_name: ofelia
    command: 'daemon --docker'
    restart: always
    volumes:
      - '/var/run/docker.sock:/var/run/docker.sock:ro'
    networks:
      - wordpress-sites
networks:
  wordpress-sites:
    name: wordpress-sites
    driver: bridge
    external: 'true'
  db-network:
    name: db-network
    driver: bridge

EOF

echo "Starting docker compose"
cd ~/.fly && docker compose up -d

# ping the server to notify the server has been provisioned
curl -s --insecure -d "status=created" -X POST "https://app.flywp.com/callback/servers/6982/provision?signature=SIGNATURE"
