/**
 * BuddyNext space Files — single-document preview.
 *
 * BuddyNext owns the whole single-file page; this island only fills the preview
 * pane. It fetches the `/preview` REST route MediaVerse exposes (carried on the
 * document as `links.preview`, with a `_wpnonce` so cookie auth counts) and
 * renders whatever comes back:
 *
 *   - a native PDF   -> an <iframe>, streamed by the browser (no buffering)
 *   - an office file -> a PDF rendition, same <iframe> (buffered via a blob)
 *   - text/csv/md    -> rendered HTML, injected into BuddyNext's own container
 *   - anything else  -> the server-rendered "no preview" card already in the DOM
 *
 * It deliberately does NOT know MediaVerse's type-to-tier map: it reads the
 * answer off the response, so a new renderer on the MediaVerse side lights up
 * here with no BuddyNext change.
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

function setFrame( pane, src, title ) {
	const frame = document.createElement( 'iframe' );
	frame.className = 'bn-file-single__frame';
	frame.src = src;
	frame.title = title || '';
	frame.loading = 'lazy';
	pane.replaceChildren( frame );
}

store( 'buddynext/space-files', {
	callbacks: {
		async loadPreview() {
			// Capture scope BEFORE any await — getContext()/getElement() are only
			// valid during synchronous directive processing.
			const ctx = getContext();
			const root = getElement().ref;
			const pane = root.querySelector( '[data-bn-preview]' );
			const noPrev = root.querySelector( '[data-bn-no-preview]' );

			const showCard = () => {
				if ( pane ) {
					pane.remove();
				}
				if ( noPrev ) {
					noPrev.hidden = false;
				}
			};

			const url = ctx.previewUrl;
			if ( ! url || ! pane ) {
				showCard();
				return;
			}

			// Native PDFs: let the browser stream + render directly. The cookie
			// plus the _wpnonce on the URL authenticate the iframe request, so no
			// fetch and no full-file buffering for the common case.
			if ( ctx.isPdf ) {
				setFrame( pane, url, ctx.title );
				return;
			}

			try {
				const res = await fetch( url, { credentials: 'same-origin' } );
				if ( ! res.ok ) {
					showCard();
					return;
				}
				const ct = ( res.headers.get( 'content-type' ) || '' ).toLowerCase();

				// An office rendition streams as a PDF even though the source is
				// not one — display it in the same iframe (buffered via a blob).
				if ( ct.indexOf( 'application/pdf' ) !== -1 ) {
					const objUrl = URL.createObjectURL( await res.blob() );
					setFrame( pane, objUrl, ctx.title );
					return;
				}

				if ( ct.indexOf( 'application/json' ) !== -1 ) {
					const data = await res.json();
					if (
						data &&
						'html' === data.mode &&
						'string' === typeof data.html &&
						'' !== data.html
					) {
						const wrap = document.createElement( 'div' );
						wrap.className = 'bn-file-single__html';
						// MediaVerse server-rendered preview HTML (same suite,
						// access-gated by the route that produced it).
						wrap.innerHTML = data.html;
						pane.replaceChildren( wrap );
						return;
					}
				}

				showCard();
			} catch ( e ) {
				showCard();
			}
		},
	},
} );
