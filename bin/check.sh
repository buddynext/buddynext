#!/usr/bin/env bash
# Full BuddyNext quality gate — runs every check that PR review would run.
#
# Use cases:
#   bin/check.sh                     # everything, exit non-zero on any failure
#   bin/check.sh --staged            # only staged files (fast pre-commit signal)
#   bin/check.sh --skip-audit        # skip the UX audit step
#
# What it runs (in order, fail-fast):
#   1. PHP -l on every .php file under includes/ + templates/
#   2. WPCS via composer's phpcs script (configured by phpcs.xml.dist)
#   3. bin/check-rest-boundary.sh — fails on any admin-ajax surface
#   4. PHPStan level 5 against includes/
#   5. bin/ux-audit.sh — block-severity violations fail
#   6. flow-audit CLI (free+pro) — fails on new/unbaselined flow-audit errors
#   7. wp buddynext cert — behavioural gate (only when BN_WP_PATH is set)
#
# This script is the single entry point a contributor runs before pushing.
# CI runs the same script. Anchor docs: docs/v2 Plans/PLAN.md Part 4 gates,
# Claude skills /wp-plugin-development + /ux-audit.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1
PLUGIN_DIR="$PWD"

STAGED=0

# Is this the per-commit tier?
#
# --staged advertises itself as the "fast pre-commit signal" and it was not one.
# Two of the twenty-eight gates below honoured it (php -l and WPCS, narrowed to
# staged files); the other twenty-six ran in full, including the Playwright
# journey suite, the whole PHPUnit suite and the behavioural cert. A commit
# therefore cost the release battery — measured here at over ten minutes before
# it was killed — which is how a hook stops being a hook: people learn to pass
# --no-verify, and that disables the cheap gates too, which were the ones worth
# having on every commit.
#
# CLAUDE.md already draws the line ("Per fix: the pre-commit hook (staged
# lint/WPCS/PHPStan) … Per release: the full battery"). This makes the script
# agree with it. bin/build-release.sh runs without --staged and still gets
# everything, and it already refuses a skipped journey run.
bn_heavy_gate_skipped() {
	if [ "$STAGED" = 1 ]; then
		note "$1 — release gate, skipped in --staged (runs in full via bin/check.sh and bin/build-release.sh)"
		return 0
	fi
	return 1
}
SKIP_AUDIT=0
for arg in "$@"; do
	case "$arg" in
		--staged)     STAGED=1 ;;
		--skip-audit) SKIP_AUDIT=1 ;;
		--help|-h)
			grep '^#' "$0" | grep -v '^#!' | sed 's/^# \{0,1\}//'
			exit 0
			;;
	esac
done

EXIT=0
RED=$'\e[31m'
GREEN=$'\e[32m'
YELLOW=$'\e[33m'
DIM=$'\e[2m'
RESET=$'\e[0m'

section() { printf "\n${DIM}── %s ──${RESET}\n" "$1"; }
ok()      { printf "${GREEN}✓${RESET} %s\n" "$1"; }
fail()    { printf "${RED}✗${RESET} %s\n" "$1"; EXIT=1; }
note()    { printf "${YELLOW}!${RESET} %s\n" "$1"; }

# Run a python3-based gate. PASS when the script exits 0; FAIL with $2 when it exits
# non-zero; SKIP with a note (never a failure) when python3 OR the script is absent.
#
# The doc/registry gates below previously guarded only on the SCRIPT existing, then ran
# `python3 bin/x.py` unconditionally. On a clone without python3 that hard-failed all of
# them, blocking every commit for the wrong reason — the failure mode that teaches people
# to pass --no-verify, which also disables WPCS, PHPStan and the rest. Guarding on the
# interpreter mirrors the `command -v wp` guard the cert gate already uses.
py_gate() {
	# $1 = script path, $2 = failure message.
	if ! command -v python3 >/dev/null 2>&1; then
		note "python3 not found — skipped ${1##*/}"
		return 0
	fi
	if [ ! -f "$1" ]; then
		note "${1} missing"
		return 0
	fi
	python3 "$1" || fail "$2"
}

