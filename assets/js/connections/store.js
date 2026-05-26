/* BuddyNext — Connections Interactivity API store (profile connections tab). */
import { store, getContext } from '@wordpress/interactivity';
import { bnConfirm } from '../shell/dialog.js';

store( 'buddynext/connections', {
	actions: {
		* removeConnection( event ) {
			const ctx = getContext();
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn || ! ctx.restNonce ) { return; }
			const ok = yield bnConfirm( {
				title: 'Remove this connection?',
				body: 'You can reconnect later if you change your mind.',
				confirmLabel: 'Remove',
				tone: 'danger',
			} );
			if ( ! ok ) { return; }
			const userId = btn.dataset.userId;
			try {
				const res = yield fetch( ctx.restUrl + 'users/' + userId + '/connect', {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					const card = btn.closest( '.bn-connection-card' );
					if ( card ) { card.remove(); }
				}
			} catch ( _e ) {}
		},

		* sendMessage( event ) {
			const btn = event.target.closest( '[data-user-id]' );
			if ( ! btn ) { return; }
			window.location.href = '/messages/?compose=' + btn.dataset.userId;
		},
	},
} );

/*
   Card-list client-side filter — wired to any
   `<input data-bn-filter-cards=".some-card-class">` in the same
   document. Filters by name + handle text on each matching card.
   Runs at init and on each input event.
   ---------------------------------------------------------------- */
function initBnCardFilter() {
	const inputs = document.querySelectorAll( 'input[data-bn-filter-cards]' );
	inputs.forEach( ( input ) => {
		if ( input.dataset.bnFilterReady === '1' ) { return; }
		input.dataset.bnFilterReady = '1';
		const selector = input.dataset.bnFilterCards;
		if ( ! selector ) { return; }
		const apply = () => {
			const q = ( input.value || '' ).trim().toLowerCase();
			document.querySelectorAll( selector ).forEach( ( card ) => {
				if ( q === '' ) {
					card.hidden = false;
					return;
				}
				const text = ( card.textContent || '' ).toLowerCase();
				card.hidden = ! text.includes( q );
			} );
		};
		input.addEventListener( 'input', apply );
		apply();
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initBnCardFilter );
} else {
	initBnCardFilter();
}
