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

full_version_for() {
    local version="${1:-}"
    local binary=""
    local full=""

    for binary in "/usr/bin/php${version}" "/usr/local/bin/php${version}" "/usr/bin/php${version/./}"; do
        [[ -x "${binary}" ]] || continue
        full="$("${binary}" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
        [[ -n "${full}" ]] && break
    done

    if [[ -z "${full}" && -x "/usr/sbin/php-fpm${version}" ]]; then
        full="$("/usr/sbin/php-fpm${version}" -v 2>/dev/null | head -n 1 | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1 || true)"
    fi

    if [[ -z "${full}" ]] && command -v dpkg-query >/dev/null 2>&1; then
        full="$(dpkg-query -W -f='${Version}' "php${version}-cli" 2>/dev/null | grep -Eo '^([0-9]+:)?[0-9]+\.[0-9]+\.[0-9]+' | sed 's/^[0-9]*://' || true)"
    fi

    if [[ "${full}" =~ ^${version//./\\.}\.[0-9]+ ]]; then
        printf '%s' "${BASH_REMATCH[0]}"
    fi
}

WITH_FULL=0
if [[ "${1:-}" == "--full" ]]; then
    WITH_FULL=1
fi

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

mapfile -t SORTED < <(printf '%s\n' "${VERSIONS[@]}" | awk 'NF > 0' | sort -uV | tac)

for version in "${SORTED[@]}"; do
    if [[ "${WITH_FULL}" -eq 1 ]]; then
        printf '%s %s\n' "${version}" "$(full_version_for "${version}")"
    else
        printf '%s\n' "${version}"
    fi
done
