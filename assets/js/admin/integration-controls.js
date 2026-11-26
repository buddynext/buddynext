/**
 * Integration Settings: the list of connected products.
 *
 * Keeps each row's status chip, "where it appears" badges and master switch in step with its
 * three real switches, opens and closes the details, and filters the list. The three switches
 * and the sub-tab switches are the only fields that are saved; everything here is display.
 */
( function () {
	'use strict';

	var list = document.querySelector( '[data-bn-int-list]' );
	if ( ! list ) {
		return;
	}

	var cards = Array.prototype.slice.call( list.querySelectorAll( '[data-bn-int-card]' ) );
	var search = document.querySelector( '[data-bn-int-search]' );
	var empty = document.querySelector( '[data-bn-int-empty]' );
	var filter = 'all';

	function areaInputs( card ) {
		return Array.prototype.slice.call( card.querySelectorAll( '[data-bn-int-area-input]' ) );
	}

	function isHidden( card ) {
		var inputs = areaInputs( card );
		return inputs.length > 0 && ! inputs.some( function ( i ) { return i.checked; } );
	}

	// Chip wording comes from the server so it is translated.
	var chipText = { on: list.getAttribute( 'data-label-on' ), hidden: list.getAttribute( 'data-label-hidden' ) };

	function sync( card ) {
		var inputs = areaInputs( card );
		inputs.forEach( function ( input ) {
			var area = card.querySelector( '[data-bn-int-area="' + input.getAttribute( 'data-bn-int-area-input' ) + '"]' );
			if ( area ) {
				area.classList.toggle( 'is-off', ! input.checked );
			}
		} );
		var master = card.querySelector( '[data-bn-int-master]' );
		if ( master ) {
			master.checked = ! isHidden( card );
		}
		var chip = card.querySelector( '[data-bn-int-chip]' );
		if ( chip && 'attention' !== chip.getAttribute( 'data-state' ) ) {
			var state = isHidden( card ) ? 'hidden' : 'on';
			chip.setAttribute( 'data-state', state );
			if ( chipText[ state ] ) {
				chip.textContent = chipText[ state ];
			}
		}
	}

	function counts() {
		var hidden = cards.filter( isHidden ).length;
		var attention = cards.filter( function ( c ) { return '1' === c.getAttribute( 'data-attention' ); } ).length;
		var map = { all: cards.length, attention: attention, hidden: hidden };
		Object.keys( map ).forEach( function ( key ) {
			var el = document.querySelector( '[data-bn-int-count="' + key + '"]' );
			if ( el ) {
				el.textContent = String( map[ key ] );
			}
		} );
	}

	function apply() {
		var q = search ? search.value.trim().toLowerCase() : '';
		var shown = 0;
		cards.forEach( function ( card ) {
			var match = ! q || ( card.getAttribute( 'data-name' ) || '' ).indexOf( q ) > -1;
			if ( 'attention' === filter ) {
				match = match && '1' === card.getAttribute( 'data-attention' );
			} else if ( 'hidden' === filter ) {
				match = match && isHidden( card );
			}
			card.hidden = ! match;
			if ( match ) {
				shown++;
			}
		} );
		if ( empty ) {
			empty.hidden = shown > 0;
		}
	}

	list.addEventListener( 'change', function ( event ) {
		var card = event.target.closest( '[data-bn-int-card]' );
		if ( ! card ) {
			return;
		}
		if ( event.target.hasAttribute( 'data-bn-int-master' ) ) {
			// One switch for every place the product offers; each real switch then reports its own change.
			// The wanted state is read once: each reported change re-syncs the master switch.
			var want = event.target.checked;
			areaInputs( card ).forEach( function ( input ) {
				if ( input.checked !== want ) {
					input.checked = want;
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		}
		sync( card );
		counts();
		apply();
	} );

	list.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest( '[data-bn-int-toggle]' );
		if ( ! toggle ) {
			return;
		}
		var card = toggle.closest( '[data-bn-int-card]' );
		var open = card.classList.toggle( 'is-open' );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	} );

	document.addEventListener( 'click', function ( event ) {
		var pill = event.target.closest( '[data-bn-int-filter]' );
		if ( ! pill ) {
			return;
		}
		filter = pill.getAttribute( 'data-bn-int-filter' );
		document.querySelectorAll( '[data-bn-int-filter]' ).forEach( function ( el ) {
			var on = el === pill;
			el.classList.toggle( 'is-active', on );
			el.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		apply();
	} );

	if ( search ) {
		search.addEventListener( 'input', apply );
	}

	cards.forEach( sync );
	counts();
}() );
