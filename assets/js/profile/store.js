/* BuddyNext — Profile Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';

/* ── Shared helpers ────────────────────────────────────────────────── */

var slugTimer = null;
var slugAbort = null;

function apiUrl( path ) {
	return ( window.wpApiSettings && window.wpApiSettings.root || '/wp-json/' ) + path;
}

function nonce() {
	return getContext().restNonce || '';
}

/* Collect all named flat inputs/textareas (excluding the slug input). */
function collectFlatData( wrap ) {
	var data = {};
	wrap.querySelectorAll( 'input[name], textarea[name], select[name]' ).forEach( function ( el ) {
		if ( el.id !== 'bn-ep-slug' ) {
			data[ el.name ] = el.value;
		}
	} );
	return data;
}

/* Collect repeater entries from a container by data-entry-index children. */
function collectRepeaterEntries( containerId ) {
	var container = document.getElementById( containerId );
	if ( ! container ) { return []; }
	var entries = [];
	container.querySelectorAll( '.bn-ep-repeater-entry' ).forEach( function ( row ) {
		var entry = {};
		row.querySelectorAll( 'input[name], textarea[name]' ).forEach( function ( el ) {
			// name="work_experience[0][work_company]" → extract inner key
			var m = el.name.match( /\[\d+\]\[([^\]]+)\]$/ );
			if ( m ) { entry[ m[1] ] = el.value; }
		} );
		entries.push( entry );
	} );
	return entries;
}

/* Build full save payload: flat fields + repeater groups. */
function buildPayload( ctx ) {
	var wrap = document.querySelector( '[data-wp-interactive="buddynext/profile"]' );
	var data = collectFlatData( wrap );
	data.interests       = ctx.interests.join( ',' );
	data.work_experience = collectRepeaterEntries( 'bn-ep-work-entries' );
	data.education       = collectRepeaterEntries( 'bn-ep-edu-entries' );
	return data;
}

/* Shared save logic. */
async function doSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	ctx.saved  = false;
	try {
		await fetch( apiUrl( 'buddynext/v1/me/profile' ), {
			method:  'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
			body:    JSON.stringify( buildPayload( ctx ) ),
		} );
		ctx.saved = true;
		setTimeout( function () { ctx.saved = false; }, 3000 );
	} catch ( _e ) {
		// Autosave — fail silently.
	} finally {
		ctx.saving = false;
	}
}

/* Re-number visible repeater entries after add/remove. */
function renumberEntries( containerId ) {
	var container = document.getElementById( containerId );
	if ( ! container ) { return; }
	var entries = container.querySelectorAll( '.bn-ep-repeater-entry' );
	entries.forEach( function ( row, i ) {
		var numEl = row.querySelector( '.bn-ep-repeater-num' );
		if ( numEl ) { numEl.textContent = String( i + 1 ); }
		row.dataset.entryIndex = String( i );
		// Update all input names to reflect new index.
		row.querySelectorAll( '[name]' ).forEach( function ( el ) {
			el.name = el.name.replace( /\[\d+\]/, '[' + i + ']' );
		} );
		var removeBtn = row.querySelector( '.bn-ep-repeater-remove' );
		if ( removeBtn ) { removeBtn.dataset.entryIndex = String( i ); }
	} );
}

