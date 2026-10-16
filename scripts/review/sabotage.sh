#!/usr/bin/env bash
# Targeted sabotage: breaks one guarantee in the plugin copy of the fast Moodle tree, runs only the filtered PHPUnit
# tests, and puts the file back. Used by /task, /review-pr and /recheck-pr (.claude/commands).
#
#   scripts/review/sabotage.sh <file> '<sed expression, delimiter #>' "<phpunit filter>"
#
#   <file>    path relative to the plugin root, e.g. classes/external/start_job.php
#   <filter>  PHPUnit --filter regex: a class or class::method, e.g. start_job_test or 'start_job_test::test_denied'
#
# It works on the plugin as it stands in the container's fast tree, i.e. the code of the last scripts/verifica run
# (schimbari, rapid, complet) or of scripts/review/precheck.sh: run it after one of them, and never at the same time.
# The host files are not touched. Result, one word on the last line:
#   PICAT        the tests failed: the guarantee is covered
#   NU A PICAT   the tests stayed green: the guarantee is not covered (a problem)
#   NEAPLICAT    the sed expression changed nothing
#   FARA TESTE   the filter matched no test
set -u
export MSYS_NO_PATHCONV=1

file="${1:-}"
expr="${2:-}"
filter="${3:-}"
if [ -z "$file" ] || [ -z "$expr" ] || [ -z "$filter" ]; then
    echo "Usage: scripts/review/sabotage.sh <file> '<sed expression>' \"<phpunit filter>\"" >&2
    exit 2
fi

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
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
if [ -z "$container" ] || ! docker exec "$container" test -f "$fast/$rel/$file" 2>/dev/null; then
    echo "No container with the fast tree and $file in it: run scripts/verifica schimbari first." >&2
    exit 2
fi

# The inner script restores the file whatever happens (trap), and says the result.
docker exec -e "SAB_SED=$expr" -e "SAB_FILE=$file" -e "SAB_FILTER=$filter" "$container" sh -c '
cd '"$fast"'
f='"$rel"'/$SAB_FILE
cp "$f" /tmp/sabotage.orig
trap "cp /tmp/sabotage.orig \"$f\"" EXIT
sed -i "$SAB_SED" "$f"
if cmp -s /tmp/sabotage.orig "$f"; then echo NEAPLICAT; exit 0; fi
vendor/bin/phpunit --testsuite local_aicoursebuilder_testsuite --filter "$SAB_FILTER" >/tmp/sabotage.log 2>&1
rc=$?
if grep -qE "No tests executed|No tests found" /tmp/sabotage.log; then echo "FARA TESTE"; exit 0; fi
tail -n 8 /tmp/sabotage.log
if [ "$rc" = "0" ]; then echo "NU A PICAT"; else echo PICAT; fi
'
