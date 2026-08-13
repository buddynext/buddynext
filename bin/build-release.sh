#!/usr/bin/env bash
# Build a self-contained, command-free release zip for QA / testers.
#
#   bin/build-release.sh [dist-dir]
#
# Produces <dist>/buddynext-<version>.zip that QA installs and tests with NO
# commands (no composer, no npm). Runtime deps ship committed under libs/ and the
# plugin uses a hand-written PSR-4 autoloader, so the zip is deps-complete from
# the committed tree — no vendor/, no build step. By ALLOWLIST, only the paths the
# plugin needs to RUN ship; anything else (QA dirs, screenshots, docs, .md, dev
# configs, the dev mu-plugins/) can never leak in, regardless of what's committed.
# Version is read from the plugin header (never bumped here — BuddyNext is pre-release).
#
# Overrides (env vars — this script takes no flags; $1 is the dist dir):
#   SKIP_RELEASE_GATE=1  bypass every gate below
#   SKIP_JOURNEY_RUN=1   bypass only the journey suite
#   SKIP_CERT=1          bypass only `wp buddynext cert`
#   SKIP_FLOW_AUDIT=1    bypass only the flow-audit
#   SKIP_PHPUNIT=1       bypass only the unit suite
#
#   Every bypass has to be typed. cert and flow-audit used to skip themselves
#   whenever BN_WP_PATH was unset or the CLI was missing, which meant a machine
#   that had simply never been set up packaged a release with two behavioural
#   gates never running — announced in one line of stderr that scrolls past.
#   They are fatal now, matching the journey suite.
#   ALLOW_STALE_DOCS=1   bypass only the documentation-truth gate. For a
#                        genuinely surface-neutral commit — a docs-only re-zip —
#                        and say so in the commit message. Using it habitually
#                        defeats the point: the manifest is what tells the next
#                        audit whether a feature already exists.
#   BN_PRO_PATH=…        buddynext-pro checkout (holds Free's canonical manifest
#                        on the private shelf; defaults to a sibling directory)
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="buddynext"
VERSION="$(grep -m1 'Version:' buddynext.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d ' \r')"
DIST="${1:-$HOME/Documents/work-artifacts/scratch}"

# The ONLY paths that ship (libs/ carries the committed runtime deps).
# Optional ones (languages, uninstall.php, readme.txt) are copied only if present.
RUNTIME=( buddynext.php includes templates assets blocks libs )
OPTIONAL=( languages uninstall.php readme.txt )

