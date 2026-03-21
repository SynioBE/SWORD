#!/usr/bin/env bash
# ============================================================
# SWORD Site Deletion Script
# Site:   {{ $site->domain }} (ID: {{ $site->id }})
# Server: {{ $server->name }} (ID: {{ $server->id }})
# Generated: {{ now()->toIso8601String() }}
# ============================================================

DOMAIN="{{ $site->domain }}"
DB_NAME="{{ $site->db_name }}"
DB_USER="{{ $site->db_user }}"
MYSQL_ROOT_PASSWORD="{{ $site->server->mysql_root_password }}"
SITE_DIR="/srv/sword/sites/${DOMAIN}"
STACK_DIR="/srv/sword/stacks/${DOMAIN}"

# ── Stop and remove Docker containers ────────────────────

echo "Stopping and removing containers..."

docker compose -f "${STACK_DIR}/docker-compose.yml" down --remove-orphans 2>/dev/null || true

# Force-remove individual containers in case compose file is gone
docker rm -f "sword_{{ $site->id }}_php" 2>/dev/null || true
docker rm -f "sword_{{ $site->id }}_nginx" 2>/dev/null || true

# ── Drop MySQL database and user ─────────────────────────

echo "Dropping database and user..."

docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" 2>/dev/null || true

docker exec sword_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-swordmysql}" \
    -e "DROP USER IF EXISTS '${DB_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null || true

# ── Remove site files ────────────────────────────────────

echo "Removing site files..."

rm -rf "${SITE_DIR}"
rm -rf "${STACK_DIR}"

# ── Restart Ofelia to drop removed cron job labels ───────

echo "Restarting Ofelia..."

docker restart sword_ofelia 2>/dev/null || true

# ── Done ─────────────────────────────────────────────────

echo ""
echo "============================================"
echo " SWORD site deletion complete!"
echo " Domain: ${DOMAIN}"
echo "============================================"
