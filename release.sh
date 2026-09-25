#!/usr/bin/env bash
#
# release.sh — cut a StarDust release.
#
# The version is decided in a normal, reviewed commit: StarDust::VERSION
# and a matching `## [x.y.z] - YYYY-MM-DD` CHANGELOG heading move together
# (DocsConsistencyTest enforces the pairing). This script never edits or
# commits files. It proves that commit is releasable, then:
#
#   1. creates an annotated (optionally signed) tag with the one-line
#      message "StarDust x.y.z" — tags cannot be edited once published, so
#      the release notes never go in the tag,
#   2. pushes the tag — Packagist picks it up from the GitHub webhook,
#   3. publishes a GitHub Release whose body is that version's CHANGELOG
#      section (marked pre-release for alpha/beta/rc).
#
# Run it from Git Bash on Windows or any bash 4.4+ shell. `--help` for usage.

set -Eeuo pipefail

# --- configuration -----------------------------------------------------------

TAG_PREFIX="v"
REMOTE="origin"
BRANCH="main"
PACKAGE="damarbob/stardust"
VERSION_FILE="src/StarDust.php"
CHANGELOG="CHANGELOG.md"
# Same pin and globs as the markdown-lint CI job.
MARKDOWNLINT="markdownlint-cli2@0.23.2"
MARKDOWN_GLOBS=('*.md' 'src/**/*.md' '.agent/**/*.md' 'docs/**/*.md')

# --- options -----------------------------------------------------------------

DRY_RUN=0
ASSUME_YES=0
SKIP_TESTS=0
SKIP_CI_CHECK=0
PUSH=1
GITHUB_RELEASE=1
SIGN=0
PREID=""
TARGET_ARG=""

usage() {
    cat <<'EOF'
Usage: ./release.sh [options] [<version> | major | minor | patch | prerelease | promote]

Tags the current commit of the release branch as a StarDust release.

Target version (default: the value of StarDust::VERSION):
  <version>      An explicit version, e.g. 0.3.0-alpha.2 (a leading "v" is accepted).
  major|minor|patch
                 Bump that part of the latest tag. Add --preid to start a
                 pre-release cycle: `minor --preid alpha` on v0.2.0-alpha.3
                 gives 0.3.0-alpha.1.
  prerelease     Next number in the current cycle: alpha.1 -> alpha.2.
  promote        Next stability level: alpha.N -> beta.1 -> rc.1 -> stable.

Whichever form you use, StarDust::VERSION and the newest CHANGELOG heading
must already name the target: commit and push that change first.

Options:
  -n, --dry-run          Run every check and print what would happen; change nothing.
  -y, --yes              Do not ask for confirmation (for CI or scripted use).
      --preid <id>       Pre-release id for major|minor|patch: alpha, beta or rc.
      --sign             Create a GPG/SSH-signed tag (git tag -s).
      --skip-tests       Skip the smoke suite. PHPStan and markdownlint still run.
      --skip-ci-check    Do not require green GitHub checks on the commit.
      --no-push          Create the tag locally only (implies --no-github-release).
      --no-github-release
                         Push the tag but do not create a GitHub Release.
      --remote <name>    Remote to release to (default: origin).
      --branch <name>    Branch releases are cut from (default: main).
  -h, --help             Show this help.

Environment:
  GITHUB_TOKEN           Optional. Used for the CI check when the gh CLI is absent,
                         to avoid the unauthenticated API rate limit.
  NO_COLOR               Disable coloured output.

Pre-release ids are limited to alpha, beta and rc, written as `-alpha.N`:
Composer ignores tags whose suffix it cannot parse, so a tag such as
`-preview.1` would never reach Packagist.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -n|--dry-run) DRY_RUN=1 ;;
        -y|--yes) ASSUME_YES=1 ;;
        --preid) PREID="${2:?--preid needs a value}"; shift ;;
        --preid=*) PREID="${1#*=}" ;;
        --sign) SIGN=1 ;;
        --skip-tests) SKIP_TESTS=1 ;;
        --skip-ci-check) SKIP_CI_CHECK=1 ;;
        --no-push) PUSH=0; GITHUB_RELEASE=0 ;;
        --no-github-release) GITHUB_RELEASE=0 ;;
        --remote) REMOTE="${2:?--remote needs a value}"; shift ;;
        --remote=*) REMOTE="${1#*=}" ;;
        --branch) BRANCH="${2:?--branch needs a value}"; shift ;;
        --branch=*) BRANCH="${1#*=}" ;;
        -h|--help) usage; exit 0 ;;
        -*) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
        *)
            [[ -z "$TARGET_ARG" ]] || { echo "Only one target version may be given." >&2; exit 2; }
            TARGET_ARG="$1"
            ;;
    esac
    shift
