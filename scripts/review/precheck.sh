#!/usr/bin/env bash
# Mechanical checks for /review-pr and /recheck-pr (.claude/commands).
#
#   scripts/review/precheck.sh <pr> <outdir> [<previous review dir>]
#
# Fetches the pull request, merges it with origin/main in a scratch worktree (<outdir>/wt), checks the things a script
# can check (sensitive zones, lang parity en/ro, new strings, prompts, forbidden APIs, secrets, CI status), copies the
# result into the fast Moodle tree of the Docker stack (/opt/moodle-fast, made by scripts/verifica) and runs php -l,
# phpcs on the changed files and the whole PHPUnit suite there. Writes summary.md, pr.diff, head.sha, pr.json.
# With a previous review directory (head.sha, pr.diff) it also writes delta-files.txt and delta.diff: what changed in
# the pull request since that review (what came only through the merge with main does not show).
#
# The fast tree is shared with scripts/verifica: do not run both at the same time. Afterwards run
# scripts/review/cleanup.sh. Exit 2 = environment (Docker, fast tree, gh), not code.
set -u
export MSYS_NO_PATHCONV=1

pr="${1:-}"
out="${2:-}"
prev="${3:-}"
if [ -z "$pr" ] || [ -z "$out" ]; then
    echo "Usage: scripts/review/precheck.sh <pr> <outdir> [<previous review dir>]" >&2
    exit 2
fi

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo" || exit 2
# Native git.exe does not understand the POSIX form (/c/...) that pwd gives, and $out may arrive with backslashes:
# use the mixed form (C:/...) everywhere.
command -v cygpath >/dev/null 2>&1 && out="$(cygpath -u "$out")"
mkdir -p "$out"
out="$(cd "$out" && pwd)"
command -v cygpath >/dev/null 2>&1 && out="$(cygpath -m "$out")"
summary="$out/summary.md"
wt="$out/wt"
fast=/opt/moodle-fast
rel=public/local/aicoursebuilder

gh_bin="$(command -v gh || true)"
[ -n "$gh_bin" ] || { [ -x "/c/Program Files/GitHub CLI/gh.exe" ] && gh_bin="/c/Program Files/GitHub CLI/gh.exe"; }
[ -n "$gh_bin" ] || { echo "gh not found: install it and run 'gh auth login'." >&2; exit 2; }

git fetch -q origin main || exit 2
git fetch -q -f origin "pull/$pr/head:refs/review/pr-$pr" || { echo "Pull request $pr not found." >&2; exit 2; }
head_sha="$(git rev-parse "refs/review/pr-$pr")"
base="$(git merge-base origin/main "$head_sha")"
echo "$head_sha" >"$out/head.sha"
"$gh_bin" pr view "$pr" --json number,title,author,baseRefName,headRefName,mergeStateStatus,body,url >"$out/pr.json"

if [ -n "$prev" ] && [ -f "$prev/head.sha" ] && [ "$(cat "$prev/head.sha")" = "$head_sha" ]; then
    echo "Nothing new: the pull request is still at $head_sha (the sha of the previous review)." | tee "$summary"
    exit 0
fi

git diff "$base" "$head_sha" >"$out/pr.diff"
git diff --name-only "$base" "$head_sha" >"$out/files.txt"

{
    echo "# Precheck PR #$pr"
    echo
    echo "- head: $head_sha, base of the diff: $(git rev-parse --short "$base")"
    echo "- pr.json: author, branch, base, mergeStateStatus ($("$gh_bin" pr view "$pr" --json mergeStateStatus -q .mergeStateStatus))"
    echo "- files changed: $(wc -l <"$out/files.txt")"
    echo
} >"$summary"

# ---- delta since the previous review --------------------------------------------------------------------------
if [ -n "$prev" ] && [ -f "$prev/head.sha" ] && [ -f "$prev/pr.diff" ]; then
    old_head="$(cat "$prev/head.sha")"
    old_base="$(git merge-base origin/main "$old_head" 2>/dev/null || true)"
    : >"$out/delta-files.txt"
    : >"$out/delta.diff"
    if [ -n "$old_base" ]; then
        while IFS= read -r f; do
            [ -n "$f" ] || continue
            a="$(git diff "$old_base" "$old_head" -- "$f")"
            b="$(git diff "$base" "$head_sha" -- "$f")"
            if [ "$a" != "$b" ]; then
                echo "$f" >>"$out/delta-files.txt"
                diff -u <(echo "$a") <(echo "$b") >>"$out/delta.diff"
            fi
        done < <({ git diff --name-only "$old_base" "$old_head"; cat "$out/files.txt"; } | sort -u)
    fi
    echo "- delta since $(git rev-parse --short "$old_head"): $(wc -l <"$out/delta-files.txt") files (delta-files.txt, delta.diff)" >>"$summary"
    echo >>"$summary"
fi

# ---- merge with origin/main in a scratch worktree -------------------------------------------------------------
git worktree prune
git worktree add -q --detach "$wt" origin/main || exit 2
if git -C "$wt" merge -q --no-edit "$head_sha" >"$out/merge.log" 2>&1; then
    echo "## Merge with origin/main: CLEAN" >>"$summary"
else
    echo "## Merge with origin/main: CONFLICTS (the checks below run on the pull request head)" >>"$summary"
    sed 's/^/    /' "$out/merge.log" >>"$summary"
    git -C "$wt" merge --abort
    git -C "$wt" checkout -q --detach "$head_sha"
