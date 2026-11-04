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
import { bnToast, bnBlockConfirm, bnConfirmDisconnect, bnMarkReported, bnReportDialog, bnResolveConnectNote } from '@buddynext/shell-dialog';
import { restFetch, toastRestError } from '@buddynext/rest-client';
import { onNavReady, bnPageUrl } from '@buddynext/nav-init';

/* -- i18n -------------------------------------------------------------- */
/* Translated strings are injected server-side into the Interactivity state
 * (AssetService::i18n_members) because Script Modules cannot use
 * wp_set_script_translations(). The dictionary is read once from the
 * buddynext/members namespace below; each lookup keeps the English literal as a
 * fallback so the UI never breaks if the state is absent. fmt() fills
 * sprintf-style '%s' / '%d' placeholders in order. */
let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }
function fmt( tpl, ...vals ) { let i = 0; return String( null == tpl ? '' : tpl ).replace( /%[sd]/g, () => String( vals[ i++ ] ?? '' ) ); }

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
	/*
	 * Every grid the visitor's toggle owns — and only those.
	 *
	 * Two bugs lived in the single `querySelector` this replaces. It returned the
	 * FIRST .bn-md-grid on the page, so with more than one on screen (any page
	 * carrying a Member Directory block) the others were never touched at all. And
	 * it touched grids it had no business touching: a block's grid is rendered with
	 * the layout the SITE OWNER picked in the editor, and this toggled it back off
	 * on hydrate from the visitor's localStorage — which defaults to grid, so the
	 * owner's "list" was erased on every page load. The block has no toggle UI, so
	 * nothing could restore it.
	 *
	 * `is-view-pinned` marks an owner-chosen grid. The toggle is a /members/ hub
	 * feature, where the control actually exists; a pinned grid is not its business.
	 */
	document.querySelectorAll( '.bn-md-grid:not(.is-view-pinned)' ).forEach( ( grid ) => {
		grid.classList.toggle( 'is-list', next === 'list' );
	} );
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
		const sortToOrderby = { newest: 'registered', alphabetical: 'display_name', most_active: 'post_count', online: 'online' };
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
		// A filter change lands on page 1.
		window.history.replaceState( {}, '', bnPageUrl( url.toString(), 1 ) );
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
	const hrefFor = ( n ) => bnPageUrl( window.location.href, n );
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
	nav.appendChild( linkBtn( 2, t( 'pagerNext', 'Next »' ) ) );
}

