/**
 * Accent folding for live slug previews (space address, handle box).
 *
 * The server saves slugs through sanitize_title(), whose remove_accents() also
 * folds letters that Unicode does not decompose (Ø, Ł, Æ, ß) and follows the
 * site locale (German "ä" becomes "ae"). PHP sends that exact table as this
 * module's data (AssetService::slug_fold_map()), so a preview can not drift from
 * the saved address. NFD stripping stays as the fallback for anything else.
 */

let foldMap = null;

function map() {
	if ( null === foldMap ) {
		foldMap = {};
		const el = document.getElementById( 'wp-script-module-data-@buddynext/slug' );
		try {
			foldMap = ( el && JSON.parse( el.textContent ).fold ) || {};
		} catch ( e ) {
			foldMap = {};
		}
	}
	return foldMap;
}

/**
 * Fold accented letters to the ASCII sanitize_title() would store.
 *
 * @param {string} text Raw text.
 * @return {string} Folded text, case preserved.
 */
export function foldAccents( text ) {
	const fold = map();
	return String( text )
		.replace( /[^\x00-\x7f]/g, ( c ) => fold[ c ] ?? c )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' );
}
