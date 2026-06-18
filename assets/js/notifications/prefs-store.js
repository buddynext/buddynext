/**
 * BuddyNext - Notification preferences Interactivity API store.
 *
 * Powers `/settings/notifications/` (and the alias `/notifications/preferences/`).
 *
 * The template inlines a context object with:
 *   restPrefsUrl    string  GET + PUT /me/notification-prefs
 *   restChannelsUrl string  GET + PUT /me/notification-channels
 *   restSpacesUrl   string  GET + POST /me/space-notification-prefs
 *   nonce           string  wp_rest nonce
 *   prefs           map     { [type]: { on_site, email_freq } }
 *   spacePrefs      map     { [space_id]: 'all'|'mentions_only'|'none' }
 *   channels        map     { in_app, email, push }
 *   pushAvailable   bool
 *   catalogue       array   per-type defaults for resetToDefaults
 *   isDirty         bool    seeded false
 *   isSaving        bool    seeded false
 *   savedAt         number  unix seconds of last successful save (0 = never)
 *   errors          map     { [type]: 'message' } keyed by row
 *   openGroups      map     { [group]: bool } accordion state
 *   resetConfirmOpen bool
 *
 * Companion service: includes/Notifications/NotificationPrefService.php +
 * NotificationController endpoints.
 */
import { store, getContext } from '@wordpress/interactivity';
import { restFetch } from '../shell/rest-client.js';

const VALID_FREQ = [ 'immediate', 'daily', 'weekly', 'off' ];

/**
 * Toast helper - delegates to the shell-provided bnToast when available.
 *
 * @param {string} message
 * @param {string} tone success|error|info
 */
function toast( message, tone ) {
	if ( typeof window !== 'undefined' && typeof window.bnToast === 'function' ) {
		window.bnToast( message, tone );
		return;
	}
	if ( typeof window !== 'undefined' && window.console ) {
		window.console.warn( '[buddynext]', tone, message );
	}
}

/**
 * Format a unix-second timestamp into "Just now", "1 min ago", etc.
 *
 * @param {number} ts
 * @return {string}
 */
function formatSavedLabel( ts ) {
	if ( ! ts ) { return ''; }
	var diff = Math.floor( Date.now() / 1000 ) - ts;
	if ( diff < 5 )     { return 'Just now'; }
	if ( diff < 60 )    { return diff + 's ago'; }
	if ( diff < 3600 )  { return Math.floor( diff / 60 ) + ' min ago'; }
	return Math.floor( diff / 3600 ) + 'h ago';
}

/* Beforeunload guard - mirror the Profile edit pattern. */
var unloadHandlerAttached = false;
function ensureUnloadGuard() {
	if ( unloadHandlerAttached ) { return; }
	window.addEventListener( 'beforeunload', function ( event ) {
		var wrap = document.querySelector( '[data-wp-interactive="buddynext/notification-prefs"]' );
		if ( ! wrap ) { return; }
		if ( wrap.dataset.bnDirty === '1' ) {
			event.preventDefault();
			event.returnValue = '';
			return '';
		}
	} );
	unloadHandlerAttached = true;
}

function syncDirtyAttr( dirty ) {
	var wrap = document.querySelector( '[data-wp-interactive="buddynext/notification-prefs"]' );
	if ( wrap ) { wrap.dataset.bnDirty = dirty ? '1' : '0'; }
}

/**
 * Build the diff payload for PUT /me/notification-prefs by comparing the
 * current prefs map against the snapshot captured at last save.
 *
 * @param {Object} current
 * @param {Object} initial
 * @return {Object} subset of types where anything changed
 */
function buildPrefsDiff( current, initial ) {
	var diff = {};
	Object.keys( current || {} ).forEach( function ( type ) {
		var c = current[ type ] || {};
		var i = ( initial && initial[ type ] ) || {};
		if ( c.on_site !== i.on_site || c.email_freq !== i.email_freq ) {
			diff[ type ] = {
				on_site: !! c.on_site,
				email_freq: c.email_freq || 'immediate',
			};
		}
	} );
	return diff;
}

