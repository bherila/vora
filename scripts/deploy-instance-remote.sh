#!/usr/bin/env bash
set -euo pipefail

# Paths are relative to the SSH account's home; the calling workflow validates
# arguments before passing them through the remote shell.
deploy_path=$1
php_binary=$2
expected_profile=$3
web_roots=${4:-}
valid_relative_path() {
  [[ "$1" =~ ^[a-zA-Z0-9_.-]+(/[a-zA-Z0-9_.-]+)*$ && "$1" != . && "$1" != *..* && "$1" != */./* && "$1" != */. ]]
}
valid_relative_path "$deploy_path"
IFS=',' read -r -a roots <<< "$web_roots"
for root in "${roots[@]}"; do
  test -n "$root" || continue
  valid_relative_path "$root"
  target="$HOME/$root"
  # A new instance must never replace a real directory or its own application.
  case "$root/" in "$deploy_path/"*) echo "Web root overlaps application" >&2; exit 1;; esac
  case "$deploy_path/" in "$root/"*) echo "Web root contains application" >&2; exit 1;; esac
  if [ -e "$target" ] && [ ! -L "$target" ]; then
    echo "Web root must be absent or a symlink: $root" >&2
    exit 1
  fi
done

cd "$HOME/$deploy_path"
test -f .env
chmod 710 .
chmod 600 .env
umask 077
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
"$php_binary" artisan config:clear
"$php_binary" artisan config:cache
chmod 600 bootstrap/cache/config.php
"$php_binary" artisan ops:instance-profile --expect="$expected_profile"
"$php_binary" artisan migrate --force --no-interaction
"$php_binary" artisan schema:assert-persona
"$php_binary" artisan view:clear

for root in "${roots[@]}"; do
  test -n "$root" || continue
  ln -sfnT "$HOME/$deploy_path/public" "$HOME/$root"
done
