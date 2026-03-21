#!/usr/bin/env bash
#
# SWORD — start or bootstrap the local development environment (Laravel Sail + Vite).
# See docs/run-dev.md for details; README.md lists the same manual steps.
#
# Usage:
#   ./run-dev.sh              # Full setup if needed, then start Vite (Sail)
#   ./run-dev.sh --reset      # Tear down Docker volumes, reinstall deps, migrate, start dev
#   ./run-dev.sh --help
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

LOG_FILE="${SCRIPT_DIR}/run-dev.log"
RESET=false
# Set in main() before exec redirects stdout to tee (after that, [[ -t 1 ]] is false).
RUN_DEV_TTY=0

# ---------------------------------------------------------------------------
# Output helpers (TTY-aware colors; always plain text in log via tee)
# ---------------------------------------------------------------------------
if [[ -t 1 ]]; then
    _BOLD=$'\033[1m'
    _DIM=$'\033[2m'
    _GREEN=$'\033[32m'
    _CYAN=$'\033[36m'
    _YELLOW=$'\033[33m'
    _RESET=$'\033[0m'
else
    _BOLD='' _DIM='' _GREEN='' _CYAN='' _YELLOW='' _RESET=''
fi

banner() {
    echo ""
    echo "${_CYAN}${_BOLD}╔══════════════════════════════════════════════════════════════════════╗${_RESET}"
    printf "${_CYAN}${_BOLD}║${_RESET} %-68s ${_CYAN}${_BOLD}║${_RESET}\n" "$1"
    echo "${_CYAN}${_BOLD}╚══════════════════════════════════════════════════════════════════════╝${_RESET}"
    echo ""
}

section() {
    local title="$1"
    echo ""
    echo "${_GREEN}${_BOLD}▶ ${title}${_RESET}"
    echo "${_DIM}────────────────────────────────────────────────────────────────────────${_RESET}"
}

step() {
    local num="$1"
    local name="$2"
    local detail="${3:-}"
    echo ""
    echo "${_BOLD}  Step ${num}${_RESET} — ${name}"
    if [[ -n "${detail}" ]]; then
        echo "${_DIM}  ${detail}${_RESET}"
    fi
}

info() {
    echo "${_DIM}  →${_RESET} $*"
}

# Shown once before blocking on Vite so developers see how to sign in locally.
print_app_login_hint() {
    section "Local sign-in (seeded user)"
    echo ""
    echo "${_BOLD}  Use these credentials in the browser after the app loads:${_RESET}"
    echo "${_DIM}  Email:${_RESET}    ${_CYAN}test@example.com${_RESET}"
    echo "${_DIM}  Password:${_RESET} ${_CYAN}password${_RESET}"
    echo ""
}

# Stop Vite (and stray npm wrappers) inside the Sail app container. Docker
# Compose exec does not always tear down the dev server on Ctrl+C, which leaves
# port 5173 (or VITE_PORT) busy on the next run.
stop_sail_vite_dev_children() {
    [[ -x vendor/bin/sail ]] || return 0
    # shellcheck disable=SC2016
    ./vendor/bin/sail run sh -c '
        pkill -TERM -f "[v]ite" 2>/dev/null || true
        pkill -TERM -f "npm exec vite" 2>/dev/null || true
        pkill -TERM -f "npm run dev" 2>/dev/null || true
        sleep 1
        pkill -KILL -f "[v]ite" 2>/dev/null || true
        pkill -KILL -f "npm exec vite" 2>/dev/null || true
    ' 2>/dev/null || true
}

run_sail_npm_dev() {
    print_app_login_hint
    local cleanup_done=0

    finish_vite_session() {
        ((cleanup_done)) && return 0
        cleanup_done=1
        info "Stopping Vite / npm dev processes inside Sail (frees the dev port)…"
        stop_sail_vite_dev_children
    }

    # Ctrl+C often reaches docker/npm before bash; always clean up when the
    # blocking command returns, and also handle signals delivered to this shell.
    trap 'trap - INT TERM; finish_vite_session; exit 130' INT
    trap 'trap - INT TERM; finish_vite_session; exit 143' TERM

    set +e
    ./vendor/bin/sail npm run dev
    local ec=$?
    set -e

    trap - INT TERM
    finish_vite_session

    # Propagate typical Ctrl+C exit status when docker/npm was interrupted.
    if [[ $ec -eq 130 ]] || [[ $ec -eq 143 ]]; then
        exit "$ec"
    fi
    return "$ec"
}