store( 'buddynext/notification-prefs', {
	state: {
		get savedLabel() {
			var ctx = getContext();
			return formatSavedLabel( ctx && ctx.savedAt ? ctx.savedAt : 0 );
		},
		get saveBarHidden() {
			var ctx = getContext();
			return ! ctx || ( ! ctx.isDirty && ! ctx.isSaving && ! ctx.savedAt );
		},
		get statusLabel() {
			var ctx = getContext();
			if ( ! ctx ) { return ''; }
			if ( ctx.isSaving ) { return 'Saving...'; }
			if ( ctx.isDirty )  { return 'Unsaved changes'; }
			if ( ctx.savedAt )  { return 'Saved ' + formatSavedLabel( ctx.savedAt ); }
			return '';
		},
		// Per-row reactive state — the row provides prefType, the chip provides
		// chipFreq. Bound via data-wp-bind so toggling, and especially Reset to
		// defaults (which rebuilds ctx.prefs), re-renders the controls instead of
		// leaving the server-rendered checked/aria-pressed state stale.
		get rowOnSite() {
			var ctx = getContext();
			var entry = ctx && ctx.prefs ? ctx.prefs[ ctx.prefType ] : null;
			return !! ( entry && entry.on_site );
		},
		get rowFreqActive() {
			var ctx = getContext();
			var entry = ctx && ctx.prefs ? ctx.prefs[ ctx.prefType ] : null;
			return !! ( entry && entry.email_freq === ctx.chipFreq );
		},
	},
	callbacks: {
		init() {
			ensureUnloadGuard();
			syncDirtyAttr( false );
		},
	},
	actions: {
		toggleGroup( event ) {
			var ctx     = getContext();
			var trigger = event.target.closest( '[data-group]' );
			if ( ! trigger || ! ctx ) { return; }
			var group = trigger.dataset.group;
			var open  = Object.assign( {}, ctx.openGroups || {} );
			open[ group ] = ! open[ group ];
			ctx.openGroups = open;
		},

		setChannel( event ) {
			var ctx = getContext();
			var el  = event.target.closest( '[data-channel]' );
			if ( ! ctx || ! el ) { return; }
			var key = el.dataset.channel;
			var channels = Object.assign( {}, ctx.channels || {} );
			channels[ key ] = !! el.checked;
			ctx.channels = channels;
			ctx.isDirty  = true;
			syncDirtyAttr( true );
		},

		setOnSite( event ) {
			var ctx = getContext();
			var el  = event.target.closest( '[data-type]' );
			if ( ! ctx || ! el ) { return; }
			var type = el.dataset.type;
			var prefs = Object.assign( {}, ctx.prefs || {} );
			var entry = Object.assign( {}, prefs[ type ] || {} );
			entry.on_site = !! el.checked;
			prefs[ type ] = entry;
			ctx.prefs = prefs;
			ctx.isDirty = true;
			syncDirtyAttr( true );
		},

		setEmailFreq( event ) {
			var ctx = getContext();
			var btn = event.target.closest( '[data-type][data-freq]' );
			if ( ! ctx || ! btn ) { return; }
			var type = btn.dataset.type;
			var freq = btn.dataset.freq;
			if ( VALID_FREQ.indexOf( freq ) === -1 ) { return; }
			var prefs = Object.assign( {}, ctx.prefs || {} );
			var entry = Object.assign( {}, prefs[ type ] || {} );
			entry.email_freq = freq;
			prefs[ type ] = entry;
			ctx.prefs = prefs;
			ctx.isDirty = true;
			syncDirtyAttr( true );

			// Visual chip swap - update aria-pressed siblings without waiting
			// for save. Interactivity bindings re-evaluate on context change
			// but the chips read their pressed state from data-freq matching
			// ctx.prefs[type].email_freq so we let the bindings do the work.
		},

		async setSpacePref( event ) {
			var ctx = getContext();
			var btn = event.target.closest( '[data-space-id][data-pref]' );
			if ( ! ctx || ! btn ) { return; }
			var spaceId = parseInt( btn.dataset.spaceId, 10 );
			var pref    = btn.dataset.pref;
			if ( ! spaceId || [ 'all', 'mentions_only', 'none' ].indexOf( pref ) === -1 ) { return; }

			var previous = Object.assign( {}, ctx.spacePrefs || {} );
			var next     = Object.assign( {}, previous );
			next[ spaceId ] = pref;
			ctx.spacePrefs = next;

			try {
				var res = await restFetch( '', {
					base: ctx.restSpacesUrl,
					nonce: ctx.nonce,
					method: 'POST',
					body: { space_id: spaceId, pref: pref },
					toastOnError: false,
				} );
				if ( ! res.ok ) {
					throw new Error( 'http_' + res.status );
				}
				toast( 'Space preference saved.', 'success' );
			} catch ( _e ) {
				ctx.spacePrefs = previous;
				toast( 'Could not save space preference.', 'error' );
			}
		},

		async saveAll() {
			var ctx = getContext();
			if ( ! ctx || ctx.isSaving ) { return; }

			ctx.isSaving = true;
			ctx.errors   = {};

			var prefsDiff = buildPrefsDiff( ctx.prefs, ctx.initialPrefs );
			var channelsChanged = (
				! ctx.initialChannels ||
				ctx.channels.in_app !== ctx.initialChannels.in_app ||
				ctx.channels.email  !== ctx.initialChannels.email ||
				ctx.channels.push   !== ctx.initialChannels.push   ||
				ctx.channels.sound  !== ctx.initialChannels.sound
			);

			var ok = true;

			if ( Object.keys( prefsDiff ).length > 0 ) {
				try {
					var res = await restFetch( '', {
						base: ctx.restPrefsUrl,
						nonce: ctx.nonce,
						method: 'PUT',
						body: prefsDiff,
						toastOnError: false,
					} );
					if ( ! res.ok ) {
						var err = res.data;
						if ( err && err.data && err.data.params ) {
							ctx.errors = err.data.params;
						}
						throw new Error( 'http_' + res.status );
					}
				} catch ( _e ) {
					ok = false;
				}
			}

			if ( ok && channelsChanged ) {
				try {
					var resCh = await restFetch( '', {
						base: ctx.restChannelsUrl,
						nonce: ctx.nonce,
						method: 'PUT',
						body: ctx.channels || {},
						toastOnError: false,
					} );
					if ( ! resCh.ok ) {
						throw new Error( 'http_' + resCh.status );
					}
				} catch ( _e ) {
					ok = false;
				}
			}

			ctx.isSaving = false;

			if ( ! ok ) {
				// Rollback to the last known good snapshot.
				ctx.prefs    = Object.assign( {}, ctx.initialPrefs || {} );
				ctx.channels = Object.assign( {}, ctx.initialChannels || {} );
				toast( 'Could not save preferences.', 'error' );
				return;
			}

			ctx.initialPrefs    = JSON.parse( JSON.stringify( ctx.prefs || {} ) );
			ctx.initialChannels = JSON.parse( JSON.stringify( ctx.channels || {} ) );
			ctx.isDirty         = false;
			ctx.savedAt         = Math.floor( Date.now() / 1000 );
			syncDirtyAttr( false );
			toast( 'Preferences saved.', 'success' );
		},

		openResetConfirm() {
			var ctx = getContext();
			if ( ctx ) { ctx.resetConfirmOpen = true; }
		},

		closeResetConfirm() {
			var ctx = getContext();
			if ( ctx ) { ctx.resetConfirmOpen = false; }
		},

		resetToDefaults() {
			var ctx = getContext();
			if ( ! ctx ) { return; }

			var next = {};
			( ctx.catalogue || [] ).forEach( function ( entry ) {
				next[ entry.slug ] = {
					on_site:    !! entry.default_on_site,
					email_freq: entry.default_email_freq || 'immediate',
				};
			} );
			ctx.prefs = next;

			var spaces = Object.assign( {}, ctx.spacePrefs || {} );
			Object.keys( spaces ).forEach( function ( id ) {
				spaces[ id ] = 'all';
			} );
			ctx.spacePrefs = spaces;

			ctx.isDirty = true;
			ctx.resetConfirmOpen = false;
			syncDirtyAttr( true );
		},
	},
} );