/* Build a kebab-icon SVG using DOM nodes (no innerHTML). */
function buildKebabIcon() {
	const svgNs = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS( svgNs, 'svg' );
	// Same class as the server-rendered icon, so both share its sizing and flex-shrink: 0.
	svg.setAttribute( 'class', 'bn-icon' );
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
	// Mark as imperatively-driven. The Interactivity API only hydrates directives
	// present at its initial DOM scan, so cards rebuilt here after a filter/tab
	// switch never hydrate. Instead of the (inert) data-wp-* directives + a
	// per-card hand-paint we used to set, these cards now carry plain
	// data-bn-action verbs and their reactive state lives in data-* attributes;
	// a SINGLE delegated handler (actions.onGridClick) on the hydrated grid
	// wrapper drives Follow / Connect / kebab for every JS-built card. The
	// marker scopes the outside-click closer in init() to these cards.
	article.dataset.bnImperative = '1';
	// Reactive state for the delegated handler — read/written as data-* so there
	// is one source of truth per card (no parallel JS object to drift).
	article.dataset.displayName = item.display_name || '';
	article.dataset.handle      = item.handle || '';
	article.dataset.followState = followStateOf( item.is_following, item.is_pending );
	article.dataset.connection  = ( item.connection && item.connection.state ) || 'none';
	// Was hardcoded '0'. The REST payload now carries is_muted (it did not before),
	// and the kebab label 380 lines down already read item.is_muted — so the two
	// disagreed inside this same file: the menu could say "Unmute" while the card
	// claimed it was not muted. SSR (member-card.php) had it right all along.
	article.dataset.muted       = item.is_muted ? '1' : '0';

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
		deg.textContent = degree === 1 ? t( 'degreeFirst', '1st' ) : t( 'degreeSecond', '2nd' );
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

	// Profession/headline tagline — mirrors member-card.php so a filter/sort
	// re-render doesn't drop the line that says who each member is.
	if ( item.headline ) {
		const headline = document.createElement( 'p' );
		headline.className = 'bn-md-card__headline';
		headline.textContent = item.headline;
		identity.appendChild( headline );
	}

	body.appendChild( identity );

	// Add-on chips (Pro member labels): the server card prints these through
	// buddynext_member_card_meta_html, in the same place.
	if ( Array.isArray( item.labels ) && item.labels.length ) {
		const overlay = document.createElement( 'div' );
		overlay.className = 'bn-md-card__meta-overlay';
		overlay.appendChild( buildLabels( item.labels ) );
		body.appendChild( overlay );
	}

	// Member type badge — mirrors the server member-card.php: optional SVG icon
	// (server-sanitised via MemberTypeService::render_icon_svg) before the name.
	if ( item.member_type && item.member_type.name ) {
		const badge = document.createElement( 'span' );
		badge.className = 'bn-badge bn-md-card__type';
		badge.setAttribute( 'data-tone', 'accent' );
		// The owner's colour pair, as member-card.php paints it (the API already
		// sends the readable text colour). Without it every badge turned the plain
		// accent pill after any filter, sort or search.
		if ( item.member_type.color && item.member_type.text_color ) {
			badge.style.background = item.member_type.color;
			badge.style.color      = item.member_type.text_color;
		}
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
			? t( 'mutualConnectionSingular', '1 mutual connection' )
			: fmt( t( 'mutualConnectionPlural', '%d mutual connections' ), item.mutual_count );
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
		view.textContent = item.is_self ? t( 'editProfile', 'Edit profile' ) : t( 'viewProfile', 'View profile' );
		actions.appendChild( view );
	} else {
		// Follow — item.can_follow is PrivacyService::can_follow(), filters and
		// all; an existing Following or Requested state still shows so the member
		// can undo it. The button carries a plain data-bn-action verb; the
		// delegated onGridClick handler drives it.
		if ( item.can_follow || item.is_following || item.is_pending ) {
			const follow = document.createElement( 'button' );
			follow.type = 'button';
			follow.className = 'bn-btn bn-md-card__follow';
			follow.setAttribute( 'data-size', 'sm' );
			follow.setAttribute( 'data-bn-action', 'follow' );
			paintFollowBtn( follow, article.dataset.followState );
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
			conn.setAttribute( 'data-bn-action', 'connect' );
			paintConnectBtn( conn, cs );
			actions.appendChild( conn );
		}

		// Accept/decline pair for pending-received.
		const decide = document.createElement( 'span' );
		decide.className = 'bn-md-card__connect-decide';
		decide.hidden = cs !== 'pending-received';
		[
			{ label: t( 'accept', 'Accept' ),  variant: 'primary', verb: 'accept' },
			{ label: t( 'decline', 'Decline' ), variant: 'ghost',   verb: 'decline' },
		].forEach( ( cfg ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'bn-btn';
			b.setAttribute( 'data-variant', cfg.variant );
			b.setAttribute( 'data-size',    'sm' );
			b.setAttribute( 'data-bn-action', cfg.verb );
			b.textContent = cfg.label;
			decide.appendChild( b );
		} );
		actions.appendChild( decide );

		// The viewer's plan withholds the action: same control as partials/social-upgrade-link.php.
		if ( item.upgrade_url ) {
			const upgrade = document.createElement( 'a' );
			upgrade.className = 'bn-btn bn-social-upgrade';
			upgrade.setAttribute( 'data-variant', 'primary' );
			upgrade.setAttribute( 'data-size', 'sm' );
			upgrade.href = item.upgrade_url;
			upgrade.textContent = item.upgrade_label || '';
			actions.appendChild( upgrade );
		}
	}

	body.appendChild( actions );
	article.appendChild( body );

	return article;
}

/* Member-label chips, matching ProfileLabelInjector::render_chips(): the first
 * three, then a "+N" chip. text_color and icon_svg come from the server, which
 * owns the contrast rule and the icon set. */
function buildLabels( labels ) {
	const CHIP_CAP = 3;
	const wrap = document.createElement( 'span' );
	wrap.className = 'bn-member-labels';
	const chip = ( text ) => {
		const el = document.createElement( 'span' );
		el.className = 'bn-badge bn-member-label';
		el.setAttribute( 'data-tone', 'accent' );
		el.append( text );
		return el;
	};
	labels.slice( 0, CHIP_CAP ).forEach( ( label ) => {
		const el = chip( label.name || '' );
		el.setAttribute( 'data-label-slug', label.slug || '' );
		if ( label.color ) {
			el.style.background  = label.color;
			el.style.color       = label.text_color || '';
			el.style.borderColor = 'transparent';
		}
		// Rendered by IconService from a validated slug, never member input.
		if ( label.icon_svg ) { el.insertAdjacentHTML( 'afterbegin', label.icon_svg ); }
		wrap.appendChild( el );
	} );
	if ( labels.length > CHIP_CAP ) {
		const more = chip( '+' + ( labels.length - CHIP_CAP ) );
		more.classList.add( 'bn-member-label--more' );
		wrap.appendChild( more );
	}
	return wrap;
}

/* The three states of a member card's Follow control. */
function followStateOf( isFollowing, isPending ) {
	if ( isPending ) { return 'pending'; }
	return isFollowing ? 'following' : 'unfollowed';
}

