# `run-dev.sh` — local development (Sail + Vite)

This document describes the repository script **`run-dev.sh`**, which automates the same Docker-based workflow documented in the [README](../README.md) (Composer via a one-off container, Laravel Sail, database migration with seeders, npm, and Vite with hot module reloading).

**Requirements:** Docker (for Sail and for the initial Composer install image). Run the script from the **repository root** (where `composer.json` lives).

---

## Quick start

```bash
chmod +x run-dev.sh   # once, if needed
./run-dev.sh
```

On a fresh clone, the script detects an incomplete workspace and runs **full setup**, then starts the Vite dev server. On later runs, it usually only ensures Sail is up and runs **`./vendor/bin/sail npm run dev`**.

---

## What the script does

### Default: `./run-dev.sh`

The script chooses a path based on the working tree:

| Condition | Action |
|-----------|--------|
| `vendor/autoload.php` **or** `.env` is missing | **Full setup** (see below), then Vite |
| `vendor` and `.env` exist but **`node_modules/`** is missing | Start Sail, **`sail npm install`**, then Vite |
| Otherwise | **`sail up -d`**, then **`sail npm run dev`** |

**Full setup** (matches the README order):

1. `composer install` using the `laravelsail/php84-composer:latest` image (writes to `./vendor` on the host).
2. Copy **`.env.example` → `.env`** if `.env` does not exist.
3. **`./vendor/bin/sail up -d`**
4. **Wait for MySQL** — `sail up -d` returns before `mysqld` is ready; the script polls `mysqladmin ping` inside the **`mysql`** container (up to **120 seconds**, override with **`SAIL_MYSQL_WAIT_MAX_SECONDS`**) so migrations do not hit “Connection refused”. In an interactive terminal it shows a **spinner and rotating messages** on one line; output redirected to a file or piped only logs periodic “still waiting” lines so logs stay readable.
5. **`./vendor/bin/sail artisan key:generate`**
6. **`./vendor/bin/sail artisan migrate:fresh --seed`**
7. **`./vendor/bin/sail npm install`**
8. **`./vendor/bin/sail npm run dev`** (blocks until you stop it with Ctrl+C)

Immediately before **`sail npm run dev`** starts, the script prints the default seeded login **test@example.com** / **password** (same values as in the [README](../README.md)). That reminder also appears on “daily” runs when only Sail + Vite are started.

### Reset: `./run-dev.sh --reset`

Use when you want a **clean Docker state** and a **fresh database** (and a clean `node_modules` on the host):

1. If Sail is available: **`./vendor/bin/sail down -v --remove-orphans`** (removes named volumes, so database data in Docker is cleared).
2. Removes **`node_modules/`** if present.
3. Runs **full setup** again (same steps as above), then starts Vite.

> [!NOTE]
> **`--reset` does not delete** `vendor/` or `.env`. For a completely clean clone-like state, remove those yourself before running, or clone fresh.

### Help: `./run-dev.sh --help`

Prints usage and exits (no Docker, no log file write for the main session).

---

## Logging

Every normal run (not `--help`) **appends** the full terminal transcript (stdout and stderr) to **`run-dev.log`** in the repository root, so you can inspect failures or share logs when debugging.

`run-dev.log` is listed in `.gitignore` and is not intended to be committed.

---

## Relation to other scripts

- **[`setup.sh`](../setup.sh)** — a shorter shell script that brings Sail up, runs key/migrate, **`tunnel:sync`**, npm install, and `npm run dev`. It does **not** run the Docker Composer install step or implement the “first run vs daily run” logic of `run-dev.sh`.
- **Manual steps** — see the [README](../README.md) if you prefer to run each command yourself.

---

## Troubleshooting

- **“Run this script from the SWORD repository root”** — `cd` to the project directory that contains `composer.json`.
- **Docker / Sail errors** — ensure Docker is running and you have permission to use it.
- **Connection refused (MySQL) during migrations** — the script waits for MySQL before `migrate:fresh`. On very slow machines or first-time image pulls, increase the wait: `SAIL_MYSQL_WAIT_MAX_SECONDS=300 ./run-dev.sh`. Check **`./vendor/bin/sail logs mysql`** if it still times out.
- **Port already in use (Vite / 5173)** — after **Ctrl+C**, the script stops Vite and related **npm** processes **inside the Sail app container** so the next run can bind the port. If something still holds the port (unusual host-side `npm`, another project), stop it manually or adjust **`VITE_PORT`** in `.env`.
- After **`--reset`**, expect **`migrate:fresh --seed`**; local seed data is recreated from your seeders.

---

## Tests

Automated checks live in **`tests/Unit/RunDevScriptTest.php`** (bash syntax sanity and `--help` output).