# 0. Release gate — never package a build that fails the quality bar. Aborts on a
#    NEW/unbaselined flow-audit error (static, free+pro pair; the CLI loads
#    audit/.flow-audit-baseline.json and exits non-zero only on unbaselined
#    errors) or a cert failure (behavioural, needs a live WP site). Flow-audit
#    always runs; cert runs only when BN_WP_PATH points at a live activated site.
#    Override the CLI path with FLOW_AUDIT_CLI and the Pro root with BN_PRO_PATH;
#    set SKIP_RELEASE_GATE=1 to bypass (e.g. a docs-only re-zip).
if [ "${SKIP_RELEASE_GATE:-0}" != 1 ]; then
	# Resolve the Pro checkout ONCE: it is both the second input to flow-audit and
	# the home of Free's canonical manifest. Prefer a sibling directory, which is
	# how a wp-content/plugins tree is laid out, and fall back to the legacy path.
	if [ -z "${BN_PRO_PATH:-}" ]; then
		if [ -d "$(cd .. && pwd)/buddynext-pro" ]; then
			BN_PRO_PATH="$(cd .. && pwd)/buddynext-pro"
		else
			BN_PRO_PATH="$HOME/dev/repos/buddynext-pro"
		fi
	fi

	# Documentation-truth gate — truth is a gate, not a habit.
	#
	# The manifest is what the manifest-first rule reads to answer "does this
	# already exist?". When it lags the code it does not merely go stale, it
	# answers WRONG in the direction that makes us rebuild what we already ship:
	# a surface absent from the manifest reads as "not built yet". That has
	# already produced duplicate feature cards on this project, and this cycle
	# the scanner was silently dropping a live route for the same reason.
	#
	# FREE IS THE AWKWARD CASE, and getting it wrong makes the gate decorative.
	# This repo is public, so /audit/ is gitignored by design and the canonical
	# manifest lives on the private shelf in the Pro repo. `git log -- audit/
	# manifest.json` here therefore returns NOTHING, and a gate that treats an
	# empty timestamp as "no manifest to check" passes every time while proving
	# nothing. So resolve the shelf explicitly and REFUSE when it is absent: you
	# cannot cut a release without it, and failing loudly beats a green tick.
	#
	# Override with ALLOW_STALE_DOCS=1 for a genuinely surface-neutral commit -
	# a docs-only re-zip - and say so in the commit message. Not habitually.
	if [ "${ALLOW_STALE_DOCS:-0}" = 1 ]; then
		echo "release gate: docs-truth BYPASSED (ALLOW_STALE_DOCS=1)" >&2
	else
		echo "release gate: docs-truth…"

		# Newest commit touching code that can add a surface.
		CODE_TS="$(git log -1 --format=%ct -- includes templates blocks assets 2>/dev/null || echo 0)"
		CODE_TS="${CODE_TS:-0}"

		# The canonical Free manifest, on the private shelf in the Pro repo.
		PRO_ROOT="$BN_PRO_PATH"
		SHELF_REL="free-internal/audit/manifest.json"
		if [ ! -f "$PRO_ROOT/$SHELF_REL" ]; then
			echo "release gate FAILED: cannot find the Free manifest on the private shelf." >&2
			echo "  Looked for: $PRO_ROOT/$SHELF_REL" >&2
			echo "  It is NOT in this repo by design (public repo, /audit/ gitignored)." >&2
			echo "  Point BN_PRO_PATH at the buddynext-pro checkout, or ALLOW_STALE_DOCS=1 to bypass." >&2
			exit 18
		fi
		MAN_TS="$(git -C "$PRO_ROOT" log -1 --format=%ct -- "$SHELF_REL" 2>/dev/null || echo 0)"
		MAN_TS="${MAN_TS:-0}"
		if [ "$MAN_TS" -eq 0 ]; then
			echo "release gate FAILED: the shelf manifest exists but is not committed in $PRO_ROOT." >&2
			echo "  An uncommitted manifest has no timestamp to compare, so this gate cannot prove anything." >&2
			exit 18
		fi
		if [ "$CODE_TS" -gt "$MAN_TS" ]; then
			echo "release gate FAILED: code changed after the last manifest refresh." >&2
			echo "  Run /wp-plugin-onboard --refresh, commit the manifest to the shelf, then rebuild." >&2
			echo "  Surface-neutral commit only: ALLOW_STALE_DOCS=1" >&2
			exit 18
		fi

		# Buyer-level roll-up. Tracked in THIS repo (it holds no internal inventory).
		if [ -f CAPABILITIES.md ]; then
			CAP_TS="$(git log -1 --format=%ct -- CAPABILITIES.md 2>/dev/null || echo 0)"
			CAP_TS="${CAP_TS:-0}"
			if [ "$CODE_TS" -gt "$CAP_TS" ]; then
				echo "release gate FAILED: code changed after the last CAPABILITIES.md update." >&2
				echo "  Refresh the buyer-level capability roll-up, commit it, then rebuild." >&2
				echo "  Surface-neutral commit only: ALLOW_STALE_DOCS=1" >&2
				exit 18
			fi
		else
			echo "release gate FAILED: CAPABILITIES.md is missing from the plugin root." >&2
			echo "  It is the source of truth for store and docs copy - generate it via /wp-plugin-onboard." >&2
			exit 18
		fi

		# Maturity is the structured status field, never a free-text name. A
		# feature whose NAME says "coming soon" stays mislabeled after it ships.
		# Scoped to name/label/title values so a legitimate "Coming soon" string
		# in a UI template is untouched.
		STALE_LABEL="$(grep -rniE "[\"'](name|label|title)[\"'][[:space:]]*=>[[:space:]]*[\"'][^\"']*coming soon" --include='*.php' includes 2>/dev/null || true)"
		if [ -n "$STALE_LABEL" ]; then
			echo "release gate FAILED: a feature name/label still says 'coming soon':" >&2
			echo "$STALE_LABEL" >&2
			echo "  Use the structured status field, not a free-text name." >&2
			exit 18
		fi

		echo "    manifest + CAPABILITIES current; no stale labels"
	fi

	# Unit suite. CI runs it on every PR, but nothing stopped THIS script packaging
	# a zip with a red suite — so the contract tests (field-type conformance, and
	# every other test written to stop a regression returning) could not block a
	# release. A test that cannot fail a release is documentation.
	#
	# Same fatal-unless-typed shape as flow-audit below: a machine without a
	# vendor/ install must not silently package an untested build.
	if [ "${SKIP_PHPUNIT:-0}" = 1 ]; then
		echo "release gate: phpunit BYPASSED (SKIP_PHPUNIT=1)" >&2
	elif [ -x vendor/bin/phpunit ]; then
		echo "release gate: phpunit…"
		if ! vendor/bin/phpunit >/dev/null 2>&1; then
			echo "release gate FAILED: unit suite is red — run: vendor/bin/phpunit" >&2
			exit 1
		fi
	else
		echo "release gate FAILED: phpunit did not run — vendor/bin/phpunit missing (run composer install), or SKIP_PHPUNIT=1 to bypass deliberately." >&2
		exit 1
	fi

	FLOW_AUDIT_CLI="${FLOW_AUDIT_CLI:-$HOME/.mcp-servers/wp-plugin-qa-mcp-server/build/flow-audit-cli.js}"
	if command -v node >/dev/null 2>&1 && [ -f "$FLOW_AUDIT_CLI" ]; then
		echo "release gate: flow-audit (free + pro)…"
		if ! node "$FLOW_AUDIT_CLI" "$PWD" "$BN_PRO_PATH" >/dev/null 2>&1; then
			echo "release gate FAILED: new/unbaselined flow-audit errors — run: node \"$FLOW_AUDIT_CLI\" \"$PWD\" \"$BN_PRO_PATH\" (see audit/flow-audit-report.md)" >&2
			exit 1
		fi
	elif [ "${SKIP_FLOW_AUDIT:-0}" = 1 ]; then
		echo "release gate: flow-audit BYPASSED (SKIP_FLOW_AUDIT=1)" >&2
	else
		# A SKIP here is FATAL, exactly as it is for the journey suite below.
		#
		# This used to warn and continue, so a machine that simply did not have the
		# CLI installed packaged a release with the gate never running — and said so
		# in one line of stderr that scrolls past. A gate that cannot fail when its
		# precondition is missing is not a gate; it is a comment. The cert fix on
		# card 10158857250 hardened the tool, and this is the same disease one layer
		# up, in the caller that decides whether to run it.
		#
		# SKIP_FLOW_AUDIT=1 keeps a deliberate bypass available, but it now has to be
		# typed, which makes it a recorded decision instead of an accident.
		echo "release gate FAILED: flow-audit did not run — CLI not found. Set FLOW_AUDIT_CLI to .../build/flow-audit-cli.js, or SKIP_FLOW_AUDIT=1 to bypass deliberately." >&2
		exit 1
	fi
	if [ -n "${BN_WP_PATH:-}" ] && command -v wp >/dev/null 2>&1; then
		echo "release gate: wp buddynext cert…"
		if ! wp --path="$BN_WP_PATH" buddynext cert >/dev/null 2>&1; then
			echo "release gate FAILED: functional certification failed — run: wp --path=\"$BN_WP_PATH\" buddynext cert" >&2
			exit 1
		fi
	elif [ "${SKIP_CERT:-0}" = 1 ]; then
		echo "release gate: cert BYPASSED (SKIP_CERT=1)" >&2
	else
		# FATAL, same reasoning as flow-audit above. Card 10158857250 made
		# `wp buddynext cert` exit non-zero when it proves nothing; that hardening
		# could still be sidestepped entirely by not exporting BN_WP_PATH, because
		# this caller then quietly skipped the tool. The behavioural gate is the one
		# that actually exercises the product, so declining to run it must be a
		# choice somebody typed, not the default on any machine that has not set an
		# environment variable.
		echo "release gate FAILED: cert did not run — set BN_WP_PATH to a live WP root with the plugin active, or SKIP_CERT=1 to bypass deliberately." >&2
		exit 1
	fi

	# Journey suite. Unlike the gates above, a SKIP here is FATAL: cutting a zip
	# without having run the customer journeys once is exactly how a suite failing
	# 24 of 95 specs went unnoticed while the coverage gate reported green. Point
	# BN_BASE_URL at the site the release was verified on.
	#
	# Set SKIP_JOURNEY_RUN=1 only for a docs-only re-zip of an already-verified
	# tree, and say so in the commit.
	if [ "${SKIP_JOURNEY_RUN:-0}" = 1 ]; then
		echo "release gate: journey run BYPASSED (SKIP_JOURNEY_RUN=1)" >&2
	elif [ -f bin/check-journey-run.sh ]; then
		echo "release gate: journey suite…"
		# `|| JRC=$?` is required: set -e would abort on the script's own exit
		# status before the case below ever runs, turning a deliberate FAILED
		# message into a bare exit 2.
		JRC=0
		bin/check-journey-run.sh || JRC=$?
		case "$JRC" in
			0) : ;;
			2)
				echo "release gate FAILED: the journey suite did not run. Set BN_BASE_URL to the" >&2
				echo "  site this release was verified on, or SKIP_JOURNEY_RUN=1 for a docs-only re-zip." >&2
				exit 1
				;;
			*)
				echo "release gate FAILED: journey regressions against tests/e2e/.journey-baseline.json" >&2
				exit 1
				;;
		esac
	else
		echo "release gate FAILED: bin/check-journey-run.sh is missing." >&2
		exit 1
	fi