done

# --- output helpers ----------------------------------------------------------

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
    C_RED=$'\e[31m' C_GREEN=$'\e[32m' C_YELLOW=$'\e[33m' C_BOLD=$'\e[1m' C_DIM=$'\e[2m' C_RESET=$'\e[0m'
else
    C_RED="" C_GREEN="" C_YELLOW="" C_BOLD="" C_DIM="" C_RESET=""
fi

WARNINGS=0
step() { printf '\n%s==> %s%s\n' "$C_BOLD" "$*" "$C_RESET"; }
ok()   { printf '  %s✓%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn() { printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; WARNINGS=$((WARNINGS + 1)); }
die()  { printf '\n%serror:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

# Runs a command that changes state; in --dry-run it is only printed.
run() {
    if [[ $DRY_RUN -eq 1 ]]; then
        printf '  %s[dry-run]%s %s\n' "$C_DIM" "$C_RESET" "$*"
    else
        "$@"
    fi
}

confirm() {
    [[ $ASSUME_YES -eq 1 ]] && return 0
    [[ -r /dev/tty ]] || die "No terminal to confirm on; re-run with --yes."
    local reply
    read -r -p "$1 [y/N] " reply </dev/tty
    [[ "$reply" =~ ^[Yy]([Ee][Ss])?$ ]]
}

WORK_DIR="$(mktemp -d)"
TAG_CREATED=""
cleanup() {
    local status=$?
    rm -rf "$WORK_DIR"
    if [[ $status -ne 0 && -n "$TAG_CREATED" ]]; then
        printf '\n%sThe tag %s exists locally but the release did not finish.%s\n' "$C_YELLOW" "$TAG_CREATED" "$C_RESET" >&2
        printf 'Re-run the failed step by hand, or delete it with: git tag -d %s\n' "$TAG_CREATED" >&2
    fi
}
trap cleanup EXIT
trap 'printf "%serror:%s unexpected failure at line %s: %s\n" "$C_RED" "$C_RESET" "$LINENO" "$BASH_COMMAND" >&2' ERR

have() { command -v "$1" >/dev/null 2>&1; }

# --- semver helpers ----------------------------------------------------------

# Core SemVer without build metadata (Composer does not order on it), with the
# pre-release restricted to the forms Composer and Packagist understand.
SEMVER_RE='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)\.([1-9][0-9]*))?$'

is_version() { [[ "$1" =~ $SEMVER_RE ]]; }

# semver_cmp A B -> prints -1, 0 or 1 (SemVer 2.0 precedence).
semver_cmp() {
    local a="$1" b="$2" a_pre="" b_pre="" i x y
    [[ "$a" == *-* ]] && a_pre="${a#*-}"
    [[ "$b" == *-* ]] && b_pre="${b#*-}"
    local -a ac bc ap bp
    IFS=. read -r -a ac <<<"${a%%-*}"
    IFS=. read -r -a bc <<<"${b%%-*}"
    for i in 0 1 2; do
        if ((ac[i] > bc[i])); then echo 1; return; fi
        if ((ac[i] < bc[i])); then echo -1; return; fi
    done
    if [[ -z "$a_pre" && -z "$b_pre" ]]; then echo 0; return; fi
    if [[ -z "$a_pre" ]]; then echo 1; return; fi   # a release outranks its pre-releases
    if [[ -z "$b_pre" ]]; then echo -1; return; fi
    IFS=. read -r -a ap <<<"${a_pre,,}"
    IFS=. read -r -a bp <<<"${b_pre,,}"
    for ((i = 0; ; i++)); do
        if ((i >= ${#ap[@]} && i >= ${#bp[@]})); then echo 0; return; fi
        if ((i >= ${#ap[@]})); then echo -1; return; fi
        if ((i >= ${#bp[@]})); then echo 1; return; fi
        x="${ap[i]}" y="${bp[i]}"
        [[ "$x" == "$y" ]] && continue
        if [[ "$x" =~ ^[0-9]+$ && "$y" =~ ^[0-9]+$ ]]; then
            ((x > y)) && echo 1 || echo -1
        elif [[ "$x" =~ ^[0-9]+$ ]]; then echo -1
        elif [[ "$y" =~ ^[0-9]+$ ]]; then echo 1
        else
            local LC_ALL=C
            [[ "$x" > "$y" ]] && echo 1 || echo -1
        fi
        return
    done
}

# next_version BASE KEYWORD [PREID] -> prints the bumped version.
next_version() {
    local base="$1" kind="$2" preid="${3:-}"
    [[ "$base" =~ $SEMVER_RE ]] || die "Cannot bump '$base': not a release version."
    local major="${BASH_REMATCH[1]}" minor="${BASH_REMATCH[2]}" patch="${BASH_REMATCH[3]}"
    local pre_id="${BASH_REMATCH[5]}" pre_num="${BASH_REMATCH[6]}" core
    case "$kind" in
        major) core="$((major + 1)).0.0" ;;
        minor) core="$major.$((minor + 1)).0" ;;
        patch) core="$major.$minor.$((patch + 1))" ;;
        prerelease)
            [[ -n "$pre_id" ]] || die "$base is not a pre-release; use major|minor|patch --preid <id>."
            echo "$major.$minor.$patch-$pre_id.$((pre_num + 1))"; return ;;
        promote)
            case "$pre_id" in
                alpha) echo "$major.$minor.$patch-beta.1" ;;
                beta)  echo "$major.$minor.$patch-rc.1" ;;
                rc)    echo "$major.$minor.$patch" ;;
                *)     die "$base is already stable; there is nothing to promote." ;;
            esac
            return ;;
    esac
    if [[ -n "$preid" ]]; then echo "$core-$preid.1"; else echo "$core"; fi
}

# --- 1. repository state -----------------------------------------------------

step "Repository"

have git || die "git is not installed."
have php || die "php is not on PATH."
ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "Not inside a git repository."
cd "$ROOT"
[[ -f "$VERSION_FILE" && -f "$CHANGELOG" ]] || die "Run this from the StarDust repository."

current_branch="$(git symbolic-ref --short -q HEAD || true)"
[[ "$current_branch" == "$BRANCH" ]] \
    || die "Releases are cut from '$BRANCH', but HEAD is on '${current_branch:-a detached HEAD}'."
ok "on branch $BRANCH"

# Untracked files outside the package (ROADMAP.md, sibling repos) are fine;
# modified tracked files, or untracked files inside it, would make the gates
# below test something other than the commit being tagged.
[[ -z "$(git status --porcelain --untracked-files=no)" ]] \
    || die "Tracked files have uncommitted changes:"$'\n'"$(git status --short --untracked-files=no)"
stray="$(git ls-files --others --exclude-standard -- src bin tests schemas docs examples docker)"
[[ -z "$stray" ]] || die "Untracked files inside the package would skew the checks:"$'\n'"$stray"
ok "working tree matches HEAD"

git fetch --quiet --tags "$REMOTE" "$BRANCH" || die "Could not fetch from '$REMOTE'."
head_sha="$(git rev-parse HEAD)"
remote_sha="$(git rev-parse "$REMOTE/$BRANCH")"
if [[ "$head_sha" != "$remote_sha" ]]; then
    ahead="$(git rev-list --count "$REMOTE/$BRANCH..HEAD")"
    behind="$(git rev-list --count "HEAD..$REMOTE/$BRANCH")"
    die "HEAD is $ahead ahead / $behind behind $REMOTE/$BRANCH. Push or pull first: a tag must point at a commit that is already on $BRANCH."
fi
ok "HEAD ${head_sha:0:7} is $REMOTE/$BRANCH"

# --- 2. version ----------------------------------------------------------------

step "Version"

latest_tag="$(git -c versionsort.suffix=- tag --list "${TAG_PREFIX}*" --sort=-v:refname \
    | while read -r t; do is_version "${t#"$TAG_PREFIX"}" && { echo "$t"; break; }; done || true)"
latest="${latest_tag#"$TAG_PREFIX"}"

code_version="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$VERSION_FILE" | head -n1)"
[[ -n "$code_version" ]] || die "Could not read StarDust::VERSION from $VERSION_FILE."

[[ -z "$PREID" || "$PREID" =~ ^(alpha|beta|rc)$ ]] || die "--preid must be alpha, beta or rc."
case "$TARGET_ARG" in
    "") VERSION="$code_version" ;;
    major|minor|patch|prerelease|promote)
        [[ -n "$latest" ]] || die "No existing ${TAG_PREFIX}x.y.z tag to bump from; pass an explicit version."
        VERSION="$(next_version "$latest" "$TARGET_ARG" "$PREID")" ;;
    *) VERSION="${TARGET_ARG#"$TAG_PREFIX"}" ;;