# 1. PHP lint
section "PHP -l"
if [ "$STAGED" = 1 ]; then
	PHP_FILES="$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)"
else
	PHP_FILES="$(find includes templates -type f -name '*.php' 2>/dev/null)"
fi
if [ -z "$PHP_FILES" ]; then
	note "no PHP files to lint"
else
	LINT_BAD=0
	while IFS= read -r f; do
		[ -z "$f" ] && continue
		[ -f "$f" ] || continue
		if ! php -l "$f" >/dev/null 2>&1; then
			fail "$f"
			LINT_BAD=$((LINT_BAD+1))
		fi
	done <<< "$PHP_FILES"
	[ "$LINT_BAD" = 0 ] && ok "all clean"
fi

# 2. WPCS
section "WPCS (WordPress standard)"
if [ -x vendor/bin/phpcs ]; then
	# No --standard: let phpcs auto-discover the ruleset (a local phpcs.xml if one
	# exists, otherwise the committed phpcs.xml.dist). Pinning --standard=phpcs.xml
	# broke the moment the tracked phpcs.xml was removed, and before that it forced
	# the local-override name even on clones that only had the .dist.
	if [ "$STAGED" = 1 ]; then
		if [ -n "$PHP_FILES" ]; then
			# shellcheck disable=SC2086
			if vendor/bin/phpcs $PHP_FILES; then
				ok "staged PHP clean"
			else
				fail "staged PHP has WPCS issues"
			fi
		fi
	else
		if vendor/bin/phpcs; then
			ok "all PHP clean"
		else
			fail "WPCS violations"
		fi
	fi
else
	note "vendor/bin/phpcs missing — run \`composer install\`"
fi

# 3. REST-frontend boundary (no admin-ajax)
section "REST-frontend boundary"
if [ -x bin/check-rest-boundary.sh ]; then
	if bin/check-rest-boundary.sh; then
		:
	else
		fail "admin-ajax surface detected — frontend must be 100% REST"
	fi
else
	note "bin/check-rest-boundary.sh missing"
fi

# 3b. Journey selectors — a spec must not assert markup the product removed
section "Journey selectors (spec vs product)"
if [ -x bin/check-journey-selectors.sh ]; then
	if bin/check-journey-selectors.sh; then
		:
	else
		fail "a journey spec asserts markup that no longer exists"
	fi
else
	note "bin/check-journey-selectors.sh missing"
fi

# 3a-. Stylesheet dependency graph — every front-end sheet reaches bn-base
section "Stylesheet deps (bn-base reachable)"
if [ -x bin/check-style-deps.sh ]; then
	if bin/check-style-deps.sh; then
		:
	else
		fail "stylesheet with no declared path to bn-base — it can print above the token layer"
	fi
else
	note "bin/check-style-deps.sh missing"
fi

# 3a++. Block class coverage — every emitted bn-* class is defined + reachable
section "Block class coverage"
if [ -x bin/check-block-class-coverage.sh ]; then
	if bin/check-block-class-coverage.sh; then
		:
	else
		fail "block emits a class no reachable stylesheet defines"
	fi
else
	note "bin/check-block-class-coverage.sh missing"
fi

# 3a+. Tap targets — no frontend primitive sized under the 40px floor
section "Tap targets (40px floor)"
if [ -x bin/check-tap-targets.sh ]; then
	if bin/check-tap-targets.sh; then
		:
	else
		fail "interactive primitive sized under 40px — ux-foundation Rule 13"
	fi
else
	note "bin/check-tap-targets.sh missing"
fi

# 3a. Icon set conformance — Lucide-style, no baked-in sizes
section "Icon set"
if [ -x bin/check-icons.sh ]; then
	if bin/check-icons.sh; then
		:
	else
		fail "icon set does not conform — see assets/icons/"
	fi
