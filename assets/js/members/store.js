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

import { store, getContext, getElement } from '@wordpress/interactivity';
import { bnToast, bnResolveConnectNote } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';
import { onNavReady } from '../shell/nav-init.js';

const SEARCH_DEBOUNCE_MS = 250;
const VIEW_STORAGE_KEY   = 'bn_members_view';

let searchTimer = null;

/* -- Helpers ----------------------------------------------------------- */

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
	if ( ctx.onlineOnly ) { qp.set( 'online',      '1' ); }
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
		if ( ctx.onlineOnly ) {
			url.searchParams.set( 'online', '1' );
		} else {
			url.searchParams.delete( 'online' );
		}
		url.searchParams.delete( 'paged' );
		window.history.replaceState( {}, '', url.toString() );
	} catch ( _e ) { /* history not available — soft-fail */ }
}

/* Reconcile the server numbered pager with the live filtered total. Without this
 * the pager keeps the unfiltered page count, so a filter (e.g. "online only")
 * that shrinks the set to one page would still offer pages 2..N that are empty —
 * confusing. After every filtered fetch we rebuild it from json.total: hide it
 * when everything fits on one page, otherwise show page 1 active with links that
 * reload the (now filter-aware) server. Built with DOM nodes — no innerHTML. */
function syncPager( total ) {
	const nav = document.querySelector( '.bn-pagination' );
	if ( ! nav ) { return; }

	const perPage = 20;
	const pages   = Math.max( 1, Math.ceil( ( Number( total ) || 0 ) / perPage ) );

	while ( nav.firstChild ) { nav.removeChild( nav.firstChild ); }

	if ( pages <= 1 ) { nav.hidden = true; return; }
	nav.hidden = false;

	// A filter change resets to page 1; links carry the current filters (already
	// written to the URL by syncUrl) plus the target page.
	const hrefFor = ( n ) => {
		const u = new URL( window.location.href );
		u.searchParams.set( 'paged', String( n ) );
		return u.pathname + u.search;
	};
	const linkBtn = ( n, label ) => {
		const a = document.createElement( 'a' );
		a.className   = 'bn-page-btn';
		a.href        = hrefFor( n );
		a.textContent = ( label != null ) ? label : String( n );
		return a;
	};
	const span = ( cls, text, current ) => {
		const s = document.createElement( 'span' );
		s.className   = 'bn-page-btn ' + cls;
		s.textContent = text;
		if ( current ) { s.setAttribute( 'aria-current', 'page' ); }
		return s;
	};

	nav.appendChild( span( 'current', '1', true ) );
	const near = Math.min( 3, pages );
	for ( let n = 2; n <= near; n++ ) { nav.appendChild( linkBtn( n ) ); }
	if ( pages > near + 1 ) { nav.appendChild( span( 'dots', '…', false ) ); }
	if ( pages > near )     { nav.appendChild( linkBtn( pages ) ); }
	nav.appendChild( linkBtn( 2, 'Next »' ) );
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
	// Mark as imperatively-wired: these dynamically-appended cards are not
	// hydrated by the Interactivity API (hydration gap), so their kebab is
	// driven via pop.hidden and the imperative outside-click closer in init().
	// The marker keeps that closer off the hydrated server-rendered cards.
	article.dataset.bnImperative = '1';

	// Pull REST settings off the root directory context so JS-built cards call
	// the same BuddyNext endpoints + nonce the server-rendered cards use.
	const rootCtx = getModalSettings();
	const cardCtx = {
		userId:      item.user_id,
		displayName: item.display_name,
		isFollowing: !! item.is_following,
		connection:  ( item.connection && item.connection.state ) || 'none',
		menuOpen:    false,
		isMuted:     false,
		restUrl:     rootCtx.restUrl,
		restNonce:   rootCtx.restNonce,
	};
	article.setAttribute( 'data-wp-context', JSON.stringify( cardCtx ) );

	// Kebab (secondary actions) — pinned top-right, over the cover. Built first
	// so it overlays the cover; mirrors templates/parts/member-card.php.
	if ( item.can_interact ) {
		article.appendChild( buildKebab( item ) );
	}

	// Cover banner — brand-safe tone gradient (the member's cover image, when
	// available, is applied via the inline background). Mirrors the space card.
	const TONES = [ 'sky', 'cyan', 'emerald', 'lime', 'amber', 'coral' ];
	const cover = document.createElement( 'div' );
	cover.className = 'bn-md-card__cover';
	cover.setAttribute( 'data-tone', TONES[ Math.abs( parseInt( item.user_id, 10 ) || 0 ) % TONES.length ] );
	cover.setAttribute( 'aria-hidden', 'true' );
	if ( item.cover_url ) {
		cover.style.backgroundImage = "url('" + item.cover_url + "')";
	}
	article.appendChild( cover );

	// Avatar — overlaps the cover, bottom-left.
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

	// Padded body (everything below the cover).
	const body = document.createElement( 'div' );
	body.className = 'bn-md-card__body';

	// Identity group — name + handle. The server template (member-card.php) wraps
	// these in .bn-md-card__identity (display:contents in grid, a flex column in
	// list view). JS-built cards must mirror it or list-view alignment breaks
	// after a tab/filter switch re-renders the grid.
	const identity = document.createElement( 'div' );
	identity.className = 'bn-md-card__identity';

	// Name (+ optional connection-degree chip).
	const h3 = document.createElement( 'h3' );
	h3.className = 'bn-md-card__name';
	const nameLink = document.createElement( 'a' );
	nameLink.href = item.profile_url;
	nameLink.textContent = item.display_name;
	h3.appendChild( nameLink );
	const degree = parseInt( item.degree, 10 ) || 0;
	if ( degree === 1 || degree === 2 ) {
		const deg = document.createElement( 'span' );
		deg.className = 'bn-md-card__degree';
		deg.setAttribute( 'data-degree', String( degree ) );
		deg.textContent = degree === 1 ? '1st' : '2nd';
		h3.appendChild( deg );
	}
	identity.appendChild( h3 );

	// Handle.
	if ( item.handle ) {
		const handle = document.createElement( 'p' );
		handle.className = 'bn-md-card__handle';
		handle.textContent = '@' + item.handle;
		identity.appendChild( handle );
	}

	body.appendChild( identity );

	// Member type badge — mirrors the server member-card.php: optional SVG icon
	// (server-sanitised via MemberTypeService::render_icon_svg) before the name.
	if ( item.member_type && item.member_type.name ) {
		const badge = document.createElement( 'span' );
		badge.className = 'bn-badge bn-md-card__type';
		badge.setAttribute( 'data-tone', 'accent' );
		if ( item.member_type.icon_svg ) {
			const iconWrap = document.createElement( 'span' );
			iconWrap.className = 'bn-type-badge__icon';
			iconWrap.setAttribute( 'aria-hidden', 'true' );
			// icon_svg is wp_kses-filtered server-side (strict SVG allowlist), so
			// assigning it as markup here is safe.
			iconWrap.innerHTML = item.member_type.icon_svg;
			badge.appendChild( iconWrap );
		}
		badge.appendChild( document.createTextNode( item.member_type.name ) );
		body.appendChild( badge );
	}

	// Bio excerpt.
	if ( item.bio_excerpt ) {
		const bio = document.createElement( 'p' );
		bio.className = 'bn-md-card__bio';
		bio.textContent = item.bio_excerpt;
		body.appendChild( bio );
	}

	// Mutual count.
	if ( item.mutual_count > 0 ) {
		const mu = document.createElement( 'p' );
		mu.className = 'bn-md-card__mutual';
		mu.textContent = item.mutual_count === 1
			? '1 mutual connection'
			: item.mutual_count + ' mutual connections';
		body.appendChild( mu );
	}

	// Action row (Follow + Connect; kebab lives top-right, not here).
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
		// Follow — gated on the target's who_can_follow privacy (mirrors the
		// server member-card.php $bn_can_follow gate); an existing Following
		// state still shows so the user can unfollow.
		if ( item.can_follow || item.is_following ) {
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
		}

		// Connect primary — gated on who_can_connect (mirrors the server gate);
		// an existing Requested/Connected state still shows even if the target
		// later restricts new requests.
		const cs = ( item.connection && item.connection.state ) || 'none';
		if ( item.can_connect || [ 'pending-sent', 'accepted' ].includes( cs ) ) {
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
		}

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
	}

	body.appendChild( actions );
	article.appendChild( body );

	// The WP Interactivity API only hydrates directives (data-wp-on--*,
	// data-wp-bind--*) on markup present at its initial scan. Cards rebuilt here
	// after a filter/tab switch are injected straight into the DOM and are never
	// hydrated, so their data-wp-on--click attributes are inert — Follow/Connect
	// silently stop working after the first tab change. Wire real listeners on
	// these JS-built cards so they behave identically to the server-rendered
	// ones, driving the same REST endpoints against the card's own context.
	wireCardListeners( article, cardCtx, item );

	return article;
}