function followLabelFor( state ) {
	if ( 'pending' === state ) { return t( 'requested', 'Requested' ); }
	return 'following' === state ? t( 'following', 'Following' ) : t( 'follow', 'Follow' );
}

/* Paint a Follow button from its state. Used both for the initial render in
 * buildCard() and after a delegated toggle — one painter, so server-equivalent
 * styling stays consistent. */
function paintFollowBtn( btn, state ) {
	if ( ! btn ) { return; }
	btn.textContent = followLabelFor( state );
	btn.setAttribute( 'data-variant', 'unfollowed' === state ? 'primary' : 'secondary' );
	btn.setAttribute( 'data-state', state );
	if ( 'pending' === state ) {
		btn.setAttribute( 'aria-label', t( 'ariaCancelRequest', 'Cancel follow request' ) );
	} else {
		btn.removeAttribute( 'aria-label' );
	}
}

/* Send one follow change and toast the outcome, for both card renderers. Only the
 * server knows whether a follow landed or waits for the owner's approval, so the
 * caller repaints from what this returns, never ahead of it. A member who cancels
 * or unfollows ends unfollowed; a refusal keeps the state they had.
 *
 * @return {Promise<string>} The Follow control's state now.
 */
async function sendFollow( uid, handle, state, cfg ) {
	const res = await restFetch( '/users/' + uid + '/follow', {
		method: 'unfollowed' === state ? 'POST' : 'DELETE', base: cfg.restUrl || undefined, nonce: cfg.restNonce, toastOnError: false,
	} );
	if ( ! res.ok ) {
		toastRestError( res, 'following' === state
			? fmt( t( 'toastCouldNotUnfollow', 'Could not unfollow @%s. Try again.' ), handle )
			: fmt( t( 'toastCouldNotFollow', 'Could not follow @%s. Try again.' ), handle ) );
		return state;
	}
	if ( 'following' === state ) {
		bnToast( fmt( t( 'toastUnfollowed', 'Unfollowed @%s' ), handle ), { tone: 'info' } );
		return 'unfollowed';
	}
	if ( 'pending' === state ) {
		bnToast( fmt( t( 'toastRequestCancelled', 'Follow request to @%s cancelled' ), handle ), { tone: 'info' } );
		return 'unfollowed';
	}
	if ( res.data && res.data.pending ) {
		bnToast( fmt( t( 'toastRequestSent', 'Follow request sent to @%s' ), handle ), { tone: 'success' } );
		return 'pending';
	}
	bnToast( fmt( t( 'toastNowFollowing', 'Now following @%s' ), handle ), { tone: 'success' } );
	return 'following';
}

/* Paint a Connect primary button (label / visibility / state) from a connection
 * state. The accept-decline pair visibility is handled by the caller. */
function paintConnectBtn( btn, state ) {
	if ( ! btn ) { return; }
	const s = state || 'none';
	const showPrimary = s === 'none' || s === 'pending-sent' || s === 'accepted';
	btn.hidden = ! showPrimary;
	btn.textContent = s === 'accepted' ? t( 'connected', 'Connected' ) : ( s === 'pending-sent' ? t( 'requested', 'Requested' ) : t( 'connect', 'Connect' ) );
	btn.setAttribute( 'data-variant', 'secondary' );
	btn.setAttribute( 'data-state', s );
	if ( s === 'accepted' ) {
		btn.setAttribute( 'aria-haspopup', 'dialog' );
	} else {
		btn.removeAttribute( 'aria-haspopup' );
	}
}

/* -- Delegated card handlers (drive JS-built cards from onGridClick) ----- *
 * Each reads/writes the card's data-* state (single source of truth) and
 * repaints only the acted element — the imperative DOM update appropriate for
 * runtime nodes the Interactivity API cannot hydrate. Mirrors the server-card
 * actions (toggleFollow/toggleConnection/…) one-for-one. */

/* The public @handle every toast names, never the display name. */
function cardHandle( card ) {
	return card.dataset.handle || t( 'memberFallback', 'member' );
}

async function delegatedFollow( card, btn, cfg ) {
	const uid = parseInt( card.dataset.userId, 10 ) || 0;
	btn.disabled = true;
	btn.setAttribute( 'aria-busy', 'true' );
	card.dataset.followState = await sendFollow( uid, cardHandle( card ), card.dataset.followState, cfg );
	paintFollowBtn( btn, card.dataset.followState );
	btn.disabled = false;
	btn.removeAttribute( 'aria-busy' );
}

