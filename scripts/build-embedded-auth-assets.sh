#!/usr/bin/env bash
set -euo pipefail

# Released packages already contain their frontend. A reviewed source pin must
# build its own matching assets before it enters a deployment artifact.
provider_dir=${1:-vendor/bherila/auth-manager}
manifest="$provider_dir/public/build/manifest.json"
if [ -s "$manifest" ]; then
  echo 'Using packaged identity-provider assets.'
  exit 0
fi
test -f "$provider_dir/package.json"
test -f "$provider_dir/pnpm-lock.yaml"

# Install build dependencies in a disposable copy, never in vendor/: otherwise
# deployment tarballs would include the provider's node_modules directory.
build_dir=$(mktemp -d)
trap 'rm -rf -- "$build_dir"' EXIT
tar -C "$provider_dir" \
  --exclude='./node_modules' --exclude='./vendor' --exclude='./.git' \
  --exclude='./storage' --exclude='./.env' --exclude='./.env.*' \
  --exclude='./public/hot' -cf - . | tar -C "$build_dir" -xf -
# The disposable directory is outside the host workspace. Keep the provider's
# own workspace policy, including its reviewed dependency build allowlist.
pnpm --dir "$build_dir" install --frozen-lockfile
pnpm --dir "$build_dir" run build
test -s "$build_dir/public/build/manifest.json"
mkdir -p "$provider_dir/public/build"
cp -a "$build_dir/public/build/." "$provider_dir/public/build/"
echo 'Built matching identity-provider assets.'