else
	note "bin/check-icons.sh missing"
fi

# 3a1. No inline <script> in admin PHP — hard gate.
#
# Blocking, unlike the tab cap below, because the tree is clean today: this can
# only fail on something the current change introduced, which is the whole point
# of a gate written after the count proved unstable.
section "Inline script in admin PHP"
if [ -x bin/check-inline-script.sh ]; then
	if bin/check-inline-script.sh; then
		:
	else
		fail "inline <script> in admin PHP — enqueue it instead"
	fi
else
	note "bin/check-inline-script.sh missing"
fi

# 3a2. Admin five-tab cap — ADVISORY until the re-file lands.
#
# BLOCKING as of the IA re-file (card 10207094624). It was advisory only because
# Settings and Moderation both shipped 7, and failing every commit for a drift no
# single commit introduced is how people learn to pass --no-verify. Both are back
# under five, so the note this block used to print would now be decoration.
#
# The rule reached 7 twice precisely because nothing enforced it, and Pro
# contributes 21 of the 49 tabs from a separate repo — so it will drift again
# unless a build says so.
section "Admin tab cap"
if [ -x bin/check-tab-cap.sh ]; then
	if bin/check-tab-cap.sh; then
		:
	else
		fail "an admin section exceeds the five-tab cap — re-file a tab through AdminHub::TAB_PLACEMENT, or change the cap deliberately in both the gate and that docblock"
	fi
else
	note "bin/check-tab-cap.sh missing"
fi

# 3b. Route URLs — no hand-rolled home_url() paths outside PageRouter
section "Route URLs (PageRouter only)"
if [ -x bin/check-route-urls.sh ]; then
	if bin/check-route-urls.sh; then
		:
	else
		fail "hand-rolled route URL — use a PageRouter builder or annotate bn-route-ok"
	fi
else
	note "bin/check-route-urls.sh missing"
fi

# 3c. Cross-plugin guards — a call into a partner plugin must be guarded against
# the class it ACTUALLY calls. A guard naming a sibling class reads as careful,
# passes every other gate here, and fatals only on a site with the partner
# deactivated — the configuration nobody develops on.
section "Cross-plugin guards"
if [ -f bin/check-cross-plugin-guards.php ]; then
	if php bin/check-cross-plugin-guards.php; then
		:
	else
		fail "unguarded call into a partner plugin — guard the exact class the body calls"
	fi
else
	note "bin/check-cross-plugin-guards.php missing"
fi

# 3bc. Surface map — the store-per-hub contract must match source.
#
# audit/surface-map.json records hub -> module -> namespace -> actions, generated
# from PageRouter::enqueue_hub_assets() + AssetService + store() calls. It is the
# index that answers "which store loads on which surface, and what does it wire" —
# the thing that was answered by hand and got wrong (people/post hubs missed). If
# a refactor changes an enqueue, a namespace, or an action without regenerating
# the map, this fails so the drift is caught at the change, not a survey later.
section "Surface map (store-per-hub contract)"
if [ -f bin/build-surface-map.php ]; then
	if php bin/build-surface-map.php --check >/dev/null 2>&1; then
		ok "surface map matches source"
	else
		fail "surface map stale — regenerate: php bin/build-surface-map.php (then commit audit/surface-map.json to the Pro shelf)"
	fi
else
	note "bin/build-surface-map.php missing"
fi

# 3bd. Store-merge collisions — two co-loading files must not define the same
# store key in one namespace without a declared dependency ordering them.
# store() merges silently, so an undeclared clash means last-loaded wins with no
# error. Passes today (the four overlaps are editor-vs-frontend); this keeps a
# deliberate file split from introducing a live one.
section "Store-merge collisions"
if [ -f bin/check-store-collisions.php ]; then
	if php bin/check-store-collisions.php >/dev/null 2>&1; then
		ok "no undeclared same-key collision between co-loading store modules"
	else
		fail "undeclared store-key collision — run: php bin/check-store-collisions.php"
	fi
