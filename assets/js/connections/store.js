/* BuddyNext — Connections Interactivity API store (profile connections tab). */
import { store } from '@wordpress/interactivity';

store( 'buddynext/connections', {
	actions: {
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
