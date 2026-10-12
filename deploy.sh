#!/usr/bin/env bash
# Deploy local_aicoursebuilder on the test server: pull the branch, run the Moodle
# upgrade and purge caches. Override any variable from the environment.
#
# The plugin directory has to be a git clone of the repository, owned by the web user (see README, Deployment).
# For a private repository set DEPLOY_KEY to the path of a read-only deploy key that the web user can read.
set -euo pipefail

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PLUGIN_DIR="${PLUGIN_DIR:-${MOODLE_DIR}/public/local/aicoursebuilder}"
PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www-data}"
GIT_BRANCH="${GIT_BRANCH:-main}"
DEPLOY_KEY="${DEPLOY_KEY:-}"

# Runs a command as the web user, which owns the plugin directory and the Moodle files.
as_web_user() {
    if [ "$(id -un)" = "${WEB_USER}" ]; then
        "$@"
    else
        sudo -u "${WEB_USER}" "$@"
    fi
}

run_moodle() {
    as_web_user "${PHP_BIN}" "$@"
}

if ! as_web_user git -C "${PLUGIN_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "${PLUGIN_DIR} is not a git clone of the plugin, or ${WEB_USER} cannot use it." >&2
    echo "Set it up once, as in README.md > Deployment, and run this script again." >&2
    exit 1
fi

if [ -n "${DEPLOY_KEY}" ]; then
    export GIT_SSH_COMMAND="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"
fi

as_web_user env ${GIT_SSH_COMMAND:+GIT_SSH_COMMAND="${GIT_SSH_COMMAND}"} \
    git -C "${PLUGIN_DIR}" pull --ff-only origin "${GIT_BRANCH}"
run_moodle "${MOODLE_DIR}/admin/cli/upgrade.php" --non-interactive
run_moodle "${MOODLE_DIR}/admin/cli/purge_caches.php"
