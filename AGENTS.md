# Agent instructions

## Translations

Follow the Wbcom translation runbook, for Claude Code, Cursor or a person:
https://github.com/vapvarun/wbcom-i18n-ai/blob/master/AGENTS.md
(locally: `~/dev/repos/wbcom-i18n-ai/AGENTS.md`)

Start with `wbcom-i18n sync`. It rebuilds the template from the code, so new text is not missed.
Never translate by hand or from a chat. Finish with `wbcom-i18n check` and `wbcom-i18n compile`, and commit the `.po`, `.mo` and `.json` files together.

Everything else about this plugin is in `CLAUDE.md`.
