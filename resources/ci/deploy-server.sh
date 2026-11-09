#!/usr/bin/env bash
set -euo pipefail
umask 0002

deploy_path=${1:?Missing application path}
revision=${2:?Missing revision}
[[ "$deploy_path" == /var/www/chips-api ]]
[[ "$revision" =~ ^[a-f0-9]{40}$ ]]
[[ "$(realpath "$deploy_path")" == "$deploy_path" ]]
[[ -f "$deploy_path/artisan" && -f "$deploy_path/.env" ]]
[[ -w "$deploy_path/bootstrap/cache" && -w "$deploy_path/storage/framework/views" && -w "$deploy_path/storage/logs" ]]
php_bin=/usr/bin/php8.5
incoming="$deploy_path/.deploy/incoming-$revision"
release="$incoming/release"
backup="$deploy_path/.deploy/backup-$revision-$(date -u +%Y%m%d%H%M%S)"
services=(chips-api-payments chips-api-notifications chips-api-scheduler chips-api-reverb)
maintenance=0

artisan() { "$php_bin" "$deploy_path/artisan" "$@" --no-interaction; }
finish() {
  status=$?
  if (( status != 0 && maintenance == 1 )); then
    artisan down --retry=60 >/dev/null 2>&1 || true
    printf 'Deployment failed. Maintenance remains enabled. Backup: %s\n' "$backup" >&2
  fi
  exit "$status"
}
trap finish EXIT

command -v rsync >/dev/null
command -v curl >/dev/null
[[ -x "$php_bin" ]]
"$php_bin" -r 'foreach (["pdo_mysql", "redis", "dom", "curl"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension."\n"); exit(1); } }'
sudo -n -l /usr/bin/supervisorctl stop "${services[@]}" >/dev/null
sudo -n -l /usr/bin/supervisorctl start "${services[@]}" >/dev/null
sudo -n -l /usr/bin/systemctl reload php8.5-fpm >/dev/null

cd "$incoming"
sha256sum --check release.tar.gz.sha256
mkdir "$release"
tar -xzf release.tar.gz -C "$release"
[[ "$(cat "$release/REVISION")" == "$revision" ]]
[[ ! -e "$release/.env" ]]
[[ -f "$release/vendor/autoload.php" && -f "$release/artisan" ]]
[[ -f "$release/resources/ci/rsync-excludes.txt" ]]

cd "$deploy_path"
exec 9>"$deploy_path/.deploy/deploy.lock"
flock -n 9
[[ ! -e "$deploy_path/storage/framework/down" ]] || { printf 'Application already in maintenance; refusing deployment.\n' >&2; exit 1; }
artisan down --retry=60
maintenance=1
sudo -n /usr/bin/supervisorctl stop "${services[@]}"

excludes=(--exclude-from="$release/resources/ci/rsync-excludes.txt")
mkdir "$backup"
rsync -a "${excludes[@]}" "$deploy_path/" "$backup/"
rsync -a --delete "${excludes[@]}" "$release/" "$deploy_path/"
find "$deploy_path/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -delete

artisan package:discover
artisan migrate --force
artisan marketplace:backfill-user-ids
artisan storage:link
artisan optimize
artisan queue:restart
sudo -n /usr/bin/systemctl reload php8.5-fpm
artisan up
sudo -n /usr/bin/supervisorctl start "${services[@]}"
curl --fail --silent --show-error --retry 3 --retry-delay 2 --output /dev/null http://127.0.0.1:8087/up
curl --fail --silent --show-error --retry 3 --retry-delay 2 --output /dev/null -H 'Accept: application/json' http://127.0.0.1:8087/api/v1/categories
maintenance=0
printf 'Deployed %s. Previous code backup: %s\n' "$revision" "$backup"