fi

TMP="$(mktemp -d)"
SRC="$TMP/src"
STAGE="$TMP/$SLUG"
mkdir -p "$SRC" "$STAGE"

# 1. Clean committed state only.
git archive HEAD | tar -x -C "$SRC"

# 2. Copy ONLY the allowlist into the staged plugin dir. No composer step:
#    runtime deps are committed under libs/ and loaded via a hand-written
#    autoloader, so the git-archived tree is already deps-complete.
for item in "${RUNTIME[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done
for item in "${OPTIONAL[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done

# 4. Belt-and-braces: strip docs + dev cruft that bundled libs carry (their own
#    READMEs, VCS dotfiles, and composer manifests). None are needed at runtime,
#    and they otherwise trip packaging / hidden-file checks.
find "$STAGE" -type f -name '*.md' -delete
find "$STAGE" -type f \( -name '.gitignore' -o -name '.gitattributes' -o -name 'composer.json' -o -name 'composer.lock' -o -name '.editorconfig' \) -delete
find "$STAGE" -depth -type d \( -name '.github' -o -name '.git' -o -name '.circleci' \) -exec rm -rf {} +

# 4b. Bundled-runtime-dep assertion. The allowlist and the strip in step 4 are two
#     independent ways to lose a bundled dependency, and losing one is silent: the
#     zip builds, installs, and only explodes (or quietly disables licensing) on a
#     customer's site. That has already happened once in this portfolio — a bundled
#     SDK was stripped from a release zip and the plugin fataled on activation.
#
#     So assert, do not assume. These are the files without which the EDD SL SDK is
#     not merely degraded but ABSENT: the entry file, the class the loader guards on
#     (buddynext.php checks src/Versions.php before requiring anything), and the two
#     assets Handler.php enqueues. Pro does NOT bundle the SDK on purpose — it loads
#     this copy out of BUDDYNEXT_DIR, so if it goes missing here it goes missing for
#     both plugins at once.
REQUIRED_FILES=(
	"libs/edd-sl-sdk/edd-sl-sdk.php"
	"libs/edd-sl-sdk/src/Versions.php"
	"libs/edd-sl-sdk/src/Utilities/Path.php"
	"libs/edd-sl-sdk/src/Handlers/Handler.php"
	"libs/edd-sl-sdk/assets/build/js/edd-sl-sdk.js"
	"libs/edd-sl-sdk/assets/build/css/style-edd-sl-sdk.css"
	"libs/edd-sl-sdk/templates/license-control.php"
)
MISSING=0
for f in "${REQUIRED_FILES[@]}"; do
	if [ ! -f "$STAGE/$f" ]; then
		echo "build FAILED: bundled runtime dep missing from the staged package: $f" >&2
		MISSING=1
	fi
