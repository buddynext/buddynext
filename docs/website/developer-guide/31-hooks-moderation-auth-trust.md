# Hooks: Moderation, Trust, and Authentication

The action and filter seams for content moderation (reports, removals, strikes, suspensions, shadow bans, appeals), the automated content-safeguard pipeline, and the authentication surface (two-factor, registration spam control, email verification, social login). This page is for developers building moderation tooling, anti-spam integrations, trust-and-safety dashboards, or custom sign-in providers. Every hook below is fired or applied by BuddyNext Free, so it is available without Pro - the same seams are where BuddyNext Pro's Moderation Rules engine plugs in.

![The moderation queue whose report, strike, suspension, and content-safeguard hooks are documented on this page](../images/moderation-queue.webp)

## Overview / Contract

- **Actions fire after the state change commits.** A removal, strike, or suspension action runs after the database write succeeds. Listeners that need the full row should re-fetch by ID through the relevant service (for example `buddynext_service( 'moderation' )`), not reconstruct it from the passed scalars.
- **The safeguard pipeline is a single filter.** Every automated content rule - banned words, blocked links, rate limits, duplicate holds, the new-member gate, and Pro's keyword/ML blocklists - resolves through one filter, `buddynext_safeguard_check`. It runs on create and on edit. Return a `WP_Error` to block, or pass the value through to allow.
- **Registration filters are gates or scores.** `buddynext_spam_protection_enabled` and `buddynext_registration_challenge_enabled` return booleans; `buddynext_registration_spam_score` returns an integer score; the domain-list filters return arrays. BuddyNext never blocks a sign-up by calling addon code directly - it reads these values.
- **Two-factor is advisory, never a hard block.** `buddynext_2fa_required_roles` surfaces a UI hint for the listed roles; BuddyNext does not refuse sign-in for a member who has not enabled 2FA.
- **The fired moderation events match the canonical integration contract** in the Free plugin's `docs/specs/HOOKS.md` (status: Locked). Where a signature below differs from an older listing, the live code wins.

## The safeguard pipeline: `buddynext_safeguard_check`

This is the single most important seam on this page. Every post (and every edit) runs through `SafeguardService`, which executes the built-in automated checks in order and then applies `buddynext_safeguard_check` as the final gate. This is the extension point BuddyNext Pro's Moderation Rules engine hooks to add keyword blocklists and ML scoring, and the same seam your own anti-spam logic plugs into.

The built-in checks that run before the filter, in order:

1. Blocked IP (option `buddynext_blocked_ips`)
2. Banned words, site-wide and per-space (options `buddynext_banned_words` and `bn_space_{id}_banned_words`)
3. Blocked link domains (option `bn_blocked_domains`)
4. Post rate limit per user per minute
5. Duplicate-content hold
6. New-member review gate