async function delegatedConnect( card, btn, cfg ) {
	const uid  = parseInt( card.dataset.userId, 10 ) || 0;
	const name = cardHandle( card );
	const cur  = card.dataset.connection || 'none';
	const endpoint = '/users/' + uid + '/connect';
	if ( cur === 'pending-received' ) { return; }

	if ( cur === 'none' ) {
		const note = await bnResolveConnectNote( {
			body: fmt( t( 'noteBody', 'Add a personal message to your request to @%s, or send it without one.' ), name ),
		} );
		if ( note === null ) { return; }
		card.dataset.connection = 'pending-sent';
		paintConnectBtn( btn, 'pending-sent' );
		try {
			const res = await restFetch( endpoint, { method: 'POST', base: cfg.restUrl || undefined, nonce: cfg.restNonce, body: { note }, toastOnError: false } );
			if ( ! res.ok ) { throw res; }
			bnToast( fmt( t( 'toastConnectionSent', 'Connection request sent to @%s' ), name ), { tone: 'success' } );
		} catch ( e ) {
			card.dataset.connection = 'none';
			paintConnectBtn( btn, 'none' );
			toastRestError( e, fmt( t( 'toastCouldNotSendRequest', 'Could not send request to @%s. Try again.' ), name ) );
		}
		return;
	}

	if ( cur === 'pending-sent' || cur === 'accepted' ) {
		// Same rule as the server-rendered card (toggleConnection): removing a
		// connection is confirmed, withdrawing a pending request is not.
		if ( cur === 'accepted' && ! await bnConfirmDisconnect( card.dataset.displayName || '' ) ) { return; }
		card.dataset.connection = 'none';
		paintConnectBtn( btn, 'none' );
		try {
			const res = await restFetch( endpoint, { method: 'DELETE', base: cfg.restUrl || undefined, nonce: cfg.restNonce, toastOnError: false } );
			if ( ! res.ok ) { throw res; }
			bnToast( cur === 'accepted' ? fmt( t( 'toastDisconnected', 'Disconnected from @%s' ), name ) : fmt( t( 'toastRequestWithdrawn', 'Request to @%s withdrawn' ), name ), { tone: 'info' } );
		} catch ( e ) {
			const real = 404 === e.status ? 'none' : cur;
			card.dataset.connection = real;
			paintConnectBtn( btn, real );
			toastRestError( e, t( 'toastCouldNotUpdateConnection', 'Could not update connection. Try again.' ) );
		}
	}
}

async function delegatedDecide( card, accept, cfg ) {
	const uid  = parseInt( card.dataset.userId, 10 ) || 0;
	const name = cardHandle( card );
	const prev = card.dataset.connection || 'pending-received';
	const decideWrap = card.querySelector( '.bn-md-card__connect-decide' );
	const primaryBtn = card.querySelector( '.bn-md-card__connect-primary' );
	card.dataset.connection = accept ? 'accepted' : 'none';
	if ( decideWrap ) { decideWrap.hidden = true; }
	if ( primaryBtn ) { paintConnectBtn( primaryBtn, card.dataset.connection ); }
	try {
		const res = await restFetch( '/users/' + uid + '/connect/' + ( accept ? 'accept' : 'decline' ),
			{ method: 'POST', base: cfg.restUrl || undefined, nonce: cfg.restNonce, toastOnError: false } );
		if ( ! res.ok ) { throw res; }
		bnToast( accept ? fmt( t( 'toastConnectedWith', 'Connected with @%s' ), name ) : fmt( t( 'toastRequestDeclined', 'Request from @%s declined' ), name ), { tone: accept ? 'success' : 'info' } );
	} catch ( e ) {
		// 404: the other person withdrew it, so going back to "pending" would be stale.
		const real = 404 === e.status ? 'none' : prev;
		card.dataset.connection = real;
		if ( decideWrap ) { decideWrap.hidden = real !== 'pending-received'; }
		if ( primaryBtn ) { paintConnectBtn( primaryBtn, real ); }
		toastRestError( e, accept ? t( 'toastCouldNotAccept', 'Could not accept request. Try again.' ) : t( 'toastCouldNotDecline', 'Could not decline request. Try again.' ) );
	}
}

function delegatedKebab( card, btn ) {
	const pop = card.querySelector( '.bn-md-card__menu-pop' );
	if ( ! pop ) { return; }
	const open = pop.hidden;
	pop.hidden = ! open;
	btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
}

function delegatedCloseKebab( card ) {
	const pop = card.querySelector( '.bn-md-card__menu-pop' );
	const btn = card.querySelector( '.bn-md-card__menu' );
	if ( pop ) { pop.hidden = true; }
	if ( btn ) { btn.setAttribute( 'aria-expanded', 'false' ); }
}