/* Build a blank repeater entry DOM node for a given group. */
function buildEntryNode( group, index ) {
	var groupConfig = {
		work_experience: {
			containerId: 'bn-ep-work-entries',
			removeLabel: 'Remove this position',
			fields: [
				{ key: 'work_company',     label: 'Company',     type: 'text',     placeholder: 'Company name' },
				{ key: 'work_title',       label: 'Job Title',   type: 'text',     placeholder: 'Your role' },
				{ key: 'work_location',    label: 'Location',    type: 'text',     placeholder: 'City or Remote' },
				{ key: 'work_daterange',   label: 'Date Range',  type: 'text',     placeholder: 'e.g. Jan 2020 \u2013 Present' },
				{ key: 'work_description', label: 'Description', type: 'textarea', placeholder: 'Brief description of your role', fullWidth: true },
			],
		},
		education: {
			containerId: 'bn-ep-edu-entries',
			removeLabel: 'Remove this entry',
			fields: [
				{ key: 'edu_institution', label: 'Institution',    type: 'text', placeholder: 'School or University' },
				{ key: 'edu_degree',      label: 'Degree',         type: 'text', placeholder: 'e.g. Bachelor of Science' },
				{ key: 'edu_field',       label: 'Field of Study', type: 'text', placeholder: 'e.g. Computer Science' },
				{ key: 'edu_daterange',   label: 'Date Range',     type: 'text', placeholder: 'e.g. 2016 \u2013 2020' },
			],
		},
	};
	var cfg = groupConfig[ group ];
	if ( ! cfg ) { return null; }

	var entry = document.createElement( 'div' );
	entry.className          = 'bn-ep-repeater-entry';
	entry.dataset.entryIndex = String( index );

	var header = document.createElement( 'div' );
	header.className = 'bn-ep-repeater-header';
	var num = document.createElement( 'span' );
	num.className   = 'bn-ep-repeater-num';
	num.textContent = String( index + 1 );
	var removeBtn = document.createElement( 'button' );
	removeBtn.className              = 'bn-ep-repeater-remove';
	removeBtn.type                   = 'button';
	removeBtn.dataset.group          = group;
	removeBtn.dataset.entryIndex     = String( index );
	removeBtn.setAttribute( 'aria-label', cfg.removeLabel );
	removeBtn.textContent            = '\u00d7';
	// Wire the click via a simple DOM listener (outside Interactivity API reactive context).
	removeBtn.addEventListener( 'click', function () {
		entry.remove();
		renumberEntries( cfg.containerId );
	} );
	header.appendChild( num );
	header.appendChild( removeBtn );
	entry.appendChild( header );

	var row = null;
	cfg.fields.forEach( function ( fieldDef, fi ) {
		if ( fieldDef.fullWidth ) {
			var grp = document.createElement( 'div' );
			grp.className = 'bn-ep-group';
			var lbl = document.createElement( 'label' );
			lbl.className   = 'bn-ep-label';
			lbl.textContent = fieldDef.label;
			var ta = document.createElement( 'textarea' );
			ta.className   = 'bn-ep-input';
			ta.name        = group + '[' + index + '][' + fieldDef.key + ']';
			ta.rows        = 3;
			ta.placeholder = fieldDef.placeholder;
			grp.appendChild( lbl );
			grp.appendChild( ta );
			entry.appendChild( grp );
			row = null;
			return;
		}
		if ( fi % 2 === 0 ) {
			row = document.createElement( 'div' );
			row.className = 'bn-ep-repeater-row';
			entry.appendChild( row );
		}
		var grp = document.createElement( 'div' );
		grp.className = 'bn-ep-group';
		var lbl = document.createElement( 'label' );
		lbl.className   = 'bn-ep-label';
		lbl.textContent = fieldDef.label;
		var inp = document.createElement( 'input' );
		inp.className   = 'bn-ep-input';
		inp.type        = fieldDef.type;
		inp.name        = group + '[' + index + '][' + fieldDef.key + ']';
		inp.placeholder = fieldDef.placeholder;
		grp.appendChild( lbl );
		grp.appendChild( inp );
		if ( row ) { row.appendChild( grp ); }
	} );

	return entry;
}

/* ── Store ─────────────────────────────────────────────────────────── */

