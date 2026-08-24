/**
 * BuddyNext space Files — single-document preview.
 *
 * BuddyNext owns the whole single-file page; this island only fills the preview
 * pane. It fetches the `/preview` REST route MediaVerse exposes (carried on the
 * document as `links.preview`, with a `_wpnonce` so cookie auth counts) and
 * renders whatever comes back:
 *
 *   - a native PDF   -> rendered to canvas by PDF.js, a clean single-column read
 *   - an office file -> its PDF rendition, rendered the same way (via a blob)
 *   - text/csv/md    -> rendered HTML, injected into BuddyNext's own container
 *   - anything else  -> the server-rendered "no preview" card already in the DOM
 *
 * Why PDF.js and not an <iframe> to the PDF: the browser's embedded PDF viewer
 * draws a thumbnail rail + toolbar and renders the page at a fixed tiny zoom in a
 * column this width — unreadable, and unfixable (modern Chrome ignores every
 * #toolbar/#pagemode open-parameter). PDF.js draws the pages to canvas at the
 * container's width, the same everywhere and on mobile. If it fails to load or a
 * document fails to open, we fall back to that same <iframe> rather than showing
 * nothing — a worse read still beats no read.
 *
 * It deliberately does NOT know MediaVerse's type-to-tier map: it reads the
 * answer off the /preview response, so a new renderer on the MediaVerse side
 * lights up here with no BuddyNext change.
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// Widest a rendered page is drawn, in CSS pixels — the canvas underneath is
// sized in DEVICE pixels, so an unbounded page on a 3x phone is a 3000px bitmap
// per page, which is where mobile Safari starts discarding canvases.
const MAX_WIDTH = 1200;

// Pages drawn on open; the rest wait for scroll. A 400-page file must not paint
// 400 canvases at once.
const EAGER_PAGES = 3;

/**
 * Fallback: hand the PDF to the browser's own viewer in an <iframe>.
 *
 * This is what the preview did before PDF.js — a failure here costs nothing that
 * previously worked. The Download button lives in the page header, outside this,
 * so it is reachable even when nothing renders.
 *
 * @param {HTMLElement} pane  Preview pane.
 * @param {string}      src   PDF URL (nonce'd preview URL, or a blob URL).
 * @param {string}      title Accessible iframe title.
 */
function iframeFallback( pane, src, title ) {
	const frame = document.createElement( 'iframe' );
	frame.className = 'bn-file-single__frame';
	frame.src = src;
	frame.title = title || '';
	frame.loading = 'lazy';
	pane.replaceChildren( frame );
}

/**
 * Draw one page into its holder.
 *
 * @param {Object}      pdf    PDFDocumentProxy.
 * @param {number}      num    1-based page number.
 * @param {HTMLElement} holder Page holder.
 */
function renderPage( pdf, num, holder ) {
	if ( ! holder || holder.dataset.rendered ) {
		return Promise.resolve();
	}
	holder.dataset.rendered = '1';

	return pdf.getPage( num ).then( ( page ) => {
		const base = page.getViewport( { scale: 1 } );
		const css = Math.min( holder.clientWidth || base.width, MAX_WIDTH );
		// Cap the device-pixel multiplier at 2 — beyond that the memory doubles
		// again for a difference nobody sees on a phone.
		const dpr = Math.min( window.devicePixelRatio || 1, 2 );
		const viewport = page.getViewport( { scale: ( css / base.width ) * dpr } );

		const canvas = document.createElement( 'canvas' );
		canvas.width = Math.floor( viewport.width );
		canvas.height = Math.floor( viewport.height );
		canvas.style.width = '100%';
		canvas.style.height = 'auto';
		canvas.setAttribute( 'role', 'img' );
		canvas.setAttribute( 'aria-label', 'Page ' + num );

		holder.replaceChildren( canvas );

		return page.render( {
			canvasContext: canvas.getContext( '2d' ),
			viewport,
			// Paper stays white in every theme — a PDF is a document, not a
			// themed surface. Filled in the canvas (not CSS) so it is exact and
			// never fights a dark-mode background token.
			background: 'rgb(255, 255, 255)',
		} ).promise;
	} );
}

