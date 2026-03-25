/* BuddyNext — Gamification Interactivity API store (leaderboard). */
import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/gamification', {
	state: {
		get activeFilter() {
			try { return getContext().filter || 'all-time'; } catch ( _e ) { return 'all-time'; }
		},
	},
	actions: {
		setFilter( event ) {
			const btn = event.target.closest( '[data-filter]' );
			if ( btn ) {
				const url = new URL( window.location.href );
				url.searchParams.set( 'period', btn.dataset.filter );
				window.location.href = url.toString();
			}
		},
	},
} );
