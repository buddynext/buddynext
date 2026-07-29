<?php
/**
 * Template loader for a BuddyNext hub.
 *
 * Returned from the `template_include` filter by PageRouter::use_hub_loader()
 * once every gate in dispatch_hub_template() has passed, and `include`d by
 * core's template loader exactly like a theme template.
 *
 * It exists so BuddyNext renders INSIDE core's template stage rather than
 * ending the request during template_redirect. Rendering there and calling exit
 * skipped the rest of core's pipeline — the `template_include` filter, the
 * `wp_before_include_template` action that starts the template-enhancement
 * output buffer, and any template_redirect callback registered after ours — so
 * every plugin built on those was silently inert on community pages.
 *
 * Deliberately holds no markup. The shell templates own the output; this only
 * hands control back to the router, which still renders the theme's own header
 * and footer around the BuddyNext canvas exactly as before.
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

\BuddyNext\Core\PageRouter::render_pending();