else
	note "bin/check-store-collisions.php missing"
fi

if [ -f bin/check-hub-registry.php ]; then
	if php bin/check-hub-registry.php >/dev/null 2>&1; then
		ok "hub registry is the one hub list — no parallel list, every route has a descriptor"
	else
		fail "hub-registry drift — run: php bin/check-hub-registry.php"
	fi
else
	note "bin/check-hub-registry.php missing"
fi

if [ -f bin/check-schema-authority.php ]; then
	if php bin/check-schema-authority.php >/dev/null 2>&1; then
		ok "schema authority holds — every column is declared in its CREATE TABLE, not only in an ALTER"
	else
		fail "schema drift — run: php bin/check-schema-authority.php"
	fi
else
	note "bin/check-schema-authority.php missing"
fi

if [ -f bin/check-mediaverse-surfaces.php ]; then
	if php bin/check-mediaverse-surfaces.php >/dev/null 2>&1; then
		ok "MediaVerse surface ownership holds — no MV assets on BN pages, placeholder avatars stay last, comment controls follow the engine's flags"
	else
		fail "MediaVerse surface-ownership violation — run: php bin/check-mediaverse-surfaces.php"
	fi
else
	note "bin/check-mediaverse-surfaces.php missing"
fi

# 3bb. Hook-doc conformance — BLOCKING.
#
# The integration-hook table in CLAUDE.md is read by third-party integrators AND by AI agents.
# It was hand-maintained and rotted: 10 of 36 signatures handed wrong values to any listener
# that trusted them, and 2 were FATAL under PHP 8 (a doc-following listener with
# accepted_args=3 gets ArgumentCountError where only 2 args are fired).
#
# This is blocking from day one, because the table is correct as of this commit. A gate that
# starts green stays green. If you change a do_action(), regenerate the table - do not hand-edit.
# 3b-i-a. docs_config.json vs the pages on disk — BLOCKING, green as of this commit.
#
# The customer-docs index is hand-maintained, and both failure directions are silent: a page
# on disk but unlisted never appears at all, and a listed page with no file is a dangling
# entry that only surfaces at publish time. The 1.1.6 docs pass added, renamed and renumbered
# pages, which is exactly when one forgotten line slips through.
section "Docs config vs disk"
py_gate bin/check-docs-config.py "docs_config.json does not match docs/website/ — see the list above"

section "Hook-doc conformance"
py_gate bin/check-hook-docs.py "hook-doc drift — regenerate the table from the do_action() call sites"

# 3b-i-a2. Public hook docs — BLOCKING for anything new, baselined for the existing 32.
#
# check-hook-docs.py above gates the ARG COUNT of do_action() hooks in CLAUDE.md. It does not
# look at filters, and it never opens the customer-facing guide — which is how all twelve
# extension points advertised in the 1.1.5 release notes shipped undocumented. A @since tag is
# a public promise; this gate makes it one the build can check.
section "Public hook docs"
py_gate bin/check-public-hook-docs.py "a hook promised with @since is missing from docs/website/developer-guide/"

# 3b-i-b. Interactivity directive paths — BLOCKING, and green as of this commit.
#
# A directive value is resolved as a PROPERTY PATH (optionally prefixed with one "!"), never
# as JavaScript. `data-wp-bind--hidden="!(context.isDirty && !context.isSaving)"` therefore
# resolves to undefined and negates to true: the element is hidden in every state, forever.
#
# Nothing else catches it. The page renders, the markup reads as intentional, no error is
# logged, and both save bars shipped with dead status pills — notification preferences showed
# a bar that never said why it had appeared, profile edit never showed "Unsaved changes".
# They were found by measuring the DOM in a browser, not by review.
section "Interactivity directive paths"
py_gate bin/check-directive-paths.py "a directive is bound to an expression — move the comparison into a computed getter"

