/* BuddyNext — Member Directory Interactivity API store.
 *
 * Drives templates/directory/members.php:
 *   - reactive filter bar (no Apply submit)
 *   - 250 ms debounced search, instant sort + relation tab clicks
 *   - member-type pill row
 *   - skeleton, empty, and error states wired to context.loading / hasError
 *   - per-card Follow + Connect with optimistic UI, rollback + toast
 *   - per-card kebab menu wired to Mute / Block / Report via shared modals
 *   - state-driven Connect 5-state machine: none, pending-sent, pending-received, accepted, blocked
 *
 * Every action that mutates server state shows a toast on success and rolls
 * back the optimistic UI plus shows a danger toast on REST 4xx/5xx.
 */

import { store, getContext } from '@wordpress/interactivity';
import { bnToast } from '../shell/dialog.js';

const SEARCH_DEBOUNCE_MS = 250;
const VIEW_STORAGE_KEY   = 'bn_members_view';

let searchTimer = null;

/* -- Helpers ----------------------------------------------------------- */

function apiUrl( ctx, path ) {
	if ( ctx && ctx.restUrl ) {
		return ctx.restUrl.replace( /\/$/, '' ) + path;
	}
	return ( ( window.wpApiSettings && window.wpApiSettings.root ) || '/wp-json/' ) + 'buddynext/v1' + path;
}

function restNonce( ctx ) {
	return ( ctx && ctx.restNonce ) || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
}

function readView() {
	try {
		return window.localStorage.getItem( VIEW_STORAGE_KEY ) === 'list' ? 'list' : 'grid';
	} catch ( _e ) {
		return 'grid';
	}
}

function writeView( next ) {
	try {
		window.localStorage.setItem( VIEW_STORAGE_KEY, next );
	} catch ( _e ) { /* storage unavailable — soft-fail */ }
}

function applyViewClass( next ) {
	const grid = document.querySelector( '.bn-md-grid' );
	if ( grid ) {
		grid.classList.toggle( 'is-list', next === 'list' );
	}
	document.querySelectorAll( '.bn-md-filters__view .bn-btn' ).forEach( ( btn ) => {
		const pressed = btn.dataset.view === next;
		btn.setAttribute( 'aria-pressed', pressed ? 'true' : 'false' );
	} );
}

/* Read the root directory context object from the data-wp-context JSON on the
 * root element. We use this for filter state (search/sort/relation) and for
 * Block / Report modal state. WP Interactivity will keep the *reactive* state
 * proxy in sync via getContext(); this helper is only used outside reactive
 * scopes (e.g. callbacks attached to DOM by buildCard()). */
function rootEl() {
	return document.querySelector( '[data-wp-interactive="buddynext/members"]' );
}

function buildQuery( ctx ) {
	const qp = new URLSearchParams();
	if ( ctx.search )     { qp.set( 'search',      ctx.search ); }
	if ( ctx.sort )       { qp.set( 'sort',        ctx.sort ); }
	if ( ctx.relation )   { qp.set( 'relation',    ctx.relation ); }
	if ( ctx.memberType ) { qp.set( 'member_type', ctx.memberType ); }
	qp.set( 'per_page', '20' );
	return qp.toString();
}

function syncUrl( ctx ) {
	try {
		const url = new URL( window.location.href );
		if ( ctx.search ) { url.searchParams.set( 's', ctx.search ); } else { url.searchParams.delete( 's' ); }
		if ( ctx.relation && ctx.relation !== 'all' ) {
			url.searchParams.set( 'relation', ctx.relation );
		} else {
			url.searchParams.delete( 'relation' );
		}
		const sortToOrderby = { newest: 'registered', alphabetical: 'display_name', most_active: 'post_count', online: 'post_count' };
		const orderby = sortToOrderby[ ctx.sort ] || 'registered';
		if ( orderby === 'registered' ) {
			url.searchParams.delete( 'orderby' );
		} else {
			url.searchParams.set( 'orderby', orderby );
		}
		if ( ctx.memberType ) {
			url.searchParams.set( 'type', ctx.memberType );
		} else {
			url.searchParams.delete( 'type' );
		}
		url.searchParams.delete( 'paged' );
		window.history.replaceState( {}, '', url.toString() );
	} catch ( _e ) { /* history not available — soft-fail */ }
}

