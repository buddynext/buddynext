/**
 * Initials for an avatar placeholder.
 *
 * Mirrors AvatarService::initials_for() so a member shows the same initials
 * in a server-rendered avatar and in any preview a store draws: the first
 * letters of the first and last words, or the first two letters of a single
 * word.
 *
 * @param {string} name Display name.
 * @return {string} One or two uppercase characters, or "?".
 */
export function initials( name ) {
	const parts = String( name || '' ).trim().split( ' ' ).filter( Boolean );
	if ( parts.length >= 2 ) {
		return ( [ ...parts[ 0 ] ][ 0 ] + [ ...parts[ parts.length - 1 ] ][ 0 ] ).toUpperCase();
	}
	return parts.length ? [ ...parts[ 0 ] ].slice( 0, 2 ).join( '' ).toUpperCase() : '?';
}