# 3b-ii. Erasure completeness — BLOCKING, and green as of this commit.
#
# Every bn_* table with a user-bearing column must be on MemberCleanupService::erase_map()
# (we delete it when a member is erased) or ::retain_map() (we keep it, with a stated legal
# basis). A table on neither list is not a decision that was made and lost — it is a decision
# nobody was ever asked to make.
#
# That is not hypothetical: DATA-LIFECYCLE.md §9 has required exactly this for as long as it
# has existed, and bn_activity_log, bn_email_log and bn_webhook_log were still never purged,
# because adding a table forced nobody to answer the question. This gate forces it.
section "Erasure completeness"
py_gate bin/check-erasure.py "a user-keyed table is not registered for erasure or retention — see DATA-LIFECYCLE.md §9"

# 3b-iii. Journey tags — BLOCKING, and green as of this commit.
#
# A spec that names no journey is invisible to coverage reporting: it proves no catalogued
# journey and appears as no gap. Five specs sat in that state until 2026-07-21 — all passing,
# none counted. This gate only checks that an id is DECLARED; it cannot check the id exists,
# because the journey catalogue is internal and lives in the pro repo. The matching gate
# there (bin/check-journey-coverage.py) reconciles both directions.
section "Journey tags"
py_gate bin/check-journey-tags.py "a Playwright spec declares no journey id — add it to the spec's docblock"

# 3b-iii-b. Journey EXECUTION — BLOCKING when a site is reachable.
#
# The two gates above check that journey ids are DECLARED and that the catalogue
# and the spec files reconcile. Neither runs a single test. On 2026-08-02 that gap
# was measured: the suite failed 24 of 95 specs while the coverage gate reported
# "catalogue and Playwright suite reconcile in both directions" and went green.
# Almost none of the 24 were product defects — selectors that had never matched
# anything, features that had moved to the settings hub, and two assertions that
# demanded the OPPOSITE of public-surface-integrity.md. All invisible, because
# nothing executed them.
#
# Gated against tests/e2e/.journey-baseline.json rather than "all green": ten
# specs need Stripe keys, an AI provider, custom domains or 2FA enrolment and
# cannot pass without them. Exit 2 means skipped (no site) and is not a failure
# here; bin/build-release.sh refuses that skip, which is where the requirement to
# have actually run it belongs.
section "Journey run (Playwright)"
if bn_heavy_gate_skipped "journey suite"; then
	:
elif [ -f bin/check-journey-run.sh ]; then
	# Captured, not tested inline: under set -e the non-zero exit would abort
	# the run before the case could classify skip-vs-regression.
	JRC=0
	bin/check-journey-run.sh || JRC=$?
	case "$JRC" in
		0) : ;;
		2) note "journey run skipped — set BN_BASE_URL to gate against the baseline" ;;
		*) fail "journey suite regressed against tests/e2e/.journey-baseline.json" ;;
	esac
else
	note "bin/check-journey-run.sh missing"
fi

# 3b-iv. Layer-1 PHPUnit regression tests — BLOCKING when the WP integration
# harness is present, skipped (not failed) otherwise so a clone without the test
# suite installed still passes the rest of the gate. CI runs it unconditionally
# (see .github/workflows/ci.yml `phpunit` job); locally, install once with
# `bin/install-wp-tests.sh` and it becomes part of every check.
section "PHPUnit (WP integration)"
BN_WTL="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
if bn_heavy_gate_skipped "full PHPUnit suite"; then
	:
elif [ -f "$BN_WTL/includes/functions.php" ] && [ -f vendor/bin/phpunit ]; then
	PURC=0
	WP_TESTS_DIR="$BN_WTL" vendor/bin/phpunit --no-coverage >/tmp/bn-phpunit.log 2>&1 || PURC=$?
	if [ "$PURC" -eq 0 ]; then
		echo "  phpunit: green"
	else
		tail -25 /tmp/bn-phpunit.log
		fail "PHPUnit failed — see output above"
	fi