On a create, all six run. On an edit, only the content-based checks (banned words, blocked domains) re-run, because the rate-limit, duplicate, and new-member gates are create-time concerns. Either way, `buddynext_safeguard_check` runs last, so your filter applies to both create and edit.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_safeguard_check` | filter | A post is about to be saved (create or edit), after the built-in automated checks pass | `true\|WP_Error $result, int $user_id, string $content, string $link_url` |
| `buddynext_client_ip` | filter | The safeguard service resolves the request IP for the blocked-IP check | `string $ip` |
| `buddynext_report_reasons` | filter | The report reason list is built (default: `spam, harassment, misinformation, inappropriate, fake, impersonation, other`) | `string[] $reasons` |
| `buddynext_moderation_auto_actions` | filter | A report has just been inserted, deciding which automated actions to apply (Free returns empty; Pro stacks actions here) | `array $actions, array $report` |

> **Note:** `$result` is `true` when every built-in check passed. To block content, return a `WP_Error`. To allow it, return `$result` unchanged. Returning a non-`WP_Error`, non-`true` value is treated as allow. The `pending_review` error code is intentionally non-fatal upstream - callers save the post with a pending status rather than discarding it - so reserve `WP_Error` returns from your filter for content you genuinely want rejected.

### Auto-action shapes for `buddynext_moderation_auto_actions`

Each entry in the returned array is an associative array with at least an `action` key. The supported shapes:

```php
array( 'action' => 'remove',  'reason' => 'string' );
array( 'action' => 'warn',    'user_id' => 123, 'reason' => 'string' );
array( 'action' => 'suspend', 'user_id' => 123, 'reason' => 'string', 'duration_days' => 7 );
```

## Moderation event actions

These fire after a moderator (or an auto-action) acts on content or a member. Trust-and-safety integrations and gamification penalties hook these.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_report_created` | action | A member submits a report | `int $report_id, string $object_type, int $object_id, int $reporter_id` |
| `buddynext_content_removed` | action | Reported content is removed (by a moderator or an auto-action) | `string $object_type, int $object_id, int $actor_id` |
| `buddynext_user_warned` | action | A member is issued a warning | `int $user_id, int $actor_id, string $reason` |
| `buddynext_strike_issued` | action | A strike is recorded against a member | `int $strike_id, int $user_id, int $actor_id` |
| `buddynext_user_suspended` | action | A member is suspended | `int $user_id, int $actor_id` |
| `buddynext_user_unsuspended` | action | A suspension is lifted | `int $user_id` |
| `buddynext_member_suspended` | action | Member-domain mirror of a suspension | `int $user_id, int $by_user_id` |
| `buddynext_member_unsuspended` | action | Member-domain mirror of an unsuspension | `int $user_id, int $by_user_id` |
| `buddynext_user_shadow_banned` | action | A member is shadow-banned (their content stays visible only to themselves) | `int $user_id` |
| `buddynext_user_shadow_ban_removed` | action | A shadow ban is lifted | `int $user_id` |
| `buddynext_appeal_submitted` | action | A member appeals a moderation decision | `int $user_id, int $appeal_id, string $type, int $suspension_id` |
| `buddynext_appeal_resolved` | action | An appeal is decided | `int $appeal_id, int $user_id, string $decision` |
| `buddynext_space_user_banned` | action | A member is banned from a space | `int $space_id, int $user_id, int $banned_by` |
| `buddynext_space_user_unbanned` | action | A space ban is lifted | `int $space_id, int $user_id` |

> **Note:** Some events have two flavours. The `buddynext_user_*` suspension events are the moderation-domain canonical events; the `buddynext_member_*` variants are the member-domain mirrors fired from the admin Members screens. Hook whichever matches where you need to react; do not assume both fire on every code path.

## Moderation-queue render seams

The admin moderation queue and the member-facing report modal expose theming seams so you can add columns, row actions, or panel content without forking the templates.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_mod_queue_columns` | filter | The moderation-queue table header is built | `array $columns` |
| `buddynext_mod_queue_row_actions` | action | A moderation-queue row's action cell renders | row context args |
| `buddynext_moderation_queue_before` | action | Before the moderation-queue list renders | - |
| `buddynext_part_member_report_modal_before` / `_after` | action | Around the member report modal markup | `array $args` |
| `buddynext_part_member_report_modal_args` / `_classes` | filter | Shape the report modal's args / wrapper classes | `array $args` / `array $classes, array $args` |
| `buddynext_part_space_settings_panel_moderation_before` / `_after` | action | Around the space moderation settings panel | `array $args` |

> **Tip:** The `_part_*` modal and panel seams follow the same four-hook contract as every other BuddyNext template part (`_before`, `_after`, `_args`, `_classes`). For the full convention, see Hooks: Template Parts.

## Authentication: two-factor

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_2fa_enabled` | action | A member turns on two-factor authentication | `int $user_id` |
| `buddynext_2fa_disabled` | action | A member turns off two-factor authentication | `int $user_id` |
| `buddynext_2fa_required_roles` | filter | Deciding whether 2FA is advised for a user (default: empty = advised for nobody) | `array $roles` |
| `buddynext_2fa_issuer` | filter | Building the `otpauth://` provisioning URI; sets the label shown in the authenticator app (default: site name) | `string $issuer` |

