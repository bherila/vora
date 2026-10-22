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
# Codex runs setup as root, and Composer then asks "Continue as root/super user
# [yes]?" on every invocation (even --version) and blocks on stdin until the
# 3600-second startup timeout. Allow root explicitly, and detach stdin so any
# future prompt fails fast instead of hanging.
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
exec </dev/null
export PATH="$HOME/.local/bin:$PATH"
# Node's built-in fetch ignores HTTP(S)_PROXY unless asked; pnpm and other Node tools use it.
export NODE_USE_ENV_PROXY=1

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
  local package_manager
  package_manager="$(pnpm_package_manager)"

  local wanted="${package_manager#pnpm@}"
  wanted="${wanted%%+*}"

  # With no packageManager pin the helper above defaults to a bare major (pnpm@10): there is no
  # exact version to match, so keep any installed pnpm and otherwise install through Corepack.
  if [[ ! "$wanted" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
    if command -v pnpm >/dev/null 2>&1; then
      log "pnpm already installed: $(pnpm --version)"
      return 0
    fi
    log "Installing pnpm via Corepack"
    need_cmd corepack
    mkdir -p "$HOME/.local/bin"
    corepack enable --install-directory "$HOME/.local/bin"
    corepack prepare "$package_manager" --activate
    pnpm --version
    return 0
  fi

  # A pnpm whose own version differs from packageManager tries to switch by downloading the
  # wanted release (@pnpm/exe.<platform>) with Node's built-in fetch, which ignores the sandbox
  # egress proxy, so even `pnpm --version` fails. Never let a version check trigger that.
  local current=""
  if command -v pnpm >/dev/null 2>&1; then
    current="$(npm_config_manage_package_manager_versions=false pnpm --version 2>/dev/null || true)"
  fi
  if [[ "$current" == "$wanted" ]]; then
    log "pnpm $wanted already installed"
    return 0
  fi

  # npm honours the proxy and CA settings the sandbox provides, so install the exact version
  # through npm into ~/.local (first on PATH) rather than letting pnpm or Corepack fetch it.
  log "Installing pnpm $wanted via npm (found: ${current:-none})"
  need_cmd npm
  mkdir -p "$HOME/.local"
  if npm install --global --prefix "$HOME/.local" --no-audit --no-fund "pnpm@$wanted"; then
    hash -r
    current="$(npm_config_manage_package_manager_versions=false pnpm --version 2>/dev/null || true)"
  fi

  if [[ "$current" != "$wanted" ]]; then
    log "Installing pnpm $wanted via Corepack"
    need_cmd corepack
    mkdir -p "$HOME/.local/bin"
    corepack enable --install-directory "$HOME/.local/bin"
    corepack prepare "$package_manager" --activate
    hash -r
    current="$(npm_config_manage_package_manager_versions=false pnpm --version 2>/dev/null || true)"
  fi

  if [[ "$current" != "$wanted" ]]; then
    echo "ERROR: could not install pnpm $wanted (have: ${current:-none}). The lockfile is pinned to it via packageManager." >&2
    exit 1
  fi

  # The exact version is on PATH; nothing should try to switch versions from here on.
  export npm_config_manage_package_manager_versions=false
  log "pnpm $(pnpm --version)"
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
