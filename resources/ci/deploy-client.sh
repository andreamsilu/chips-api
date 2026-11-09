#!/usr/bin/env bash
set -euo pipefail

: "${DEPLOY_HOST:?Set DEPLOY_HOST}"
: "${DEPLOY_USER:?Set DEPLOY_USER}"
: "${DEPLOY_PATH:?Set DEPLOY_PATH}"
: "${CI_COMMIT_SHA:?Missing CI commit}"
DEPLOY_PORT=${DEPLOY_PORT:-22}
[[ "$DEPLOY_HOST" =~ ^[a-zA-Z0-9.-]+$ ]]
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ ]]
[[ "$DEPLOY_PORT" =~ ^[0-9]+$ ]] && (( DEPLOY_PORT >= 1 && DEPLOY_PORT <= 65535 ))
[[ "$DEPLOY_PATH" == /var/www/chips-api ]]
[[ "$CI_COMMIT_SHA" =~ ^[a-f0-9]{40}$ ]]

latest=$(git ls-remote origin refs/heads/main | cut -f1)
if [[ "$latest" != "$CI_COMMIT_SHA" ]]; then
  printf 'Skipping an outdated pipeline; main has advanced.\n'
  exit 0
fi

sha256sum --check release.tar.gz.sha256
remote="$DEPLOY_USER@$DEPLOY_HOST"
incoming="$DEPLOY_PATH/.deploy/incoming-$CI_COMMIT_SHA"
ssh_options=(-p "$DEPLOY_PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 -o ServerAliveInterval=15)
ssh "${ssh_options[@]}" "$remote" "umask 077; mkdir -p '$incoming'"
rsync -a -e "ssh -p $DEPLOY_PORT -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15" \
  release.tar.gz release.tar.gz.sha256 "$remote:$incoming/"
ssh "${ssh_options[@]}" "$remote" "bash -s -- '$DEPLOY_PATH' '$CI_COMMIT_SHA'" < resources/ci/deploy-server.sh
