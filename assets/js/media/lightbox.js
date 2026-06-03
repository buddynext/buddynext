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
			download: overlay.querySelector( '[data-bn-lb-download]' ),
			open:     overlay.querySelector( '[data-bn-lb-open]' ),
			form:     overlay.querySelector( '[data-bn-lb-comment-form]' ),
			input:    overlay.querySelector( '[data-bn-lb-comment-input]' ),
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
		opts.headers = Object.assign( { 'X-WP-Nonce': cfg.nonce || '' }, opts.headers || {} );
		if ( opts.json ) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify( opts.json );
			delete opts.json;
		}
		return fetch( REST + path, opts ).then( function ( r ) {
			return r.ok ? r.json().catch( function () { return {}; } ) : Promise.reject( r );
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
	}

	// ── Panel ───────────────────────────────────────────────────────────────
	function clear( node ) { if ( node ) { while ( node.firstChild ) { node.removeChild( node.firstChild ); } } }

	function loadPanel( id ) {
		current = id;
		// reset transient state
		resetReactions();
		clear( panel.author );
		clear( panel.comments );
		if ( panel.views ) { panel.views.textContent = ''; }
		if ( panel.favorite ) { panel.favorite.setAttribute( 'aria-pressed', 'false' ); }

		// Media meta (author, views, urls).
		api( '/media/' + id ).then( function ( m ) {
			if ( current !== id ) { return; }
			renderAuthor( m );
			var views = ( m.stats && m.stats.views ) || 0;
			if ( panel.views ) {
				panel.views.textContent = views + ' ' + ( 1 === views ? ( I18N.view || 'view' ) : ( I18N.views || 'views' ) );
			}
			// Open + Download both target the media file (signed URL) — never the
			// MediaVerse /media/ single page. That page is MediaVerse's own UX;
			// BuddyNext owns the UX and must not send users into the engine UI.
			if ( m.file_url ) {
				if ( panel.download ) { panel.download.setAttribute( 'href', m.file_url ); }
				if ( panel.open ) { panel.open.setAttribute( 'href', m.file_url ); }
			}
		} ).catch( function () {} );

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

	function renderAuthor( m ) {
		if ( ! panel.author ) { return; }
		clear( panel.author );
		var av = m.author_avatar || ( m.author_data && m.author_data.avatar ) || '';
		var name = m.author_name || ( m.author_data && m.author_data.name ) || '';
		if ( av && /^(https?:)?\//.test( av ) ) {
			var img = document.createElement( 'img' );
			img.className = 'bn-lightbox__author-avatar';
			img.setAttribute( 'src', av );
			img.setAttribute( 'alt', '' );
			panel.author.appendChild( img );
		}
		var sp = document.createElement( 'span' );
		sp.className = 'bn-lightbox__author-name';
		sp.textContent = name;
		panel.author.appendChild( sp );
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

	function favorite() {
		if ( ! requireLogin() || ! current ) { return; }
		var btn = panel.favorite;
		var on = btn.getAttribute( 'aria-pressed' ) === 'true';
		btn.setAttribute( 'aria-pressed', on ? 'false' : 'true' ); // optimistic
		api( '/media/' + current + '/favorite', { method: 'POST' } ).then( function ( r ) {
			if ( r && typeof r.favorited !== 'undefined' ) {
				btn.setAttribute( 'aria-pressed', r.favorited ? 'true' : 'false' );
			}
		} ).catch( function () { btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); } );
	}

	function share() {
		// Share the BuddyNext page the media lives on (post / profile) — never a
		// signed (expiring) file URL or the MediaVerse /media/ page.
		var url = window.location.href;
		if ( navigator.share ) {
			navigator.share( { url: url } ).catch( function () {} );
		} else if ( navigator.clipboard ) {
			navigator.clipboard.writeText( url ).then( function () {
				if ( window.bnToast ) { window.bnToast( 'Link copied', 'success' ); }
			} ).catch( function () {} );
		}
	}

	function renderComments( list ) {
		clear( panel.comments );
		if ( ! list.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'bn-lightbox__comments-empty';
			empty.textContent = I18N.noComments || 'No comments yet.';
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
		if ( window.bnToast ) { window.bnToast( I18N.loginPrompt || 'Log in to interact.', 'info' ); }
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
