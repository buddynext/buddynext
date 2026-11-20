/**
 * Write hub crop preview.
 *
 * Mirrors the image Member Blog shows in #bp_member_post_img_preview into the two
 * crops the site will use (16:9 feed cover, square list thumb) and picks the size
 * hint from the image's real width. The server prints the box at the end of the form;
 * it is moved under the image box here. Watches the img's src, so a new pick, a
 * replace and a remove all update it. Text is set as text, never as HTML.
 *
 * @package BuddyNext
 */

const box = document.querySelector( '.bn-crop-preview' );
const source = document.getElementById( 'bp_member_post_img_preview' );

if ( box && source ) {
	const hint = box.querySelector( '[data-bn-crop-hint]' );
	const crops = box.querySelectorAll( '.bn-crop-preview__crop img' );
	const usual = hint.textContent;
	const min = parseInt( box.dataset.bnCropMin, 10 ) || 600;

	source.closest( '.bp-member-blog-field-container' )?.after( box );

	const sync = () => {
		const src = source.getAttribute( 'src' ) || '';
		// Member Blog's Remove resets the src to a default and hides this container.
		box.hidden = '' === src || 'none' === getComputedStyle( holder ).display;
		if ( box.hidden ) {
			return;
		}
		crops.forEach( ( img ) => img.setAttribute( 'src', src ) );
		const size = () => {
			const small = source.naturalWidth > 0 && source.naturalWidth < min;
			hint.textContent = small ? box.dataset.bnCropSmall : usual;
			// A photo under the cover minimum is not used as a cover: show text only, no stretched crops.
			box.querySelector( '.bn-crop-preview__crops' ).hidden = small;
		};
		if ( source.complete ) {
			size();
		} else {
			source.addEventListener( 'load', size, { once: true } );
		}
	};

	const holder = source.parentElement;
	const watch = new MutationObserver( sync );
	watch.observe( source, { attributes: true, attributeFilter: [ 'src' ] } );
	watch.observe( holder, { attributes: true, attributeFilter: [ 'style', 'class' ] } );
	sync();
}
