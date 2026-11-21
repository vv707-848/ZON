#!/usr/bin/env bash
# /workspace/zon/deploy/ai-node/e2e.sh <report.json> — search and matching as the app asks them, on a lab Pod (owner,
# 21 Nov 2026): Laravel's API, its MySQL and Redis, the agents with Qdrant and the embedding server, and the engine
# lab-pod.sh already serves — all on this Pod, none of it production's. Then `php artisan zon:lab-e2e`: a test-arena
# batch indexed into Qdrant, the answer cache emptied, one warm-up per endpoint, then eight at once, the time from
# sending to receiving each answer, and how each was obtained (the model ranked it, or the other path).
#
# Settings it reads: LLM_API_KEY and ZON_PROFILE (lab-pod.sh's), ZON_RANK_MODE (the agents' ranking, default compact),
# ZON_E2E_LISTINGS (300), ZON_E2E_ROUNDS (3). A step that fails ends the script with its exit code: lab-pod.sh
# records it among the failures and goes on to post what is done.
set -euo pipefail
W=${ZON_WORKSPACE:-/workspace}
OUT=${1:?the report path}
LOGS="$W/logs"
: "${LLM_API_KEY:?}" "${ZON_PROFILE:?}"
export DEBIAN_FRONTEND=noninteractive
say() { echo "== e2e · $*"; }
wait_for() {  # wait_for <what> <tries> <command…>
  local what=$1 tries=$2
  shift 2
  for _ in $(seq 1 "$tries"); do "$@" >/dev/null 2>&1 && return 0; sleep 2; done
  echo "$what did not come up" >&2
  return 1
}

say "packages: MySQL, Redis, PHP 8.5 (ppa:ondrej/php), Composer, Qdrant v1.20.0 (deploy/data-node/compose.yaml)"
apt-get update -qq
apt-get install -y -qq software-properties-common mysql-server redis-server unzip >/dev/null
add-apt-repository -y ppa:ondrej/php >/dev/null
apt-get update -qq
apt-get install -y -qq php8.5-cli php8.5-mysql php8.5-redis php8.5-mbstring php8.5-xml php8.5-curl php8.5-intl \
  php8.5-bcmath php8.5-zip php8.5-gd >/dev/null
echo "pcre.jit=0" > /etc/php/8.5/cli/conf.d/99-zon.ini     # as production (deploy/php/zon.ini)
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://github.com/qdrant/qdrant/releases/download/v1.20.0/qdrant-x86_64-unknown-linux-gnu.tar.gz \
  | tar -xz -C /usr/local/bin
php -v | head -1

say "services"
service mysql start >/dev/null 2>&1 || (mysqld_safe > "$LOGS/mysql.log" 2>&1 &)
wait_for MySQL 60 mysqladmin ping
mysql -e "CREATE DATABASE IF NOT EXISTS zone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'zon'@'127.0.0.1' IDENTIFIED BY 'zon-lab'; GRANT ALL ON zone.* TO 'zon'@'127.0.0.1';"
redis-server --daemonize yes --bind 127.0.0.1 --save "" --appendonly no >/dev/null
mkdir -p "$W/qdrant"
(cd "$W/qdrant" && qdrant > "$LOGS/qdrant.log" 2>&1 &)
wait_for Qdrant 60 curl -sf http://127.0.0.1:6333/readyz
# the embedding server, as production's node serves it to the agents (18080: entrypoint.sh's tunnel port)
("$W/venv-embed/bin/python" "$W/embed/server.py" --host 127.0.0.1 --port 18080 > "$LOGS/embed.log" 2>&1 &)
wait_for "the embedding server" 150 curl -sf http://127.0.0.1:18080/health

say "the agents (rank ${ZON_RANK_MODE:-compact})"
KEY=$(openssl rand -hex 32)
SECRET=$(openssl rand -hex 32)
cd "$W/zon/agents"
AGENTS_ENV=(AGENTS_INTERNAL_KEY="$KEY" INDEX_SHARED_SECRET="$SECRET" QDRANT_URL=http://127.0.0.1:6333
  EMBED_URL=http://127.0.0.1:18080 LLM_BASE_URL=http://127.0.0.1:30000/v1 LLM_PROFILE="$ZON_PROFILE"
  ZON_RANK_MODE="${ZON_RANK_MODE:-compact}" INDEX_STATE_PATH="$W/index_state.sqlite3" PORT=8100)
env "${AGENTS_ENV[@]}" .venv/bin/zon-agents bootstrap
(env "${AGENTS_ENV[@]}" .venv/bin/zon-agents serve > "$LOGS/agents.log" 2>&1 &)
wait_for "the agents" 60 curl -sf http://127.0.0.1:8100/agents/health
curl -s http://127.0.0.1:8100/agents/health; echo

say "Laravel"
cd "$W/zon/backend"
# production's example, each key this node sets taken out first: one value per key
cat > "$LOGS/e2e.env" <<ENV
APP_ENV=staging
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
DB_HOST=127.0.0.1
DB_DATABASE=zone
DB_USERNAME=zon
DB_PASSWORD=zon-lab
REDIS_HOST=127.0.0.1
CACHE_STORE=redis
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
BROADCAST_CONNECTION=log
MEDIA_DISK=public
RATE_SALT=$(openssl rand -hex 16)
AGENTS_URL=http://127.0.0.1:8100
AGENTS_INTERNAL_KEY=$KEY
INDEX_SHARED_SECRET=$SECRET
SMS_DRIVER=fake
PAYMENTS_DRIVER=fake
APP_STORE_DRIVER=fake
GOOGLE_PLAY_DRIVER=fake
PUSH_DRIVER=fake
LOG_CHANNEL=single
ENV
grep -v -E "^($(cut -d= -f1 "$LOGS/e2e.env" | paste -sd'|'))=" .env.example > .env
cat "$LOGS/e2e.env" >> .env
composer install --no-interaction --no-progress --prefer-dist --quiet
php artisan key:generate --force --no-interaction >/dev/null
php artisan migrate --seed --force --no-interaction >/dev/null
(PHP_CLI_SERVER_WORKERS=16 php artisan serve --host=127.0.0.1 --port=8000 > "$LOGS/laravel.log" 2>&1 &)
wait_for "Laravel's API" 60 curl -sf -o /dev/null http://127.0.0.1:8000/up

say "the measurement"
php artisan zon:lab-e2e --base=http://127.0.0.1:8000 --listings="${ZON_E2E_LISTINGS:-300}" --at-once=8 \
  --rounds="${ZON_E2E_ROUNDS:-3}" --qdrant=http://127.0.0.1:6333 --out="$OUT"
