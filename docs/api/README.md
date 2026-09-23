# BuddyNext API catalogue (`buddynext/v1`)

Machine-readable OpenAPI description of the Free REST surface. It is **generated
from the live WordPress route registry**, so it cannot drift from the code. The
hand-written narrative reference (paths, params, examples, gotchas) lives in
`../website/developer-guide/` pages 14-24 - read those for prose; use this for
tooling (client generation, Postman/Insomnia import, contract tests).

`openapi.combined.json` adds Pro's `buddynext-pro/v1` namespace. Every operation in
both documents declares its 200 response body, so a client generator produces typed
models.

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

`sync-api-docs.sh` regenerates both documents, then runs the OpenAPI gate
(`bin/check-openapi.php`) and the reachability audit. To run just the generator,
load it with `wp eval "require …"` (not `eval-file`, which wraps the file in
`eval()` and rejects the `declare(strict_types=1)` first statement):

```bash
wp eval "require '$PWD/bin/gen-openapi.php';"
```

### Which routes are documented

The generator documents every route the plugins can register. Routes behind a
feature toggle (webhooks, realtime, AI, push) are included whatever the site's
settings, because the tools switch every feature on while they read the registry
(`bin/openapi-all-routes.php`). Partner-plugin routes (WPMediaVerse, Jetonomy,
Eventonomy, Learnomy) register only when the partner is active, so generate on a
site with those partners and BuddyNext Pro active. The gate refuses to run anywhere
else.

## Response schemas

Response bodies come from a build-time registry, not from the route registrations:

- `includes/REST/ResponseSchema.php` (Free) and Pro's `includes/REST/ResponseSchema.php`
  hold one method per resource (a WordPress item schema) and a `map()` of
  method + path to resource and shape (`item`, `array`, `paginated`, or `text` with a
  `content_type` for non-JSON routes such as the PWA service worker).
- A write route without a map entry gets the shared `action_result` body: a JSON
  object whose fields depend on the operation. Map it to a resource when it
  returns one.
- Timestamp fields listed in `Core\Dates::timestamp_keys()` automatically document
  their ISO `<key>_gmt` sibling.
- Schemas are authored from live responses. `bin/author-response-schemas.php`
  drafts entries for every GET route that has none: it calls each route as an
  administrator with sample ids and prints resource methods and map entries to
  review and paste.

## OpenAPI gate

`bin/check-openapi.php` fails when:

1. any operation in a fresh generate has no 200 response schema;
2. the committed `openapi.combined.json` differs from a fresh generate (a route
   added, removed or changed without regenerating);
3. a list resource's live response returns a field its schema does not declare,
   or no longer returns one it does.

It exits 2 (skipped) on a site without the partner plugins.

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
4. Add a `ResponseSchema::map()` entry for its response (and a resource method if
   the shape is new; `bin/author-response-schemas.php` drafts both for GET routes).
5. Run `bin/sync-api-docs.sh` to regenerate both documents and pass the gate.
