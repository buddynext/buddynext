/**
 * Shared helpers for the feed store family.
 *
 * feed/store.js is split by concern into sibling files (tabs.js, share-modal.js,
 * …). Each needs the same handful of module-local helpers the monolith used to
 * keep private: the injected i18n table, the string lookup, and the disabled
 * card-prepend. They live here so every split file imports one instance.
 *
 * The i18n table is a MODULE SINGLETON on purpose. ES modules are evaluated once
 * and shared, so feed/store.js calls setI18N() after reading the dictionary from
 * the buddynext/feed state, and every other file's t() reads the same table
 * through this module — no per-file copy, no re-injection.
 *
 * @package BuddyNext
 */

let I18N = {};

/**
 * Set the shared translated-strings table.
 *
 * Called once from feed/store.js after the feed store's state.i18n is available.
 * Script Modules cannot use wp_set_script_translations(), so strings are injected
 * into Interactivity state server-side (AssetService::i18n_feed) and read here.
 *
 * @param {Object} dict Key => translated string.
 * @return {void}
 */
export function setI18N( dict ) {
	I18N = dict || {};
}

/**
 * Look up a translated string, falling back to the English literal.
 *
 * @param {string} k  Key.
 * @param {string} fb English fallback so the UI never breaks on a missing key.
 * @return {string}
 */
export function t( k, fb ) {
	return ( I18N && I18N[ k ] ) || fb;
}

/**
 * Fill sprintf-style %s / %d / %1$s placeholders.
 *
 * @param {string} tpl     Template.
 * @param {...*}   vals Values.
 * @return {string}
 */
export function fmt( tpl, ...vals ) {
	let i = 0;
	return String( null == tpl ? '' : tpl ).replace(
		/%(?:(\d+)\$)?[sd]/g,
		( m, pos ) => String( vals[ pos ? pos - 1 : i++ ] ?? '' )
	);
}

/**
 * Apply a wp.hooks filter when the registry is present, else return the value.
 *
 * Reads the classic window.wp.hooks global (enqueued alongside the feed module),
 * so a third party can filter comment rendering through buddynext.comment /
 * buddynext.commentNode.
 *
 * @param {string} hook  Hook name.
 * @param {*}      value Value to filter.
 * @param {...*}   args  Extra args passed to the filter.
 * @return {*}
 */
export function bnApplyFilters( hook, value, ...args ) {
	if ( window.wp && window.wp.hooks && typeof window.wp.hooks.applyFilters === 'function' ) {
		return window.wp.hooks.applyFilters( hook, value, ...args );
	}
	return value;
}

/**
 * Refuse to insert a server-rendered feed card client-side.
 *
 * DISABLED and always returns false. A post card is an Interactivity island; the
 * API hydrates islands present at first paint, so markup injected afterwards is
 * inert — React / Comment / Share / Save all dead until reload (cards 10127252280
 * and 10127947165). There is no supported client-side hydrate: WP exports no
 * public hydrate from @wordpress/interactivity, and core's router uses privateApis
 * a plugin must not depend on. Returning false routes both callers (composer
 * submit, repost) into the reload fallback they already carry, so the member gets
 * a fully-hydrated card from the server. The real fix is rendering the feed
 * through the Interactivity API itself (data-wp-each), planned separately.
 *
 * @param {string} html Server-rendered card markup (ignored).
 * @return {boolean} Always false.
 */
export function prependFeedCard( html ) {
	return false;
}