async function delegatedMute( card, btn, cfg ) {
	const uid  = parseInt( card.dataset.userId, 10 ) || 0;
	const name = cardHandle( card );
	const was  = card.dataset.muted === '1';
	delegatedCloseKebab( card );
	card.dataset.muted = was ? '0' : '1';
	btn.textContent = was ? t( 'mute', 'Mute' ) : t( 'unmute', 'Unmute' );
	try {
		const res = await restFetch( '/users/' + uid + '/mute', {
			method: was ? 'DELETE' : 'POST', base: cfg.restUrl || undefined, nonce: cfg.restNonce, toastOnError: false,
		} );
		if ( ! res.ok ) { throw res; }
		bnToast( was ? fmt( t( 'toastUnmuted', 'Unmuted @%s' ), name ) : fmt( t( 'toastMuted', 'Muted @%s' ), name ), { tone: 'success' } );
	} catch ( e ) {
		card.dataset.muted = was ? '1' : '0';
		btn.textContent = was ? t( 'unmute', 'Unmute' ) : t( 'mute', 'Mute' );
		toastRestError( e, t( 'toastCouldNotUpdateMute', 'Could not update mute state. Try again.' ) );
	}
}

/* Kebab (secondary actions) pinned to the card's top-right. Mirrors the
   markup in templates/parts/member-card.php. Buttons carry plain data-bn-action
   verbs; the delegated onGridClick handler drives them (server cards use the
   hydrated Interactivity actions — both share the same verbs). */