/* Build a kebab-icon SVG using DOM nodes (no innerHTML). */
function buildKebabIcon() {
	const svgNs = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS( svgNs, 'svg' );
	svg.setAttribute( 'width',  '16' );
	svg.setAttribute( 'height', '16' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'fill',   'none' );
	svg.setAttribute( 'stroke', 'currentColor' );
	svg.setAttribute( 'stroke-width', '2' );
	svg.setAttribute( 'aria-hidden', 'true' );
	[ 12, 19, 5 ].forEach( ( cx ) => {
		const c = document.createElementNS( svgNs, 'circle' );
		c.setAttribute( 'cx', String( cx ) );
		c.setAttribute( 'cy', '12' );
		c.setAttribute( 'r',  '1' );
		svg.appendChild( c );
	} );
	return svg;
}

/* Render the items array into the grid. Uses DOM APIs only — every
 * user-controlled string passes through textContent, every URL through
 * setAttribute. */
function renderGrid( items ) {
	const grid = document.querySelector( '.bn-md-grid' );
	if ( ! grid ) { return; }
	grid.replaceChildren();
	items.forEach( ( item ) => {
		const card = buildCard( item );
		if ( card ) { grid.appendChild( card ); }
	} );
}

function buildCard( item ) {
	const article = document.createElement( 'article' );
	article.className = 'bn-card bn-md-card';
	article.setAttribute( 'role', 'listitem' );
	article.dataset.userId = String( item.user_id );

	const cardCtx = {
		userId:      item.user_id,
		displayName: item.display_name,
		isFollowing: !! item.is_following,
		connection:  ( item.connection && item.connection.state ) || 'none',
		menuOpen:    false,
		isMuted:     false,
	};
	article.setAttribute( 'data-wp-context', JSON.stringify( cardCtx ) );

	// Avatar.
	const avLink = document.createElement( 'a' );
	avLink.href = item.profile_url;
	avLink.className = 'bn-md-card__avatar-link';
	avLink.setAttribute( 'tabindex', '-1' );
	avLink.setAttribute( 'aria-hidden', 'true' );
	const avSpan = document.createElement( 'span' );
	avSpan.className = 'bn-avatar bn-md-card__avatar';
	avSpan.setAttribute( 'data-size', 'xl' );
	avSpan.setAttribute( 'data-presence', item.is_online ? 'online' : 'offline' );
	if ( item.avatar_url ) {
		const img = document.createElement( 'img' );
		img.src = item.avatar_url;
		img.alt = '';
		img.width = 72;
		img.height = 72;
		img.loading = 'lazy';
		img.decoding = 'async';
		avSpan.appendChild( img );
	} else {
		avSpan.textContent = ( item.display_name || '' ).slice( 0, 2 ).toUpperCase();
	}
	avLink.appendChild( avSpan );
	article.appendChild( avLink );

	// Name.
	const h3 = document.createElement( 'h3' );
	h3.className = 'bn-md-card__name';
	const nameLink = document.createElement( 'a' );
	nameLink.href = item.profile_url;
	nameLink.textContent = item.display_name;
	h3.appendChild( nameLink );
	article.appendChild( h3 );

	// Handle.
	if ( item.handle ) {
		const handle = document.createElement( 'p' );
		handle.className = 'bn-md-card__handle';
		handle.textContent = '@' + item.handle;
		article.appendChild( handle );
	}

	// Member type badge.
	if ( item.member_type && item.member_type.name ) {
		const badge = document.createElement( 'span' );
		badge.className = 'bn-badge bn-md-card__type';
		badge.setAttribute( 'data-tone', 'accent' );
		badge.textContent = item.member_type.name;
		article.appendChild( badge );
	}

	// Bio excerpt.
	if ( item.bio_excerpt ) {
		const bio = document.createElement( 'p' );
		bio.className = 'bn-md-card__bio';
		bio.textContent = item.bio_excerpt;
		article.appendChild( bio );
	}

	// Mutual count.
	if ( item.mutual_count > 0 ) {
		const mu = document.createElement( 'p' );
		mu.className = 'bn-md-card__mutual';
		mu.textContent = item.mutual_count === 1
			? '1 mutual connection'
			: item.mutual_count + ' mutual connections';
		article.appendChild( mu );
	}

	// Action row.
	const actions = document.createElement( 'div' );
	actions.className = 'bn-md-card__actions';

	if ( ! item.can_interact ) {
		const view = document.createElement( 'a' );
		view.className = 'bn-btn';
		view.setAttribute( 'data-variant', item.is_self ? 'secondary' : 'primary' );
		view.setAttribute( 'data-size', 'sm' );
		view.href = item.profile_url;
		view.textContent = item.is_self ? 'Edit profile' : 'View profile';
		actions.appendChild( view );
	} else {
		// Follow.
		const follow = document.createElement( 'button' );
		follow.type = 'button';
		follow.className = 'bn-btn bn-md-card__follow';
		follow.setAttribute( 'data-size', 'sm' );
		follow.setAttribute( 'data-wp-bind--data-variant', 'state.cardFollowVariant' );
		follow.setAttribute( 'data-wp-bind--data-state',    'state.cardFollowState' );
		follow.setAttribute( 'data-wp-text',                'state.cardFollowLabel' );
		follow.setAttribute( 'data-wp-on--click',           'actions.toggleFollow' );
		follow.textContent = item.is_following ? 'Following' : 'Follow';
		actions.appendChild( follow );

		// Connect primary.
		const cs = ( item.connection && item.connection.state ) || 'none';
		const conn = document.createElement( 'button' );
		conn.type = 'button';
		conn.className = 'bn-btn bn-md-card__connect-primary';
		conn.setAttribute( 'data-size', 'sm' );
		conn.setAttribute( 'data-wp-bind--hidden',         '!state.cardShowConnect' );
		conn.setAttribute( 'data-wp-bind--data-variant',   'state.cardConnectVariant' );
		conn.setAttribute( 'data-wp-bind--data-state',     'state.cardConnectState' );
		conn.setAttribute( 'data-wp-text',                 'state.cardConnectLabel' );
		conn.setAttribute( 'data-wp-on--click',            'actions.toggleConnection' );
		if ( cs === 'accepted' )           { conn.textContent = 'Connected'; }
		else if ( cs === 'pending-sent' )  { conn.textContent = 'Requested'; }
		else                                { conn.textContent = 'Connect'; }
		if ( ! [ 'none', 'pending-sent', 'accepted' ].includes( cs ) ) {
			conn.hidden = true;
		}
		actions.appendChild( conn );

		// Accept/decline pair for pending-received.
		const decide = document.createElement( 'span' );
		decide.className = 'bn-md-card__connect-decide';
		decide.setAttribute( 'data-wp-bind--hidden', '!state.cardShowReceived' );
		if ( cs !== 'pending-received' ) { decide.hidden = true; }
		[
			{ label: 'Accept',  variant: 'primary', action: 'actions.acceptConnection' },
			{ label: 'Decline', variant: 'ghost',   action: 'actions.declineConnection' },
		].forEach( ( cfg ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'bn-btn';
			b.setAttribute( 'data-variant', cfg.variant );
			b.setAttribute( 'data-size',    'sm' );
			b.setAttribute( 'data-wp-on--click', cfg.action );
			b.textContent = cfg.label;
			decide.appendChild( b );
		} );
		actions.appendChild( decide );

		// Kebab.
		const menuWrap = document.createElement( 'div' );
		menuWrap.className = 'bn-md-card__menu-wrap';
		const menuBtn = document.createElement( 'button' );
		menuBtn.type = 'button';
		menuBtn.className = 'bn-md-card__menu';
		menuBtn.setAttribute( 'aria-label', 'More actions' );
		menuBtn.setAttribute( 'aria-haspopup', 'true' );
		menuBtn.setAttribute( 'aria-expanded', 'false' );
		menuBtn.setAttribute( 'data-wp-on--click', 'actions.toggleCardMenu' );
		menuBtn.setAttribute( 'data-wp-bind--aria-expanded', 'state.cardMenuExpanded' );
		menuBtn.appendChild( buildKebabIcon() );
		menuWrap.appendChild( menuBtn );

		const menuPop = document.createElement( 'div' );
		menuPop.className = 'bn-md-card__menu-pop';
		menuPop.setAttribute( 'role', 'menu' );
		menuPop.setAttribute( 'data-wp-bind--hidden', '!state.cardMenuOpen' );
		menuPop.hidden = true;
		[
			{ label: 'Mute',   action: 'actions.toggleMute', danger: false, textBind: 'state.cardMuteLabel' },
			{ label: 'Block',  action: 'actions.openBlock',  danger: true },
			{ label: 'Report', action: 'actions.openReport', danger: true },
		].forEach( ( cfg ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'bn-md-card__menu-item' + ( cfg.danger ? ' bn-md-card__menu-item--danger' : '' );
			b.setAttribute( 'role', 'menuitem' );
			b.setAttribute( 'data-wp-on--click', cfg.action );
			if ( cfg.textBind ) {
				b.setAttribute( 'data-wp-text', cfg.textBind );
			}
			b.textContent = cfg.label;
			menuPop.appendChild( b );
		} );
		menuWrap.appendChild( menuPop );
		actions.appendChild( menuWrap );
	}

	article.appendChild( actions );
	return article;
}

