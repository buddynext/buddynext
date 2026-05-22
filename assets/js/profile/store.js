/* BuddyNext - Profile Interactivity API store. */
import { store, getContext } from '@wordpress/interactivity';
import { bnToast } from '../shell/dialog.js';

/* -- Shared helpers ----------------------------------------------------- */

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
		// Skip the slug input (handled by its own endpoint) and any repeater fields.
		if ( el.id === 'bn-ep-slug' ) { return; }
		if ( /\[\d+\]\[/.test( el.name ) ) { return; }
		data[ el.name ] = el.value;
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

/* Validate a single URL value client-side (matches PHP wp_http_validate_url). */
function isValidUrlClient( raw ) {
	if ( ! raw ) { return true; }
	var candidate = /^https?:\/\//i.test( raw ) ? raw : 'https://' + raw.replace( /^\/+/, '' );
	try {
		var u = new URL( candidate );
		return u.protocol === 'http:' || u.protocol === 'https:';
	} catch ( _e ) {
		return false;
	}
}

/* Reset errors object (Interactivity state cannot mutate keys via delete). */
function clearErrors( ctx ) {
	ctx.errors = {};
}

/* Master save flow - submits all fields, handles 200 / 422 / 5xx. */
async function doSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	ctx.saved  = false;
	clearErrors( ctx );

	try {
		var res = await fetch( apiUrl( 'buddynext/v1/me/profile' ), {
			method:  'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
			body:    JSON.stringify( buildPayload( ctx ) ),
		} );

		var json = {};
		try { json = await res.json(); } catch ( _e ) {}

		if ( res.ok ) {
			ctx.saved   = true;
			ctx.isDirty = false;
			bnToast( ( window.bnI18n && window.bnI18n.profileSaved ) || 'Profile saved', { tone: 'success' } );
			setTimeout( function () { ctx.saved = false; }, 3000 );
		} else if ( res.status === 422 && json && json.errors ) {
			ctx.errors = json.errors;
			bnToast( ( window.bnI18n && window.bnI18n.fieldsNeedAttention ) || 'Some fields need attention', { tone: 'danger' } );
		} else {
			bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not save. Please try again.', { tone: 'danger' } );
		}
	} catch ( _e ) {
		bnToast( ( window.bnI18n && window.bnI18n.saveFailed ) || 'Could not save. Please try again.', { tone: 'danger' } );
	} finally {
		ctx.saving = false;
	}
}

