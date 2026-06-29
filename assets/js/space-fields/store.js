/* BuddyNext — Space custom-fields Interactivity store.
 *
 * Saves the registry-driven "Custom fields" settings panel over
 * POST /buddynext/v1/spaces/{id}/fields. The panel renders developer-registered
 * (non-core) space fields via FieldType::render_input(); this store collects the
 * inputs, posts them, and surfaces the server's per-field 422 errors inline. */
import { store, getContext } from '@wordpress/interactivity';
import { bnToast } from '../shell/dialog.js';
import { restFetch } from '../shell/rest-client.js';

let I18N = {};
function t( k, fb ) { return ( I18N && I18N[ k ] ) || fb; }

/* Collect one value per registered field row, by input shape. */
function collectFields( root ) {
	const out = {};
	root.querySelectorAll( '[data-field-key]' ).forEach( function ( row ) {
		const key    = row.dataset.fieldKey;
		const radios = row.querySelectorAll( 'input[type=radio]' );
		const cb     = row.querySelector( 'input[type=checkbox]' );
		const multi  = row.querySelector( 'select[multiple]' );
		if ( radios.length ) {
			const checked = Array.prototype.find.call( radios, function ( r ) { return r.checked; } );
			out[ key ] = checked ? checked.value : '';
		} else if ( cb ) {
			out[ key ] = cb.checked ? '1' : '0';
		} else if ( multi ) {
			out[ key ] = Array.prototype.map.call( multi.selectedOptions, function ( o ) { return o.value; } );
		} else {
			const el = row.querySelector( 'input, select, textarea' );
			out[ key ] = el ? el.value : '';
		}
	} );
	return out;
}

function clearErrors( root ) {
	root.querySelectorAll( '[data-bn-field-error]' ).forEach( function ( e ) {
		e.hidden = true;
		e.textContent = '';
	} );
}

const spaceFieldsStore = store( 'buddynext/space-fields', {
	actions: {
		* saveFields( event ) {
			// Bound to the form's submit so Enter and the button both reach here.
			if ( event && typeof event.preventDefault === 'function' ) { event.preventDefault(); }
			const ctx = getContext();
			if ( ! ctx.restNonce || ! ctx.spaceId ) { return; }
			const root = event.target.closest( '[data-bn-space-fields-form]' ) || document;
			clearErrors( root );
			const btn = root.querySelector( '[data-bn-save]' );
			if ( btn ) { btn.disabled = true; }
			try {
				const res = yield restFetch( '/spaces/' + ctx.spaceId + '/fields', {
					method: 'POST',
					nonce: ctx.restNonce,
					body: { fields: collectFields( root ) },
					toastOnError: false,
				} );
				if ( res.ok ) {
					bnToast( t( 'saved', 'Fields saved.' ), { tone: 'success' } );
				} else {
					const errs = ( res.data && res.data.errors ) || {};
					let shown = 0;
					Object.keys( errs ).forEach( function ( key ) {
						const row = root.querySelector( '[data-field-key="' + key + '"]' );
						const el  = row && row.querySelector( '[data-bn-field-error]' );
						if ( el ) { el.textContent = errs[ key ]; el.hidden = false; shown++; }
					} );
					if ( ! shown ) { bnToast( t( 'saveFailed', 'Could not save the fields. Try again.' ), { tone: 'danger' } ); }
				}
			} catch ( _e ) {
				bnToast( t( 'saveFailed', 'Could not save the fields. Try again.' ), { tone: 'danger' } );
			} finally {
				if ( btn ) { btn.disabled = false; }
			}
		},
	},
} );

I18N = ( spaceFieldsStore.state && spaceFieldsStore.state.i18n ) || {};
