/**
 * BuddyNext feed filter tabs.
 *
 * Split out of feed/store.js: the buddynext/feed-tabs store is a self-contained
 * concern (the filter tab bar on the activity hub) with no dependency on the feed
 * store's internals — it needs only getContext. Loaded as a relative import from
 * feed/store.js, so it registers when @buddynext/feed is enqueued, exactly as
 * before; the file moved, the loading did not.
 *
 * @package BuddyNext
 */

import { store, getContext } from '@wordpress/interactivity';

store( 'buddynext/feed-tabs', {
	actions: {
		setFilter( event ) {
			if ( event && event.preventDefault ) {
				event.preventDefault();
			}
			const ctx    = getContext();
			const target = event && event.target ? event.target.closest( '[data-filter]' ) : null;
			const filter = target ? target.getAttribute( 'data-filter' ) : '';
			if ( ! filter || filter === ctx.filter ) {
				return;
			}
			ctx.filter = filter;
			// Reactive page transitions reload the surface so server-rendered post
			// cards stay the single source of truth — see docs/specs/UI-CONTRACT.md.
			const url = new URL( window.location.href );
			url.searchParams.set( 'filter', filter );
			url.searchParams.delete( 'cursor' );
			window.location.href = url.toString();
		},
	},
} );