esac
TAG="$TAG_PREFIX$VERSION"

is_version "$VERSION" \
    || die "'$VERSION' is not a releasable version. Expected x.y.z or x.y.z-(alpha|beta|rc).N."
if [[ -n "$latest" ]]; then
    [[ "$(semver_cmp "$VERSION" "$latest")" == 1 ]] || die "$VERSION does not come after the latest tag, $latest_tag."
    ok "$latest_tag -> $TAG"
else
    ok "first tag: $TAG"
fi

git rev-parse -q --verify "refs/tags/$TAG" >/dev/null && die "Tag $TAG already exists locally."
if git ls-remote --exit-code --tags "$REMOTE" "refs/tags/$TAG" >/dev/null 2>&1; then
    die "Tag $TAG already exists on $REMOTE."
fi
ok "tag $TAG is free locally and on $REMOTE"

[[ "$code_version" == "$VERSION" ]] \
    || die "StarDust::VERSION is '$code_version'. Set it to '$VERSION' in $VERSION_FILE, add the CHANGELOG entry, commit, push, and re-run."
ok "StarDust::VERSION is $VERSION"

# --- 3. changelog --------------------------------------------------------------

step "Changelog"

heading="$(grep -m1 -E '^## \[' "$CHANGELOG" || true)"
heading="${heading%$'\r'}"
[[ "$heading" =~ ^'## ['([^]]+)'] - '([0-9]{4}-[0-9]{2}-[0-9]{2})$ ]] \
    || die "The newest CHANGELOG heading is '$heading'; expected '## [$VERSION] - YYYY-MM-DD'."