done
if [ "$MISSING" -ne 0 ]; then
	echo "" >&2
	echo "The EDD SL SDK must ship inside the zip. It is committed under libs/ and is" >&2
	echo "NOT restored by composer at install time — if it is not in the package, it is" >&2
	echo "not on the customer's site. Check the RUNTIME allowlist and the strip in step 4." >&2
	rm -rf "$TMP"
	exit 1
fi
echo "bundled deps: EDD SL SDK complete (${#REQUIRED_FILES[@]}/${#REQUIRED_FILES[@]})"

# 4c. Translation assertion. `languages` sits in OPTIONAL above, which means
#     "copy it if it happens to be there" — so losing it produces a green build
#     that ships an all-English plugin while the store page still claims the
#     languages. That claim being untrue is a customer-reported bug in its own
#     right, which is what this assertion exists to make impossible.
#
#     Assert on the ARTIFACT and on NAMED locales, not on a directory: a
#     `[ -d languages ]` check passes on a folder holding only .po sources, and
#     .po is the translator's file — the runtime loads .mo.
#
#     ja / ko_KR / zh_CN are deliberately ABSENT from this list. They are 2%
#     scaffolds that bin/i18n.sh holds back below the coverage bar, so asserting
#     them would fail every build for locales we correctly do not ship. When one
#     of them clears the bar, add it here in the same commit that starts shipping
#     it — this list and the claim we make to customers move together.
REQUIRED_LOCALES=( de_DE es_ES fr_FR it_IT nl_NL pt_BR ru_RU )
MISSING_MO=0
for loc in "${REQUIRED_LOCALES[@]}"; do
	if [ ! -f "$STAGE/languages/$SLUG-$loc.mo" ]; then
		echo "build FAILED: compiled translation missing from the staged package: languages/$SLUG-$loc.mo" >&2
		MISSING_MO=1
	fi
done
if [ "$MISSING_MO" -ne 0 ]; then
	echo "" >&2
	echo "A .mo absent from the package means that language silently falls back to" >&2
	echo "English on the customer's site while we still advertise it. Run bin/i18n.sh" >&2
	echo "and check the OPTIONAL allowlist. If a locale was dropped ON PURPOSE because" >&2
	echo "it fell below the coverage bar, remove it from REQUIRED_LOCALES here too —" >&2
	echo "and from the marketing copy that promises it." >&2
	rm -rf "$TMP"
	exit 1
fi
echo "translations: ${#REQUIRED_LOCALES[@]} compiled locales present"

# 5. Zip.
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$TMP"

echo "built: $ZIP ($(du -h "$ZIP" | cut -f1))"