## Authentication: registration and spam control

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_spam_protection_enabled` | filter | Deciding whether registration spam protection runs | `bool $enabled` |
| `buddynext_register_rate_limit` | filter | Resolving the max registrations per window (default from option `buddynext_reg_rate_limit`) | `int $max` |
| `buddynext_registration_spam_score` | filter | Scoring a registration attempt; higher is spammier | `int $score, array $ctx` |
| `buddynext_registration_blocked` | action | A registration is rejected as spam | `array $ctx, int $score` |
| `buddynext_registration_honeypot_field` | filter | The honeypot field name on the sign-up form (default `bn_website`) | `string $field` |
| `buddynext_registration_allowed_domains` | filter | The email-domain allowlist for sign-up | `array $allowed, string $email` |
| `buddynext_disposable_domains` | filter | The disposable-email-domain blocklist | `array $domains` |
| `buddynext_registration_challenge_enabled` | filter | Whether a registration challenge (captcha-style) is shown | `bool $on` |
| `buddynext_registration_pending` | action | A new account is created but awaits verification or approval | `int $user_id, string $email` |
| `buddynext_registration_fields_saved` | action | Custom registration field values are stored | `int $user_id, array $values, array $fields` |
| `buddynext_member_approved` | action | A pending registration is approved | `int $user_id` |

## Authentication: email verification and social login

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_send_verification_email` | action | A verification email is about to be sent | `int $user_id, string $token_url` |
| `buddynext_user_verified` | action | A member completes verification | `int $user_id` |
| `buddynext_email_verified` | action | A member's email address is confirmed via the verify link | `int $user_id` |
| `buddynext_email_change_requested` | action | A member requests an email-address change (pending confirmation) | `int $user_id, string $new_email` |
| `buddynext_email_changed` | action | An email-address change is confirmed | `int $user_id, string $new_email` |
| `buddynext_oauth_providers` | filter | The OAuth provider definitions are assembled | `array $providers` |
| `buddynext_auth_social_providers` | filter | The social provider buttons rendered on login / signup / connected-accounts | `array $providers` |
| `buddynext_social_icon` | filter | A social provider's icon is resolved | `string $icon, string $provider_id` |
| `buddynext_social_user_created` | action | A new account is created from a social login | `int $user_id, string $provider_id, array $profile` |

## Examples

### Add a custom safeguard via `buddynext_safeguard_check`

The example below blocks any post that contains more than two links, on top of BuddyNext's built-in checks. Because the filter runs on both create and edit, this rule applies to edited posts too.

```php
add_filter(
	'buddynext_safeguard_check',
	function ( $result, $user_id, $content, $link_url ) {
		// Respect any earlier rejection (built-in check or another plugin).
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Trusted roles bypass the rule.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return $result;
		}

		// Reject posts with more than two URLs - a common spam pattern.
		if ( preg_match_all( '#https?://#i', $content ) > 2 ) {
			return new WP_Error(
				'too_many_links',
				__( 'Posts can include at most two links. Please remove some and try again.', 'my-ext' )
			);
		}

		return $result;
	},
	10,
	4
);
```

> **Warning:** Always return `$result` unchanged when you do not want to block, and short-circuit on an existing `WP_Error` so you do not mask a rejection from a higher-priority rule (including BuddyNext Pro's Moderation Rules). Returning `true` unconditionally would silently override every other safeguard.

### Penalise the recipient on a strike (gamification)

```php
add_action(
	'buddynext_strike_issued',
	function ( $strike_id, $user_id, $actor_id ) {
		// Deduct trust points from the struck member.
		my_gamification_adjust_points( $user_id, -50, 'moderation_strike' );
	},
	10,
	3
);
```

### Reject sign-ups from a disposable-email domain you maintain

```php
add_filter(
	'buddynext_disposable_domains',
	function ( $domains ) {
		$domains[] = 'throwaway.example';
		return $domains;
	}
);
```

## Notes and gotchas

- **The safeguard filter does not see the post ID** - the post has not been saved yet on create. If you need post context, key your logic off `$user_id` and `$content`.
- **`buddynext_moderation_auto_actions` is empty in Free.** Returning actions from it is how Pro (or you) drive automatic remove/warn/suspend off a report. Each action you add executes immediately after the report row is written, so guard against double-acting on the same report.
- **Suspension and member events come in pairs.** Listen on the event that matches your trigger surface (moderation queue vs admin Members screen); do not register the same handler on both expecting one fire.
- **2FA is never a hard gate.** If your integration must enforce 2FA, enforce it in your own login flow - BuddyNext only surfaces it as advisory via `buddynext_2fa_required_roles`.
- **Free vs Pro.** Every hook on this page is Free. BuddyNext Pro adds no new safeguard seam - it extends the same `buddynext_safeguard_check` and `buddynext_moderation_auto_actions` filters, which is exactly why your custom rules and Pro's rules engine coexist on one pipeline. For the search and admin seams, see Hooks: Search, Hashtags, Sidebar, and Admin.
