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
	 * The dropdown is MOVED TO <body> while it is open, and put back on close.
	 *
	 * Two separate ancestors made it unreachable where it sits in the markup,
	 * and both are load-bearing, so neither could simply be removed:
	 *
	 * 1. `.bn-table-wrap__scroll` carries `contain: paint` - deliberately, to
	 *    stop 66 sticky cells leaking their layout width past the scroll
	 *    ancestor and growing a 277px page scrollbar. Paint containment also
	 *    makes that box the containing block for position:fixed descendants, so
	 *    the menu's correctly-computed VIEWPORT coordinates were re-resolved
	 *    against the wrapper and landed off-screen. Measured on Members >
	 *    Directory at 1440: computed left 1213 / top 695, rendered at 1684 /
	 *    1292. The button looked dead because the menu opened where nobody could
	 *    see it.
	 * 2. The actions <td> is `position: sticky; z-index: 1`, which makes it a
	 *    stacking context, so z-index 1000 was resolved INSIDE the cell and the
	 *    next row's opaque cell painted over the menu.
	 *
	 * Re-parenting to <body> escapes both at once: no contained ancestor, no
	 * stacking context, and the existing viewport maths becomes correct as
	 * written. It also retires the per-cell lift that used to work around (2).
	 */
	function dropdownOf( menu ) {
		return menu.bnDropdown || menu.querySelector( '.bn-more-dropdown' );
	}

	function restoreDropdown( menu ) {
		var dd = menu.bnDropdown;
		if ( ! dd ) {
			return;
		}
		dd.classList.remove( 'is-open' );
		if ( dd.parentElement !== menu ) {
			menu.appendChild( dd );
		}
	}

	function closeAllRowMenus() {
		document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
			open.classList.remove( 'open' );
			restoreDropdown( open );
		} );
	}

	// Place it under the trigger, right-aligned, flipping above when it would
	// overflow the viewport bottom. Coordinates are viewport-relative, which is
	// only true because the dropdown has been re-parented to <body> first.
	function positionRowMenu( menu ) {
		var btn = menu.querySelector( '.bn-more-btn' );
		var dd  = dropdownOf( menu );
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
			var owner = btn.closest( '.bn-more-menu' );
			if ( owner && ! owner.bnDropdown ) {
				// Held on the menu because the element stops being a descendant
				// once it is open - a querySelector would find nothing.
				owner.bnDropdown = owner.querySelector( '.bn-more-dropdown' );
			}

			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var menu = btn.closest( '.bn-more-menu' );
				if ( ! menu ) {
					return;
				}
				document.querySelectorAll( '.bn-more-menu.open' ).forEach( function ( open ) {
					if ( open !== menu ) {
						open.classList.remove( 'open' );
						restoreDropdown( open );
					}
				} );
				menu.classList.toggle( 'open' );
				var isOpen = menu.classList.contains( 'open' );
				if ( isOpen ) {
					// Re-parent BEFORE measuring: the maths below is
					// viewport-relative and only resolves that way once the
					// element has left the contained, sticky subtree.
					document.body.appendChild( menu.bnDropdown );
					// The descendant selector cannot reach it out here, so the
					// element carries its own open state.
					menu.bnDropdown.classList.add( 'is-open' );
					positionRowMenu( menu );
				} else {
					restoreDropdown( menu );
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