else
	note "PHPUnit skipped — WP test harness absent (run bin/install-wp-tests.sh; CI runs it)"
fi

# 3b-v. Field-type registry (A1) — BLOCKING, green as of this commit.
#
# Pro registers profile field types at runtime via buddynext_field_types. Any consumer
# that gates behaviour by field type (reveal Options/Date box, is-choice, is-date) must
# read the localised registry, not a hardcoded list that omits the add-on types. That
# drift shipped as four RFT cards in 1.0.x (Pro field's Options/Date box stayed hidden,
# display setting never persisted) — invisible with Free-only fixtures because every core
# type is in every hardcoded list. This is the static backstop the pixel pass can't be.
section "Field-type registry (A1)"
py_gate bin/check-field-type-registry.py "a field-type gate reads a hardcoded list instead of the buddynext_field_types registry"

# 3b-v. Emitted CSS class (A2) — BLOCKING, green as of this commit (baselined).
#
# An emitted design-system class must resolve to a CSS rule. Card 10123417821 shipped
# class="bn-chip" where only .bn-field-chip is styled, so the selected values rendered
# as unstyled inline text. This gate fires on the "styled-sibling near-miss" signature
# (a barer class emitted while a more-qualified sibling IS styled) and fails only on NEW
# ones beyond .a2-emitted-class-baseline.json. The full emitted-no-rule list is advisory:
# bin/check-emitted-css-classes.py --report → audit/emitted-class-report.md.
section "Emitted CSS class (A2)"
py_gate bin/check-emitted-css-classes.py "a fully-unstyled element emits a class whose styled sibling exists — likely the wrong class"

# 3b-vi. Auth-form field wiring (A3) — BLOCKING, green as of this commit.
#
# Every user-editable control in templates/auth/*.php must be captured into the fetch
# submit body — via data-bn-reg-field (collectRegFields) or a store binding
# (data-wp-on--input/change, data-wp-bind--value). Card 10123540374: the signup name
# input had neither, so the store never sent it and the server fell back to the email
# handle prefix — the account was created with the wrong display_name, nothing errored,
# and a "signup succeeded" journey passed. This gate catches that class statically.
section "Auth-form field wiring (A3)"
py_gate bin/check-form-field-wiring.py "an editable auth-form control is not wired into the submit payload (card-10 class)"

# 3c. Cache conformance — ADVISORY until the cache backlog is cleared, then make it blocking.
#
# This gate existed and was called by NOTHING, while a plan doc claimed it was wired. An
# unwired gate is worse than no gate: it reads as coverage we do not have. It reports real
# BLOCKING as of the cache-conformance sweep. The backlog is clear (0 findings), so the reason
# for running it advisory — "a hard gate that fails on a known backlog just gets switched off" —
# no longer applies.
#
# It stays honest because the CHECKER was fixed too: it used to flag correct code (a const named
# GROUP rather than CACHE_GROUP, a TTL that is a constant expression rather than digits, anything
# inside a trait), and 32 of its 42 findings were noise. A gate that cries wolf gets ignored, and
# this one was. Now it only reports real drift — so it can afford to block.
section "Cache conformance"
if [ -x bin/check-cache.sh ]; then
	if bin/check-cache.sh; then
		:
	else
		fail "cache drift — see the CACHING standard"
	fi
else
	fail "bin/check-cache.sh missing"
fi

# 4. PHPStan
section "PHPStan (level 5)"
if [ -x vendor/bin/phpstan ]; then
	if vendor/bin/phpstan analyse --no-progress --memory-limit=2G; then
		ok "no errors"
	else
		fail "PHPStan reported errors"
	fi
