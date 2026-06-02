/* BuddyNext — native media lightbox.
 *
 * BN-owned UX (no WPMediaVerse JS). Binds to media tiles rendered by
 * BuddyNext\Media\MediaRenderer (`[data-bn-media-id]` image/video buttons),
 * opens a BN lightbox shell (templates/partials/media-lightbox.php), and offers
 * gallery prev/next across the tiles of the same post grid. URLs are the signed
 * `/serve` URLs already on each tile (`data-media-src`); the lightbox never
 * constructs URLs itself.
 */
( function () {
	'use strict';

	var overlay  = null;
	var stage    = null;
	var gallery  = [];   // [{type,src,poster,alt}]
	var index    = 0;
	var lastFocus = null;

	function shell() {
		if ( overlay ) { return overlay; }
		overlay = document.querySelector( '.bn-lightbox' );
		if ( ! overlay ) { return null; }
		stage = overlay.querySelector( '[data-bn-lb-stage]' );
		overlay.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-bn-lb-close],[data-bn-lb-prev],[data-bn-lb-next]' );
			if ( ! t ) { return; }
			if ( t.hasAttribute( 'data-bn-lb-close' ) ) { close(); }
			else if ( t.hasAttribute( 'data-bn-lb-prev' ) ) { step( -1 ); }
			else if ( t.hasAttribute( 'data-bn-lb-next' ) ) { step( 1 ); }
		} );
		return overlay;
	}

	function tileToItem( tile ) {
		var img = tile.querySelector( '.bn-media-tile__img' );
		return {
			type:   tile.getAttribute( 'data-media-type' ) || 'image',
			src:    tile.getAttribute( 'data-media-src' ) || ( img ? img.src : '' ),
			poster: img ? img.src : '',
			alt:    tile.getAttribute( 'aria-label' ) || '',
		};
	}

	function render() {
		if ( ! stage || ! gallery.length ) { return; }
		var item = gallery[ index ];
		// Build via DOM APIs (no innerHTML) — XSS-safe even though src/alt come
		// from BN-produced signed-URL markup.
		while ( stage.firstChild ) { stage.removeChild( stage.firstChild ); }
		var el;
		if ( 'video' === item.type ) {
			el = document.createElement( 'video' );
			el.controls    = true;
			el.autoplay    = true;
			el.playsInline = true;
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

	function open( tiles, startIndex ) {
		if ( ! shell() ) { return; }
		gallery = tiles.map( tileToItem ).filter( function ( i ) { return i.src; } );
		if ( ! gallery.length ) { return; }
		index = Math.max( 0, Math.min( startIndex, gallery.length - 1 ) );
		lastFocus = document.activeElement;
		render();
		overlay.hidden = false;
		document.body.classList.add( 'bn-lightbox-open' );
		var closeBtn = overlay.querySelector( '[data-bn-lb-close]' );
		if ( closeBtn ) { closeBtn.focus(); }
	}

	function close() {
		if ( ! overlay ) { return; }
		overlay.hidden = true;
		document.body.classList.remove( 'bn-lightbox-open' );
		if ( stage ) { stage.innerHTML = ''; } // stop video playback
		gallery = [];
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function step( delta ) {
		if ( gallery.length < 2 ) { return; }
		index = ( index + delta + gallery.length ) % gallery.length;
		render();
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
		if ( 'Escape' === e.key ) { close(); }
		else if ( 'ArrowLeft' === e.key ) { step( -1 ); }
		else if ( 'ArrowRight' === e.key ) { step( 1 ); }
	} );
}() );
