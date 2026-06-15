#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

export CODEX_COMPOSER_OPTIMIZE_AUTOLOADER=1

exec bash "$SCRIPT_DIR/setup.sh"
