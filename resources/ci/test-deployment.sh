#!/usr/bin/env bash
set -euo pipefail

project_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
test_root="$project_dir/storage/framework/testing"
mkdir -p "$test_root"
workspace=$(mktemp -d "$test_root/ci-deploy.XXXXXX")
[[ "$(realpath "$workspace")" == "$(realpath "$test_root")"/ci-deploy.* ]]
trap 'rm -rf -- "$workspace"' EXIT
source_dir="$workspace/release"
target_dir="$workspace/application"
mkdir -p "$source_dir/app" "$source_dir/public" "$source_dir/storage/app/public" "$source_dir/bootstrap/cache" \
  "$target_dir/app" "$target_dir/storage/app/public" "$target_dir/bootstrap/cache" \
  "$target_dir/.git" "$target_dir/.deploy" "$target_dir/public"
printf 'new code' > "$source_dir/app/current.php"
printf 'obsolete code' > "$target_dir/app/obsolete.php"
printf 'server-only secret' > "$target_dir/.env"
printf 'release must not replace this' > "$source_dir/.env"
printf 'uploaded photo' > "$target_dir/storage/app/public/customer image.jpg"
printf 'cached config' > "$target_dir/bootstrap/cache/config.php"
printf 'repository metadata' > "$target_dir/.git/config"
printf 'previous backup' > "$target_dir/.deploy/backup"
ln -s "$target_dir/storage/app/public" "$target_dir/public/storage"

rsync -a --delete --exclude-from="$project_dir/resources/ci/rsync-excludes.txt" "$source_dir/" "$target_dir/"
[[ "$(cat "$target_dir/app/current.php")" == 'new code' ]]
[[ ! -e "$target_dir/app/obsolete.php" ]]
[[ "$(cat "$target_dir/.env")" == 'server-only secret' ]]
[[ "$(cat "$target_dir/storage/app/public/customer image.jpg")" == 'uploaded photo' ]]
[[ "$(cat "$target_dir/bootstrap/cache/config.php")" == 'cached config' ]]
[[ "$(cat "$target_dir/.git/config")" == 'repository metadata' ]]
[[ "$(cat "$target_dir/.deploy/backup")" == 'previous backup' ]]
[[ -L "$target_dir/public/storage" ]]

if bash "$project_dir/resources/ci/deploy-server.sh" /var/www/another-project invalid-revision; then
  printf 'Invalid deployment target was accepted.\n' >&2
  exit 1
fi
printf 'Deployment replacement preserves environment, uploads, cache, repository and backups.\n'