function buildKebab( item ) {
	const menuWrap = document.createElement( 'div' );
	menuWrap.className = 'bn-md-card__menu-wrap';

	const menuBtn = document.createElement( 'button' );
	menuBtn.type = 'button';
	menuBtn.className = 'bn-md-card__menu';
	menuBtn.setAttribute( 'aria-label', t( 'ariaMoreActions', 'More actions' ) );
	menuBtn.setAttribute( 'aria-haspopup', 'true' );
	menuBtn.setAttribute( 'aria-expanded', 'false' );
	menuBtn.setAttribute( 'data-bn-action', 'kebab' );
	menuBtn.appendChild( buildKebabIcon() );
	menuWrap.appendChild( menuBtn );

	const menuPop = document.createElement( 'div' );
	menuPop.className = 'bn-md-card__menu-pop';
	menuPop.setAttribute( 'role', 'menu' );
	menuPop.hidden = true;

	const itemsCfg = [];
	if ( ( ( item.connection && item.connection.state ) || 'none' ) === 'accepted' && item.messages_url ) {
		itemsCfg.push( { label: t( 'message', 'Message' ), verb: '', href: item.messages_url } );
	}
	itemsCfg.push(
		{ label: item.is_muted ? t( 'unmute', 'Unmute' ) : t( 'mute', 'Mute' ), verb: 'mute', danger: false, mute: true },
		{ label: t( 'block', 'Block' ),  verb: 'block',  danger: true },
		{ label: t( 'report', 'Report' ), verb: 'report', danger: true }
	);
	itemsCfg.forEach( ( cfg ) => {
		const b = document.createElement( cfg.href ? 'a' : 'button' );
		if ( cfg.href ) {
			b.href = cfg.href;
		} else {
			b.type = 'button';
			b.setAttribute( 'data-bn-action', cfg.verb );
		}
		b.className = 'bn-md-card__menu-item' + ( cfg.danger ? ' bn-md-card__menu-item--danger' : '' ) + ( cfg.mute ? ' bn-md-card__mute' : '' );
		b.setAttribute( 'role', 'menuitem' );
		b.textContent = cfg.label;
		if ( 'report' !== cfg.verb ) {
			menuPop.appendChild( b );
			return;
		}
		// Same Report / Reported pair as the server card (parts/member-card.php).
		const slot = document.createElement( 'span' );
		slot.className = 'bn-report-slot';
		slot.setAttribute( 'data-bn-report-slot', '' );
		slot.setAttribute( 'role', 'none' );
		b.setAttribute( 'data-bn-report', '' );
		b.hidden = !! item.has_reported;
		const done = document.createElement( 'span' );
		done.className = 'bn-md-card__menu-item bn-md-card__menu-item--reported';
		done.setAttribute( 'role', 'menuitem' );
		done.setAttribute( 'aria-disabled', 'true' );
		done.setAttribute( 'aria-label', t( 'youReportedMember', 'You reported this member' ) );
		done.setAttribute( 'data-bn-reported', '' );
		done.textContent = t( 'reported', 'Reported' );
		done.hidden = ! item.has_reported;
		slot.appendChild( b );
		slot.appendChild( done );
		menuPop.appendChild( slot );
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
		ctx.error    = t( 'errorLoadMembers', 'Could not load members. Check your connection and try again.' );
	} finally {
		ctx.loading   = false;
		ctx.searching = false;
	}
}

/* -- Store ------------------------------------------------------------- */

const membersStore = store( 'buddynext/members', {
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

		get cardFollowState()   { const c = getContext(); return followStateOf( c.isFollowing, c.followPending ); },
		get cardFollowVariant() { const c = getContext(); return c.isFollowing || c.followPending ? 'secondary' : 'primary'; },
		get cardFollowLabel()   { const c = getContext(); return followLabelFor( followStateOf( c.isFollowing, c.followPending ) ); },
		get cardFollowAria()    { return getContext().followPending ? t( 'ariaCancelRequest', 'Cancel follow request' ) : undefined; },

		// Every connection state renders secondary; the state itself is carried by
		// data-state, which is what the CSS varies on. This read as a branch with
		// two identical arms, which invites someone to "fix" one of them.
		get cardConnectVariant() { return 'secondary'; },
		get cardConnectState() { return getContext().connection || 'none'; },
		get cardConnectLabel() {
			const s = getContext().connection;
			if ( s === 'accepted' )     { return t( 'connected', 'Connected' ); }
			if ( s === 'pending-sent' ) { return t( 'requested', 'Requested' ); }
			return t( 'connect', 'Connect' );
		},
		get cardShowConnect() {
			const s = getContext().connection;
			return s === 'none' || s === 'pending-sent' || s === 'accepted';
		},
		get cardShowReceived() { return getContext().connection === 'pending-received'; },
		get cardMenuOpen()     { return !! getContext().menuOpen; },
		get cardMenuExpanded() { return getContext().menuOpen ? 'true' : 'false'; },
		get cardMuteLabel()    { return getContext().isMuted ? t( 'unmute', 'Unmute' ) : t( 'mute', 'Mute' ); },
	},
	callbacks: {
		init() {
			applyViewClass( readView() );
			if ( typeof document !== 'undefined' && ! document.__bnMembersOutsideBound ) {
				document.addEventListener( 'click', ( ev ) => {
					if ( ! ev.target ) { return; }
					// JS-BUILT cards only (data-bn-imperative): these are not hydrated
					// by the Interactivity API (the hydration gap onGridClick handles
					// via delegated dispatch), so their kebab is driven imperatively
					// via pop.hidden. Server-rendered cards are hydrated and close
					// through the closeCardMenuOnOutside action instead — touching
					// pop.hidden on them here would bypass the reactive binding and
					// desync state.cardMenuOpen (the "blink"/stuck-menu bug).
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
			// The filter bar renders member types as a CHIP ROW (matching the
			// Spaces directory), so the slug comes off the clicked chip's
			// data-type-slug. The `value` branch below is kept for any other
			// control a theme may still supply - a <select> override, say - since
			// reading it costs one line and losing it would break that silently.
			const pill = typeof target.closest === 'function' ? target.closest( '[data-type-slug]' ) : null;
			if ( pill ) {
				ctx.memberType = pill.dataset.typeSlug || '';
			} else if ( 'value' in target ) {
				ctx.memberType = target.value || '';
			} else {
				return;
			}
			// Light exactly one chip. aria-selected (not aria-pressed) because these
			// are tabs in a tablist, the same role the Spaces category chips use -
			// a screen reader should hear one selected tab, not five toggle buttons
			// each reporting their own pressed state.
			document.querySelectorAll( '.bn-md-chips [data-type-slug]' ).forEach( ( chip ) => {
				chip.setAttribute( 'aria-selected', ( chip.dataset.typeSlug || '' ) === ctx.memberType ? 'true' : 'false' );
			} );
			// A theme-supplied <select> override, if one exists, stays in step.
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
			// Reset must un-light the type chips too, or the row keeps showing a
			// filter that is no longer applied - the control and the results would
			// disagree, which is worse than not having a reset.
			document.querySelectorAll( '.bn-md-chips [data-type-slug]' ).forEach( ( chip ) => {
				chip.setAttribute( 'aria-selected', '' === ( chip.dataset.typeSlug || '' ) ? 'true' : 'false' );
			} );
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

		/* -- Delegated card dispatch (JS-built cards) ------------------ *
		 * One click handler on the hydrated grid wrapper drives every card
		 * rebuilt by renderGrid() after a filter/tab change. Those cards are
		 * appended at runtime, so the Interactivity API never hydrates their
		 * directives — the same hydration gap buddynext/messages onThreadClick
		 * solves with delegated dispatch. Buttons carry a data-bn-action verb;
		 * per-card reactive state lives in data-* on the card (single source of
		 * truth). Server-rendered cards (initial load) are fully hydrated and
		 * handle their own clicks through actions.toggleFollow/etc., so this
		 * delegate early-returns for any card NOT marked data-bn-imperative to
		 * avoid double-firing. */
		onGridClick( event ) {
			const trigger = event.target.closest( '[data-bn-action]' );
			if ( ! trigger ) { return; }
			const card = trigger.closest( '.bn-md-card[data-user-id]' );
			// Only JS-built cards are driven here; hydrated server cards self-handle.
			if ( ! card || card.dataset.bnImperative !== '1' ) { return; }

			const verb = trigger.dataset.bnAction;
			const ctx  = getContext();
			const cfg  = { restUrl: ctx.restUrl, restNonce: restNonce( ctx ) };

			switch ( verb ) {
				case 'follow':  delegatedFollow( card, trigger, cfg ); break;
				case 'connect': delegatedConnect( card, trigger, cfg ); break;
				case 'accept':  delegatedDecide( card, true, cfg ); break;
				case 'decline': delegatedDecide( card, false, cfg ); break;
				case 'kebab':   delegatedKebab( card, trigger ); break;
				case 'mute':    delegatedMute( card, trigger, cfg ); break;
				case 'block':   delegatedCloseKebab( card ); blockMember( parseInt( card.dataset.userId, 10 ) || 0, card.dataset.displayName ); break;
				case 'report':  delegatedCloseKebab( card ); reportMember( parseInt( card.dataset.userId, 10 ) || 0, card.querySelector( '[data-bn-report]' ) ); break;
				default: break;
			}
		},

		/* -- Card actions (server-rendered, hydrated cards) ------------ */

		async toggleFollow() {
			const ctx = getContext();
			if ( ctx.busy ) { return; }
			ctx.busy = true;
			const was = followStateOf( ctx.isFollowing, ctx.followPending );
			const now = await sendFollow( ctx.userId, ctx.handle || t( 'memberFallback', 'member' ), was, { restUrl: ctx.restUrl, restNonce: restNonce( ctx ) } );
			ctx.isFollowing   = 'following' === now;
			ctx.followPending = 'pending' === now;
			ctx.busy = false;
		},

		async toggleConnection() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const cur  = ctx.connection || 'none';
			const name = ctx.handle || t( 'memberFallback', 'member' );
			if ( cur === 'pending-received' ) { return; }
			if ( cur === 'none' ) {
				// LinkedIn-style optional note. Cancelling leaves the button as-is.
				const note = await bnResolveConnectNote( {
					body: fmt( t( 'noteBody', 'Add a personal message to your request to @%s, or send it without one.' ), name ),
				} );
				if ( note === null ) { return; }
				ctx.busy = true;
				ctx.connection = 'pending-sent';
				try {
					const res = await restFetch( '/users/' + ctx.userId + '/connect', {
						method:       'POST',
						base:         ctx.restUrl || undefined,
						nonce:        restNonce( ctx ),
						body:         { note: note },
						toastOnError: false,
					} );
					if ( res.ok ) {
						bnToast( fmt( t( 'toastConnectionSent', 'Connection request sent to @%s' ), name ), { tone: 'success' } );
					} else {
						ctx.connection = 'none';
						// rest-client already surfaced the appeal toast for a hold/
						// suspension; only add the generic retry toast otherwise
						// (card 10264293681).
						if ( ! res.suspended ) {
							toastRestError( res, fmt( t( 'toastCouldNotSendRequest', 'Could not send request to @%s. Try again.' ), name ) );
						}
					}
				} finally {
					ctx.busy = false;
				}
				return;
			}
			if ( cur === 'pending-sent' ) {
				ctx.busy = true;
				ctx.connection = 'none';
				try {
					const res = await restFetch( '/users/' + ctx.userId + '/connect', {
						method:       'DELETE',
						base:         ctx.restUrl || undefined,
						nonce:        restNonce( ctx ),
						toastOnError: false,
					} );
					if ( ! res.ok ) { throw res; }
					bnToast( fmt( t( 'toastRequestWithdrawn', 'Request to @%s withdrawn' ), name ), { tone: 'info' } );
				} catch ( e ) {
					ctx.connection = 404 === e.status ? 'none' : 'pending-sent';
					toastRestError( e, t( 'toastCouldNotWithdraw', 'Could not withdraw request. Try again.' ) );
				} finally {
					ctx.busy = false;
				}
				return;
			}
			if ( cur === 'accepted' ) {
				if ( ! await bnConfirmDisconnect( ctx.displayName || '' ) ) { return; }
				ctx.busy = true;
				ctx.connection = 'none';
				try {
					const res = await restFetch( '/users/' + ctx.userId + '/connect', {
						method:       'DELETE',
						base:         ctx.restUrl || undefined,
						nonce:        restNonce( ctx ),
						toastOnError: false,
					} );
					if ( ! res.ok ) { throw res; }
					bnToast( fmt( t( 'toastDisconnected', 'Disconnected from @%s' ), name ), { tone: 'info' } );
				} catch ( e ) {
					ctx.connection = 'accepted';
					toastRestError( e, t( 'toastCouldNotDisconnect', 'Could not disconnect. Try again.' ) );
				} finally {
					ctx.busy = false;
				}
			}
		},

		async acceptConnection() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const name = ctx.handle || t( 'memberFallback', 'member' );
			const prev = ctx.connection;
			ctx.busy = true;
			ctx.connection = 'accepted';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect/accept', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        restNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastConnectedWith', 'Connected with @%s' ), name ), { tone: 'success' } );
			} catch ( e ) {
				ctx.connection = 404 === e.status ? 'none' : prev;
				toastRestError( e, t( 'toastCouldNotAccept', 'Could not accept request. Try again.' ) );
			} finally {
				ctx.busy = false;
			}
		},

		async declineConnection() {
			const ctx  = getContext();
			if ( ctx.busy ) { return; }
			const name = ctx.handle || t( 'memberFallback', 'member' );
			const prev = ctx.connection;
			ctx.busy = true;
			ctx.connection = 'none';
			try {
				const res = await restFetch( '/users/' + ctx.userId + '/connect/decline', {
					method:       'POST',
					base:         ctx.restUrl || undefined,
					nonce:        restNonce( ctx ),
					toastOnError: false,
				} );
				if ( ! res.ok ) { throw res; }
				bnToast( fmt( t( 'toastRequestDeclined', 'Request from @%s declined' ), name ), { tone: 'info' } );
			} catch ( e ) {
				ctx.connection = 404 === e.status ? 'none' : prev;
				toastRestError( e, t( 'toastCouldNotDecline', 'Could not decline request. Try again.' ) );
			} finally {
				ctx.busy = false;
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
			const name = ctx.handle || t( 'memberFallback', 'member' );
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
				if ( ! res.ok ) { throw res; }
				bnToast( was ? fmt( t( 'toastUnmuted', 'Unmuted @%s' ), name ) : fmt( t( 'toastMuted', 'Muted @%s' ), name ), { tone: 'success' } );
			} catch ( e ) {
				ctx.isMuted = was;
				toastRestError( e, t( 'toastCouldNotUpdateMute', 'Could not update mute state. Try again.' ) );
			}
		},

		/* -- Cross-surface dialogs (Block + Report) ------------------- *
		 * The shared bnBlockConfirm / bnReportDialog, the same as the profile
		 * page and the message thread, so there is one wording and one behaviour. */

		openBlock() {
			const ctx = getContext();
			ctx.menuOpen = false;
			blockMember( ctx.userId, ctx.displayName );
		},

		openReport() {
			const ctx = getContext();
			ctx.menuOpen = false;
			reportMember( ctx.userId, getElement().ref );
		},
	},
} );

