#!/usr/bin/env bash
set -Eeuo pipefail

log() {
  printf '\n==> %s\n' "$*"
}

warn() {
  printf '\nWARN: %s\n' "$*" >&2
}

cleanup_paths=()

cleanup() {
  local path

  for path in "${cleanup_paths[@]-}"; do
    if [[ -n "$path" ]]; then
      rm -f "$path"
    fi
  done
}

trap cleanup EXIT

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

export CI="${CI:-1}"
export PATH="$HOME/.local/bin:$PATH"

cd "$REPO_ROOT"

need_cmd curl
need_cmd node
need_cmd php

ensure_composer() {
  if command -v composer >/dev/null 2>&1; then
    log "Composer already installed: $(composer --version)"
    return 0
  fi

  log "Installing Composer"

  mkdir -p "$HOME/.local/bin"

  local installer
  installer="$(mktemp)"
  cleanup_paths+=("$installer")

  local expected_checksum
  local actual_checksum
  expected_checksum="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o "$installer"
  actual_checksum="$(php -r "echo hash_file('sha384', '$installer');")"

  if [[ "$expected_checksum" != "$actual_checksum" ]]; then
    echo "ERROR: Invalid Composer installer checksum." >&2
    exit 1
  fi

  php "$installer" \
    --install-dir="$HOME/.local/bin" \
    --filename=composer \
    --quiet

  rm -f "$installer"
}

pnpm_package_manager() {
  node - <<'NODE'
const fs = require("fs");

let packageManager = "";

try {
  packageManager = JSON.parse(fs.readFileSync("package.json", "utf8")).packageManager || "";
} catch {
  packageManager = "";
}

if (packageManager && !packageManager.startsWith("pnpm@")) {
  process.stderr.write(`ERROR: package.json packageManager must use pnpm, got ${packageManager}\n`);
  process.exit(1);
}

process.stdout.write(packageManager || "pnpm@10");
NODE
}

ensure_pnpm() {
  if command -v pnpm >/dev/null 2>&1; then
    log "pnpm already installed: $(pnpm --version)"
    return 0
  fi

  log "Installing pnpm via Corepack"

  need_cmd corepack
  mkdir -p "$HOME/.local/bin"
  corepack enable --install-directory "$HOME/.local/bin"
  corepack prepare "$(pnpm_package_manager)" --activate
  pnpm --version
}

has_github_dependencies() {
  grep -Eqs 'github\.com/bherila/auth|bwh-auth' composer.json package.json pnpm-lock.yaml 2>/dev/null
}

configure_github_auth() {
  if [[ -z "${GITHUB_TOKEN:-}" ]]; then
    if has_github_dependencies; then
      warn "GITHUB_TOKEN is not set; GitHub-hosted dependencies may fail to install."
    fi

    return 0
  fi

  local existing_userconfig="${NPM_CONFIG_USERCONFIG:-}"
  local npmrc
  npmrc="$(mktemp)"
  cleanup_paths+=("$npmrc")

  if [[ -n "$existing_userconfig" && -f "$existing_userconfig" ]]; then
    cat "$existing_userconfig" > "$npmrc"
  fi

  {
    printf '//github.com/:_authToken=%s\n' "$GITHUB_TOKEN"
    printf '//github.com/:always-auth=true\n'
  } >> "$npmrc"

  export NPM_CONFIG_USERCONFIG="$npmrc"

  if [[ -z "${COMPOSER_AUTH:-}" ]]; then
    export COMPOSER_AUTH
    COMPOSER_AUTH="$(php -r 'echo json_encode(["github-oauth" => ["github.com" => getenv("GITHUB_TOKEN")]], JSON_UNESCAPED_SLASHES);')"
  fi
}

install_node_dependencies() {
  if [[ ! -f package.json ]]; then
    return 0
  fi

  log "Installing Node dependencies"

  if [[ -f pnpm-lock.yaml ]]; then
    pnpm install --frozen-lockfile --prefer-offline
  else
    pnpm install --prefer-offline
  fi
}

install_php_dependencies() {
  if [[ ! -f composer.json ]]; then
    return 0
  fi

  log "Installing PHP dependencies"

  local composer_args=(
    install
    --no-interaction
    --prefer-dist
    --no-progress
  )

  if [[ "${CODEX_COMPOSER_OPTIMIZE_AUTOLOADER:-0}" == "1" ]]; then
    composer_args+=(--optimize-autoloader)
  fi

  log "Checking PHP platform requirements"
  composer check-platform-reqs --lock

  composer "${composer_args[@]}"
}

install_dependencies() {
  install_php_dependencies
  ensure_pnpm
  install_node_dependencies
}

prepare_laravel_environment() {
  if [[ ! -f artisan || ! -f .env.example ]]; then
    return 0
  fi

  if [[ ! -f .env ]]; then
    log "Creating local .env from .env.example"
    cp .env.example .env
  fi

  if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    log "Generating Laravel application key"
    php artisan key:generate --no-interaction --force
  fi
}

ensure_composer
configure_github_auth
install_dependencies
prepare_laravel_environment

log "Codex environment setup complete"
