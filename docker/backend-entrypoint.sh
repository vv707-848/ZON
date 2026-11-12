#!/usr/bin/env bash
# One container per role, from one image (compose.yaml): web · worker · scheduler · reverb.
# «web» prepares everything first, once: packages, .env and its key, migrations, the reference data, and the
# SuperAgent's read-only database user. The other roles start after web answers /up.
set -euo pipefail
role="${1:-web}"

if [[ "$role" == "web" ]]; then
  composer install --no-interaction --no-progress --prefer-dist
  if [[ ! -f .env ]]; then
    cp .env.example .env                       # compose's environment overrides its production values
    php artisan key:generate --force
  fi
  until php artisan db:show >/dev/null 2>&1; do echo "waiting for MySQL…"; sleep 2; done
  php artisan migrate --force
  php artisan db:seed --class=ReferenceSeeder --force
  # The SuperAgent's account, as on the data node (deploy/mysql/ai_reader.sql), for this machine's network.
  php -r '
    $sql = strtr(file_get_contents("../deploy/mysql/ai_reader.sql"), ["'"'"'10.10.0.10'"'"'" => "'"'"'%'"'"'", "CHANGE-ME-long-random" => getenv("REPORT_DB_PASSWORD")]);
    $sql = preg_replace("/^--.*$/m", "", $sql);
    $db = new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"), "root", getenv("MYSQL_ROOT_PASSWORD"));
    foreach (array_filter(array_map("trim", explode(";", $sql))) as $statement) { $db->exec($statement); }
  ' 
  php artisan storage:link --force >/dev/null
  exec php artisan serve --host=0.0.0.0 --port=8000
fi

case "$role" in
  worker)    exec php artisan queue:work redis --queue=index,ai,notify,default,media --sleep=1 ;;
  scheduler) exec php artisan schedule:work ;;
  reverb)    exec php artisan reverb:start --host=0.0.0.0 --port=8080 ;;
  *)         exec "$@" ;;
esac