[[ "${BASH_REMATCH[1]}" == "$VERSION" ]] \
    || die "The newest CHANGELOG heading is for ${BASH_REMATCH[1]}, not $VERSION."
release_date="${BASH_REMATCH[2]}"
today="$(date +%Y-%m-%d)"
if [[ "$release_date" == "$today" ]]; then
    ok "## [$VERSION] - $release_date"
else
    warn "CHANGELOG dates this release $release_date; today is $today."
fi

grep -qF "[$VERSION]: " "$CHANGELOG" \
    || warn "No '[$VERSION]: <compare url>' link reference at the foot of $CHANGELOG."

# The section body, without its heading, surrounding blank lines, or the
# link references that follow the oldest section.
NOTES_FILE="$WORK_DIR/notes.md"
awk -v heading="## [$VERSION]" '
    index($0, heading) == 1 { found = 1; next }
    found && /^## \[/        { exit }
    found && /^\[[^]]+\]: /  { next }
    found                    { lines[++n] = $0 }
    END {
        first = 1; while (first <= n && lines[first] ~ /^[[:space:]]*$/) first++
        last = n;  while (last >= first && lines[last] ~ /^[[:space:]]*$/) last--
        for (i = first; i <= last; i++) print lines[i]
    }
' "$CHANGELOG" >"$NOTES_FILE"
[[ -s "$NOTES_FILE" ]] || die "The CHANGELOG section for $VERSION is empty."
ok "release notes: $(wc -l <"$NOTES_FILE" | tr -d ' ') lines"

# --- 4. quality gates ----------------------------------------------------------

step "Quality gates"

gate() {
    local name="$1"; shift
    local log="$WORK_DIR/${name// /-}.log" tty=0
    [[ -t 1 ]] && tty=1
    [[ $tty -eq 1 ]] && printf '  … %s' "$name"
    if "$@" >"$log" 2>&1; then
        [[ $tty -eq 1 ]] && printf '\r'
        ok "$name"
    else
        [[ $tty -eq 1 ]] && printf '\r'
        printf '  %s✗%s %s — last lines of output:\n' "$C_RED" "$C_RESET" "$name" >&2
        tail -n 30 "$log" | sed 's/^/      /' >&2
        die "$name failed."
    fi
}

if have composer; then
    gate "composer validate" composer validate --strict --no-check-publish --no-interaction
else
    warn "composer not found; skipped composer validate"
fi

[[ -f vendor/bin/phpstan ]] || die "vendor/ is missing; run composer install."
gate "PHPStan" php vendor/bin/phpstan analyse --no-progress --no-interaction

