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
if [ -z "${BN_BASE_URL:-}" ]; then
	echo "journey run SKIPPED — set BN_BASE_URL to the site under test." >&2
	echo "  (release builds refuse this skip; see bin/build-release.sh)" >&2
	exit 2
fi
if ! curl -sf -o /dev/null --max-time 10 "$BN_BASE_URL"; then
	echo "journey run SKIPPED — $BN_BASE_URL is not reachable." >&2
	exit 2
fi
if [ ! -d node_modules/@playwright ] && ! command -v npx >/dev/null 2>&1; then
	echo "journey run SKIPPED — Playwright is not installed (npm install)." >&2
	exit 2
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