fi
echo >>"$summary"

# ---- content checks -------------------------------------------------------------------------------------------
{
    echo "## Sensitive zones touched"
    grep -E '^(db/|version\.php|classes/external/|classes/privacy/|lib\.php|settings\.php|deploy\.sh|\.github/|thirdparty|thirdpartylibs\.xml|AGENTS\.md|schema/|prompts/)' "$out/files.txt" || echo "none"
    echo
    echo "## AGENTS.md / README.md / docs/ touched"
    grep -E '^(AGENTS\.md|README\.md|docs/)' "$out/files.txt" || echo "none"
    echo
    echo "## Prompts: existing versions modified or deleted (a version that has run is never edited)"
    git diff --name-status "$base" "$head_sha" -- prompts | grep -v '^A' || echo "none"
    echo
    echo "## Lang parity en/ro (keys in one and not in the other, merged tree)"
    keys() { grep -oE "^\\\$string\['[^']+'\]" "$wt/lang/$1/local_aicoursebuilder.php" | sort -u; }
    diff <(keys en) <(keys ro) && echo "parity OK"
    echo
    echo "## Version / upgrade"
    if grep -q '^version.php$' "$out/files.txt"; then echo "version.php changed"; else echo "version.php NOT changed"; fi
    if grep -qE '^db/(install\.xml|upgrade\.php)$' "$out/files.txt"; then
        echo "db/install.xml: $(grep -c '^db/install.xml$' "$out/files.txt"), db/upgrade.php: $(grep -c '^db/upgrade.php$' "$out/files.txt") (both must change together)"
    fi
    echo
    echo "## amd/src changed without amd/build (CI Grunt would fail as stale)"
    for f in $(grep -E '^amd/src/.*\.js$' "$out/files.txt"); do
        grep -qx "amd/build/$(basename "$f" .js).min.js" "$out/files.txt" || echo "$f"
    done
    echo "(end of list)"
    echo
    echo "## classes/ changed without any tests/ change"
    if grep -q '^classes/' "$out/files.txt" && ! grep -q '^tests/' "$out/files.txt"; then echo "YES"; else echo "no"; fi
    echo
    echo "## Added lines matching forbidden APIs / secrets"
    grep -E '^\+[^+]' "$out/pr.diff" | grep -nE 'question_make_default_categories|save_question\(|INSMC|backup_controller|restore_controller|api[_-]?key|secret|password|sk-[A-Za-z0-9]{10,}' || echo "none"
    echo
    echo "## CI (gh pr checks)"
    "$gh_bin" pr checks "$pr" 2>&1 || true
    echo
} >>"$summary"

# ---- PHP checks in the container ------------------------------------------------------------------------------
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
if [ -z "$container" ] || ! docker exec "$container" test -f "$fast/.synced" 2>/dev/null; then
    echo "## PHP checks: NOT RUN (no container mounting this plugin, or no fast tree: start the Docker stack and run" | tee -a "$summary"
    echo "scripts/verifica rapid once on your branch)." | tee -a "$summary"
    exit 2
fi

echo "Syncing the merged tree into $container:$fast/$rel"
tar cf - --exclude=.git -C "$wt" . | docker exec -i "$container" sh -c "rm -rf $fast/$rel && mkdir -p $fast/$rel && tar xf - -C $fast/$rel" || exit 2

phpcs="$(for p in /tmp/quality/vendor/bin/phpcs /quality/vendor/bin/phpcs; do docker exec "$container" test -x "$p" && echo "$p" && break; done)"
changed_php="$(cd "$wt" && git diff --name-only --diff-filter=d origin/main HEAD | grep -E '\.php$' | grep -v '^thirdparty/' | tr '\n' ' ')"
{
    echo "## php -l / phpcs on the changed .php files"
    if [ -z "$changed_php" ]; then
        echo "no changed .php file"
    else
        docker exec "$container" sh -c "cd $fast/$rel && for f in $changed_php; do php -l \$f; done" | grep -v '^No syntax errors' || true
        if [ -n "$phpcs" ]; then
            docker exec "$container" sh -c "cd $fast/$rel && php -d memory_limit=1G $phpcs --standard=moodle --extensions=php --warning-severity=1 -q $changed_php" \
                && echo "phpcs: OK" || echo "phpcs: FAILED (output above)"
        else
            echo "phpcs: NOT RUN (moodle-cs not found in the container)"
        fi
    fi
    echo
} >>"$summary" 2>&1

echo "PHPUnit (whole suite, a few minutes)..."
docker exec "$container" sh -c "cd $fast && (php public/admin/tool/phpunit/cli/util.php --diag >/dev/null 2>&1 || php public/admin/tool/phpunit/cli/init.php --disable-composer >/dev/null 2>&1); vendor/bin/phpunit --testsuite local_aicoursebuilder_testsuite" >"$out/phpunit.log" 2>&1
rc=$?
{
    echo "## PHPUnit, whole suite on the merged tree: $([ "$rc" = "0" ] && echo OK || echo "FAILED (exit $rc)")"
    tail -n 12 "$out/phpunit.log"
    echo
    echo "grunt, mustache, phpdoc, savepoints, behat: CI only (see the CI section above)."
} >>"$summary"

echo "Done: $summary (worktree: $wt)"