async function refresh( ctx ) {
	ctx.loading  = true;
	ctx.hasError = false;
	ctx.error    = '';
	try {
		const res = await fetch( apiUrl( ctx, '/members?' + buildQuery( ctx ) ), {
			headers: { 'X-WP-Nonce': restNonce( ctx ) },
		} );
		if ( ! res.ok ) {
			throw new Error( 'rest_' + res.status );
		}
		const json = await res.json();
		const items = Array.isArray( json.items ) ? json.items : [];
		renderGrid( items );
		ctx.isEmpty = items.length === 0;
		ctx.totalLabel = ( json.total || 0 ) + '';
	} catch ( _e ) {
		ctx.hasError = true;
		ctx.error    = 'Could not load members. Check your connection and try again.';
	} finally {
		ctx.loading   = false;
		ctx.searching = false;
	}
}

/* -- Store ------------------------------------------------------------- */

const memberStore = store( 'buddynext/members', {
	state: {
		get isListView()    { return readView() === 'list'; },
		get isGridPressed() { return readView() === 'grid' ? 'true' : 'false'; },
		get isListPressed() { return readView() === 'list' ? 'true' : 'false'; },

		get allPillPressed() {
			return getContext().memberType === '' ? 'true' : 'false';
		},
		get allPillClass() {
			return getContext().memberType === '' ? 'bn-md-pill is-active' : 'bn-md-pill';
		},

		get showEmpty() {
			const ctx = getContext();
			return !! ctx.isEmpty && ! ctx.loading && ! ctx.hasError;
		},
		get gridHidden() {
			const ctx = getContext();
			return !! ctx.loading || !! ctx.hasError || !! ctx.isEmpty;
		},

		get cardFollowVariant() { return getContext().isFollowing ? 'secondary' : 'primary'; },
		get cardFollowState()   { return getContext().isFollowing ? 'following' : 'unfollowed'; },
		get cardFollowLabel()   { return getContext().isFollowing ? 'Following' : 'Follow'; },

		get cardConnectVariant() {
			const s = getContext().connection;
			if ( s === 'pending-sent' ) { return 'ghost'; }
			return 'secondary';
		},
		get cardConnectState() { return getContext().connection || 'none'; },
		get cardConnectLabel() {
			const s = getContext().connection;
			if ( s === 'accepted' )     { return 'Connected'; }
			if ( s === 'pending-sent' ) { return 'Requested'; }
			return 'Connect';
		},
		get cardShowConnect() {
			const s = getContext().connection;
			return s === 'none' || s === 'pending-sent' || s === 'accepted';
		},
		get cardShowReceived() { return getContext().connection === 'pending-received'; },
		get cardMenuOpen()     { return !! getContext().menuOpen; },
		get cardMenuExpanded() { return getContext().menuOpen ? 'true' : 'false'; },
		get cardMuteLabel()    { return getContext().isMuted ? 'Unmute' : 'Mute'; },
	},
	callbacks: {
		init() {
			applyViewClass( readView() );
			if ( typeof document !== 'undefined' && ! document.__bnMembersOutsideBound ) {
				document.addEventListener( 'click', ( ev ) => {
					if ( ! ev.target ) { return; }
					document.querySelectorAll( '.bn-md-card[data-user-id]' ).forEach( ( card ) => {
						const wrap = card.querySelector( '.bn-md-card__menu-wrap' );
						if ( ! wrap || wrap.contains( ev.target ) ) { return; }
						const pop = wrap.querySelector( '.bn-md-card__menu-pop' );
						if ( pop && pop.hidden === false ) {
							pop.hidden = true;
							const btn = wrap.querySelector( '.bn-md-card__menu' );
							if ( btn ) { btn.setAttribute( 'aria-expanded', 'false' ); }
						}
					} );
				}, true );
				document.__bnMembersOutsideBound = true;
			}
		},
	},
	actions: {
		setGridView() { writeView( 'grid' ); applyViewClass( 'grid' ); },
		setListView() { writeView( 'list' ); applyViewClass( 'list' ); },

		/* -- Filter actions -------------------------------------------- */

		handleSearchInput( event ) {
			const ctx = getContext();
			const value = event && event.target ? event.target.value : '';
			ctx.search    = value;
			ctx.searching = true;
			clearTimeout( searchTimer );
			searchTimer = setTimeout( () => {
				syncUrl( ctx );
				refresh( ctx );
			}, SEARCH_DEBOUNCE_MS );
		},

		selectSort( event ) {
			const ctx = getContext();
			ctx.sort = event && event.target ? event.target.value : 'newest';
			syncUrl( ctx );
			refresh( ctx );
		},

		selectRelation( event ) {
			const ctx = getContext();
			const btn = event && event.target ? event.target.closest( '[data-relation]' ) : null;
			if ( ! btn ) { return; }
			ctx.relation = btn.dataset.relation || 'all';
			document.querySelectorAll( '.bn-md-strip .bn-tab[data-relation]' ).forEach( ( t ) => {
				const active = t.dataset.relation === ctx.relation;
				t.classList.toggle( 'is-active', active );
				t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );
			syncUrl( ctx );
			refresh( ctx );
		},

		selectMemberType( event ) {
			const ctx = getContext();
			const btn = event && event.target ? event.target.closest( '[data-type-slug]' ) : null;
			if ( ! btn ) { return; }
			ctx.memberType = btn.dataset.typeSlug || '';
			document.querySelectorAll( '.bn-md-pill-row .bn-md-pill' ).forEach( ( p ) => {
				const active = ( p.dataset.typeSlug || '' ) === ctx.memberType;
				p.classList.toggle( 'is-active', active );
				p.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
			syncUrl( ctx );
			refresh( ctx );
		},

		resetFilters() {
			const ctx = getContext();
			ctx.search     = '';
			ctx.sort       = 'newest';
			ctx.relation   = 'all';
			ctx.memberType = '';
			document.querySelectorAll( '.bn-md-strip__search-input' ).forEach( ( inp ) => { inp.value = ''; } );
			document.querySelectorAll( '.bn-md-strip__sort' ).forEach( ( sel ) => { sel.value = 'newest'; } );
			document.querySelectorAll( '.bn-md-strip .bn-tab[data-relation]' ).forEach( ( t ) => {
				const active = t.dataset.relation === 'all';
				t.classList.toggle( 'is-active', active );
				t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );
			document.querySelectorAll( '.bn-md-pill-row .bn-md-pill' ).forEach( ( p ) => {
				const active = ( p.dataset.typeSlug || '' ) === '';
				p.classList.toggle( 'is-active', active );
				p.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
			syncUrl( ctx );
			refresh( ctx );
		},

		retry() {
			refresh( getContext() );
		},

		/* -- Card actions ---------------------------------------------- */

		async toggleFollow() {
			const ctx     = getContext();
			const wasFollow = !! ctx.isFollowing;
			const name    = ctx.displayName || 'member';
			ctx.isFollowing = ! wasFollow;
			try {
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/follow' ), {
					method:  wasFollow ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': restNonce( ctx ) },
				} );
				if ( ! res.ok ) { throw new Error( 'follow_failed_' + res.status ); }
				bnToast(
					wasFollow ? 'Unfollowed @' + name : 'Now following @' + name,
					{ tone: 'success' }
				);
			} catch ( _e ) {
				ctx.isFollowing = wasFollow;
				bnToast(
					wasFollow
						? 'Could not unfollow @' + name + '. Try again.'
						: 'Could not follow @' + name + '. Try again.',
					{ tone: 'danger' }
				);
			}
		},

		async toggleConnection() {
			const ctx  = getContext();
			const cur  = ctx.connection || 'none';
			const name = ctx.displayName || 'member';
			if ( cur === 'pending-received' ) { return; }
			if ( cur === 'none' ) {
				ctx.connection = 'pending-sent';
				try {
					const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect' ), {
						method:  'POST',
						headers: { 'X-WP-Nonce': restNonce( ctx ) },
					} );
					if ( ! res.ok ) { throw new Error( 'connect_failed_' + res.status ); }
					bnToast( 'Connection request sent to @' + name, { tone: 'success' } );
				} catch ( _e ) {
					ctx.connection = 'none';
					bnToast( 'Could not send request to @' + name + '. Try again.', { tone: 'danger' } );
				}
				return;
			}
			if ( cur === 'pending-sent' ) {
				ctx.connection = 'none';
				try {
					const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect' ), {
						method:  'DELETE',
						headers: { 'X-WP-Nonce': restNonce( ctx ) },
					} );
					if ( ! res.ok ) { throw new Error( 'withdraw_failed_' + res.status ); }
					bnToast( 'Request to @' + name + ' withdrawn', { tone: 'info' } );
				} catch ( _e ) {
					ctx.connection = 'pending-sent';
					bnToast( 'Could not withdraw request. Try again.', { tone: 'danger' } );
				}
				return;
			}
			if ( cur === 'accepted' ) {
				ctx.connection = 'none';
				try {
					const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect' ), {
						method:  'DELETE',
						headers: { 'X-WP-Nonce': restNonce( ctx ) },
					} );
					if ( ! res.ok ) { throw new Error( 'disconnect_failed_' + res.status ); }
					bnToast( 'Disconnected from @' + name, { tone: 'info' } );
				} catch ( _e ) {
					ctx.connection = 'accepted';
					bnToast( 'Could not disconnect. Try again.', { tone: 'danger' } );
				}
			}
		},

		async acceptConnection() {
			const ctx  = getContext();
			const name = ctx.displayName || 'member';
			const prev = ctx.connection;
			ctx.connection = 'accepted';
			try {
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect/accept' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': restNonce( ctx ) },
				} );
				if ( ! res.ok ) { throw new Error( 'accept_failed_' + res.status ); }
				bnToast( 'Connected with @' + name, { tone: 'success' } );
			} catch ( _e ) {
				ctx.connection = prev;
				bnToast( 'Could not accept request. Try again.', { tone: 'danger' } );
			}
		},

		async declineConnection() {
			const ctx  = getContext();
			const name = ctx.displayName || 'member';
			const prev = ctx.connection;
			ctx.connection = 'none';
			try {
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/connect/decline' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': restNonce( ctx ) },
				} );
				if ( ! res.ok ) { throw new Error( 'decline_failed_' + res.status ); }
				bnToast( 'Request from @' + name + ' declined', { tone: 'info' } );
			} catch ( _e ) {
				ctx.connection = prev;
				bnToast( 'Could not decline request. Try again.', { tone: 'danger' } );
			}
		},

		toggleCardMenu( event ) {
			if ( event && typeof event.stopPropagation === 'function' ) {
				event.stopPropagation();
			}
			const ctx = getContext();
			ctx.menuOpen = ! ctx.menuOpen;
		},

		async toggleMute() {
			const ctx  = getContext();
			const name = ctx.displayName || 'member';
			const was  = !! ctx.isMuted;
			ctx.isMuted  = ! was;
			ctx.menuOpen = false;
			try {
				const res = await fetch( apiUrl( ctx, '/users/' + ctx.userId + '/mute' ), {
					method:  was ? 'DELETE' : 'POST',
					headers: { 'X-WP-Nonce': restNonce( ctx ) },
				} );
				if ( ! res.ok ) { throw new Error( 'mute_failed' ); }
				bnToast( was ? 'Unmuted @' + name : 'Muted @' + name, { tone: 'success' } );
			} catch ( _e ) {
				ctx.isMuted = was;
				bnToast( 'Could not update mute state. Try again.', { tone: 'danger' } );
			}
		},

		/* -- Cross-surface modals (Block + Report) -------------------- *
		 * These actions cannot mutate the root reactive context proxy
		 * from a nested card scope, so they imperatively open vanilla
		 * modals built from the existing partials. The partial DOM is
		 * already in the page (rendered by members.php); we toggle
		 * `hidden` on the backdrop and wire submit / cancel handlers. */

		openBlock() {
			const ctx = getContext();
			ctx.menuOpen = false;
			openBlockModal( ctx.userId, ctx.displayName );
		},

		openReport() {
			const ctx = getContext();
			ctx.menuOpen = false;
			openReportModal( 'user', ctx.userId, ctx.displayName );
		},
	},
} );

