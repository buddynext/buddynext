#!/usr/bin/env bash
# One command for what CI runs: the full quality gate, then the unit suite.
#
#   bin/local-ci.sh            # bin/check.sh, then vendor/bin/phpunit
#   bin/local-ci.sh --staged   # arguments go to bin/check.sh
#
# The unit suite needs the WordPress test library (see CLAUDE.md, "First-time setup").
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
bin/check.sh "$@" || exit 1
vendor/bin/phpunit
