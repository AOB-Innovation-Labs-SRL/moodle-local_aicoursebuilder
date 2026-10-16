#!/usr/bin/env bash
# Cleans up after scripts/review/precheck.sh: removes the scratch worktree and the fetched PR ref, and puts the
# plugin of this checkout back into the fast Moodle tree of the container (precheck.sh replaced it with the PR code).
#
#   scripts/review/cleanup.sh <worktree> [<pr>]
set -u
export MSYS_NO_PATHCONV=1

wt="${1:-}"
pr="${2:-}"
if [ -z "$wt" ]; then
    echo "Usage: scripts/review/cleanup.sh <worktree> [<pr>]" >&2
    exit 2
fi

command -v cygpath >/dev/null 2>&1 && wt="$(cygpath -m "$wt")"
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo" || exit 2
[ -d "$wt" ] && git worktree remove --force "$wt"
git worktree prune
[ -n "$pr" ] && git update-ref -d "refs/review/pr-$pr"

fast=/opt/moodle-fast
rel=public/local/aicoursebuilder
base_name="$(basename "$repo")"
container="${VERIFICA_CONTAINER:-}"
if [ -z "$container" ]; then
    for c in $(docker ps --format '{{.Names}}' 2>/dev/null); do
        if docker inspect "$c" --format '{{range .Mounts}}{{.Destination}}|{{.Source}}{{println}}{{end}}' 2>/dev/null \
            | grep -qiE "^/var/www/html/$rel\|.*[/\\\\]$base_name\$"; then
            container="$c"
            break
        fi
    done
fi
if [ -n "$container" ] && docker exec "$container" test -f "$fast/.synced" 2>/dev/null; then
    docker exec "$container" sh -c "rm -rf $fast/$rel && mkdir -p $fast/$rel && cd /var/www/html/$rel && tar cf - --exclude=./.git . | tar xf - -C $fast/$rel && cd $fast && (php public/admin/tool/phpunit/cli/util.php --diag >/dev/null 2>&1 || php public/admin/tool/phpunit/cli/init.php --disable-composer >/dev/null 2>&1)"
    echo "Fast tree restored from this checkout."
fi