else
	note "vendor/bin/phpstan missing — run \`composer install\`"
fi

# 4. UX audit
if [ "$SKIP_AUDIT" = 0 ]; then
	section "UX audit (token + primitive gates)"
	if [ -x bin/ux-audit.sh ]; then
		AUDIT_OUTPUT="$(bin/ux-audit.sh "$PLUGIN_DIR" 2>/dev/null)"
		BLOCK_LINE="$(echo "$AUDIT_OUTPUT" | grep -E '\*\*Block-severity violations:' || echo 'Block-severity violations: ?')"
		BLOCK_COUNT="$(echo "$BLOCK_LINE" | grep -oE '[0-9]+' | head -1)"
		BLOCK_COUNT="${BLOCK_COUNT:-0}"
		if [ "$BLOCK_COUNT" = 0 ]; then
			ok "0 block-severity"
		else
			fail "${BLOCK_COUNT} block-severity violations — run bin/ux-audit.sh to see them"
		fi
	else
		note "bin/ux-audit.sh missing"
	fi
fi

# 5. Flow audit (cross-layer dup / orphan / rest-flow / canonical / template /
# logic). Static — runs the free + pro pair through the flow-audit CLI, which
# loads audit/.flow-audit-baseline.json and exits non-zero ONLY on new /
# unbaselined errors (the same baseline-suppression pattern as the contract
# audit). The CLI ships in the wp-plugin-qa MCP server OUTSIDE this repo; override
# its path with FLOW_AUDIT_CLI and the Pro root with BN_PRO_PATH. Skipped (not
# failed) when node or the CLI is unavailable so a fresh clone without the MCP
# server still passes the other gates.
section "Flow audit (free + pro pair)"
FLOW_AUDIT_CLI="${FLOW_AUDIT_CLI:-$HOME/.mcp-servers/wp-plugin-qa-mcp-server/build/flow-audit-cli.js}"
BN_PRO_PATH="${BN_PRO_PATH:-$HOME/dev/repos/buddynext-pro}"
if bn_heavy_gate_skipped "flow audit"; then
	:
elif command -v node >/dev/null 2>&1 && [ -f "$FLOW_AUDIT_CLI" ]; then
	if node "$FLOW_AUDIT_CLI" "$PLUGIN_DIR" "$BN_PRO_PATH" >/dev/null 2>&1; then
		ok "0 unbaselined flow-audit errors"
	else
		fail "flow-audit: new/unbaselined errors — run: node \"$FLOW_AUDIT_CLI\" \"$PLUGIN_DIR\" \"$BN_PRO_PATH\" (see audit/flow-audit-report.md)"
	fi
else
	note "skipped — flow-audit CLI not found (set FLOW_AUDIT_CLI to .../build/flow-audit-cli.js)"
fi

# 6. Functional certification (behaviour, not shape) — needs a live WP site.
# Set BN_WP_PATH to the WordPress root the plugin is active on; without it the
# behavioural gate is skipped (the static checks above still ran). This is the
# only gate that proves toggles actually enforce and routes don't fatal.
section "Functional certification (wp buddynext cert)"
if bn_heavy_gate_skipped "behavioural cert"; then
	:
elif [ -n "${BN_WP_PATH:-}" ] && command -v wp >/dev/null 2>&1; then
	if wp --path="$BN_WP_PATH" buddynext cert 2>/dev/null; then
		ok "functional certification passed"
	else
		fail "functional certification failed — run: wp --path=\"$BN_WP_PATH\" buddynext cert"
	fi
else
	note "skipped — set BN_WP_PATH to the WordPress root to run the behavioural gate"
fi

# Summary
echo
if [ "$EXIT" = 0 ]; then
	printf "${GREEN}All checks passed.${RESET}\n"
else
	printf "${RED}One or more checks failed.${RESET} Fix and re-run \`bin/check.sh\`.\n"
fi
exit "$EXIT"