/* -- Vanilla modal openers for the cross-surface block + report flows -- */

function getModalSettings() {
	const root = rootEl();
	if ( ! root ) { return { restUrl: '', restNonce: '' }; }
	try {
		const c = JSON.parse( root.getAttribute( 'data-wp-context' ) || '{}' );
		return { restUrl: c.restUrl || '', restNonce: c.restNonce || '' };
	} catch ( _e ) {
		return { restUrl: '', restNonce: '' };
	}
}

function openBlockModal( userId, displayName ) {
	const modal = document.querySelector( '.bn-pf-block-backdrop' );
	if ( ! modal ) { return; }
	modal.dataset.targetId   = String( userId );
	modal.dataset.targetName = String( displayName || '' );
	const title = modal.querySelector( '.bn-modal__title' );
	if ( title ) {
		title.textContent = displayName ? 'Block ' + displayName + '?' : 'Block this member?';
	}
	modal.hidden = false;

	bindOnce( modal, 'block-bound', () => {
		modal.querySelectorAll( '[data-wp-on--click="actions.closeBlockConfirm"]' ).forEach( ( b ) => {
			b.addEventListener( 'click', ( e ) => { e.preventDefault(); modal.hidden = true; } );
		} );
		const cta = modal.querySelector( '[data-wp-on--click="actions.confirmBlock"]' );
		if ( cta ) {
			cta.addEventListener( 'click', async ( e ) => {
				e.preventDefault();
				if ( cta.dataset.submitting === '1' ) { return; }
				cta.dataset.submitting = '1';
				cta.setAttribute( 'aria-disabled', 'true' );
				const { restUrl, restNonce: nonce } = getModalSettings();
				const targetId = parseInt( modal.dataset.targetId || '0', 10 );
				const name     = modal.dataset.targetName || 'member';
				try {
					const res = await fetch(
						restUrl.replace( /\/$/, '' ) + '/users/' + targetId + '/block',
						{ method: 'POST', headers: { 'X-WP-Nonce': nonce } }
					);
					if ( ! res.ok ) { throw new Error( 'block_failed' ); }
					modal.hidden = true;
					bnToast( '@' + name + ' blocked', { tone: 'success' } );
					const card = document.querySelector( '.bn-md-card[data-user-id="' + targetId + '"]' );
					if ( card ) { card.remove(); }
				} catch ( _e ) {
					bnToast( 'Could not block. Try again.', { tone: 'danger' } );
				} finally {
					cta.dataset.submitting = '';
					cta.removeAttribute( 'aria-disabled' );
				}
			} );
		}
	} );
}

