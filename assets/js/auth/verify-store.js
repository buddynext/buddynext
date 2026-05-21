/* BuddyNext — Email-verification resend handler.
 *
 * Plain (non-module) classic script attached to the resend button on
 * templates/auth/verify.php. Posts to /wp-json/buddynext/v1/auth/verify/resend
 * and updates the inline toast under the button.
 */
( function () {
	'use strict';

	function init() {
		var btn = document.getElementById( 'bn-resend-btn' );
		if ( ! btn ) {
			return;
		}

		var labelSending = btn.getAttribute( 'data-label-sending' ) || 'Sending…';
		var labelReady   = btn.getAttribute( 'data-label-ready' ) || btn.textContent.trim();
		var msgSent      = btn.getAttribute( 'data-msg-sent' ) || 'Verification email sent.';
		var msgError     = btn.getAttribute( 'data-msg-error' ) || 'Something went wrong. Please try again.';

		btn.addEventListener( 'click', function () {
			var feedback = document.getElementById( 'bn-verify-feedback' );

			function showFeedback( text, tone ) {
				if ( ! feedback ) {
					return;
				}
				feedback.textContent = text;
				feedback.setAttribute( 'data-tone', tone );
				feedback.removeAttribute( 'hidden' );
			}

			function resetButton() {
				btn.disabled = false;
				// Preserve any leading <svg> icon by replacing only the trailing
				// label text node. Easier: rewrite the button's last text node.
				var labelNode = btn.querySelector( '.bn-resend-label' );
				if ( labelNode ) {
					labelNode.textContent = labelReady;
				} else {
					btn.textContent = labelReady;
				}
			}

			btn.disabled = true;
			var labelNode = btn.querySelector( '.bn-resend-label' );
			if ( labelNode ) {
				labelNode.textContent = labelSending;
			} else {
				btn.textContent = labelSending;
			}

			fetch( btn.dataset.url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   btn.dataset.nonce,
				},
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					showFeedback(
						( data && data.message ) || msgSent,
						data && data.success ? 'success' : 'danger'
					);
					resetButton();
				} )
				.catch( function () {
					showFeedback( msgError, 'danger' );
					resetButton();
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
