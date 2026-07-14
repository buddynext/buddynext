# BuddyNext API catalogue (`buddynext/v1`)

Machine-readable OpenAPI description of the Free REST surface. It is **generated
from the live WordPress route registry**, so it cannot drift from the code. The
hand-written narrative reference (paths, params, examples, gotchas) lives in
`../website/developer-guide/` pages 14-24 - read those for prose; use this for
tooling (client generation, Postman/Insomnia import, contract tests).

Pro's `buddynext-pro/v1` namespace is intentionally out of scope here.

## Files

| File | Purpose | Edit by hand? |
|---|---|---|
| `openapi.config.json` | Generator input: `info` block, `servers`, security schemes, and the path-prefix to tag rules. | Yes - this is the source of the non-generated metadata. |
| `openapi.json` | Generated OpenAPI 3.1 document. Overwritten on every run. | **No** - regenerate instead. |

## Regenerate

The generator introspects the running route registry, so it needs a WordPress
install with BuddyNext active. It cannot run from a bare checkout.

```bash
# From the plugin root, against your WordPress install:
WP_PATH=/path/to/wordpress bin/sync-api-docs.sh
```

`sync-api-docs.sh` runs the generator through WP-CLI and then the reachability
audit. To run just the generator, load it with `wp eval "require …"` (not
`eval-file`, which wraps the file in `eval()` and rejects the
`declare(strict_types=1)` first statement):

```bash
wp eval "require '$PWD/bin/gen-openapi.php';"
```

## Reachability audit

`tests/audit/rest-reachability.php` walks the live registry and fails if any
`buddynext/v1` route declares no HTTP method or no permission callback (WP treats
a missing callback as public - a foot-gun on a write route). It also flags any
path in `openapi.json` that no longer maps to a live route, so a stale generated
file is caught.

```bash
wp eval "require '$PWD/tests/audit/rest-reachability.php';"
```

## Adding or renaming routes

1. Register the route in its domain controller as usual.
2. Document it in the matching `developer-guide/` REST page (14-24).
3. If it introduces a new path prefix, add a `tagRules` entry in
   `openapi.config.json` so it lands under the right tag.
4. Run `bin/sync-api-docs.sh` to regenerate `openapi.json`.
