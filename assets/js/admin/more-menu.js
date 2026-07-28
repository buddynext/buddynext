/**
 * BuddyNext admin row "more" (kebab) dropdown.
 *
 * Shared open/close + positioning for the `.bn-more-menu` overflow menu used in
 * admin list tables (Members, Spaces). Extracted from members.js so every list
 * page reuses ONE implementation instead of copying the wiring. Markup contract:
 *
 *   <div class="bn-more-menu">
 *     <button class="bn-more-btn" aria-haspopup="menu">…</button>
 *     <div class="bn-more-dropdown" role="menu">…</div>
 *   </div>
 *
 * Enqueued by each list page's admin class (handle: bn-admin-more-menu).
 *
 * @package BuddyNext\Admin
 */
( function () {
	'use strict';

	/*
	 * The dropdown is position:fixed with z-index 1000, but the sticky actions
	 * cell it lives in is `position: sticky; z-index: 1`, which makes that <td>
	 * a stacking context — so the 1000 is resolved INSIDE the cell, not against
	 * the page. Every row's actions cell has the same z-index, so the tie is
	 * broken by DOM order and the next row's opaque cell paints straight over
	 * the open menu. position:fixed does not help: it changes the containing
	 * block, not which stacking context the element participates in.
	 *
	 * So the owning cell is lifted for exactly as long as its menu is open.
	 */
	function setCellLift( menu, lifted ) {
		var cell = menu.closest( 'td' );
		if ( cell ) {
			cell.classList.toggle( 'bn-cell--menu-open', lifted );
		}
	}

	function closeAllRowMenus() {
		document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
			open.classList.remove( 'open' );
			setCellLift( open, false );
		} );
	}

	// The dropdown is position:fixed so it escapes the overflow:hidden on the
	// rounded card/table ancestors (which clipped the bottom rows). Place it
	// under the trigger, right-aligned, flipping above when it would overflow
	// the viewport bottom.
	function positionRowMenu( menu ) {
		var btn = menu.querySelector( '.bn-more-btn' );
		var dd  = menu.querySelector( '.bn-more-dropdown' );
		if ( ! btn || ! dd ) {
			return;
		}
		var gap    = 4;
		var margin = 8;
		var br     = btn.getBoundingClientRect();
		var ddRect = dd.getBoundingClientRect();

		var left = br.right - ddRect.width;
		if ( left < margin ) {
			left = margin;
		}
		if ( left + ddRect.width > window.innerWidth - margin ) {
			left = window.innerWidth - margin - ddRect.width;
		}

		var top = br.bottom + gap;
		if ( top + ddRect.height > window.innerHeight - margin ) {
			var above = br.top - gap - ddRect.height;
			top = above >= margin ? above : Math.max( margin, window.innerHeight - margin - ddRect.height );
		}

		dd.style.left = Math.round( left ) + 'px';
		dd.style.top  = Math.round( top ) + 'px';
	}

	function initRowMenus() {
		var triggers = document.querySelectorAll( '.bn-more-btn' );
		if ( ! triggers.length ) {
			return;
		}

		triggers.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var menu = btn.closest( '.bn-more-menu' );
				if ( ! menu ) {
					return;
				}
				document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
					if ( open !== menu ) {
						open.classList.remove( 'open' );
						setCellLift( open, false );
					}
				} );
				menu.classList.toggle( 'open' );
				var isOpen = menu.classList.contains( 'open' );
				setCellLift( menu, isOpen );
				if ( isOpen ) {
					positionRowMenu( menu );
				}
			} );
		} );

		document.addEventListener( 'click', closeAllRowMenus );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeAllRowMenus();
			}
		} );

		// A fixed menu can't follow the page as it scrolls, so close it on any
		// scroll (capture: catch scrolling containers too) or resize.
		window.addEventListener( 'scroll', closeAllRowMenus, true );
		window.addEventListener( 'resize', closeAllRowMenus );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initRowMenus );
	} else {
		initRowMenus();
	}
}() );
