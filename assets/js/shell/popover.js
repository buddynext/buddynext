/* BuddyNext — keep an absolutely-positioned popover inside the viewport.
 *
 * Lived in feed/shared.js while both callers were feed surfaces. It moved here
 * when the spaces directory and space hero needed it too: a viewport clamp is
 * chrome, not feed logic, and `@buddynext/spaces` importing `@buddynext/feed-shared`
 * to get one would have read as a dependency that is not really there.
 *
 * The rule the callers share: CSS alone cannot do this. These popovers are
 * absolutely positioned inside a narrow wrapper and pinned to one edge of their
 * trigger, so a viewport-relative max-width still starts wherever the trigger
 * happens to sit — and the trigger MOVES with the layout. The spaces sort chip is
 * the clearest case: full-width (so end-pinning is correct) below 480px, a 128px
 * chip at the start of a horizontal row from 481px, and indented by the sidebar
 * from about 1024px. It renders correctly, off-screen, then correctly again as the
 * window widens. No static inset is right at all three.
 */

/**
 * Shift an absolutely-positioned popover back inside the viewport — either edge.
 *
 * Measures after paint and translates back by exactly the overrun, never past the
 * opposite gutter: a popover too wide for the screen stays pinned to the start
 * gutter rather than swapping one clipped edge for the other.
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
