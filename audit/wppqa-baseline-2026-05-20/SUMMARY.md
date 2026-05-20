# wppqa baseline — 2026-05-20

First wppqa audit pass before onboarding manifest generation. Captured here so future runs don't re-investigate the same findings from scratch.

## Tool runs

| Check | Pass | Fail | Notes |
|---|---:|---:|---|
| `wppqa_check_plugin_dev_rules` | 0 | 15 | 8 security + 14 admin-UI / 5 tap-target findings |
| `wppqa_check_rest_js_contract` | 111 | 0 | Clean — no REST↔JS drift |
| `wppqa_check_wiring_completeness` | 7 | 32 | Mostly false positives (checker only inspects `templates/`, misses service-layer readers) |

## Plugin-dev-rules findings

### Security: nonce-without-cap (8) — all **FALSE POSITIVE**

wppqa flags `check_admin_referer` / `wp_verify_nonce` calls that lack a `current_user_can()` in nearby lines, but its heuristic doesn't walk backwards far enough to catch authorization gates set at the top of the function or the top of the included template.

| Reported location | Actual gate location | Capability used |
|---|---|---|
| `Admin/Members/ProfileFieldsManager.php:371` | line 365 (same function) | `manage_options` |
| `Admin/Members/ProfileFieldsManager.php:436` | line 430 (same function) | `manage_options` |
| `Admin/Members/ProfileFieldsManager.php:530` | line 523 (same function) | `manage_options` |
| `Admin/Members/ProfileFieldsManager.php:604` | line 597 (same function) | `manage_options` |
| `templates/spaces/settings.php:95` | line 61 (top of file) | `buddynext_can('buddynext-spaces/manage-settings', ['space_id' => $space_id])` |
| `templates/spaces/settings.php:157` | line 61 (top of file) | same |
| `templates/spaces/settings.php:174` | line 61 (top of file) | same |
| `templates/spaces/settings.php:192` | line 61 (top of file) | same |

**Action**: none. Document here so the next baseline run accepts these as known-noise.

### Frontend-responsive findings (14 inline-onclick, 5 tap-targets)

Out of scope for backend phase. Deferred to frontend design-guideline phase.

## Wiring-completeness findings

The checker inspects only `templates/` for setting consumers. Many genuine readers live in PHP service classes (EmailSender, AvatarService, ProfileService) that the checker doesn't walk. Bucketing of the 32 reports:

| Cluster | Verdict |
|---|---|
| EmailEditor settings (`template_slug`, `subject`, `body_html`, `preview_text`, `enabled`) | Read by `EmailSender::render()` — false positive |
| AvatarSettings (`bn_avatar_style`, `bn_remove_default_avatar`, `bn_default_avatar_url`, `bn_remove_default_cover`, `bn_default_cover_url`) | Read by `AvatarService` filter on `get_avatar_url` — false positive |
| MemberTypesManager (`type_id`, `type_slug`, `group_id`) | CRUD form fields, not display settings — false positive |
| ProfileFieldsManager (`field_id`, `visibility`, `attr`, `options`, `is_required`, `date_display`) | Form fields for schema CRUD — verify each one against ProfileService consumers in next pass |

**Action**: triage each cluster in a dedicated pass after backend security work lands.

## REST authorization audit (B2)

Separately enumerated all 26 `permission_callback => '__return_true'` endpoints. Three real authorization bugs found and fixed in this commit:

1. `GET /posts/{id}` — privacy gates incomplete (no block check, no secret-space check, no followers-only enforcement)
2. `GET /spaces/{id}/feed` — no secret-space membership gate
3. `GET /users/{id}/followers` + `/following` — no block-list filtering

See the commit body for the full fix detail. The remaining 23 open endpoints are either legitimately public (catalogs, PWA manifest, hashtag pages) or already gated at the handler level via prior fixes (search, members directory, `GET /spaces/{id}`).

## Verification artifacts

- `php -l` clean on all touched files
- PHPStan level 5 clean on all touched files
- PHPUnit deferred to B4 quality-baseline phase (needs `bin/install-wp-tests.sh` first)
