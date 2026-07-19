#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
source "${SCRIPT_DIR}/../shared/logs.sh"

dscript_info "Installing base packages..."

PACKAGES=(
  curl
  wget
  unzip
  git
  htop
  nano
  jq
  software-properties-common
  apt-transport-https
  ca-certificates
  gnupg
  lsb-release
  supervisor
  cron
)

dscript_pkg_install "${PACKAGES[@]}"

dscript_info "Base packages installed."
