#!/usr/bin/env bash
#
# Journey EXECUTION gate — run the Playwright suite and fail on regression.
#
# Why this exists, in one sentence: the journey catalogue could report
# "catalogue and Playwright suite reconcile in both directions" while the suite
# failed 24 of 95 specs, because the reconciliation gate matches journey IDs
# against spec FILES and never runs them.
#
# Measured 2026-08-02 on a clean install: 24 specs failing, of which almost none
# were product defects — selectors that had never matched anything, features that
# had moved, and two assertions that demanded the OPPOSITE of
# public-surface-integrity.md (a removed privacy lever, and `coming soon` copy).
# Every one of those was invisible because nothing executed the suite.
#
# The gate is a BASELINE, not "all green". 10 specs need external configuration —
# Stripe keys, an AI provider, custom domains, 2FA enrolment — or feature toggles
# that ship off, and they fail on every site without that setup. Demanding zero
# failures would make this a gate people bypass, and a gate people routinely
# bypass is not a gate. So:
#
#   * a spec failing that is NOT in the baseline  -> FAIL (a regression)
#   * a baselined spec that now PASSES            -> FAIL (shrink the baseline)
#
# The second direction matters as much as the first. Without it the baseline only
# ever grows, and a baseline that only grows is a way of writing down that you
# stopped caring.
#
# Usage:
#   bin/check-journey-run.sh                 # gate against the baseline
#   bin/check-journey-run.sh --update        # rewrite the baseline (needs a reason)
#
# Environment:
#   BN_BASE_URL         site to test (required; no default — see the skip note)
#   BN_TEST_USER/PASS   credentials the auth fixture uses
#   BN_TEST_OTHER_USER  a second, non-owner member for owner-gate specs
#   BN_PRO=1            include Pro specs
#   BN_JOURNEY_PROJECT  playwright project (default: desktop)
set -uo pipefail
cd "$(dirname "$0")/.."

BASELINE="tests/e2e/.journey-baseline.json"
PROJECT="${BN_JOURNEY_PROJECT:-desktop}"

# ── Reachability ──────────────────────────────────────────────────────────────
# A missing site SKIPS, loudly, and does not fail the run: contributors commit on
# machines with no WordPress. It must never skip QUIETLY though — a silent skip is
# how a gate becomes decorative. bin/build-release.sh treats the skip as fatal,
# which is where "you must actually have run this" belongs.
# Auto-detect the local dev site before skipping.
#
# Requiring an env var made this gate opt-in, and an opt-in gate is one nobody
# opts into: a comment-editing regression from the 2026-08-04 post-card refactor
# sat in the branch for two days, through nine card closures, while every
# `bin/check.sh` run reported green because this returned 2 and moved on. The
# suite caught it in one run the first time anyone set the variable.
#
# So the default is now "run it", and skipping is what needs a reason. Nothing
# is weakened — an unreachable site still skips, and the release build still
# refuses the skip outright.
#
# A candidate must PROVE it is a BuddyNext install, not merely answer. Checking
# the home page with `curl -sf` accepted http://localhost:10003 — a different
# product's Local site, which 301s to a 404 (-f only judges the response it
# actually received, and without -L it never follows). The suite then ran its
# whole pack against that host and reported 38 phantom regressions, byte-identical
# across three runs. Guessing WRONG is worse than skipping: a silent skip gets
# ignored, but 38 red lines train people to ignore the gate itself.
bn_is_buddynext_site() {
	curl -sfL --max-time 5 "${1%/}/wp-json/buddynext/v1" 2>/dev/null | grep -q '"namespace"'
}

# Candidates come from the shared resolver (tests/e2e/_fixtures/resolve-base-url.mjs)
# — the SAME source playwright.config.ts uses, so the gate and a direct
# `npx playwright test` agree on which local site to hit instead of drifting.
# Every candidate is a real Local site on THIS machine (buddynext-named / the
# owning install first); no hostname is hardcoded, because BuddyNext ships to
# anyone and no tester's URL can be assumed. Each candidate is REST-probed below
# before the suite runs against it.
bn_site_candidates() {
	if command -v node >/dev/null 2>&1; then
		node tests/e2e/_fixtures/resolve-base-url.cjs --list 2>/dev/null
	fi
}

if [ -z "${BN_BASE_URL:-}" ]; then
	while read -r candidate; do
		[ -n "$candidate" ] || continue
		if bn_is_buddynext_site "$candidate"; then
			BN_BASE_URL="$candidate"
			export BN_BASE_URL
			echo "journey run: auto-detected $BN_BASE_URL (set BN_BASE_URL to override)"
			break
		fi
	done <<EOF