die() {
    echo "${_YELLOW}Error:${_RESET} $*" >&2
    exit 1
}

require_project_root() {
    [[ -f composer.json ]] || die "Run this script from the SWORD repository root (composer.json not found)."
}

have_sail() {
    [[ -x vendor/bin/sail ]]
}

# Clear the current terminal line (for spinner redraw). Safe no-op in logs.
clear_terminal_line() {
    if [[ ${RUN_DEV_TTY:-0} -eq 1 ]]; then
        printf '\r\033[K'
    fi
}

# Wait until the MySQL container accepts TCP connections. `sail up -d` returns
# before mysqld is ready, which otherwise causes "Connection refused" during
# migrate:fresh. Uses mysqladmin inside the mysql service (no .env parsing).
wait_for_mysql_ready() {
    local max_seconds="${SAIL_MYSQL_WAIT_MAX_SECONDS:-120}"
    local attempt=0
    local frame_idx=0
    local i q
    # UTF-8 braille frames; falls back to ASCII if locale is not UTF-8.
    local frames
    if [[ ${LC_ALL:-${LANG:-}} == *UTF-8* ]] || [[ ${LC_ALL:-${LANG:-}} == *utf8* ]]; then
        frames=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
    else
        frames=('|' '/' '-' '\')
    fi
    local -a quips=(
        "MySQL is still waking up—hang tight."
        "Spinning disks, brewing queries…"
        "Stretch or refill your drink."
        "The DB is catching up."
        "Almost there—InnoDB is worth the wait."
        "Patience—the database is coming online."
    )

    section "Waiting for MySQL to be ready"
    info "Containers are up; MySQL may still be initializing. Polling until mysqladmin ping succeeds (up to ${max_seconds}s)…"

    while [[ $attempt -lt $max_seconds ]]; do
        if ./vendor/bin/sail exec mysql sh -c 'mysqladmin ping -h127.0.0.1 -p"$MYSQL_ROOT_PASSWORD" --silent' 2>/dev/null; then
            clear_terminal_line
            info "MySQL is accepting connections."
            return 0
        fi

        if [[ ${RUN_DEV_TTY:-0} -eq 1 ]]; then
            i=$((frame_idx % ${#frames[@]}))
            q=$(( (attempt / 6) % ${#quips[@]} ))
            printf '\r  %s%s%s  Waiting for MySQL…  %2ds / %ds  %s%s%s' \
                "${_CYAN}" "${frames[$i]}" "${_RESET}" \
                "$attempt" "$max_seconds" \
                "${_DIM}" "${quips[$q]}" "${_RESET}"
            frame_idx=$((frame_idx + 1))
        elif (( attempt >= 5 && attempt % 5 == 0 )); then
            info "Still waiting for MySQL… (${attempt}s / ${max_seconds}s)"
        fi

        sleep 1
        attempt=$((attempt + 1))
    done

    clear_terminal_line
    die "MySQL did not become ready within ${max_seconds}s. Inspect logs with: ./vendor/bin/sail logs mysql"
}

# ---------------------------------------------------------------------------
# Setup pieces (mirror README.md)
# ---------------------------------------------------------------------------
composer_install_docker() {
    step "1" "Install PHP dependencies with Composer (one-off Docker image)" \
        "Uses laravelsail/php84-composer; writes to ./vendor on the host."
    docker run \
        -u "$(id -u):$(id -g)" \
        -v "$(pwd):/var/www/html" \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        composer install --ignore-platform-reqs
}

ensure_env_file() {
    step "2" "Configure environment file" \
        "Copy .env.example → .env when missing; edit .env if you need custom values."
    if [[ ! -f .env ]]; then
        cp .env.example .env
        info "Created .env from .env.example"
    else
        info ".env already present — leaving as-is"
    fi
}

run_sail_up_detached() {
    ./vendor/bin/sail up -d
}

sail_up() {
    step "3" "Start Docker containers (Sail)" \
        "Runs: sail up -d"
    run_sail_up_detached
}

sail_key_and_migrate() {
    step "4" "Application key and database" \
        "Wait for MySQL, then key:generate and migrate:fresh --seed"
    wait_for_mysql_ready
    ./vendor/bin/sail artisan key:generate
    ./vendor/bin/sail artisan migrate:fresh --seed
}

sail_npm_install() {
    step "5" "Install Node dependencies inside Sail" \
        "Runs: sail npm install"
    ./vendor/bin/sail npm install
}

start_vite_dev() {
    step "6" "Start Vite with hot module reloading" \
        "Runs: sail npm run dev (blocks until you stop with Ctrl+C)"
    run_sail_npm_dev
}

full_setup_from_readme() {
    banner "Full setup — first-time or incomplete workspace"
    composer_install_docker
    ensure_env_file
    sail_up
    sail_key_and_migrate
    sail_npm_install
}

repair_npm_only() {
    banner "Repair — Node dependencies missing"
    section "Containers and npm install"
    step "1" "Start Docker containers (Sail)" \
        "Runs: sail up -d"
    run_sail_up_detached
    step "2" "Install Node dependencies inside Sail" \
        "Runs: sail npm install"
    ./vendor/bin/sail npm install
}

reset_environment() {
    banner "Reset — tear down stack, clean install, fresh database"
    section "Stopping Sail and removing Docker volumes"
    if have_sail; then
        ./vendor/bin/sail down -v --remove-orphans
        info "Sail stack stopped; named volumes removed (database data reset)."
    else
        info "Sail not present yet — nothing to tear down."
    fi

    section "Clean frontend install directory (optional host state)"
    if [[ -d node_modules ]]; then
        rm -rf node_modules
        info "Removed node_modules/"
    else
        info "No node_modules/ to remove."
    fi

    section "Re-run full setup"
    full_setup_from_readme
}

daily_dev_start() {
    banner "Development session — stack up, then Vite"
    section "Ensure Sail is running"
    step "1" "Start containers if needed" "./vendor/bin/sail up -d"
    run_sail_up_detached
    section "Frontend dev server"
    step "2" "Vite (HMR)" "./vendor/bin/sail npm run dev"
    run_sail_npm_dev
}

needs_composer_vendor() {
    [[ ! -f vendor/autoload.php ]]
}

needs_env_file() {
    [[ ! -f .env ]]
}

needs_node_modules() {
    [[ ! -d node_modules ]]
}

usage() {
    cat <<'EOF'
SWORD — run-dev.sh

Usage:
  ./run-dev.sh              Full setup if needed, then start Vite (Sail)
  ./run-dev.sh --reset      Tear down Docker volumes, reinstall deps, migrate, start dev
  ./run-dev.sh --help       Show this message

Logs are appended to run-dev.log in the repository root.
EOF
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --reset)
                RESET=true
                shift
                ;;
            -h | --help)
                usage
                exit 0
                ;;
            *)
                die "Unknown option: $1 (try --help)"
                ;;
        esac
    done
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    parse_args "$@"
    require_project_root

    RUN_DEV_TTY=0
    [[ -t 1 ]] && RUN_DEV_TTY=1

    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE"

    # Append full session transcript (stdout + stderr) to run-dev.log
    exec > >(tee -a "$LOG_FILE") 2>&1

    echo ""
    echo "${_DIM}Log file:${_RESET} ${LOG_FILE}"
    echo "${_DIM}Started:${_RESET}  $(date -Iseconds)"
    echo ""

    if [[ "${RESET}" == true ]]; then
        reset_environment
        start_vite_dev
        return
    fi

    if needs_composer_vendor || needs_env_file; then
        full_setup_from_readme
        start_vite_dev
        return
    fi

    if needs_node_modules; then
        repair_npm_only
        start_vite_dev
        return
    fi

    daily_dev_start
}

main "$@"