/**
 * Render a PDF into the pane with PDF.js, or fall back to the iframe.
 *
 * @param {HTMLElement} pane Preview pane.
 * @param {string}      src  PDF URL (nonce'd preview URL, or a blob URL).
 * @param {Object}      ctx  Island context (pdfLib, pdfWorker, title).
 */
async function renderPdf( pane, src, ctx ) {
	if ( ! ctx.pdfLib ) {
		iframeFallback( pane, src, ctx.title );
		return;
	}
	try {
		const pdfjs = await import( /* webpackIgnore: true */ ctx.pdfLib );
		pdfjs.GlobalWorkerOptions.workerSrc = ctx.pdfWorker;

		// withCredentials: the preview URL is session-gated, so a fetch without
		// cookies is refused exactly as it should be.
		const pdf = await pdfjs.getDocument( { url: src, withCredentials: true } ).promise;

		const pages = document.createElement( 'div' );
		pages.className = 'bn-pdf__pages';
		const holders = [];
		for ( let i = 1; i <= pdf.numPages; i++ ) {
			const holder = document.createElement( 'div' );
			holder.className = 'bn-pdf__page';
			holder.setAttribute( 'data-bn-pdf-page', String( i ) );
			pages.appendChild( holder );
			holders.push( holder );
		}
		pane.replaceChildren( pages );

		const eager = [];
		for ( let p = 1; p <= Math.min( EAGER_PAGES, pdf.numPages ); p++ ) {
			eager.push( renderPage( pdf, p, holders[ p - 1 ] ) );
		}
		// One page failing is not the document failing — keep the rest.
		Promise.all( eager ).catch( () => {} );

		if ( pdf.numPages > EAGER_PAGES && 'IntersectionObserver' in window ) {
			const io = new IntersectionObserver(
				( entries ) => {
					entries.forEach( ( entry ) => {
						if ( ! entry.isIntersecting ) {
							return;
						}
						io.unobserve( entry.target );
						const n = parseInt( entry.target.getAttribute( 'data-bn-pdf-page' ), 10 );
						renderPage( pdf, n, entry.target ).catch( () => {} );
					} );
				},
				{ rootMargin: '600px 0px' }
			);
			holders.slice( EAGER_PAGES ).forEach( ( el ) => io.observe( el ) );
		} else if ( pdf.numPages > EAGER_PAGES ) {
			// No IntersectionObserver: draw the rest rather than leave them blank.
			for ( let q = EAGER_PAGES + 1; q <= pdf.numPages; q++ ) {
				renderPage( pdf, q, holders[ q - 1 ] ).catch( () => {} );
			}
		}
	} catch ( e ) {
		iframeFallback( pane, src, ctx.title );
	}
}

/* ── Sharing (members + link) — BuddyNext's own modal over MediaVerse's
 *    documents/{id}/permissions REST. Shown only when the viewer may grant. ─── */

function shareEmptyLi( text ) {
	const li = document.createElement( 'li' );
	li.className = 'bn-share__empty';
	li.textContent = text;
	return li;
}

function shareGrantLi( ctx, g ) {
	const li = document.createElement( 'li' );
	li.className = 'bn-share__grant';

	const name = document.createElement( 'span' );
	name.className = 'bn-share__grant-name';
	name.textContent = g.is_link
		? ctx.i18n.link
		: ( 'role' === g.grantee_type ? g.role : ( g.user_name || '#' + g.user_id ) );

	const perm = document.createElement( 'span' );
	perm.className = 'bn-share__grant-perm';
	perm.textContent = ( ctx.levelLabels && ctx.levelLabels[ g.permission ] ) || g.permission;

	const btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.className = 'bn-share__grant-remove';
	btn.textContent = ctx.i18n.remove;
	btn.addEventListener( 'click', () => shareRevoke( ctx, g.id ) );

	li.append( name, perm, btn );
	return li;
}

async function shareErrorFrom( ctx, res ) {
	let msg = ctx.i18n.error;
	try {
		const d = await res.json();
		if ( d && d.message ) {
			msg = d.message;
		}
	} catch ( e ) {}
	ctx.shareError = msg;
}