$(bn_site_candidates)
EOF
fi
if [ -z "${BN_BASE_URL:-}" ]; then
	echo "journey run SKIPPED — no BuddyNext install found; set BN_BASE_URL." >&2
	echo "  (release builds refuse this skip; see bin/build-release.sh)" >&2
	exit 2
fi
if ! bn_is_buddynext_site "$BN_BASE_URL"; then
	echo "journey run SKIPPED — $BN_BASE_URL is not a reachable BuddyNext install." >&2
	echo "  (no BuddyNext REST namespace at \$BN_BASE_URL/wp-json/buddynext/v1)" >&2
	exit 2
fi

# BN_WP_PATH matters as much as BN_BASE_URL, and used to be nobody's job.
#
# The specs drive a browser at $BN_BASE_URL and ALSO shell out to wp-cli for the
# things a browser cannot do: resolve an actor's user id, drain an Action
# Scheduler group, read a row back. That shim (tests/e2e/_fixtures/wp.ts) took
# its path from BN_WP_PATH and fell back to a hard-coded personal path when it
# was unset.
#
# Nothing set it. The runner auto-detected the SITE and left the PATH alone, so
# on any machine where that hard-coded directory does not exist, every wp call
# ran against nothing — and failed SILENTLY, because wp prints its complaint to
# a stream cleanStdout() strips, so the shim returned an empty string. An empty
# string parses as user id 0, which surfaces much later as `actor "…" must
# exist` in whichever specs happened to need an actor.
#
# That is the shape reported as "~24-32 regressions that vary run to run"
# (card 10225491280): not flaky product code, a second target that was not there.
# With the path exported the same suite reports 183 passed, 1 regression.
#
# So it is resolved here, next to the site it must agree with, and a run that
# cannot find it SKIPS rather than reporting noise — the same contract the
# missing-site branch above already follows.
if [ -z "${BN_WP_PATH:-}" ]; then
	for candidate in \
		"$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." 2>/dev/null && pwd)" \
		"$HOME/Local Sites/buddynext/app/public" \
		"$HOME/Local Sites/buddynext-dev/app/public"; do
		if [ -n "$candidate" ] && [ -f "$candidate/wp-load.php" ]; then
			BN_WP_PATH="$candidate"
			export BN_WP_PATH
			echo "journey run: auto-detected BN_WP_PATH=$BN_WP_PATH"
			break
		fi
	done
fi

if [ -z "${BN_WP_PATH:-}" ] || [ ! -f "$BN_WP_PATH/wp-load.php" ]; then
	echo "journey run SKIPPED — no WordPress root found for the wp-cli shim." >&2
	echo "  The specs shell out to wp for actors and queue draining; without a real" >&2
	echo "  path those calls return an empty string and surface as unrelated" >&2
	echo "  failures. Set BN_WP_PATH to the WordPress root." >&2
	exit 2
fi

if ! command -v wp >/dev/null 2>&1; then
	echo "journey run SKIPPED — wp-cli is not on PATH." >&2
	echo "  Local does not provide one; the specs need it for actors and queue draining." >&2
	exit 2
fi
if [ ! -d node_modules/@playwright ] && ! command -v npx >/dev/null 2>&1; then
	echo "journey run SKIPPED — Playwright is not installed (npm install)." >&2
	exit 2
fi

# Resolve BN_TEST_USER to the login of user ID 1 — the account the auth fixture
# logs in as via ?autologin=1. The profile/owner specs navigate to
# urls.member(BN_TEST_USER) as "own profile", so BN_TEST_USER MUST be that same
# logged-in user or every owner-scoped spec fails with ".bn-app not found" (the
# profile is a 404). The hard-coded default 'varundubey' only holds on the one
# machine where user 1 happened to be named varundubey; anywhere else (user 1 =
# admin, say) ~25 profile/owner specs report phantom regressions. Derive it from
# the site instead of assuming a name — same lesson as BN_BASE_URL and BN_WP_PATH.
if [ -z "${BN_TEST_USER:-}" ]; then
	resolved_user="$(wp --path="$BN_WP_PATH" user get 1 --field=user_login 2>/dev/null | tr -d '[:space:]')"
	if [ -n "$resolved_user" ]; then
		BN_TEST_USER="$resolved_user"
		export BN_TEST_USER
		echo "journey run: BN_TEST_USER=$BN_TEST_USER (user 1 — the autologin=1 account)"
	fi