function openReportModal( targetType, targetId, displayName ) {
	const modal = document.querySelector( '.bn-pf-report-backdrop' );
	if ( ! modal ) { return; }
	modal.dataset.targetType = String( targetType || 'user' );
	modal.dataset.targetId   = String( targetId || 0 );
	modal.dataset.targetName = String( displayName || '' );
	// Reset form fields.
	const reasonSel = modal.querySelector( '#bn-pf-report-reason' );
	if ( reasonSel ) { reasonSel.value = 'spam'; }
	const notesEl = modal.querySelector( '#bn-pf-report-notes' );
	if ( notesEl ) { notesEl.value = ''; }
	modal.hidden = false;

	bindOnce( modal, 'report-bound', () => {
		modal.querySelectorAll( '[data-wp-on--click="actions.closeReport"]' ).forEach( ( b ) => {
			b.addEventListener( 'click', ( e ) => { e.preventDefault(); modal.hidden = true; } );
		} );
		const cta = modal.querySelector( '[data-wp-on--click="actions.submitReport"]' );
		if ( cta ) {
			cta.addEventListener( 'click', async ( e ) => {
				e.preventDefault();
				if ( cta.dataset.submitting === '1' ) { return; }
				cta.dataset.submitting = '1';
				cta.setAttribute( 'aria-disabled', 'true' );
				const { restUrl, restNonce: nonce } = getModalSettings();
				const reason = ( reasonSel && reasonSel.value ) || 'other';
				const notes  = ( notesEl && notesEl.value )  || '';
				try {
					const res = await fetch(
						restUrl.replace( /\/$/, '' ) + '/reports',
						{
							method:  'POST',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
							body:    JSON.stringify( {
								object_type: modal.dataset.targetType || 'user',
								object_id:   parseInt( modal.dataset.targetId || '0', 10 ),
								reason:      reason,
								notes:       notes,
							} ),
						}
					);
					if ( ! res.ok && res.status !== 201 ) { throw new Error( 'report_failed' ); }
					modal.hidden = true;
					bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
				} catch ( _e ) {
					bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
				} finally {
					cta.dataset.submitting = '';
					cta.removeAttribute( 'aria-disabled' );
				}
			} );
		}
	} );
}

function bindOnce( el, flag, fn ) {
	if ( el.dataset[ flag ] === '1' ) { return; }
	el.dataset[ flag ] = '1';
	fn();
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => { applyViewClass( readView() ); } );
	} else {
		applyViewClass( readView() );
	}
}

export default memberStore;