/* Silent autosave - used by per-field blur where defined. Failure stays quiet. */
async function doAutoSave( ctx ) {
	if ( ctx.saving ) { return; }
	ctx.saving = true;
	try {
		var res = await fetch( apiUrl( 'buddynext/v1/me/profile' ), {
			method:  'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce() },
			body:    JSON.stringify( buildPayload( ctx ) ),
		} );
		if ( res.ok ) {
			ctx.saved   = true;
			ctx.isDirty = false;
			setTimeout( function () { ctx.saved = false; }, 3000 );
		}
	} catch ( _e ) {
		/* silent for autosave */
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
				{ key: 'work_daterange',   label: 'Date Range',  type: 'text',     placeholder: 'e.g. Jan 2020 to Present' },
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
				{ key: 'edu_daterange',   label: 'Date Range',     type: 'text', placeholder: 'e.g. 2016 to 2020' },
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
	removeBtn.textContent            = '×';
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

/* Track the beforeunload listener so we can attach once and detach on save. */
var unloadHandlerAttached = false;
function ensureUnloadGuard() {
	if ( unloadHandlerAttached ) { return; }
	window.addEventListener( 'beforeunload', function ( event ) {
		var wrap = document.querySelector( '[data-wp-interactive="buddynext/profile"] .bn-ep-form-shell' );
		if ( ! wrap ) { return; }
		// Read the live context flag from a hidden marker - simplest is a data attribute we update on save/dirty.
		if ( wrap.dataset.bnDirty === '1' ) {
			event.preventDefault();
			event.returnValue = '';
			return '';
		}
	} );
	unloadHandlerAttached = true;
}

/* Mirror the reactive isDirty state onto the form element so beforeunload can read it cheaply. */
function syncDirtyAttr( dirty ) {
	var wrap = document.querySelector( '.bn-ep-form-shell' );
	if ( wrap ) { wrap.dataset.bnDirty = dirty ? '1' : '0'; }
}

/* -- Tab URL sync ------------------------------------------------------- */

var BN_VALID_TABS = [ 'posts', 'replies', 'media', 'likes', 'discussions' ];

function applyTabId( tabId ) {
	if ( ! tabId || BN_VALID_TABS.indexOf( tabId ) === -1 ) {
		tabId = 'posts';
	}
	document.querySelectorAll( '.bn-pf-tabs .bn-tab' ).forEach( function ( t ) {
		var isActive = t.dataset.tab === tabId;
		t.classList.toggle( 'active', isActive );
		t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
	} );
	document.querySelectorAll( '[data-tab-panel]' ).forEach( function ( p ) {
		p.hidden = p.dataset.tabPanel !== tabId;
	} );
	var postsContent = document.querySelector( '.bn-profile-posts-panel' );
	if ( postsContent ) {
		postsContent.hidden = tabId !== 'posts';
	}
}

function pushTabToUrl( tabId ) {
	if ( ! window.history || typeof window.history.pushState !== 'function' ) { return; }
	var url = new URL( window.location.href );
	if ( tabId && tabId !== 'posts' ) {
		url.searchParams.set( 'tab', tabId );
	} else {
		url.searchParams.delete( 'tab' );
	}
	window.history.pushState( { bnTab: tabId }, '', url.toString() );
}

function applyTabFromUrl() {
	var params = new URLSearchParams( window.location.search );
	var tab    = params.get( 'tab' ) || 'posts';
	applyTabId( tab );
}

/* -- Store ------------------------------------------------------------- */

store( 'buddynext/profile', {
	state: {
		get muteLabel()  { return getContext().isMuted   ? 'Unmute'  : 'Mute'; },
		get blockLabel() { return getContext().isBlocked ? 'Unblock' : 'Block'; },
	},
	callbacks: {
		/* Init for the edit page: register the beforeunload guard once. */
		initEditGuard() {
			ensureUnloadGuard();
		},
		/* Init for the view page: read ?tab=... and wire popstate. */
		initView() {
			applyTabFromUrl();
			if ( ! window.__bnProfilePopstateBound ) {
				window.addEventListener( 'popstate', applyTabFromUrl );
				window.__bnProfilePopstateBound = true;
			}
		},
	},
	actions: {

		/* Profile tab switching - Posts / Replies / Media / Likes
		 *
		 * Updates aria/active state on tab buttons, toggles panels, and
		 * pushes the active tab into the URL (?tab=replies) so reload +
		 * back-button work. The popstate handler in initView() reverses
		 * the transition when the user hits Back.
		 */
		setTab( event ) {
			const tab    = event.target.closest( '[data-tab]' );
			if ( ! tab ) { return; }
			const tabId  = tab.dataset.tab;
			applyTabId( tabId );
			pushTabToUrl( tabId );
		},

		/* Mark form as dirty on any user input. */
		markDirty() {
			var ctx = getContext();
			if ( ! ctx.isDirty ) {
				ctx.isDirty = true;
				syncDirtyAttr( true );
			}
		},

		/* Inline field validation on blur. */
		validateField( event ) {
			var ctx   = getContext();
			var input = event.target;
			if ( ! input || ! input.name ) { return; }
			var name  = input.name;
			var val   = ( input.value || '' ).trim();

			// Clone errors to avoid mutating a frozen proxy.
			var errors = Object.assign( {}, ctx.errors || {} );

			if ( name === 'display_name' ) {
				if ( val === '' ) {
					errors.display_name = 'Display name is required.';
				} else {
					delete errors.display_name;
				}
			} else if ( name === 'website' || name.indexOf( 'social_' ) === 0 ) {
				if ( val !== '' && ! isValidUrlClient( val ) ) {
					errors[ name ] = 'Enter a valid URL (https://example.com).';
				} else {
					delete errors[ name ];
				}
			}

			ctx.errors = errors;
		},

		/* Master save action. Triggered by the form submit / Save button. */
		saveProfile( event ) {
			if ( event && typeof event.preventDefault === 'function' ) {
				event.preventDefault();
			}
			var ctx = getContext();

			// Run a client-side pass so we don't bother the server with obviously bad payloads.
			var errors = {};
			var nameInput = document.getElementById( 'bn-ep-name' );
			if ( nameInput && ( nameInput.value || '' ).trim() === '' ) {
				errors.display_name = 'Display name is required.';
				nameInput.focus();
			}
			[ 'website', 'social_twitter', 'social_linkedin', 'social_github', 'social_instagram', 'social_youtube' ].forEach( function ( fname ) {
				var el = document.querySelector( '[name="' + fname + '"]' );
				if ( ! el ) { return; }
				var v = ( el.value || '' ).trim();
				if ( v !== '' && ! isValidUrlClient( v ) ) {
					errors[ fname ] = 'Enter a valid URL (https://example.com).';
				}
			} );
			if ( Object.keys( errors ).length > 0 ) {
				ctx.errors = errors;
				bnToast( 'Some fields need attention', { tone: 'danger' } );
				return;
			}

			doSave( ctx ).then( function () {
				syncDirtyAttr( ctx.isDirty );
				if ( ctx.saved ) {
					// Smooth redirect after save: profileUrl is in context.
					setTimeout( function () {
						if ( ctx.profileUrl ) {
							window.location.href = ctx.profileUrl;
						}
					}, 700 );
				}
			} );
		},

		/* Cancel guard - the beforeunload listener handles the dirty-state prompt
		 * for any navigation away from the edit page (link clicks, back button,
		 * tab close). This action is a no-op kept for compatibility with any
		 * older template that still references it. */
		confirmCancel() {
			/* no-op: handled by beforeunload guard */
		},

		/* Per-field autosave kept for backwards compatibility (used by sliders / toggles). */
		autosave() { doAutoSave( getContext() ); },

		/* Slug availability check - debounced 400 ms. */
		checkSlug() {
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

		saveSlug() {
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

		addEntry( event ) {
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
			// Adding a row counts as a dirty edit.
			getContext().isDirty = true;
			syncDirtyAttr( true );
		},

		removeEntry( event ) {
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
			getContext().isDirty = true;
			syncDirtyAttr( true );
		},

		addInterestOnEnter( event ) {
			if ( event.key !== 'Enter' ) { return; }
			event.preventDefault();
			var ctx = getContext();
			var val = event.target.value.trim();
			if ( val && ctx.interests.indexOf( val ) === -1 ) {
				ctx.interests = ctx.interests.concat( [ val ] );
				ctx.isDirty   = true;
				syncDirtyAttr( true );
			}
			event.target.value = '';
		},

		removeInterest( event ) {
			var ctx      = getContext();
			var btn      = event.target.closest( '[data-interest]' );
			var interest = btn ? btn.dataset.interest : null;
			if ( interest ) {
				ctx.interests = ctx.interests.filter( function ( i ) { return i !== interest; } );
				ctx.isDirty   = true;
				syncDirtyAttr( true );
			}
		},

		focusTagInput() {
			var el = document.querySelector( '.bn-ep-tag-input' );
			if ( el ) { el.focus(); }
		},

		triggerCoverUpload() {
			var el = document.getElementById( 'bn-ep-cover-file' );
			if ( el ) { el.click(); }
		},
		triggerAvatarUpload() {
			var el = document.getElementById( 'bn-ep-avatar-file' );
			if ( el ) { el.click(); }
		},

		async handleAvatarFileChange( event ) {
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
					bnToast( 'Avatar updated', { tone: 'success' } );
				}
			} finally {
				ctx.avatarUploading  = false;
				event.target.value   = '';
			}
		},

		async handleCoverFileChange( event ) {
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
					bnToast( 'Cover updated', { tone: 'success' } );
				}
			} finally {
				ctx.coverUploading = false;
				event.target.value = '';
			}
		},

		/* -- Social actions (profile view page) ------------------------- */

		async follow() {
			var ctx = getContext();
			if ( ctx.isFollowing ) { return; }
			// Optimistic.
			ctx.isFollowing   = true;
			ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/follow' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'follow_failed' ); }
				bnToast( 'Followed', { tone: 'success' } );
			} catch ( _e ) {
				ctx.isFollowing   = false;
				ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
				bnToast( 'Could not follow. Try again.', { tone: 'danger' } );
			}
		},

		async unfollow() {
			var ctx = getContext();
			if ( ! ctx.isFollowing ) { return; }
			ctx.isFollowing   = false;
			ctx.followerCount = Math.max( 0, ( ctx.followerCount || 1 ) - 1 );
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/follow' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'unfollow_failed' ); }
				bnToast( 'Unfollowed', { tone: 'info' } );
			} catch ( _e ) {
				ctx.isFollowing   = true;
				ctx.followerCount = ( ctx.followerCount || 0 ) + 1;
				bnToast( 'Could not unfollow. Try again.', { tone: 'danger' } );
			}
		},

		async connect() {
			var ctx = getContext();
			if ( ! ctx.showConnect ) { return; }
			ctx.connectionPending = true;
			ctx.showConnect       = false;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'connect_failed' ); }
				bnToast( 'Connection request sent', { tone: 'success' } );
			} catch ( _e ) {
				ctx.connectionPending = false;
				ctx.showConnect       = true;
				bnToast( 'Could not send request', { tone: 'danger' } );
			}
		},

		async withdrawRequest() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionPending = false;
					ctx.showConnect       = true;
					bnToast( 'Request withdrawn', { tone: 'info' } );
				}
			} catch ( _e ) {}
		},

		async acceptRequest() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect/accept' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionReceived = false;
					ctx.isConnected        = true;
					ctx.showConnect        = false;
					bnToast( 'Connected', { tone: 'success' } );
				}
			} catch ( _e ) {}
		},

		async declineRequest() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect/decline' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.connectionReceived = false;
					ctx.showConnect        = true;
					bnToast( 'Request declined', { tone: 'info' } );
				}
			} catch ( _e ) {}
		},

		async disconnectUser() {
			var ctx = getContext();
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/connect' ), {
					method:  'DELETE',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( res.ok ) {
					ctx.isConnected       = false;
					ctx.showConnect       = true;
					ctx.connectionPending = false;
					bnToast( 'Disconnected', { tone: 'info' } );
				}
			} catch ( _e ) {}
		},


		/* -- More-options menu -------------------------------------- */

		toggleMoreMenu() {
			var ctx = getContext();
			ctx.moreMenuOpen = ! ctx.moreMenuOpen;
		},

		async toggleMute() {
			var ctx    = getContext();
			var wasMuted = !! ctx.isMuted;
			// Optimistic.
			ctx.isMuted      = ! wasMuted;
			ctx.moreMenuOpen = false;
			var method = wasMuted ? 'DELETE' : 'POST';
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/mute' ), {
					method:  method,
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'mute_failed' ); }
				bnToast( wasMuted ? 'Unmuted' : 'Muted', { tone: 'success' } );
			} catch ( _e ) {
				ctx.isMuted = wasMuted;
				bnToast( 'Could not update mute state', { tone: 'danger' } );
			}
		},

		/* Block requires an explicit confirmation modal - destructive action. */
		toggleBlock() {
			var ctx = getContext();
			if ( ctx.isBlocked ) {
				// Unblock is reversible - no confirm needed.
				doUnblock( ctx );
				return;
			}
			ctx.blockConfirmOpen = true;
			ctx.moreMenuOpen     = false;
		},

		closeBlockConfirm() {
			getContext().blockConfirmOpen = false;
		},

		async confirmBlock() {
			var ctx = getContext();
			if ( ctx.blockSubmitting ) { return; }
			ctx.blockSubmitting = true;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/block' ), {
					method:  'POST',
					headers: { 'X-WP-Nonce': ctx.restNonce },
				} );
				if ( ! res.ok ) { throw new Error( 'block_failed' ); }
				ctx.isBlocked        = true;
				ctx.blockConfirmOpen = false;
				bnToast( ( ctx.displayName ? ctx.displayName + ' blocked' : 'Member blocked' ), { tone: 'success' } );
				// After block we redirect to the members directory since the profile is no longer accessible.
				setTimeout( function () {
					window.location.href = ( ctx.peopleUrl || '/members/' );
				}, 800 );
			} catch ( _e ) {
				bnToast( 'Could not block. Try again.', { tone: 'danger' } );
			} finally {
				ctx.blockSubmitting = false;
			}
		},

		/* -- Report modal ----------------------------------------------- */

		openReport() {
			var ctx = getContext();
			ctx.reportOpen      = true;
			ctx.reportReason    = 'spam';
			ctx.reportNotes     = '';
			ctx.moreMenuOpen    = false;
		},

		closeReport() {
			getContext().reportOpen = false;
		},

		setReportReason( event ) {
			getContext().reportReason = event.target.value;
		},

		setReportNotes( event ) {
			getContext().reportNotes = event.target.value;
		},

		async submitReport() {
			var ctx = getContext();
			if ( ctx.reportSubmitting ) { return; }
			ctx.reportSubmitting = true;
			try {
				var res = await fetch( apiUrl( 'buddynext/v1/reports' ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ctx.restNonce },
					body:    JSON.stringify( {
						object_type: 'user',
						object_id:   ctx.profileUserId,
						reason:      ctx.reportReason || 'other',
						notes:       ctx.reportNotes  || '',
					} ),
				} );
				if ( ! res.ok && res.status !== 201 ) { throw new Error( 'report_failed' ); }
				ctx.reportOpen = false;
				bnToast( 'Report submitted. Thanks for keeping the community safe.', { tone: 'success' } );
			} catch ( _e ) {
				bnToast( 'Could not submit report. Try again.', { tone: 'danger' } );
			} finally {
				ctx.reportSubmitting = false;
			}
		},
	},
} );

/* -- Helpers that need access to the store -------------------------- */

async function doUnblock( ctx ) {
	var wasBlocked = !! ctx.isBlocked;
	ctx.isBlocked    = false;
	ctx.moreMenuOpen = false;
	try {
		var res = await fetch( apiUrl( 'buddynext/v1/users/' + ctx.profileUserId + '/block' ), {
			method:  'DELETE',
			headers: { 'X-WP-Nonce': ctx.restNonce },
		} );
		if ( ! res.ok ) { throw new Error( 'unblock_failed' ); }
		bnToast( 'Unblocked', { tone: 'info' } );
	} catch ( _e ) {
		ctx.isBlocked = wasBlocked;
		bnToast( 'Could not unblock', { tone: 'danger' } );
	}
}