if have npx; then
    # Lint the tracked files the CI globs match, not whatever else sits in the
    # checkout: CI never sees untracked notes, and neither should this gate.
    mapfile -d '' md_files < <(git ls-files -z -- "${MARKDOWN_GLOBS[@]/#/:(glob)}")
    gate "markdownlint" npx --yes "$MARKDOWNLINT" "${md_files[@]}"
else
    warn "npx not found; skipped markdownlint"
fi

if [[ $SKIP_TESTS -eq 1 ]]; then
    warn "smoke suite skipped (--skip-tests)"
else
    # The suite skips, rather than fails, without a database — a skipped run
    # passes and proves nothing, so insist on a configured one.
    if [[ -z "${STARDUST_TEST_DSN:-}" ]] && ! grep -qs 'STARDUST_TEST_DSN' phpunit.xml; then
        die "The smoke suite needs a database: set STARDUST_TEST_DSN or create phpunit.xml from phpunit.xml.dist (or pass --skip-tests)."
    fi
    printf '  %s(the smoke suite takes a few minutes)%s\n' "$C_DIM" "$C_RESET"
    gate "smoke suite" php vendor/bin/phpunit --testsuite Smoke --no-progress
fi

# --- 5. CI status --------------------------------------------------------------

step "CI"

remote_url="$(git remote get-url "$REMOTE")"
SLUG=""
[[ "$remote_url" =~ github\.com[:/]([^/]+/[^/]+)$ ]] && SLUG="${BASH_REMATCH[1]%.git}"

fetch_check_runs() {
    local path="repos/$SLUG/commits/$head_sha/check-runs?per_page=100"
    if have gh; then
        gh api "$path"
    elif have curl; then
        local -a auth=()
        [[ -n "${GITHUB_TOKEN:-}" ]] && auth=(-H "Authorization: Bearer $GITHUB_TOKEN")
        curl -fsSL -H "Accept: application/vnd.github+json" "${auth[@]}" "https://api.github.com/$path"
    else
        return 127
    fi
}

if [[ $SKIP_CI_CHECK -eq 1 ]]; then
    warn "CI check skipped (--skip-ci-check)"
elif [[ -z "$SLUG" ]]; then
    warn "$REMOTE is not a GitHub remote; skipped the CI check"
elif ! runs_json="$(fetch_check_runs)"; then
    die "Could not read GitHub checks for ${head_sha:0:7} (install gh or curl, or set GITHUB_TOKEN). --skip-ci-check to bypass."
else
    # stdin, not a file path: a native Windows php cannot open MSYS /tmp paths.
    ci_report="$(php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d) || !isset($d["check_runs"])) { echo "error\nunexpected API response"; exit; }
        $pending = $failed = [];
        foreach ($d["check_runs"] as $r) {
            if ($r["status"] !== "completed") { $pending[] = $r["name"]; continue; }
            if (!in_array($r["conclusion"], ["success", "neutral", "skipped"], true)) {
                $failed[] = $r["name"] . " (" . $r["conclusion"] . ")";
            }
        }
        if ($failed)                 { echo "failure\n", implode("\n", $failed); }
        elseif ($pending)            { echo "pending\n", implode("\n", $pending); }
        elseif (!$d["check_runs"])   { echo "none\n"; }
        else                         { echo "success\n", count($d["check_runs"]); }
    ' <<<"$runs_json")"
    ci_state="${ci_report%%$'\n'*}"
    ci_detail="${ci_report#*$'\n'}"
    case "$ci_state" in
        success) ok "all $ci_detail GitHub checks passed on ${head_sha:0:7}" ;;
        none)    die "No GitHub checks have run on ${head_sha:0:7} yet. Wait for CI, or --skip-ci-check." ;;
        pending) die "CI is still running on ${head_sha:0:7}:"$'\n'"$(sed 's/^/    /' <<<"$ci_detail")" ;;
        failure) die "CI failed on ${head_sha:0:7}:"$'\n'"$(sed 's/^/    /' <<<"$ci_detail")" ;;
        *)       die "Could not interpret the GitHub checks response: $ci_detail" ;;
    esac
fi

# --- 6. confirm ------------------------------------------------------------------

prerelease=0
[[ "$VERSION" == *-* ]] && prerelease=1

