#!/usr/bin/env bash
# Deploy local_aicoursebuilder on the test server: pull main, run the Moodle
# upgrade and purge caches. Override any variable from the environment.
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PLUGIN_DIR="${PLUGIN_DIR:-${MOODLE_DIR}/public/local/aicoursebuilder}"
PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www-data}"
GIT_BRANCH="${GIT_BRANCH:-main}"

run_moodle() {
    if [ "$(id -un)" = "${WEB_USER}" ]; then
        "${PHP_BIN}" "$@"
    else
        sudo -u "${WEB_USER}" "${PHP_BIN}" "$@"
    fi
}

git -C "${PLUGIN_DIR}" pull --ff-only origin "${GIT_BRANCH}"
run_moodle "${MOODLE_DIR}/admin/cli/upgrade.php" --non-interactive
run_moodle "${MOODLE_DIR}/admin/cli/purge_caches.php"