async function shareLoadGrants( ctx ) {
	const ul = document.querySelector( '[data-bn-grants]' );
	if ( ! ul ) {
		return;
	}
	try {
		const res = await fetch( ctx.permsUrl, {
			headers: { 'X-WP-Nonce': ctx.nonce },
			credentials: 'same-origin',
		} );
		if ( ! res.ok ) {
			ul.replaceChildren( shareEmptyLi( ctx.i18n.error ) );
			return;
		}
		const grants = await res.json();
		if ( ! Array.isArray( grants ) || ! grants.length ) {
			ul.replaceChildren( shareEmptyLi( ctx.i18n.noShares ) );
			return;
		}
		ul.replaceChildren( ...grants.map( ( g ) => shareGrantLi( ctx, g ) ) );
	} catch ( e ) {
		ul.replaceChildren( shareEmptyLi( ctx.i18n.error ) );
	}
}

async function shareRevoke( ctx, id ) {
	try {
		const res = await fetch( ctx.permDelUrl + id, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': ctx.nonce },
			credentials: 'same-origin',
		} );
		if ( ! res.ok ) {
			await shareErrorFrom( ctx, res );
			return;
		}
		shareLoadGrants( ctx );
	} catch ( e ) {
		ctx.shareError = ctx.i18n.error;
	}
}

store( 'buddynext/space-files', {
	actions: {
		openShare() {
			const ctx = getContext();
			ctx.shareOpen = true;
			ctx.shareError = '';
			shareLoadGrants( ctx );
		},
		closeShare() {
			getContext().shareOpen = false;
		},
		async addMember( event ) {
			event.preventDefault();
			const ctx = getContext();
			const form = event.target;
			const login = ( form.login.value || '' ).trim();
			const permission = form.permission.value || 'view';
			if ( ! login ) {
				return;
			}
			ctx.shareError = '';
			ctx.shareBusy = true;
			try {
				const res = await fetch( ctx.permsUrl, {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( { grantee_type: 'user', user_login: login, permission } ),
				} );
				if ( ! res.ok ) {
					await shareErrorFrom( ctx, res );
					return;
				}
				form.login.value = '';
				shareLoadGrants( ctx );
			} catch ( e ) {
				ctx.shareError = ctx.i18n.error;
			} finally {
				ctx.shareBusy = false;
			}
		},
		async createLink() {
			const ctx = getContext();
			const sel = document.querySelector( '.bn-share__link-perm' );
			const permission = sel ? sel.value : 'view';
			ctx.shareError = '';
			ctx.shareBusy = true;
			try {
				const res = await fetch( ctx.permsUrl + '/link', {
					method: 'POST',
					headers: { 'X-WP-Nonce': ctx.nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( { permission } ),
				} );
				if ( ! res.ok ) {
					await shareErrorFrom( ctx, res );
					return;
				}
				const data = await res.json();
				ctx.shareLink = ( data && data.url ) || '';
				shareLoadGrants( ctx );
			} catch ( e ) {
				ctx.shareError = ctx.i18n.error;
			} finally {
				ctx.shareBusy = false;
			}
		},
		copyLink() {
			const ctx = getContext();
			if ( ctx.shareLink && navigator.clipboard ) {
				navigator.clipboard.writeText( ctx.shareLink ).catch( () => {} );
			}
		},
	},
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

			// Native PDF: render it directly (no fetch — PDF.js streams it).
			if ( ctx.isPdf ) {
				renderPdf( pane, url, ctx );
				return;
			}

			try {
				const res = await fetch( url, { credentials: 'same-origin' } );
				if ( ! res.ok ) {
					showCard();
					return;
				}
				const type = ( res.headers.get( 'content-type' ) || '' ).toLowerCase();

				// An office rendition streams as a PDF even though the source is
				// not one — render it the same way, from the bytes we just read.
				if ( type.indexOf( 'application/pdf' ) !== -1 ) {
					const objUrl = URL.createObjectURL( await res.blob() );
					renderPdf( pane, objUrl, ctx );
					return;
				}

				if ( type.indexOf( 'application/json' ) !== -1 ) {
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