step "Release plan"
printf '  package    %s\n' "$PACKAGE"
printf '  tag        %s%s%s%s\n' "$C_BOLD" "$TAG" "$C_RESET" "$([[ $prerelease -eq 1 ]] && echo '  (pre-release)')"
printf '  commit     %s  %s\n' "${head_sha:0:7}" "$(git log -1 --format=%s)"
printf '  previous   %s\n' "${latest_tag:-none}"
printf '  signed     %s\n' "$([[ $SIGN -eq 1 ]] && echo yes || echo no)"
printf '  push       %s\n' "$([[ $PUSH -eq 1 ]] && echo "$REMOTE" || echo 'no (local tag only)')"
printf '  gh release %s\n' "$([[ $GITHUB_RELEASE -eq 1 ]] && echo yes || echo no)"
[[ -n "$latest_tag" ]] && printf '  commits    %s since %s\n' "$(git rev-list --count "$latest_tag..HEAD")" "$latest_tag"
[[ $WARNINGS -gt 0 ]] && printf '  %s%d warning(s) above%s\n' "$C_YELLOW" "$WARNINGS" "$C_RESET"
printf '\n%s--- release notes ---%s\n' "$C_DIM" "$C_RESET"
head -n 12 "$NOTES_FILE"
[[ "$(wc -l <"$NOTES_FILE")" -gt 12 ]] && printf '%s… (%s)%s\n' "$C_DIM" "$CHANGELOG" "$C_RESET"
printf '%s---------------------%s\n\n' "$C_DIM" "$C_RESET"

if [[ $DRY_RUN -eq 0 ]]; then
    confirm "Tag and publish $TAG?" || { echo "Aborted; nothing was changed."; exit 1; }
fi

# --- 7. tag, push, publish -------------------------------------------------------

step "Publishing"

# One line only. The notes belong in the GitHub Release, which can be
# corrected later; a published tag cannot.
tag_args=(tag -m "StarDust $VERSION")
if [[ $SIGN -eq 1 ]]; then tag_args+=(-s); else tag_args+=(-a); fi
run git "${tag_args[@]}" "$TAG" "$head_sha"
[[ $DRY_RUN -eq 1 ]] || { TAG_CREATED="$TAG"; ok "created $TAG"; }

if [[ $PUSH -eq 1 ]]; then
    run git push "$REMOTE" "refs/tags/$TAG"
    [[ $DRY_RUN -eq 1 ]] || { TAG_CREATED=""; ok "pushed $TAG to $REMOTE"; }
fi

if [[ $GITHUB_RELEASE -eq 1 ]]; then
    gh_args=(release create "$TAG" --verify-tag --title "$TAG" --notes-file "$NOTES_FILE")
    [[ $prerelease -eq 1 ]] && gh_args+=(--prerelease)
    if have gh && [[ -n "$SLUG" ]]; then
        run gh "${gh_args[@]}" --repo "$SLUG"
        [[ $DRY_RUN -eq 1 ]] || ok "published the GitHub Release"
    elif [[ -n "$SLUG" ]]; then
        saved_notes="$ROOT/.git/RELEASE_NOTES_$VERSION.md"
        [[ $DRY_RUN -eq 1 ]] || cp "$NOTES_FILE" "$saved_notes"
        warn "gh CLI not found, so the GitHub Release was not created. Publish it by hand:"
        printf '      https://github.com/%s/releases/new?tag=%s%s\n' "$SLUG" "$TAG" "$([[ $prerelease -eq 1 ]] && echo '&prerelease=1')"
        printf '      notes: %s\n' "$saved_notes"
    fi
fi

# --- done ------------------------------------------------------------------------

if [[ $DRY_RUN -eq 1 ]]; then
    printf '\n%sDry run complete — every check passed and nothing was changed.%s\n' "$C_GREEN" "$C_RESET"
    exit 0
fi

IFS=. read -r v_major v_minor _ <<<"${VERSION%%-*}"
constraint="^$v_major.$v_minor"
if [[ $prerelease -eq 1 ]]; then
    stability="${VERSION#*-}"
    constraint+="@${stability%%.*}"
fi

printf '\n%s%s released.%s\n' "$C_GREEN" "$TAG" "$C_RESET"
if [[ $PUSH -eq 1 ]]; then
    printf '  Packagist  https://packagist.org/packages/%s (updates from the GitHub webhook within a minute or so)\n' "$PACKAGE"
    printf '  Install    composer require %s:%s\n' "$PACKAGE" "$constraint"
else
    printf '  The tag is local only. Push it with: git push %s refs/tags/%s\n' "$REMOTE" "$TAG"
fi
