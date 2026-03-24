/* BuddyNext — Messages Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

const { state } = store( 'buddynext/messages', {
	/*
	 * Global store state — used for values that need to be written from
	 * within a data-wp-each loop (where context writes go to the child
	 * derived context, not the root).  Templates bind with state.xxx.
	 *
	 * composeIsDisabled is a computed getter so Preact wraps it as a
	 * computed() signal — more reliably reactive in data-wp-bind than
	 * an inline OR expression whose left side short-circuits the tracking.
	 */
	state: {
		composeRecipientId:   0,
		composeRecipientName: '',
		composeBusy:          false,
		get composeIsDisabled() {
			return ! state.composeRecipientId || state.composeBusy;
		},
	},

	actions: {
		switchTab( event ) {
			event.preventDefault();
			const ctx = getContext();
			const tab = event.target.closest( '[data-tab]' )?.dataset.tab;
			if ( tab ) {
				ctx.tab = tab;
				const url = new URL( window.location.href );
				url.searchParams.set( 'tab', tab );
				url.searchParams.delete( 's' );
				window.location.href = url.toString();
			}
		},

		onSearchInput( event ) {
			const ctx = getContext();
			ctx.search = event.target.value;
		},

		openCompose( event ) {
			event.preventDefault();
			const ctx = getContext();
			ctx.isCompose      = true;
			ctx.composeQuery   = '';
			ctx.composeResults = [];
			state.composeRecipientId   = 0;
			state.composeRecipientName = '';
			state.composeBusy          = false;
			document.querySelector( '.bn-compose-start-btn' )
				?.setAttribute( 'disabled', '' );
			const url = new URL( window.location.href );
			url.searchParams.set( 'action', 'compose' );
			history.pushState( { compose: true }, '', url.toString() );
		},

		closeCompose( event ) {
			event?.preventDefault();
			const ctx     = getContext();
			ctx.isCompose = false;
			const url     = new URL( window.location.href );
			url.searchParams.delete( 'action' );
			history.pushState( {}, '', url.toString() );
		},

		async onComposeSearch( event ) {
			const ctx   = getContext();
			const query = event.target.value.trim();
			ctx.composeQuery = query;
			if ( query.length < 2 ) {
				ctx.composeResults = [];
				return;
			}
			try {
				const url = new URL( ctx.bnRestBase + '/search' );
				url.searchParams.set( 'q', query );
				url.searchParams.set( 'type', 'user' );
				url.searchParams.set( 'per_page', '8' );
				const resp = await fetch( url.toString(), {
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( resp.ok ) {
					const data  = await resp.json();
					const users = ( data.items ?? [] )
						.filter( ( r ) => r.object_type === 'user' )
						.map( ( r ) => ( { id: r.object_id, name: r.title } ) );
					ctx.composeResults = users;
				}
			} catch {
				ctx.composeResults = [];
			}
		},

		/*
		 * Called from within a data-wp-each--user loop.
		 * Writes go to global store state to guarantee root-level reactivity.
		 * Direct DOM manipulation on the start button is included as a failsafe
		 * because data-wp-bind--disabled may not re-evaluate when its expression
		 * short-circuits during the initial Preact bind-directive setup.
		 */
		selectRecipient() {
			const ctx  = getContext();
			const user = ctx.user;
			if ( user && user.id > 0 ) {
				state.composeRecipientId   = user.id;
				state.composeRecipientName = user.name;
				ctx.composeResults         = [];
				ctx.composeQuery           = '';
				document.querySelector( '.bn-compose-start-btn' )
					?.removeAttribute( 'disabled' );
			}
		},

		clearRecipient() {
			const ctx = getContext();
			state.composeRecipientId   = 0;
			state.composeRecipientName = '';
			ctx.composeQuery           = '';
			ctx.composeResults         = [];
			document.querySelector( '.bn-compose-start-btn' )
				?.setAttribute( 'disabled', '' );
		},

		async startConversation( event ) {
			event.preventDefault();
			const ctx = getContext();
			if ( ! state.composeRecipientId || state.composeBusy ) {
				return;
			}
			state.composeBusy = true;
			try {
				const resp = await fetch( ctx.mvsRestBase + '/conversations', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctx.restNonce,
					},
					body: JSON.stringify( { recipient_id: state.composeRecipientId } ),
				} );
				if ( resp.ok ) {
					const data   = await resp.json();
					const convId = data.id ?? data.conversation_id ?? null;
					if ( convId ) {
						const url = new URL( ctx.messagesUrl );
						url.searchParams.set( 'conversation', convId );
						window.location.href = url.toString();
						return;
					}
				}
			} catch {
				// re-enable button on failure
			}
			state.composeBusy = false;
		},
	},
} );