fi

# Seed the fixture users the specs log in as, exactly like BN_WP_PATH above: it
# used to be nobody's job. ~20 two-actor spaces specs autologin as BN_TEST_OTHER_USER
# (default 'alice'); on a site where that account was never created, every one of
# them fails at the login fixture ("autologin as alice did not establish a session
# cookie") and reports ~40 phantom regressions that look like broken space
# membership but are just a missing precondition. seed-e2e.sh is idempotent, so
# running it every time is safe; a failure to seed is surfaced, not swallowed.
if [ -f bin/seed-e2e.sh ]; then
	echo "journey run: seeding e2e fixture users ..."
	if ! bash bin/seed-e2e.sh >/dev/null 2>&1; then
		echo "journey run: WARNING — bin/seed-e2e.sh did not complete; two-actor specs may fail at login." >&2
	fi
fi

REPORT="$(mktemp -t bn-journey-XXXXXX.json)"
trap 'rm -f "$REPORT"' EXIT

echo "journey run: ${PROJECT} against ${BN_BASE_URL} ..."
# The runner's own exit code is deliberately ignored: a non-zero exit only says
# "something failed", which is the question the baseline answers properly below.
npx playwright test --project="$PROJECT" --reporter=json > "$REPORT" 2>/dev/null || true

if [ ! -s "$REPORT" ]; then
	echo "journey run FAILED: Playwright produced no report." >&2
	exit 1
fi

UPDATE=0
[ "${1:-}" = "--update" ] && UPDATE=1

BASELINE="$BASELINE" UPDATE="$UPDATE" REPORT="$REPORT" python3 <<'PY'
import json, os, sys

report   = os.environ['REPORT']
baseline = os.environ['BASELINE']
update   = os.environ['UPDATE'] == '1'

with open(report) as fh:
    data = json.load(fh)

failed, passed = set(), set()

def walk(node):
    for child in node.get('suites', []):
        walk(child)
    for spec in node.get('specs', []):
        # Identity is file + title: stable across reordering, unlike an index.
        ident = f"{spec.get('file','')}::{spec.get('title','')}"
        for test in spec.get('tests', []):
            for result in test.get('results', []):
                status = result.get('status')
                if status in ('failed', 'timedOut'):
                    failed.add(ident)
                elif status == 'passed':
                    passed.add(ident)

for suite in data.get('suites', []):
    walk(suite)

# A spec that both failed and passed (retry) counts as passing.
failed -= passed

if not failed and not passed:
    print('journey run FAILED: the report contained no specs at all.', file=sys.stderr)
    sys.exit(1)

if update:
    with open(baseline, 'w') as fh:
        json.dump({
            '_comment': (
                'Specs known to fail because they need external configuration or a '
                'feature that ships off. NOT a list of acceptable bugs. Every entry '
                'should carry a reason, and the list should only ever shrink. '
                'Regenerate with bin/check-journey-run.sh --update and say why in '
                'the commit message.'
            ),
            'project': os.environ.get('BN_JOURNEY_PROJECT', 'desktop'),
            'known_failing': sorted(failed),
        }, fh, indent=2)
        fh.write('\n')
    print(f'journey baseline rewritten: {len(failed)} known-failing, {len(passed)} passing')
    sys.exit(0)

try:
    with open(baseline) as fh:
        known = set(json.load(fh).get('known_failing', []))
except FileNotFoundError:
    print(f'journey run FAILED: no baseline at {baseline}.', file=sys.stderr)
    print('  Create one with: bin/check-journey-run.sh --update', file=sys.stderr)
    sys.exit(1)

regressions = sorted(failed - known)
recovered   = sorted(known & passed)

for spec in regressions:
    print(f'  REGRESSION  {spec}', file=sys.stderr)
for spec in recovered:
    print(f'  NOW PASSING {spec}', file=sys.stderr)

print(f'journey run: {len(passed)} passed, {len(failed)} failed '
      f'({len(known)} baselined, {len(regressions)} regressions, '
      f'{len(recovered)} recovered)')

if regressions:
    print('journey run FAILED: a spec outside the baseline is failing.', file=sys.stderr)
    sys.exit(1)

if recovered:
    # Not a nag. A baseline carrying specs that pass is a baseline nobody reads,
    # and the next real regression hides inside it.
    print('journey run FAILED: baselined specs now pass — remove them from the '
          'baseline (bin/check-journey-run.sh --update).', file=sys.stderr)
    sys.exit(1)

print('journey run OK — no regressions against the baseline.')
PY