store( 'buddynext/profile', {
	actions: {

		/* Main profile save (blur autosave and explicit save button). */
		autosave:    function () { doSave( getContext() ); },
		saveProfile: function () { doSave( getContext() ); },

		/* Slug availability check — debounced 400 ms. */
		checkSlug: function () {
			var ctx   = getContext();
			var input = document.getElementById( 'bn-ep-slug' );
			if ( ! input ) { return; }

			var raw  = input.value.trim().toLowerCase();
			var slug = raw.replace( /[^a-z0-9-]/g, '-' )
			              .replace( /-{2,}/g, '-' )
			              .replace( /^-|-$/g, '' );
			input.value = slug;

			if ( slug === '' ) {
				ctx.slugAvailable = null;
				ctx.slugChecking  = false;
				return;
			}

			ctx.slugChecking  = true;
			ctx.slugAvailable = null;
			clearTimeout( slugTimer );
			if ( slugAbort ) { slugAbort.abort(); }

			slugTimer = setTimeout( function () {
				slugAbort = new AbortController();
				fetch(
					apiUrl( 'buddynext/v1/profile-slug/check?slug=' + encodeURIComponent( slug ) ),
					{ headers: { 'X-WP-Nonce': ctx.restNonce }, signal: slugAbort.signal }
				).then( function ( r ) { return r.json(); } )
				 .then( function ( json ) {
				 	ctx.slugAvailable = json.available;
				 	ctx.slugChecking  = false;
				 } )
				 .catch( function ( e ) {
				 	if ( e.name !== 'AbortError' ) { ctx.slugChecking = false; }
				 } );
			}, 400 );
		},

		/* Save a custom profile slug. */
		saveSlug: function () {
			var ctx   = getContext();
			var input = document.getElementById( 'bn-ep-slug' );
			if ( ! input || ! ctx.slugAvailable ) { return; }
			var slug = input.value.trim();
			if ( ! slug ) { return; }

			ctx.slugSaving = true;
			ctx.slugSaved  = false;

			fetch( apiUrl( 'buddynext/v1/me/profile-slug' ), {
				method:  'PUT',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
				body:    JSON.stringify( { slug: slug } ),
			} ).then( function ( r ) { return r.json(); } )
			   .then( function ( json ) {
			   	ctx.profileUrl  = json.url;
			   	ctx.profileSlug = json.slug;
			   	ctx.slugSaved   = true;
			   	setTimeout( function () { ctx.slugSaved = false; }, 3000 );
			   } )
			   .catch( function () {} )
			   .finally( function () { ctx.slugSaving = false; } );
		},

		/* Add a blank repeater entry. */
		addEntry: function ( event ) {
			var btn   = event.target.closest( '[data-group]' );
			var group = btn ? btn.dataset.group : null;
			if ( ! group ) { return; }

			var containerMap = {
				work_experience: 'bn-ep-work-entries',
				education:       'bn-ep-edu-entries',
			};
			var containerId = containerMap[ group ];
			if ( ! containerId ) { return; }

			var container = document.getElementById( containerId );
			if ( ! container ) { return; }

			var index = container.querySelectorAll( '.bn-ep-repeater-entry' ).length;
			var node  = buildEntryNode( group, index );
			if ( node ) { container.appendChild( node ); }
		},

		/* Remove a repeater entry (data-group + data-entry-index on the button). */
		removeEntry: function ( event ) {
			var btn = event.target.closest( '[data-group]' );
			if ( ! btn ) { return; }

			var group = btn.dataset.group;
			var containerMap = {
				work_experience: 'bn-ep-work-entries',
				education:       'bn-ep-edu-entries',
			};
			var containerId = containerMap[ group ];

			var entryEl = btn.closest( '.bn-ep-repeater-entry' );
			if ( entryEl ) { entryEl.remove(); }
			if ( containerId ) { renumberEntries( containerId ); }
		},

		/* Interests / Skills tag input. */
		addInterestOnEnter: function ( event ) {
			if ( event.key !== 'Enter' ) { return; }
			event.preventDefault();
			var ctx = getContext();
			var val = event.target.value.trim();
			if ( val && ctx.interests.indexOf( val ) === -1 ) {
				ctx.interests = ctx.interests.concat( [ val ] );
			}
			event.target.value = '';
		},

		removeInterest: function ( event ) {
			var ctx      = getContext();
			var btn      = event.target.closest( '[data-interest]' );
			var interest = btn ? btn.dataset.interest : null;
			if ( interest ) {
				ctx.interests = ctx.interests.filter( function ( i ) { return i !== interest; } );
			}
		},

		focusTagInput: function () {
			var el = document.querySelector( '.bn-ep-tag-input' );
			if ( el ) { el.focus(); }
		},

		/* Avatar + cover file triggers. */
		triggerCoverUpload: function () {
			var el = document.getElementById( 'bn-ep-cover-file' );
			if ( el ) { el.click(); }
		},
		triggerAvatarUpload: function () {
			var el = document.getElementById( 'bn-ep-avatar-file' );
			if ( el ) { el.click(); }
		},

		handleAvatarFileChange: async function ( event ) {
			var file = event.target.files[ 0 ];
			if ( ! file ) { return; }

			var formData = new FormData();
			formData.append( 'avatar', file );

			var ctx = getContext();
			ctx.avatarUploading = true;

			try {
				var res  = await fetch( apiUrl( 'buddynext/v1/me/avatar' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': nonce() },
					body:    formData,
				} );
				var data = await res.json();
				if ( res.ok && data.avatar_url ) {
					ctx.avatarUrl = data.avatar_url;
				}
			} finally {
				ctx.avatarUploading  = false;
				event.target.value   = '';
			}
		},

		handleCoverFileChange: async function ( event ) {
			var file = event.target.files[ 0 ];
			if ( ! file ) { return; }

			var formData = new FormData();
			formData.append( 'avatar', file );

			var ctx = getContext();
			ctx.coverUploading = true;

			try {
				var res  = await fetch( apiUrl( 'buddynext/v1/me/cover' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': nonce() },
					body:    formData,
				} );
				var data = await res.json();
				if ( res.ok && data.cover_url ) {
					ctx.coverUrl = data.cover_url;
				}
			} finally {
				ctx.coverUploading = false;
				event.target.value = '';
			}
		},

		/* ── Social actions (profile view page) ─────────────────────── */

		follow: async function () {
			var ctx = getContext();
			if ( ctx.isFollowing ) { return; }
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/follow' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.isFollowing   = true;
					ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
				}
			} catch ( _e ) {}
		},

		unfollow: async function () {
			var ctx = getContext();
			if ( ! ctx.isFollowing ) { return; }
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/follow' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.isFollowing   = false;
					ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
				}
			} catch ( _e ) {}
		},

		connect: async function () {
			var ctx = getContext();
			if ( ! ctx.showConnect ) { return; }
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionPending = true;
					ctx.showConnect       = false;
				}
			} catch ( _e ) {}
		},


		/* ── More-options menu ───────────────────────────────────────── */

		toggleMoreMenu: function () {
			var ctx = getContext();
			ctx.moreMenuOpen = ! ctx.moreMenuOpen;
		},

		toggleMute: async function () {
			var ctx    = getContext();
			var method = ctx.isMuted ? 'DELETE' : 'POST';
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/mute' ), {
					method:  method,
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.isMuted      = ! ctx.isMuted;
					ctx.moreMenuOpen = false;
				}
			} catch ( _e ) {}
		},

		toggleBlock: async function () {
			var ctx    = getContext();
			var method = ctx.isBlocked ? 'DELETE' : 'POST';
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/block' ), {
					method:  method,
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.isBlocked    = ! ctx.isBlocked;
					ctx.moreMenuOpen = false;
				}
			} catch ( _e ) {}
		},

		reportUser: async function () {
			var ctx = getContext();
			try {
				await fetch( apiUrl( 'buddynext/v1/reports' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( { object_type: 'user', object_id: ctx.profileUserId } ),
				} );
			} catch ( _e ) {}
			ctx.moreMenuOpen = false;
		},
	},
} );
