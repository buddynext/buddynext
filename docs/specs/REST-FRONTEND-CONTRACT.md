# BuddyNext REST-Frontend Contract

## The constraint

The BuddyNext frontend is 100% REST.

- Every data interaction from `templates/`, `assets/js/` (including admin JS), and any block view-script goes through the WP REST API under `wp-json/buddynext/v1/*`.
- `wp-admin/admin-ajax.php` is forbidden. So is `wp_ajax_*`, `admin_url( 'admin-ajax.php' )`, the global `ajaxurl`, and any custom AJAX shim that bypasses REST.
- `check_ajax_referer()` / `wp_send_json_*()` are forbidden in new code; legacy uses must be migrated to REST controllers.

This contract was finalised on 2026-05-21 when the last admin-ajax surface (the Nav Manager slug-conflict probe) was migrated to `GET /buddynext/v1/admin/slug-check`. After that migration, `grep` for admin-ajax inside `includes/`, `templates/`, and `assets/js/` returns zero hits.

## Why

REST gives:

1. **Versioned namespace.** Everything lives under `buddynext/v1`, so a future `buddynext/v2` can ship without breaking integrations.
2. **Uniform permission + nonce.** Every route declares `permission_callback`, and clients send the standard `X-WP-Nonce` header generated with `wp_create_nonce( 'wp_rest' )`. No per-action nonce names, no per-action capability checks.
3. **Automatic OpenAPI introspection.** `wp-json/buddynext/v1` returns a machine-readable schema, picked up by tools, SDKs, and the WordPress block editor.
4. **Interactivity API ergonomics.** WP Interactivity stores call REST cleanly; admin-ajax requires bespoke wiring.
5. **Consistent error envelope.** REST errors return JSON with `code`, `message`, and `data.status`. Admin-ajax has no standard envelope.
6. **Single audit surface.** `bin/check-rest-boundary.sh` proves the contract holds in CI.

## How nonces work

Localize the REST base URL and a `wp_rest` nonce:

```php
wp_localize_script(
    'my-handle',
    'myCfg',
    array(
        'restUrl'   => esc_url_raw( rest_url( 'buddynext/v1/' ) ),
        'restNonce' => wp_create_nonce( 'wp_rest' ),
    )
);
```

Send the nonce as a header from JS:

```javascript
fetch( cfg.restUrl + 'admin/slug-check?slug=members', {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
        'X-WP-Nonce': cfg.restNonce,
        Accept: 'application/json',
    },
} );
```

Never put the nonce in the query string. Never use a custom nonce name. The header is the contract.

## How to register an endpoint

See `/wp-plugin-development` skill, Part 5 (REST API) and Part 8 (Security).

Quick template:

```php
namespace BuddyNext\Feature;

class FeatureController {

    public function register_routes(): void {
        register_rest_route(
            'buddynext/v1',
            '/feature/(?P<id>[\d]+)',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_one' ),
                'permission_callback' => array( $this, 'permission_check' ),
                'args'                => array(
                    'id' => array(
                        'required' => true,
                        'type'     => 'integer',
                        'minimum'  => 1,
                    ),
                ),
            )
        );
    }

    public function permission_check( \WP_REST_Request $request ): bool {
        return is_user_logged_in();
    }

    public function get_one( \WP_REST_Request $request ): \WP_REST_Response {
        $id = (int) $request->get_param( 'id' );

        // ... fetch + sanitize ...

        return new \WP_REST_Response( array( 'id' => $id ), 200 );
    }
}
```

Then register it inside `includes/REST/Router.php::register_routes()` so it boots on `rest_api_init`.

## How to register a permission callback

- Public read: `'permission_callback' => '__return_true'`. Only for genuinely public reads (e.g. explore feed counts).
- Logged-in only: a method that calls `is_user_logged_in()`.
- Capability check: a method that calls `current_user_can( 'manage_options' )` (admin), `'edit_posts'` (authors), or a custom plugin capability.
- Object-scoped: a method that resolves the object from the request and checks ownership/membership.

See `/wp-plugin-development` Part 8 for the full pattern.

## The exception escape hatch

In extremely rare cases admin-ajax may genuinely be the only option (third-party hook signature, legacy filter). In those cases:

1. Append `// wp-frontend-rest-only-allow: <one-line reason>` to the line.
2. Open an issue in this repo titled `Exception: admin-ajax in <file>` documenting the reason.
3. The CI gate (`bin/check-rest-boundary.sh`) ignores any line containing that marker, so it does not fail the build.

The marker exists so violations are auditable. Don't sprinkle it as a convenience — REST is the default.

## Pre-PR check

Before pushing, run:

```bash
bin/check-rest-boundary.sh
```

It scans `includes/`, `templates/`, and `assets/js/` for any admin-ajax surface and exits non-zero on a violation.

`bin/check.sh` runs this check automatically between the WPCS gate and the PHPStan gate, so a full `bin/check.sh` also enforces it.

## Inventory

The full REST surface is enumerated in [REST-INVENTORY.md](REST-INVENTORY.md). Update it whenever you add or remove a route.

## Anchor skills

- `/wp-plugin-development` — REST patterns, permission callbacks, nonce handling, sanitisation, escaping.
- `/wp-security-review` — permission-check audit.
- `/action-audit` — cross-layer wiring sanity (template → JS → REST).
