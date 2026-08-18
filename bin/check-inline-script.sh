#!/usr/bin/env bash
#
# No inline <script> in admin PHP.
#
# Behaviour printed inside a PHP render method cannot be cached, versioned,
# minified, linted or translated the way an enqueued file can, and it runs
# wherever the markup happens to land — one of the blocks this gate was written
# for resolved its own form through `document.currentScript.previousElementSibling`,
# which silently stops being true the moment anything moves.
#
# The gate exists because the COUNT WAS NOT STABLE. Between the audit and the
# fix, one file was cleaned and a new violation appeared in another (PaymentsAdmin's
# copy-to-clipboard handler) — so this was never going to hold as a one-time
# cleanup. Card 10016934115.
#
# Comment lines mentioning <script> are fine: the rule is about emitting one, and
# explaining why a file no longer does is exactly the note that should survive.
#
#   bin/check-inline-script.sh        # exit 1 on any inline <script> in admin PHP
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIRS=( "${ROOT}/includes/Admin" )

# Pro sits beside Free in a normal checkout and contributes admin screens of its
# own; every violation this gate has ever caught was on that side.
PRO="$(dirname "${ROOT}")/buddynext-pro/includes/Admin"
if [ -d "${PRO}" ]; then
	DIRS+=( "${PRO}" )
else
	echo "  note: buddynext-pro not checked out beside this repo — Free only."
fi

# Strip comment lines (// … , * … , # …) before matching so a docblock saying
# "replaces the previous inline <script>" does not fail the build that removed it.
HITS="$(grep -rn '<script' "${DIRS[@]}" 2>/dev/null \
	| grep -vE ':[[:space:]]*(\*|//|#)' \
	|| true)"

if [ -z "${HITS}" ]; then
	echo "inline script: OK — no <script> emitted from admin PHP"
	exit 0
fi

echo "" >&2
echo "inline <script> found in admin PHP:" >&2
echo "${HITS}" >&2
echo "" >&2
echo "Move the behaviour to assets/js/admin/<name>.js and wp_enqueue_script() it" >&2
echo "on the screen that needs it. See BulkModAdmin::enqueue_assets() for the shape." >&2
exit 1