// The server merges the injected dictionary into this namespace's state; read it
// once here so every helper + action above resolves translated copy at runtime.
I18N = ( membersStore.state && membersStore.state.i18n ) || {};

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

async function blockMember( userId, displayName ) {
	if ( ! userId || ! await bnBlockConfirm( displayName ) ) { return; }
	const { restUrl, restNonce: nonce } = getModalSettings();
	const name = displayName || t( 'memberFallback', 'member' );
	try {
		const res = await restFetch(
			'/users/' + userId + '/block',
			{ method: 'POST', base: restUrl || undefined, nonce: nonce, toastOnError: false }
		);
		if ( ! res.ok ) { throw res; }
		bnToast( fmt( t( 'toastBlocked', '%s blocked' ), name ), { tone: 'success' } );
		const card = document.querySelector( '.bn-md-card[data-user-id="' + userId + '"]' );
		if ( card ) { card.remove(); }
	} catch ( e ) {
		toastRestError( e, t( 'toastCouldNotBlock', 'Could not block. Try again.' ) );
	}
}

async function reportMember( userId, trigger ) {
	if ( ! userId ) { return; }
	const result = await bnReportDialog( { title: t( 'reportProfileTitle', 'Report this profile' ) } );
	if ( ! result ) { return; }
	const { restUrl, restNonce: nonce } = getModalSettings();
	try {
		const res = await restFetch(
			'/reports',
			{
				method:       'POST',
				base:         restUrl || undefined,
				nonce:        nonce,
				toastOnError: false,
				body:         { object_type: 'user', object_id: userId, reason: result.reason || 'other', notes: result.notes || '' },
			}
		);
		const data = res.data || {};
		// 409 = already reported: the report is on file, so show it as reported too.
		if ( ! res.ok && 409 !== res.status ) {
			toastRestError( res, t( 'toastCouldNotReport', 'Could not submit report. Try again.' ) );
			return;
		}
		const done = t( 'toastReportSubmitted', 'Report submitted. Thanks for keeping the community safe.' );
		bnMarkReported( trigger );
		bnToast( res.ok ? done : ( data.message || done ), { tone: res.ok ? 'success' : 'info' } );
	} catch ( _e ) {
		bnToast( t( 'toastCouldNotReport', 'Could not submit report. Try again.' ), { tone: 'danger' } );
	}
}

onNavReady( () => { applyViewClass( readView() ); } );

export default membersStore;
