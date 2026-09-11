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
| `openapi.json` | Generated OpenAPI 3.1 document (Free `buddynext/v1` only). Overwritten on every run. | **No** - regenerate instead. |
| `openapi.combined.config.json` | Generator input for the combined Free+Pro document (both namespaces, per-namespace tag prefixes). | Yes - metadata only. |
| `openapi.combined.json` | Generated Free+Pro document, used by the buddynext.com API reference. Overwritten on every run. | **No** - regenerate instead. |

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

### Combined Free+Pro spec (`openapi.combined.json`)

The combined spec is a snapshot of whatever the generating site has registered,
and route registration is **feature-gated**: Pro's push routes register only when
the `push` feature is on, its event routes only when Eventonomy is active, and the
Learnomy / gamification / webhooks integration routes only when those partners are
enabled. Generate it on a **fully-enabled install** (Pro active, every Pro module
toggle on, and the integration plugins active), or the artifact silently drops the
gated routes - the gap that shipped 8 missing Pro paths (card 10294149957).

The combined config has no `output` key of its own, so pass the destination via
`BN_OPENAPI_OUT`:

```bash
BN_OPENAPI_CONFIG="$PWD/docs/api/openapi.combined.config.json" \
BN_OPENAPI_OUT="$PWD/docs/api/openapi.combined.json" \
wp eval "putenv('BN_OPENAPI_CONFIG='.getenv('BN_OPENAPI_CONFIG')); putenv('BN_OPENAPI_OUT='.getenv('BN_OPENAPI_OUT')); require '$PWD/bin/gen-openapi.php';"
```

Because it is generated straight from the live registry, a spec-vs-registry diff
is empty in both directions by construction. Regenerate on the same fully-enabled
install after any route change so it stays that way.

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
