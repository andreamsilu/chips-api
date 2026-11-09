#!/usr/bin/env bash
set -euo pipefail

command -v docker-php-ext-install >/dev/null
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends git unzip curl rsync ca-certificates \
  libcurl4-openssl-dev libonig-dev libxml2-dev libsqlite3-dev
missing=()
for extension in pdo_mysql pdo_sqlite mbstring bcmath dom curl pcntl sockets; do
  if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then
    missing+=("$extension")
  fi
done
if (( ${#missing[@]} > 0 )); then
  docker-php-ext-install -j"$(nproc)" "${missing[@]}"
fi
pecl install redis
docker-php-ext-enable redis

installer=$(mktemp)
trap 'rm -f "$installer"' EXIT
curl --fail --silent --show-error https://getcomposer.org/installer -o "$installer"
expected=$(curl --fail --silent --show-error https://composer.github.io/installer.sig)
printf '%s  %s\n' "$expected" "$installer" | sha384sum --check
php "$installer" --2 --install-dir=/usr/local/bin --filename=composer
composer --version
