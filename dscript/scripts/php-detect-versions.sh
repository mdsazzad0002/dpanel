#!/usr/bin/env bash
set -euo pipefail

append_version() {
    local value="${1:-}"
    if [[ "${value}" =~ ^[0-9]+\.[0-9]+$ ]]; then
        VERSIONS+=("${value}")
    fi
}

extract_versions_from_text() {
    local text="${1:-}"
    local matches=()
    local match=""
    mapfile -t matches < <(printf '%s\n' "${text}" | grep -Eo '([0-9]+\.[0-9]+)' || true)
    for match in "${matches[@]}"; do
        append_version "${match}"
    done
}

VERSIONS=()

if command -v php >/dev/null 2>&1; then
    append_version "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
fi

if command -v update-alternatives >/dev/null 2>&1; then
    extract_versions_from_text "$(update-alternatives --list php 2>/dev/null || true)"
fi

if command -v dpkg >/dev/null 2>&1; then
    while IFS= read -r package; do
        if [[ "${package}" =~ ^php([0-9]+)\.([0-9]+)-(cli|fpm|common)(:.*)?$ ]]; then
            append_version "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
        fi
    done < <(dpkg-query -W -f='${binary:Package}\n' 'php*' 2>/dev/null || true)
fi

for path in /usr/bin/php* /usr/local/bin/php* /opt/php* /opt/cpanel/ea-php*/root/usr/bin/php; do
    [[ -e "${path}" ]] || continue
    base="$(basename "${path}")"

    if [[ "${base}" =~ ^php([0-9]+)\.([0-9]+)$ ]]; then
        append_version "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
        continue
    fi

    if [[ "${base}" =~ ^php([0-9])([0-9])$ ]]; then
        append_version "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
        continue
    fi

    if [[ "${base}" == "php" && -x "${path}" ]]; then
        append_version "$("${path}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    fi
done

if [[ "${#VERSIONS[@]}" -eq 0 ]]; then
    exit 2
fi

printf '%s\n' "${VERSIONS[@]}" \
    | awk 'NF > 0' \
    | sort -uV \
    | tac
