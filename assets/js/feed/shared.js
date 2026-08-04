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

/**
 * The site's UTC offset in seconds (WP's timezone setting).
 *
 * A MODULE SINGLETON, populated once by feed/store.js via setTz() after the
 * buddynext/feed state is available — the same pattern as the i18n table above.
 * The post-card (reschedule prefill), composer (schedule submit) and feed stores
 * all read it through this one instance rather than reaching into the feed store
 * object, which is defined in a sibling file.
 */
let TZ = { offset: 0 };

/**
 * Set the shared site-timezone table.
 *
 * @param {Object} tz `{ offset: <seconds> }` from buddynext/feed state.tz.
 * @return {void}
 */
export function setTz( tz ) {
	TZ = tz || { offset: 0 };
}

/**
 * The site's UTC offset, in seconds, from the server (WP's timezone setting).
 *
 * @return {number} Offset in seconds; 0 if unavailable.
 */
export function siteTzOffset() {
	return TZ && 'number' === typeof TZ.offset ? TZ.offset : 0;
}

/**
 * A datetime-local value -> the UTC "Y-m-d H:i:s" string the REST layer stores.
 *
 * The control's value is read as SITE time, not browser time. A <input
 * type="datetime-local"> carries no zone, so the wall-clock digits the author typed are
 * the digits we honour — interpreted in the site's zone, which is the zone the post card
 * (wp_date) and the admin screens already display. Treating them as browser-local made an
 * author in IST type "12:50" and the card answer "7:20 am": the same instant, but two
 * different numbers, which reads as a bug.
 *
 * @param {string} localValue "YYYY-MM-DDTHH:MM" as typed in the control.
 * @return {string} "Y-m-d H:i:s" in UTC, or '' if unparseable.
 */
export function toUtcSqlDatetime( localValue ) {
	if ( ! localValue ) { return ''; }
	// Parse the digits as if they were UTC, then subtract the site's offset. This never
	// consults the browser's own zone, so the result does not change with who is looking.
	const asIfUtc = new Date( String( localValue ).replace( ' ', 'T' ) + 'Z' );
	if ( isNaN( asIfUtc.getTime() ) ) { return ''; }
	const d = new Date( asIfUtc.getTime() - siteTzOffset() * 1000 );
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return d.getUTCFullYear() + '-' + pad( d.getUTCMonth() + 1 ) + '-' + pad( d.getUTCDate() ) +
		' ' + pad( d.getUTCHours() ) + ':' + pad( d.getUTCMinutes() ) + ':' + pad( d.getUTCSeconds() );
}

/**
 * The inverse: a stored UTC "Y-m-d H:i:s" -> a datetime-local value in SITE time.
 *
 * Used to prefill the reschedule control so the number it shows is the number the post
 * card shows.
 *
 * @param {string} sqlUtc UTC datetime, "Y-m-d H:i:s".
 * @return {string} "YYYY-MM-DDTHH:MM" in site time, or '' if unparseable.
 */
export function toSiteInputValue( sqlUtc ) {
	if ( ! sqlUtc ) { return ''; }
	const d = new Date( String( sqlUtc ).replace( ' ', 'T' ) + 'Z' );
	if ( isNaN( d.getTime() ) ) { return ''; }
	const site = new Date( d.getTime() + siteTzOffset() * 1000 );
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	// Read back with getUTC* — `site` is deliberately shifted so its UTC face IS site time.
	return site.getUTCFullYear() + '-' + pad( site.getUTCMonth() + 1 ) + '-' + pad( site.getUTCDate() ) +
		'T' + pad( site.getUTCHours() ) + ':' + pad( site.getUTCMinutes() );
}

/**
 * "Now" as a datetime-local string in SITE time — the floor for any schedule control.
 *
 * @return {string} "YYYY-MM-DDTHH:MM" in site time.
 */
export function siteNowInputValue() {
	return toSiteInputValue(
		new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' )
	);
}

/**
 * Empty a field the way a member would — clear it AND fire `input`.
 *
 * Assigning `.value` in code changes a field SILENTLY: the browser fires no
 * `input` event, so everything listening to it keeps rendering the old value.
 * Dispatching the event once here keeps every input-driven affordance (character
 * counter, typeahead, any future listener) in step, instead of each reset site
 * having to remember to poke each one by hand.
 *
 * @param {HTMLTextAreaElement|HTMLInputElement|null} el Field to clear.
 * @return {void}
 */
export function clearField( el ) {
	if ( ! el ) { return; }
	el.value = '';
	el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

/**
 * Grow a textarea to fit its content.
 *
 * Reset to auto first so the field can also shrink when text is deleted, then
 * set the height to the content's scrollHeight. The CSS max-height caps growth
 * (the field scrolls internally past that), so no JS ceiling is needed here.
 *
 * @param {HTMLTextAreaElement|null} el The textarea.
 * @return {void}
 */
export function autoResizeTextarea( el ) {
	if ( ! el ) { return; }
	el.style.height = 'auto';
	el.style.height = el.scrollHeight + 'px';
}

/**
 * HTML-escape a string for safe interpolation into innerHTML.
 *
 * Used by the comment renderer (post-card.js) and the feed store's client-side
 * enhancements (store.js), so it lives here as one instance.
 *
 * @param {string} str Raw value.
 * @return {string} HTML-escaped value.
 */
export function escapeHtml( str ) {
	return String( str == null ? '' : str ).replace(
		/[&<>"']/g,
		( ch ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ ch ] )
	);
}

/**
 * Shift an absolutely-positioned popover back inside the viewport — either edge.
 *
 * CSS alone cannot do this: these popovers are absolutely positioned inside a
 * narrow wrapper and pinned to their trigger's edge, so a viewport-relative
 * max-width still starts wherever the trigger happens to sit. The trigger's
 * position shifts with the layout (the post-card reaction pickers can overflow the
 * END edge; the composer "Posting to" popover, end-aligned, overflows the START
 * edge on a narrow screen where the chip sits toward the middle), so no static
 * inset works everywhere. Measure after paint and translate back by exactly the
 * overrun, never past the opposite gutter.
 *
 * Shared by the post-card reaction/comment pickers AND the composer privacy
 * popover — one rule, do not write a third copy.
 *
 * @param {Element|null} el Popover element, already visible. A hidden element has no measurable box.
 * @return {void}
 */
export function bnClampPopoverToViewport( el ) {
	if ( ! el ) {
		return;
	}

	el.style.removeProperty( 'transform' );
	requestAnimationFrame( () => {
		const box       = el.getBoundingClientRect();
		const gutter    = 12;
		const overRight = box.right - ( window.innerWidth - gutter );
		const overLeft  = gutter - box.left;
		if ( overRight > 0 ) {
			// Overflows the END edge — shift toward START, never past the start gutter.
			const shift = Math.min( overRight, Math.max( 0, box.left - gutter ) );
			el.style.transform = 'translateX(-' + Math.round( shift ) + 'px)';
		} else if ( overLeft > 0 ) {
			// Overflows the START edge — shift toward END, never past the end gutter.
			const room  = ( window.innerWidth - gutter ) - box.right;
			const shift = Math.min( overLeft, Math.max( 0, room ) );
			el.style.transform = 'translateX(' + Math.round( shift ) + 'px)';
		}
	} );
}
