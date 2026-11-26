/**
 * Setup videos tab: plays a film in the page and filters the film cards.
 *
 * Without this script every card is a plain link that opens the film on YouTube.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-bn-videos]' );
	if ( ! root ) {
		return;
	}

	var frame = root.querySelector( '[data-bn-video-frame]' );
	var titleEl = root.querySelector( '[data-bn-video-title]' );
	var metaEl = root.querySelector( '[data-bn-video-meta]' );
	var blurbEl = root.querySelector( '[data-bn-video-blurb]' );
	var siteTheme = root.getAttribute( 'data-theme' );

	// The privacy-friendly YouTube address. Nothing loads from it until a film is played.
	function play( id, title ) {
		var iframe = document.createElement( 'iframe' );
		iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( id ) + '?autoplay=1&rel=0';
		iframe.title = title;
		iframe.allow = 'accelerometer; autoplay; encrypted-media; picture-in-picture; fullscreen';
		iframe.allowFullscreen = true;
		iframe.className = 'bn-videos__player';
		frame.replaceChildren( iframe );
	}

	root.addEventListener( 'click', function ( event ) {
		var poster = event.target.closest( '.bn-videos__poster' );
		if ( poster ) {
			play( poster.getAttribute( 'data-id' ), titleEl.textContent );
			return;
		}

		var card = event.target.closest( '.bn-videos__row' );
		if ( card ) {
			event.preventDefault();
			titleEl.textContent = card.getAttribute( 'data-title' );
			metaEl.textContent = card.getAttribute( 'data-meta' );
			blurbEl.textContent = card.getAttribute( 'data-blurb' );
			root.querySelectorAll( '.bn-videos__row.is-current' ).forEach( function ( el ) {
				el.classList.remove( 'is-current' );
			} );
			card.classList.add( 'is-current' );
			play( card.getAttribute( 'data-id' ), card.getAttribute( 'data-title' ) );
			frame.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			return;
		}

		var chip = event.target.closest( '[data-bn-video-filter]' );
		if ( chip ) {
			apply( chip.getAttribute( 'data-bn-video-filter' ) );
		}
	} );

	function apply( filter ) {
		root.querySelectorAll( '[data-bn-video-filter]' ).forEach( function ( el ) {
			var on = el.getAttribute( 'data-bn-video-filter' ) === filter;
			el.classList.toggle( 'is-active', on );
			el.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		root.querySelectorAll( '.bn-videos__item' ).forEach( function ( item ) {
			var live = item.getAttribute( 'data-live' ) === '1';
			var mine = item.getAttribute( 'data-theme' ) === siteTheme;
			var show = 'all' === filter || ( 'live' === filter && live ) || ( 'theme' === filter && ( mine || live ) );
			item.hidden = ! show;
		} );
	}

	apply( 'theme' );
}() );