/* Attach direct event listeners + state-driven label/variant updates to a
 * JS-built member card. Mirrors the Interactivity store's card actions, but
 * operates on the card's own plain-object context (not a getContext() proxy,
 * which does not exist for dynamically-inserted nodes). */
function wireCardListeners( article, cardCtx, item ) {
	// Idempotency guard: a card element is wired exactly once. JS-built cards
	// are fresh nodes, but the guard keeps a re-run (e.g. after a client-side
	// navigation re-init) from double-binding listeners onto the same element.
	if ( article.dataset.bnMemberWired === '1' ) { return; }
	article.dataset.bnMemberWired = '1';

	const followBtn  = article.querySelector( '.bn-md-card__follow' );
	const connectBtn = article.querySelector( '.bn-md-card__connect-primary' );
	const decideWrap = article.querySelector( '.bn-md-card__connect-decide' );

	const name = cardCtx.displayName || 'member';

	function paintFollow() {
		if ( ! followBtn ) { return; }
		followBtn.textContent = cardCtx.isFollowing ? 'Following' : 'Follow';
		followBtn.setAttribute( 'data-variant', cardCtx.isFollowing ? 'secondary' : 'primary' );
		followBtn.setAttribute( 'data-state', cardCtx.isFollowing ? 'following' : 'unfollowed' );
	}

	function paintConnect() {
		const s = cardCtx.connection || 'none';
		if ( connectBtn ) {
			const showPrimary = s === 'none' || s === 'pending-sent' || s === 'accepted';
			connectBtn.hidden = ! showPrimary;
			connectBtn.textContent = s === 'accepted' ? 'Connected' : ( s === 'pending-sent' ? 'Requested' : 'Connect' );
			connectBtn.setAttribute( 'data-variant', 'secondary' );
			connectBtn.setAttribute( 'data-state', s );
		}
		if ( decideWrap ) {
			decideWrap.hidden = s !== 'pending-received';
		}
	}

	if ( followBtn ) {
		followBtn.addEventListener( 'click', async () => {
			const was = !! cardCtx.isFollowing;
			cardCtx.isFollowing = ! was;
			paintFollow();
			try {
				const res = await restFetch( '/users/' + cardCtx.userId + '/follow', {
					method:       was ? 'DELETE' : 'POST',
					base:         cardCtx.restUrl || undefined,
					nonce:        restNonce( cardCtx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw new Error( 'follow_failed_' + res.status ); }
				bnToast( was ? 'Unfollowed @' + name : 'Now following @' + name, { tone: 'success' } );
			} catch ( _e ) {
				cardCtx.isFollowing = was;
				paintFollow();
				bnToast(
					was ? 'Could not unfollow @' + name + '. Try again.' : 'Could not follow @' + name + '. Try again.',
					{ tone: 'danger' }
				);
			}
		} );
	}

	if ( connectBtn ) {
		connectBtn.addEventListener( 'click', async () => {
			const cur = cardCtx.connection || 'none';
			if ( cur === 'pending-received' ) { return; }
			const endpoint = '/users/' + cardCtx.userId + '/connect';
			if ( cur === 'none' ) {
				// LinkedIn-style optional note. Cancelling leaves the button as-is.
				const note = await bnResolveConnectNote( {
					body: 'Add a personal message to your request to @' + name + ', or send it without one.',
				} );
				if ( note === null ) { return; }
				cardCtx.connection = 'pending-sent'; paintConnect();
				try {
					const res = await restFetch( endpoint, {
						method:       'POST',
						base:         cardCtx.restUrl || undefined,
						nonce:        restNonce( cardCtx ),
						body:         { note: note },
						toastOnError: false,
					} );
					if ( ! res.ok ) { throw new Error( 'connect_failed_' + res.status ); }
					bnToast( 'Connection request sent to @' + name, { tone: 'success' } );
				} catch ( _e ) {
					cardCtx.connection = 'none'; paintConnect();
					bnToast( 'Could not send request to @' + name + '. Try again.', { tone: 'danger' } );
				}
				return;
			}
			if ( cur === 'pending-sent' || cur === 'accepted' ) {
				const prev = cur;
				cardCtx.connection = 'none'; paintConnect();
				try {
					const res = await restFetch( endpoint, { method: 'DELETE', base: cardCtx.restUrl || undefined, nonce: restNonce( cardCtx ), toastOnError: false } );
					if ( ! res.ok ) { throw new Error( 'connect_remove_failed_' + res.status ); }
					bnToast( prev === 'accepted' ? 'Disconnected from @' + name : 'Request to @' + name + ' withdrawn', { tone: 'info' } );
				} catch ( _e ) {
					cardCtx.connection = prev; paintConnect();
					bnToast( 'Could not update connection. Try again.', { tone: 'danger' } );
				}
			}
		} );
	}

	if ( decideWrap ) {
		const btns = decideWrap.querySelectorAll( '.bn-btn' );
		// Order matches buildCard(): [0] Accept, [1] Decline.
		const decide = async ( accept ) => {
			const prev = cardCtx.connection;
			cardCtx.connection = accept ? 'accepted' : 'none';
			paintConnect();
			try {
				const res = await restFetch(
					'/users/' + cardCtx.userId + '/connect/' + ( accept ? 'accept' : 'decline' ),
					{ method: 'POST', base: cardCtx.restUrl || undefined, nonce: restNonce( cardCtx ), toastOnError: false }
				);
				if ( ! res.ok ) { throw new Error( 'decide_failed_' + res.status ); }
				bnToast( accept ? 'Connected with @' + name : 'Request from @' + name + ' declined', { tone: accept ? 'success' : 'info' } );
			} catch ( _e ) {
				cardCtx.connection = prev; paintConnect();
				bnToast( accept ? 'Could not accept request. Try again.' : 'Could not decline request. Try again.', { tone: 'danger' } );
			}
		};
		if ( btns[ 0 ] ) { btns[ 0 ].addEventListener( 'click', () => decide( true ) ); }
		if ( btns[ 1 ] ) { btns[ 1 ].addEventListener( 'click', () => decide( false ) ); }
	}

	// Apply the initial styled state. buildCard() only sets the inert
	// data-wp-bind--data-variant directives (never hydrated on these injected
	// cards), so without this the Follow/Connect buttons render as bare .bn-btn
	// with no data-variant — unstyled and visibly misaligned after a tab switch.
	// paintFollow/paintConnect set the real data-variant/data-state + label.
	paintFollow();
	paintConnect();

	// Kebab menu (Mute / Block / Report) — same hydration gap as the action row.
	wireCardKebab( article, cardCtx, item );
}

/* Wire the kebab toggle + Mute/Block/Report items on a JS-built card. */
function wireCardKebab( article, cardCtx, item ) {
	const menuBtn = article.querySelector( '.bn-md-card__menu' );
	const menuPop = article.querySelector( '.bn-md-card__menu-pop' );
	if ( menuBtn && menuPop ) {
		menuBtn.addEventListener( 'click', ( ev ) => {
			ev.stopPropagation();
			const open = menuPop.hidden;
			menuPop.hidden = ! open;
			menuBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	}
	const name = cardCtx.displayName || '';
	article.querySelectorAll( '.bn-md-card__menu-item' ).forEach( ( el ) => {
		if ( el.tagName === 'A' ) { return; } // Message link — native navigation.
		const label = ( el.textContent || '' ).trim();
		el.addEventListener( 'click', async ( ev ) => {
			ev.preventDefault();
			if ( menuPop ) { menuPop.hidden = true; }
			if ( menuBtn ) { menuBtn.setAttribute( 'aria-expanded', 'false' ); }
			if ( /^Mute$|^Unmute$/.test( label ) ) {
				const was = !! cardCtx.isMuted;
				cardCtx.isMuted = ! was;
				el.textContent = cardCtx.isMuted ? 'Unmute' : 'Mute';
				try {
					const res = await restFetch( '/users/' + cardCtx.userId + '/mute', {
						method:       was ? 'DELETE' : 'POST',
						base:         cardCtx.restUrl || undefined,
						nonce:        restNonce( cardCtx ),
						toastOnError: false,
					} );
					if ( ! res.ok ) { throw new Error( 'mute_failed' ); }
					bnToast( was ? 'Unmuted @' + name : 'Muted @' + name, { tone: 'success' } );
				} catch ( _e ) {
					cardCtx.isMuted = was;
					el.textContent = cardCtx.isMuted ? 'Unmute' : 'Mute';
					bnToast( 'Could not update mute state. Try again.', { tone: 'danger' } );
				}
			} else if ( label === 'Block' ) {
				openBlockModal( cardCtx.userId, cardCtx.displayName, el );
			} else if ( label === 'Report' ) {
				openReportModal( 'user', cardCtx.userId, cardCtx.displayName, el );
			}
		} );
	} );
}

/* Kebab (secondary actions) pinned to the card's top-right. Mirrors the
   markup in templates/parts/member-card.php so server- and client-rendered
   cards behave identically. */
function buildKebab( item ) {
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

	const itemsCfg = [];
	if ( ( ( item.connection && item.connection.state ) || 'none' ) === 'accepted' && item.messages_url ) {
		itemsCfg.push( { label: 'Message', action: '', href: item.messages_url } );
	}
	itemsCfg.push(
		{ label: 'Mute',   action: 'actions.toggleMute', danger: false, textBind: 'state.cardMuteLabel' },
		{ label: 'Block',  action: 'actions.openBlock',  danger: true },
		{ label: 'Report', action: 'actions.openReport', danger: true }
	);
	itemsCfg.forEach( ( cfg ) => {
		const b = document.createElement( cfg.href ? 'a' : 'button' );
		if ( cfg.href ) {
			b.href = cfg.href;
		} else {
			b.type = 'button';
			b.setAttribute( 'data-wp-on--click', cfg.action );
		}
		b.className = 'bn-md-card__menu-item' + ( cfg.danger ? ' bn-md-card__menu-item--danger' : '' );
		b.setAttribute( 'role', 'menuitem' );
		if ( cfg.textBind ) {
			b.setAttribute( 'data-wp-text', cfg.textBind );
		}
		b.textContent = cfg.label;
		menuPop.appendChild( b );
	} );
	menuWrap.appendChild( menuPop );
	return menuWrap;
}

async function refresh( ctx ) {
	ctx.loading  = true;
	ctx.hasError = false;
	ctx.error    = '';
	try {
		const res = await restFetch( '/members?' + buildQuery( ctx ), {
			base:         ctx.restUrl || undefined,
			nonce:        restNonce( ctx ),
			toastOnError: false,
		} );
		if ( ! res.ok ) {
			throw new Error( 'rest_' + res.status );
		}
		const json = res.data || {};
		const items = Array.isArray( json.items ) ? json.items : [];
		renderGrid( items );
		ctx.isEmpty = items.length === 0;
		ctx.totalLabel = ( json.total || 0 ) + '';
		// Keep the numbered pager honest about the filtered total.
		syncPager( json.total );
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
		get hasSearch()     { return ( getContext().search || '' ).length > 0; },
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
			if ( s === 'pending-sent' ) { return 'secondary'; }
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
					// JS-BUILT cards only (data-bn-imperative): these are not hydrated
					// by the Interactivity API (see the hydration-gap note in
					// wireCardKebab), so their kebab is driven imperatively via
					// pop.hidden. Server-rendered cards are hydrated and close through
					// the closeCardMenuOnOutside action instead — touching pop.hidden
					// on them here would bypass the reactive binding and desync
					// state.cardMenuOpen (the "blink"/stuck-menu bug).
					document.querySelectorAll( '.bn-md-card[data-user-id][data-bn-imperative]' ).forEach( ( card ) => {
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

		clearSearch() {
			const ctx = getContext();
			ctx.search    = '';
			ctx.searching = false;
			clearTimeout( searchTimer );
			document.querySelectorAll( '.bn-md-strip__search-input' ).forEach( ( inp ) => {
				inp.value = '';
				inp.focus();
			} );
			syncUrl( ctx );
			refresh( ctx );
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
			const ctx    = getContext();
			const target = event && event.target ? event.target : null;
			if ( ! target ) { return; }
			// Two sources share this action: the filter-bar <select> (read its
			// value directly) and the legacy pill row (read data-type-slug off
			// the clicked pill). Detect which one fired.
			const pill = typeof target.closest === 'function' ? target.closest( '[data-type-slug]' ) : null;
			if ( pill ) {
				ctx.memberType = pill.dataset.typeSlug || '';
			} else if ( 'value' in target ) {
				ctx.memberType = target.value || '';
			} else {
				return;
			}
			// Keep both controls visually in sync with the active type.
			document.querySelectorAll( '.bn-md-pill-row .bn-md-pill' ).forEach( ( p ) => {
				const active = ( p.dataset.typeSlug || '' ) === ctx.memberType;
				p.classList.toggle( 'is-active', active );
				p.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
			document.querySelectorAll( '.bn-md-strip__type' ).forEach( ( sel ) => {
				if ( sel.value !== ctx.memberType ) { sel.value = ctx.memberType; }
			} );
			syncUrl( ctx );
			refresh( ctx );
		},

		toggleOnlineOnly( event ) {
			const ctx    = getContext();
			const target = event && event.target ? event.target : null;
			ctx.onlineOnly = target ? !! target.checked : ! ctx.onlineOnly;
			// Mirror the state onto any other online toggles on the page.
			document.querySelectorAll( '.bn-md-strip__online-input' ).forEach( ( box ) => {
				if ( box.checked !== ctx.onlineOnly ) { box.checked = ctx.onlineOnly; }
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
			ctx.onlineOnly = false;
			document.querySelectorAll( '.bn-md-strip__search-input' ).forEach( ( inp ) => { inp.value = ''; } );
			document.querySelectorAll( '.bn-md-strip__sort' ).forEach( ( sel ) => { sel.value = 'newest'; } );
			document.querySelectorAll( '.bn-md-strip__type' ).forEach( ( sel ) => { sel.value = ''; } );
			document.querySelectorAll( '.bn-md-strip__online-input' ).forEach( ( box ) => { box.checked = false; } );
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
				const res = await restFetch( '/users/' + ctx.userId + '/follow', {
					method:       wasFollow ? 'DELETE' : 'POST',
					base:         ctx.restUrl || undefined,
					nonce:        restNonce( ctx ),
					toastOnError: false,
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
				// LinkedIn-style optional note. Cancelling leaves the button as-is.
				const note = await bnResolveConnectNote( {
					body: 'Add a personal message to your request to @' + name + ', or send it without one.',
				} );
				if ( note === null ) { return; }
				ctx.connection = 'pending-sent';
				try {
					const res = await restFetch( '/users/' + ctx.userId + '/connect', {
						method:       'POST',
						base:         ctx.restUrl || undefined,
						nonce:        restNonce( ctx ),
						body:         { note: note },
						toastOnError: false,
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
					const res = await restFetch( '/users/' + ctx.userId + '/connect', {
						method:       'DELETE',
						base:         ctx.restUrl || undefined,
						nonce:        restNonce( ctx ),
						toastOnError: false,
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
					const res = await restFetch( '/users/' + ctx.userId + '/connect', {
						method:       'DELETE',
						base:         ctx.restUrl || undefined,
						nonce:        restNonce( ctx ),
						toastOnError: false,
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
				const res = await restFetch( '/users/' + ctx.userId + '/connect/accept', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        restNonce( ctx ),
					toastOnError: false,
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
				const res = await restFetch( '/users/' + ctx.userId + '/connect/decline', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        restNonce( ctx ),
					toastOnError: false,
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

		/**
		 * Close THIS (server-rendered, hydrated) card's kebab menu when a click
		 * lands outside its menu wrapper. Bound via data-wp-on-document--click on
		 * the card root so the close goes through the same reactive state as the
		 * open (single source of truth), instead of the imperative pop.hidden path
		 * that JS-built cards use. Scoped to the current card via getElement().ref.
		 *
		 * @param {MouseEvent} event The document click event.
		 */
		closeCardMenuOnOutside( event ) {
			const ctx = getContext();
			if ( ! ctx || ! ctx.menuOpen ) { return; }
			const ref = getElement()?.ref || null;
			if ( ! ref ) { return; }
			const wrap = ref.querySelector( '.bn-md-card__menu-wrap' );
			if ( ! wrap || ! wrap.contains( event.target ) ) {
				ctx.menuOpen = false;
			}
		},

		async toggleMute() {
			const ctx  = getContext();
			const name = ctx.displayName || 'member';
			const was  = !! ctx.isMuted;
			ctx.isMuted  = ! was;
			ctx.menuOpen = false;
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/mute', {
					method:       was ? 'DELETE' : 'POST',
					base:         ctx.restUrl || undefined,
					nonce:        restNonce( ctx ),
					toastOnError: false,
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

		openBlock( event ) {
			const ctx = getContext();
			ctx.menuOpen = false;
			// Pass the trigger so the modal in THIS grid (tab panel) opens — a
			// global lookup would grab the first grid's modal, often in a
			// hidden tab. See findNearestModal().
			openBlockModal( ctx.userId, ctx.displayName, event && event.target );
		},

		openReport( event ) {
			const ctx = getContext();
			ctx.menuOpen = false;
			openReportModal( 'user', ctx.userId, ctx.displayName, event && event.target );
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

/**
 * Find the modal backdrop nearest the element that triggered it.
 *
 * The block/report modals ship inside member-grid.php, so a page with several
 * member grids — e.g. the profile Followers / Following / Connections tabs —
 * renders one backdrop per grid. A global document.querySelector always returns
 * the first, which usually lives in a hidden tab panel, so un-hiding it shows
 * nothing and Block / Report appear dead on every other grid. Walk up from the
 * trigger to the closest ancestor that actually contains a matching backdrop,
 * falling back to the first in the document if the trigger is detached.
 *
 * @param {Element} originEl Element that triggered the modal (the kebab item).
 * @param {string}  selector Backdrop selector.
 * @return {Element|null} The nearest matching backdrop.
 */
function findNearestModal( originEl, selector ) {
	let node = ( originEl && 1 === originEl.nodeType ) ? originEl : null;
	while ( node ) {
		const found = node.querySelector ? node.querySelector( selector ) : null;
		if ( found ) { return found; }
		node = node.parentElement;
	}
	return document.querySelector( selector );
}

function openBlockModal( userId, displayName, originEl ) {
	const modal = findNearestModal( originEl, '.bn-pf-block-backdrop' );
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
					const res = await restFetch(
						'/users/' + targetId + '/block',
						{ method: 'POST', base: restUrl || undefined, nonce: nonce, toastOnError: false }
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

function openReportModal( targetType, targetId, displayName, originEl ) {
	const modal = findNearestModal( originEl, '.bn-pf-report-backdrop' );
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
					const res = await restFetch(
						'/reports',
						{
							method:       'POST',
							base:         restUrl || undefined,
							nonce:        nonce,
							toastOnError: false,
							body:         {
								object_type: modal.dataset.targetType || 'user',
								object_id:   parseInt( modal.dataset.targetId || '0', 10 ),
								reason:      reason,
								notes:       notes,
							},
						}
					);
					if ( ! res.ok && res.status !== 201 ) {
						// Surface the server's reason — e.g. the 409 "You have
						// already reported this member." — instead of a generic
						// failure the user misreads as "the submit failed, retry".
						const data = res.data || {};
						bnToast( data.message || 'Could not submit report. Try again.', { tone: 'danger' } );
						return;
					}
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
	// Use setAttribute, not dataset[flag]: dataset keys containing a hyphen
	// followed by a lowercase letter (e.g. 'report-bound') throw a SyntaxError,
	// which previously aborted before the modal's submit listener was attached.
	var attr = 'data-bn-' + flag;
	if ( el.getAttribute( attr ) === '1' ) { return; }
	el.setAttribute( attr, '1' );
	fn();
}

onNavReady( () => { applyViewClass( readView() ); } );

export default memberStore;
