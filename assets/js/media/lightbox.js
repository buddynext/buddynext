/* BuddyNext — interactive media lightbox.
 *
 * BN-owned UX (no WPMediaVerse JS). Binds to media tiles rendered by
 * BuddyNext\Media\MediaRenderer (`[data-bn-media-id]` image/video buttons),
 * opens the BN lightbox shell (templates/partials/media-lightbox.php) with a
 * media stage + an interaction panel, and drives the panel (author, views,
 * reactions, favorite, share, download, open, comments) by calling the engine
 * REST routes (mvs/v1/media/{id}/...) — API level only. Config + nonce come
 * from the localized `bnMedia` object.
 */
( function () {
	'use strict';

	// Translation: classic script reading from the global wp.i18n with a safe
	// identity fallback (bn-media-lightbox declares wp-i18n + wp_set_script_translations).
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	var cfg = window.bnMedia || {};
	var REST = ( cfg.mvsRest || '' ).replace( /\/$/, '' );
	var I18N = cfg.i18n || {};
	var LOGGED_IN = ( cfg.userId || 0 ) > 0;

	var overlay = null;
	var stage = null;
	var panel = {};
	var gallery = [];   // [{id,type,src,poster,alt}]
	var index = 0;
	var lastFocus = null;
	var current = null; // current media id

	function shell() {
		if ( overlay ) { return overlay; }
		overlay = document.querySelector( '.bn-lightbox' );
		if ( ! overlay ) { return null; }
		stage = overlay.querySelector( '[data-bn-lb-stage]' );
		panel = {
			author:   overlay.querySelector( '[data-bn-lb-author]' ),
			views:    overlay.querySelector( '[data-bn-lb-views]' ),
			comments: overlay.querySelector( '[data-bn-lb-comments]' ),
			favorite: overlay.querySelector( '[data-bn-lb-favorite]' ),
			report:   overlay.querySelector( '[data-bn-lb-report]' ),
			block:    overlay.querySelector( '[data-bn-lb-block]' ),
			download: overlay.querySelector( '[data-bn-lb-download]' ),
			form:     overlay.querySelector( '[data-bn-lb-comment-form]' ),
			input:    overlay.querySelector( '[data-bn-lb-comment-input]' ),
			// DM full-bleed chrome (sender + download float over the stage; the
			// side panel is hidden for private 1:1 media).
			dmAuthor:   overlay.querySelector( '[data-bn-lb-dm-author]' ),
			dmDownload: overlay.querySelector( '[data-bn-lb-dm-download]' ),
		};

		// Delegated controls (close / prev / next).
		overlay.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-bn-lb-close],[data-bn-lb-prev],[data-bn-lb-next]' );
			if ( ! t ) { return; }
			if ( t.hasAttribute( 'data-bn-lb-close' ) ) { close(); }
			else if ( t.hasAttribute( 'data-bn-lb-prev' ) ) { step( -1 ); }
			else if ( t.hasAttribute( 'data-bn-lb-next' ) ) { step( 1 ); }
		} );

		// Reactions.
		overlay.querySelectorAll( '.bn-lightbox__reaction' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { react( btn.getAttribute( 'data-reaction' ) ); } );
		} );
		// Favorite + share.
		if ( panel.favorite ) { panel.favorite.addEventListener( 'click', favorite ); }
		// Only wire Report if the shared dialog is actually on the page. The button stays
		// hidden otherwise — a missing control beats one that does nothing when clicked.
		if ( panel.report && typeof window.bnReportDialog === 'function' ) {
			panel.report.addEventListener( 'click', report );
		}
		if ( panel.block ) { panel.block.addEventListener( 'click', blockAuthor ); }
		var shareBtn = overlay.querySelector( '[data-bn-lb-share]' );
		if ( shareBtn ) { shareBtn.addEventListener( 'click', share ); }
		// Comment submit.
		if ( panel.form ) {
			panel.form.addEventListener( 'submit', function ( e ) { e.preventDefault(); addComment(); } );
		}
		return overlay;
	}

	// ── REST ────────────────────────────────────────────────────────────────
	function api( path, opts ) {
		opts = opts || {};
		var init = {
			base: REST,
			nonce: cfg.nonce || '',
			method: opts.method,
			toastOnError: false,
		};
		if ( opts.json ) {
			init.body = opts.json;
		}
		return window.buddynextRest.restFetch( path, init ).then( function ( res ) {
			return res.ok ? ( res.data || {} ) : Promise.reject( res );
		} );
	}

	// ── Media stage ───────────────────────────────────────────────────────────
	function tileToItem( tile ) {
		var img = tile.querySelector( '.bn-media-tile__img' );
		return {
			id:     parseInt( tile.getAttribute( 'data-bn-media-id' ), 10 ) || 0,
			type:   tile.getAttribute( 'data-media-type' ) || 'image',
			src:    tile.getAttribute( 'data-media-src' ) || ( img ? img.src : '' ),
			poster: img ? img.src : '',
			alt:    tile.getAttribute( 'aria-label' ) || '',
			// Source post the media came from (present only when it was posted).
			// Drives Share: with a post id the lightbox opens that post's rich
			// Share modal; without one it falls back to Copy link.
			postId:    parseInt( tile.getAttribute( 'data-post-id' ), 10 ) || 0,
			permalink: tile.getAttribute( 'data-post-permalink' ) || '',
			// Private DM media: the lightbox hides social chrome (reactions,
			// comments, favorite, share) and skips those REST calls.
			dm:     '1' === tile.getAttribute( 'data-bn-dm' ),
		};
	}

	function renderMedia() {
		if ( ! stage || ! gallery.length ) { return; }
		var item = gallery[ index ];
		while ( stage.firstChild ) { stage.removeChild( stage.firstChild ); }
		var el;
		if ( 'video' === item.type ) {
			el = document.createElement( 'video' );
			el.controls = true; el.autoplay = true; el.playsInline = true;
			if ( item.poster ) { el.setAttribute( 'poster', item.poster ); }
			el.setAttribute( 'src', item.src );
		} else {
			el = document.createElement( 'img' );
			el.setAttribute( 'src', item.src );
			el.setAttribute( 'alt', item.alt || '' );
		}
		el.className = 'bn-lightbox__media';
		stage.appendChild( el );
		overlay.classList.toggle( 'bn-lightbox--has-nav', gallery.length > 1 );
		// Private DM media: switch the lightbox into its slimmed (no social
		// chrome) mode for this item.
		overlay.classList.toggle( 'bn-lightbox--dm', !! item.dm );
	}

	// ── Panel ───────────────────────────────────────────────────────────────
	function clear( node ) { if ( node ) { while ( node.firstChild ) { node.removeChild( node.firstChild ); } } }

	function loadPanel( id ) {
		current = id;
		// Private DM media has no social layer — skip reactions/comments/favorite/
		// views entirely (the chrome is also hidden via .bn-lightbox--dm).
		var isDM = !! ( gallery[ index ] && gallery[ index ].dm );
		// reset transient state
		resetReactions();
		clear( panel.author );
		clear( panel.dmAuthor );
		clear( panel.comments );
		if ( panel.views ) { panel.views.textContent = ''; }
		setFavorite( false );

		// Media meta (author, views, urls). DM media renders into the floating
		// stage chrome (sender + download); social media into the side panel.
		api( '/media/' + id ).then( function ( m ) {
			if ( current !== id ) { return; }
			renderAuthor( m, isDM ? panel.dmAuthor : panel.author );
			applyAbuseControls( m );
			if ( ! isDM ) {
				var views = ( m.stats && m.stats.views ) || 0;
				if ( panel.views ) {
					panel.views.textContent = views + ' ' + ( 1 === views ? ( I18N.view || 'view' ) : ( I18N.views || 'views' ) );
				}
			}
			// Open + Download both target the media file (signed URL) — never the
			// MediaVerse /media/ single page. That page is MediaVerse's own UX;
			// BuddyNext owns the UX and must not send users into the engine UI.
			if ( m.file_url ) {
				var dl = isDM ? panel.dmDownload : panel.download;
				if ( dl ) { dl.setAttribute( 'href', m.file_url ); }
			}
		} ).catch( function () {} );

		// DM media: no favorite / reactions / comments / view tracking. The image
		// and author (from the meta fetch above) are all that show.
		if ( isDM ) { return; }

		// Guests can only view + download: the interaction controls aren't rendered
		// for logged-out visitors (see media-lightbox.php) and every call below is
		// auth-only, so skip them (no 401 noise, no null panel) and just track the
		// view best-effort — mirrors the DM skip above.
		if ( ! LOGGED_IN ) {
			api( '/media/' + id + '/view', { method: 'POST' } ).catch( function () {} );
			return;
		}

		// Favorite status — reflect the real state on open (the reset above is
		// just the optimistic default until this resolves).
		if ( panel.favorite ) {
			api( '/media/' + id + '/favorite' ).then( function ( r ) {
				if ( current === id && r && typeof r.favorited !== 'undefined' ) {
					setFavorite( !! r.favorited );
				}
			} ).catch( function () {} );
		}

		// Reactions.
		api( '/media/' + id + '/reactions' ).then( function ( r ) {
			if ( current === id ) { applyReactions( r ); }
		} ).catch( function () {} );

		// Comments.
		api( '/media/' + id + '/comments' ).then( function ( list ) {
			if ( current === id ) { renderComments( Array.isArray( list ) ? list : ( list.comments || [] ) ); }
		} ).catch( function () { renderComments( [] ); } );

		// View tracking (best-effort; logged-in or not).
		api( '/media/' + id + '/view', { method: 'POST' } ).catch( function () {} );
	}

	// The uploader of the media currently open. Needed for Block (which blocks the MEMBER, not
	// the file) and to hide both controls on your own media — nobody reports themselves.
	var currentAuthorId = 0;

	function applyAbuseControls( m ) {
		// WPMediaVerse's media payload carries the uploader's numeric id as `author`
		// (MediaController: `'author' => $author_id_raw`, and its own code reads it back as
		// `$author_id = $data['author']`). Read THAT field — do not invent an alias for it.
		//
		// This first shipped keyed on `m.author_id`, which no released WPMediaVerse emits: the
		// id came back undefined, currentAuthorId fell to 0, `mine` was therefore true, and BOTH
		// controls stayed hidden on every site. It passed my own browser check only because the
		// plugin dir here is a symlink to a working copy in which I had added the alias — the
		// one environment on earth where it worked. QA caught it by reading the INSTALLED code.
		currentAuthorId = parseInt( ( m && m.author ) || 0, 10 ) || 0;

		var mine = ! currentAuthorId || currentAuthorId === ( parseInt( cfg.userId, 10 ) || 0 );
		// cfg.canReport mirrors WPMediaVerse's `mvs_reports_enabled` filter. If a site turns
		// reporting off, the endpoint answers 403 — so the button must not be there at all.
		var canReport = LOGGED_IN && ! mine && !! cfg.canReport && typeof window.bnReportDialog === 'function';
		var canBlock  = LOGGED_IN && ! mine && currentAuthorId > 0;

		if ( panel.report ) { panel.report.hidden = ! canReport; }
		if ( panel.block ) { panel.block.hidden = ! canBlock; }
	}

	function renderAuthor( m, target ) {
		if ( ! target ) { return; }
		clear( target );
		var av = m.author_avatar || ( m.author_data && m.author_data.avatar ) || '';
		var name = m.author_name || ( m.author_data && m.author_data.name ) || '';
		// The REST API resolves this to the member profile (via BuddyNext's
		// mvs_user_profile_url filter), so link the author there instead of
		// leaving the name as plain text / the raw /media/@{login}/ fallback.
		var url = m.author_url || ( m.author_data && m.author_data.profile_url ) || '';

		var holder = url ? document.createElement( 'a' ) : document.createElement( 'span' );
		if ( url ) { holder.setAttribute( 'href', url ); }
		holder.className = 'bn-lightbox__author-link';

		if ( av && /^(https?:)?\//.test( av ) ) {
			var img = document.createElement( 'img' );
			img.className = 'bn-lightbox__author-avatar';
			img.setAttribute( 'src', av );
			img.setAttribute( 'alt', '' );
			holder.appendChild( img );
		}
		var sp = document.createElement( 'span' );
		sp.className = 'bn-lightbox__author-name';
		sp.textContent = name;
		holder.appendChild( sp );

		target.appendChild( holder );
	}

	function resetReactions() {
		overlay.querySelectorAll( '.bn-lightbox__reaction' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', 'false' );
			btn.classList.remove( 'is-active' );
			var c = btn.querySelector( '[data-bn-lb-reaction-count]' );
			if ( c ) { c.hidden = true; c.textContent = '0'; }
		} );
	}

	function applyReactions( r ) {
		var counts = ( r && r.counts ) || {};
		var mine = r && r.user_reaction;
		overlay.querySelectorAll( '.bn-lightbox__reaction' ).forEach( function ( btn ) {
			var type = btn.getAttribute( 'data-reaction' );
			var n = parseInt( counts[ type ], 10 ) || 0;
			var c = btn.querySelector( '[data-bn-lb-reaction-count]' );
			if ( c ) { c.textContent = n; c.hidden = n <= 0; }
			var active = ( mine === type );
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
	}

	function react( type ) {
		if ( ! requireLogin() || ! current ) { return; }
		var btn = overlay.querySelector( '.bn-lightbox__reaction[data-reaction="' + type + '"]' );
		var wasActive = btn && btn.classList.contains( 'is-active' );
		var req = wasActive
			? api( '/media/' + current + '/reactions', { method: 'DELETE' } )
			: api( '/media/' + current + '/reactions', { method: 'POST', json: { reaction_type: type } } );
		var id = current;
		req.then( function () {
			return api( '/media/' + id + '/reactions' );
		} ).then( function ( r ) {
			if ( current === id ) { applyReactions( r ); }
		} ).catch( function () {} );
	}

	// Single source of truth for the favorite affordance: the pressed state
	// drives both the filled-heart styling (CSS [aria-pressed="true"]) and the
	// label, which swaps Favorite ↔ Favorited so the toggle is unmistakable.
	function setFavorite( on ) {
		var btn = panel.favorite;
		if ( ! btn ) { return; }
		btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		var label = btn.querySelector( 'span' );
		if ( label ) {
			label.textContent = on
				? ( I18N.favorited || 'Favorited' )
				: ( I18N.favorite || 'Favorite' );
		}
	}

	/*
	 * Report a piece of media.
	 *
	 * Posts to WPMediaVerse's OWN queue (mvs/v1/media/{id}/report) — the same queue its native
	 * media page reports into, and the one moderators already work. BuddyNext does not keep a
	 * second media-report store; the engine owns media.
	 *
	 * The reason list is MVS's enum, not BuddyNext's: MVS accepts nudity / violence / copyright
	 * and has no inappropriate / impersonation, so posting our own set would be rejected as an
	 * invalid reason.
	 */
	function report() {
		if ( ! requireLogin() || ! current ) { return; }

		var reasons = [
			[ 'spam',           __( 'Spam', 'buddynext' ) ],
			[ 'harassment',     __( 'Harassment or hate speech', 'buddynext' ) ],
			[ 'nudity',         __( 'Nudity or sexual content', 'buddynext' ) ],
			[ 'violence',       __( 'Violence or graphic content', 'buddynext' ) ],
			[ 'copyright',      __( 'Copyright infringement', 'buddynext' ) ],
			[ 'misinformation', __( 'Misinformation', 'buddynext' ) ],
			[ 'other',          __( 'Something else', 'buddynext' ) ],
		];

		window.bnReportDialog( {
			title: __( 'Report this media', 'buddynext' ),
			reasons: reasons,
		} ).then( function ( result ) {
			if ( ! result ) { return; }

			var mediaId = current;

			return api( '/media/' + mediaId + '/report', {
				method: 'POST',
				json: { reason: result.reason, details: result.notes || '' },
			} ).then( function () {
				notify( __( 'Thanks — this has been sent to the moderators.', 'buddynext' ), 'success' );
			} ).catch( function () {
				notify( __( 'Could not send that report. Try again.', 'buddynext' ), 'error' );
			} );
		} );
	}

	/*
	 * Block the member who uploaded this media — BuddyNext's own social-graph block
	 * (buddynext/v1 users/{id}/block), not an MVS concept. It is the person being blocked, not
	 * the file, so it goes to our namespace rather than the engine's.
	 */
	function blockAuthor() {
		if ( ! requireLogin() || ! currentAuthorId ) { return; }

		var authorId = currentAuthorId;
		var confirmFn = window.bnConfirm;

		var proceed = typeof confirmFn === 'function'
			? confirmFn( {
				title: __( 'Block this member?', 'buddynext' ),
				body: __( 'You will not see their posts or media, and they cannot message you. You can undo this from your settings.', 'buddynext' ),
				confirmLabel: __( 'Block', 'buddynext' ),
				tone: 'danger',
			} )
			// The accessible bnConfirm is exposed on window by shell/dialog.js.
			// If it is somehow not loaded we do NOT fall back to a native
			// window.confirm on a member-facing surface - skip the action, the
			// same way the Report button no-ops when its dialog is absent.
			: Promise.resolve( false );

		Promise.resolve( proceed ).then( function ( ok ) {
			if ( ! ok ) { return; }

			// BuddyNext's namespace, not the media engine's — so it cannot go through api(),
			// which is bound to the mvs/v1 base.
			return window.buddynextRest.restFetch( '/users/' + authorId + '/block', {
				method: 'POST',
				nonce: cfg.nonce || '', // wp_rest — valid for buddynext/v1 and mvs/v1 alike.
				toastOnError: false,
			} ).then( function ( res ) {
				if ( ! res.ok ) { throw res; }
				notify( __( 'Blocked. You will not see their content.', 'buddynext' ), 'success' );
				close();
			} ).catch( function () {
				notify( __( 'Could not block that member. Try again.', 'buddynext' ), 'error' );
			} );
		} );
	}

	function favorite() {
		if ( ! requireLogin() || ! current ) { return; }
		var on = panel.favorite.getAttribute( 'aria-pressed' ) === 'true';
		setFavorite( ! on ); // optimistic
		api( '/media/' + current + '/favorite', { method: 'POST' } ).then( function ( r ) {
			if ( r && typeof r.favorited !== 'undefined' ) {
				setFavorite( !! r.favorited );
			}
		} ).catch( function () { setFavorite( on ); } );
	}

	function share() {
		var item = gallery[ index ] || null;

		// DM media has no social layer (chrome is hidden) — never share it.
		if ( item && item.dm ) { return; }

		// When the media came from a post, open the SAME rich Share modal the
		// post cards use (Repost + Copy link), threading the source post id so
		// Repost works. The modal carries its own wp_rest nonce + buddynext/v1
		// restUrl, so the lightbox only supplies the post id + permalink.
		var modal = document.querySelector( '.bn-share-modal' );
		if ( item && item.postId && modal ) {
			// Hand off to the share modal — close the lightbox first so its
			// full-screen overlay does not sit on top of (and block) the modal.
			var detail = {
				postId:    item.postId,
				permalink: item.permalink || window.location.href,
				author:    '',
				excerpt:   '',
			};
			close();
			document.dispatchEvent( new CustomEvent( 'bn-open-share-modal', { detail: detail } ) );
			return;
		}

		// No source post (e.g. a direct upload) — copy the page link the media
		// lives on. Never a signed (expiring) file URL or the MediaVerse page.
		copyToClipboard( item && item.permalink ? item.permalink : window.location.href );
	}

	// Toast feedback that never goes silent: use the shared bnToast when it is
	// on the page, otherwise render a minimal toast (same DOM contract as
	// shell/extras.js) so a copy on a surface without bn-shell-extras still
	// tells the user it worked.
	function notify( msg, tone ) {
		if ( typeof window.bnToast === 'function' ) {
			window.bnToast( msg, tone );
			return;
		}
		var c = document.querySelector( '.bn-toast-container' );
		if ( ! c ) {
			c = document.createElement( 'div' );
			c.className = 'bn-toast-container';
			document.body.appendChild( c );
		}
		var t = document.createElement( 'div' );
		t.className = 'bn-toast' + ( 'success' === tone ? ' bn-toast--success' : ( 'info' === tone ? ' bn-toast--info' : '' ) );
		t.textContent = msg;
		c.appendChild( t );
		setTimeout( function () { t.remove(); }, 3000 );
	}

	function copyToClipboard( text ) {
		var done = function () {
			notify( __( 'Link copied', 'buddynext' ), 'success' );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done ).catch( function () { legacyCopy( text, done ); } );
		} else {
			legacyCopy( text, done );
		}
	}

	function legacyCopy( text, done ) {
		try {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.top = '-1000px';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			var ok = document.execCommand( 'copy' );
			document.body.removeChild( ta );
			if ( ok ) {
				if ( done ) { done(); }
				return;
			}
		} catch ( e ) {}
		// Last resort (no Clipboard API + execCommand failed): surface the link in
		// a toast for manual copy. No native prompt() — see the UX-audit F8 rule.
		notify( ( I18N.copyManual || 'Copy this link: ' ) + text, 'info' );
	}

	function renderComments( list ) {
		if ( ! panel.comments ) { return; }
		clear( panel.comments );
		if ( ! list.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'bn-lightbox__comments-empty';
			empty.textContent = I18N.noComments || 'No comments yet. Be the first to say something!';
			panel.comments.appendChild( empty );
			return;
		}
		list.forEach( function ( c ) { panel.comments.appendChild( commentEl( c ) ); } );
	}

	function commentEl( c ) {
		var row = document.createElement( 'div' );
		row.className = 'bn-lightbox__comment';
		var name = document.createElement( 'strong' );
		name.className = 'bn-lightbox__comment-author';
		name.textContent = c.author_name || c.author || c.name || '';
		var body = document.createElement( 'span' );
		body.className = 'bn-lightbox__comment-text';
		body.textContent = c.content || c.comment_content || c.text || '';
		row.appendChild( name );
		row.appendChild( body );
		return row;
	}

	function addComment() {
		if ( ! requireLogin() || ! current ) { return; }
		var val = ( panel.input.value || '' ).trim();
		if ( ! val ) { return; }
		var id = current;
		panel.input.value = '';
		api( '/media/' + id + '/comments', { method: 'POST', json: { content: val } } ).then( function () {
			return api( '/media/' + id + '/comments' );
		} ).then( function ( list ) {
			if ( current === id ) { renderComments( Array.isArray( list ) ? list : ( list.comments || [] ) ); }
		} ).catch( function () {} );
	}

	function requireLogin() {
		if ( LOGGED_IN ) { return true; }
		if ( window.bnToast ) { window.bnToast( I18N.loginPrompt || 'Log in to react and comment.', 'info' ); }
		return false;
	}

	// ── Open / close / nav ────────────────────────────────────────────────────
	function open( tiles, startIndex ) {
		if ( ! shell() ) { return; }
		gallery = tiles.map( tileToItem ).filter( function ( i ) { return i.src; } );
		if ( ! gallery.length ) { return; }
		index = Math.max( 0, Math.min( startIndex, gallery.length - 1 ) );
		lastFocus = document.activeElement;
		renderMedia();
		if ( gallery[ index ].id ) { loadPanel( gallery[ index ].id ); }
		overlay.hidden = false;
		document.body.classList.add( 'bn-lightbox-open' );
		var closeBtn = overlay.querySelector( '[data-bn-lb-close].bn-lightbox__close' );
		if ( closeBtn ) { closeBtn.focus(); }
	}

	function close() {
		if ( ! overlay ) { return; }
		overlay.hidden = true;
		document.body.classList.remove( 'bn-lightbox-open' );
		clear( stage ); // stop video playback
		gallery = [];
		current = null;
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function step( delta ) {
		if ( gallery.length < 2 ) { return; }
		index = ( index + delta + gallery.length ) % gallery.length;
		renderMedia();
		if ( gallery[ index ].id ) { loadPanel( gallery[ index ].id ); }
	}

	// Delegated open — image/video tiles only (audio plays inline).
	document.addEventListener( 'click', function ( e ) {
		var tile = e.target.closest( '.bn-media-tile[data-bn-media-id]' );
		if ( ! tile ) { return; }
		var type = tile.getAttribute( 'data-media-type' );
		if ( 'image' !== type && 'video' !== type ) { return; }
		e.preventDefault();
		var grid  = tile.closest( '[data-bn-media-grid]' ) || tile.parentElement;
		var tiles = Array.prototype.slice.call(
			grid.querySelectorAll( '.bn-media-tile[data-bn-media-id]' )
		).filter( function ( t ) {
			var ty = t.getAttribute( 'data-media-type' );
			return 'image' === ty || 'video' === ty;
		} );
		open( tiles, tiles.indexOf( tile ) );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! overlay || overlay.hidden ) { return; }
		// Don't hijack arrows while typing a comment.
		var typing = document.activeElement && document.activeElement.matches( 'input, textarea' );
		if ( 'Escape' === e.key ) { close(); }
		else if ( ! typing && 'ArrowLeft' === e.key ) { step( -1 ); }
		else if ( ! typing && 'ArrowRight' === e.key ) { step( 1 ); }
	} );
}() );
