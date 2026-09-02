/* BuddyNext — interactive media lightbox.
 *
 * BN-owned UX (no WPMediaVerse JS). Binds to media tiles rendered by
 * BuddyNext\Media\MediaRenderer (`[data-bn-media-id]` image/video buttons),
 * opens the BN lightbox shell (templates/partials/media-lightbox.php) with a
 * media stage + an interaction panel, and drives the panel (author, views,
 * reactions, favorite, share, download, open, comments) by calling the engine
 * REST routes (mvs/v1/media/{id}/...) — API level only. Config + nonce come
 * from the localized `bnMedia` object.
 */
( function () {
	'use strict';

	// Translation: classic script reading from the global wp.i18n with a safe
	// identity fallback (bn-media-lightbox declares wp-i18n + wp_set_script_translations).
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	var cfg = window.bnMedia || {};
	var REST = ( cfg.mvsRest || '' ).replace( /\/$/, '' );
	var I18N = cfg.i18n || {};
	var LOGGED_IN = ( cfg.userId || 0 ) > 0;

	var overlay = null;
	var stage = null;
	var panel = {};
	var gallery = [];   // [{id,type,src,poster,alt}]
	var index = 0;
	var lastFocus = null;
	var current = null; // current media id

	function shell() {
		if ( overlay ) { return overlay; }
		overlay = document.querySelector( '.bn-lightbox' );
		if ( ! overlay ) { return null; }
		stage = overlay.querySelector( '[data-bn-lb-stage]' );
		panel = {
			author:   overlay.querySelector( '[data-bn-lb-author]' ),
			views:    overlay.querySelector( '[data-bn-lb-views]' ),
			comments: overlay.querySelector( '[data-bn-lb-comments]' ),
			favorite: overlay.querySelector( '[data-bn-lb-favorite]' ),
			report:   overlay.querySelector( '[data-bn-lb-report]' ),
			block:    overlay.querySelector( '[data-bn-lb-block]' ),
			download: overlay.querySelector( '[data-bn-lb-download]' ),
			form:     overlay.querySelector( '[data-bn-lb-comment-form]' ),
			input:    overlay.querySelector( '[data-bn-lb-comment-input]' ),
			save:     overlay.querySelector( '[data-bn-lb-save]' ),
			edit:     overlay.querySelector( '[data-bn-lb-edit]' ),
			unlink:   overlay.querySelector( '[data-bn-lb-unlink]' ),
			more:     overlay.querySelector( '[data-bn-lb-more]' ),
			moreWrap: overlay.querySelector( '[data-bn-lb-more-wrap]' ),
			menu:     overlay.querySelector( '[data-bn-lb-menu]' ),
			extra:    overlay.querySelector( '[data-bn-lb-panel]' ),
			fullscr:  overlay.querySelector( '[data-bn-lb-fullscreen]' ),
			// DM full-bleed chrome (sender + download float over the stage; the
			// side panel is hidden for private 1:1 media).
			dmAuthor:   overlay.querySelector( '[data-bn-lb-dm-author]' ),
			dmDownload: overlay.querySelector( '[data-bn-lb-dm-download]' ),
		};

		// Delegated controls (close / prev / next).
		overlay.addEventListener( 'click', function ( e ) {
			var t = e.target.closest( '[data-bn-lb-close],[data-bn-lb-prev],[data-bn-lb-next]' );
			if ( ! t ) { return; }
			if ( t.hasAttribute( 'data-bn-lb-close' ) ) { close(); }
			else if ( t.hasAttribute( 'data-bn-lb-prev' ) ) { step( -1 ); }
			else if ( t.hasAttribute( 'data-bn-lb-next' ) ) { step( 1 ); }
		} );

		// Reactions.
		overlay.querySelectorAll( '.bn-lightbox__reaction' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { react( btn.getAttribute( 'data-reaction' ) ); } );
		} );
		// Favorite + share.
		if ( panel.favorite ) { panel.favorite.addEventListener( 'click', favorite ); }
		// Only wire Report if the shared dialog is actually on the page. The button stays
		// hidden otherwise — a missing control beats one that does nothing when clicked.
		if ( panel.report && typeof window.bnReportDialog === 'function' ) {
			panel.report.addEventListener( 'click', report );
		}
		if ( panel.block ) { panel.block.addEventListener( 'click', blockAuthor ); }
		if ( panel.edit ) { panel.edit.addEventListener( 'click', function () { closeMenu(); openEditPanel(); } ); }
		if ( panel.save ) { panel.save.addEventListener( 'click', toggleSave ); }
		if ( panel.unlink ) { panel.unlink.addEventListener( 'click', unlinkFromSpace ); }
		// The ⋯ overflow: toggle on the trigger, close on outside-click / Escape and
		// after any item is chosen.
		if ( panel.more ) {
			panel.more.addEventListener( 'click', function ( e ) { e.stopPropagation(); toggleMenu(); } );
		}
		document.addEventListener( 'click', function ( e ) {
			if ( panel.moreWrap && ! panel.moreWrap.contains( e.target ) ) { closeMenu(); }
		} );
		if ( panel.fullscr ) { panel.fullscr.addEventListener( 'click', toggleFullscreen ); }
		var shareBtn = overlay.querySelector( '[data-bn-lb-share]' );
		if ( shareBtn ) { shareBtn.addEventListener( 'click', share ); }
		// Comment submit.
		if ( panel.form ) {
			panel.form.addEventListener( 'submit', function ( e ) { e.preventDefault(); addComment(); } );
		}
		return overlay;
	}

	// ── REST ────────────────────────────────────────────────────────────────

	/*
	 * ONE PHOTO, ONE SET OF REACTIONS.
	 *
	 * A photo in the feed is two objects: a MediaVerse media item and the
	 * BuddyNext post it was posted in. Each has its own reaction store, and the
	 * lightbox used to write the media one - so a reaction left here was
	 * invisible on the feed card, and the post's own reactions were invisible
	 * here (Basecamp 10259250229). The comment side of the same split was
	 * already closed by mirroring lightbox comments into the post thread.
	 *
	 * So: when the media has a BuddyNext post parent, the lightbox reads and
	 * writes the POST reaction, which is the one a member sees everywhere else.
	 * Media with no post parent - the library, DM media - keeps the MediaVerse
	 * path, because there is no post to speak for it.
	 *
	 * currentPostId is resolved per item from /media/{id}/space-context, the
	 * call the lightbox already makes on every open.
	 */
	var currentPostId = 0;

	/**
	 * Paint the POST's reaction summary onto the chip strip.
	 *
	 * `forMediaId` guards against a stale response repainting a photo the
	 * viewer has already scrolled past.
	 */
	function paintPostReactions( forMediaId, postId ) {
		bnApi( '/reactions?object_type=post&object_id=' + postId ).then( function ( r ) {
			if ( forMediaId === current ) { applyReactions( bnReactionShape( r ) ); }
		} ).catch( function () {} );
	}

	// The BuddyNext namespace, for the objects BuddyNext owns. `api()` below
	// talks to the media engine.
	function bnApi( path, opts ) {
		opts = opts || {};
		var init = {
			nonce: cfg.nonce || '',
			method: opts.method,
			toastOnError: false,
		};
		if ( opts.json ) { init.body = opts.json; }
		return window.buddynextRest.restFetch( path, init ).then( function ( res ) {
			return res.ok ? ( res.data || {} ) : Promise.reject( res );
		} );
	}

	/** Normalise a BuddyNext reaction payload into the shape applyReactions() reads. */
	function bnReactionShape( r ) {
		var counts = {};
		( ( r && r.summary ) || [] ).forEach( function ( row ) {
			if ( row && row.slug ) { counts[ row.slug ] = parseInt( row.count, 10 ) || 0; }
		} );
		return { counts: counts, user_reaction: ( r && r.has_reacted ) ? ( r.emoji || null ) : null };
	}

	function api( path, opts ) {
		opts = opts || {};
		var init = {
			base: REST,
			nonce: cfg.nonce || '',
			method: opts.method,
			toastOnError: false,
		};
		if ( opts.json ) {
			init.body = opts.json;
		}
		return window.buddynextRest.restFetch( path, init ).then( function ( res ) {
			return res.ok ? ( res.data || {} ) : Promise.reject( res );
		} );
	}

	// ── Media stage ───────────────────────────────────────────────────────────
	function tileToItem( tile ) {
		var img = tile.querySelector( '.bn-media-tile__img' );
		return {
			id:     parseInt( tile.getAttribute( 'data-bn-media-id' ), 10 ) || 0,
			type:   tile.getAttribute( 'data-media-type' ) || 'image',
			src:    tile.getAttribute( 'data-media-src' ) || ( img ? img.src : '' ),
			poster: img ? img.src : '',
			alt:    tile.getAttribute( 'aria-label' ) || '',
			// Source post the media came from (present only when it was posted).
			// Drives Share: with a post id the lightbox opens that post's rich
			// Share modal; without one it falls back to Copy link.
			postId:    parseInt( tile.getAttribute( 'data-post-id' ), 10 ) || 0,
			permalink: tile.getAttribute( 'data-post-permalink' ) || '',
			// Private DM media: the lightbox hides social chrome (reactions,
			// comments, favorite, share) and skips those REST calls.
			dm:     '1' === tile.getAttribute( 'data-bn-dm' ),
		};
	}

	function renderMedia() {
		if ( ! stage || ! gallery.length ) { return; }
		var item = gallery[ index ];
		while ( stage.firstChild ) { stage.removeChild( stage.firstChild ); }
		var el;
		if ( 'video' === item.type ) {
			el = document.createElement( 'video' );
			el.controls = true; el.autoplay = true; el.playsInline = true;
			if ( item.poster ) { el.setAttribute( 'poster', item.poster ); }
			el.setAttribute( 'src', item.src );
		} else {
			el = document.createElement( 'img' );
			el.setAttribute( 'src', item.src );
			el.setAttribute( 'alt', item.alt || '' );
		}
		el.className = 'bn-lightbox__media';
		stage.appendChild( el );
		overlay.classList.toggle( 'bn-lightbox--has-nav', gallery.length > 1 );
		// Private DM media: switch the lightbox into its slimmed (no social
		// chrome) mode for this item.
		overlay.classList.toggle( 'bn-lightbox--dm', !! item.dm );
	}

	// ── Panel ───────────────────────────────────────────────────────────────
	function clear( node ) { if ( node ) { while ( node.firstChild ) { node.removeChild( node.firstChild ); } } }

	function loadPanel( id ) {
		current = id;
		// Private DM media has no social layer — skip reactions/comments/favorite/
		// views entirely (the chrome is also hidden via .bn-lightbox--dm).
		var isDM = !! ( gallery[ index ] && gallery[ index ].dm );
		// reset transient state
		resetReactions();
		clear( panel.author );
		clear( panel.dmAuthor );
		clear( panel.comments );
		if ( panel.views ) { panel.views.textContent = ''; }
		setFavorite( false );

		// Media meta (author, views, urls). DM media renders into the floating
		// stage chrome (sender + download); social media into the side panel.
		api( '/media/' + id ).then( function ( m ) {
			if ( current !== id ) { return; }
			renderAuthor( m, isDM ? panel.dmAuthor : panel.author );
			applyAbuseControls( m );
			if ( ! isDM ) {
				var views = ( m.stats && m.stats.views ) || 0;
				if ( panel.views ) {
					panel.views.textContent = views + ' ' + ( 1 === views ? ( I18N.view || 'view' ) : ( I18N.views || 'views' ) );
				}
			}
			// Open + Download both target the media file (signed URL) — never the
			// MediaVerse /media/ single page. That page is MediaVerse's own UX;
			// BuddyNext owns the UX and must not send users into the engine UI.
			if ( m.file_url ) {
				var dl = isDM ? panel.dmDownload : panel.download;
				if ( dl ) { dl.setAttribute( 'href', m.file_url ); }
			}
		} ).catch( function () {} );

		// DM media: no favorite / reactions / comments / view tracking. The image
		// and author (from the meta fetch above) are all that show.
		if ( isDM ) { return; }

		// Guests cannot WRITE: favorite/react/report controls are not rendered for
		// them (see media-lightbox.php) and those calls are auth-only, so skip
		// them — no 401 noise, no null panel.
		//
		// Comments are NOT in that group. GET /media/{id}/comments answers 200
		// with the thread to an anonymous caller, so a guest is shown the
		// conversation and a "Log in to comment." line instead of a media that
		// looks like nobody has ever said anything about it. Bailing before this
		// was the JS half of that bug; the template gated the panel, this gated
		// the fetch, and either alone was enough to hide the thread.
		if ( ! LOGGED_IN ) {
			api( '/media/' + id + '/comments' ).then( function ( list ) {
				if ( current === id ) { renderComments( Array.isArray( list ) ? list : ( list.comments || [] ) ); }
			} ).catch( function () {} );
			api( '/media/' + id + '/view', { method: 'POST' } ).catch( function () {} );
			return;
		}

		// Favorite status — reflect the real state on open (the reset above is
		// just the optimistic default until this resolves).
		if ( panel.favorite ) {
			api( '/media/' + id + '/favorite' ).then( function ( r ) {
				if ( current === id && r && typeof r.favorited !== 'undefined' ) {
					setFavorite( !! r.favorited );
				}
			} ).catch( function () {} );
		}

		// Reactions. currentPostId is not known yet on the first paint (the
		// context call is in flight), so read the media's own reactions now and
		// let loadPostContext() re-read from the post when it turns out to have
		// one. The second read only happens for media that IS a post.
		api( '/media/' + id + '/reactions' ).then( function ( r ) {
			if ( current === id && 0 === currentPostId ) { applyReactions( r ); }
		} ).catch( function () {} );

		// Comments.
		api( '/media/' + id + '/comments' ).then( function ( list ) {
			if ( current === id ) { renderComments( Array.isArray( list ) ? list : ( list.comments || [] ) ); }
		} ).catch( function () { renderComments( [] ); } );

		// View tracking (best-effort; logged-in or not).
		api( '/media/' + id + '/view', { method: 'POST' } ).catch( function () {} );
	}

	// The uploader of the media currently open. Needed for Block (which blocks the MEMBER, not
	// the file) and to hide both controls on your own media — nobody reports themselves.
	var currentAuthorId = 0;
	// The media payload for whatever is open — Edit prefills from it.
	var currentMedia = null;
	// The space this media sits on when the viewer may unlink it (0 otherwise).
	var currentSpaceId = 0;

	function openMenu() {
		if ( ! panel.menu || ! panel.more ) { return; }
		panel.menu.hidden = false;
		panel.more.setAttribute( 'aria-expanded', 'true' );
	}
	function closeMenu() {
		if ( ! panel.menu || ! panel.more ) { return; }
		panel.menu.hidden = true;
		panel.more.setAttribute( 'aria-expanded', 'false' );
	}
	function toggleMenu() {
		if ( panel.menu && panel.menu.hidden ) { openMenu(); } else { closeMenu(); }
	}

	// Hide the ⋯ trigger entirely when every item in the menu is hidden for this
	// media — an empty overflow is noise, not an affordance.
	function syncMore() {
		if ( ! panel.moreWrap || ! panel.menu ) { return; }
		var anyVisible = Array.prototype.some.call(
			panel.menu.querySelectorAll( '[role="menuitem"]' ),
			function ( it ) { return ! it.hidden; }
		);
		panel.moreWrap.hidden = ! anyVisible;
		if ( ! anyVisible ) { closeMenu(); }
	}

	// Moderator "Remove from space" — returns the item to its owner's own drive
	// (kept, private), not a delete. The confirm + result strings come from the
	// server i18n dictionary. On success the item is gone from the space, so the
	// lightbox closes.
	function unlinkFromSpace() {
		if ( ! current || currentSpaceId <= 0 ) { return; }
		closeMenu();
		var msg = I18N.unlinkConfirm || 'Remove this from the space?';
		Promise.resolve(
			typeof window.bnConfirm === 'function'
				? window.bnConfirm( { title: msg, tone: 'danger' } )
				// The accessible bnConfirm is exposed on window by shell/dialog.js.
				// If it is somehow not loaded we do NOT fall back to a native
				// window.confirm on a member-facing surface - skip the action, the
				// same way Block and Report no-op when their dialog is absent.
				: Promise.resolve( false )
		).then( function ( ok ) {
			if ( ! ok ) { return; }
			window.buddynextRest.restFetch( '/spaces/' + currentSpaceId + '/media/' + current + '/unlink', {
				nonce: cfg.nonce || '', method: 'POST', toastOnError: false,
			} ).then( function ( res ) {
				if ( res && res.ok ) {
					if ( typeof window.bnToast === 'function' ) { window.bnToast( I18N.unlinkDone || 'Removed from the space.', { tone: 'success' } ); }
					close();
				} else if ( typeof window.bnToast === 'function' ) {
					window.bnToast( I18N.unlinkFail || 'Could not remove it from the space.', { tone: 'danger' } );
				}
			} );
		} );
	}

	function applyAbuseControls( m ) {
		// WPMediaVerse's media payload carries the uploader's numeric id as `author`
		// (MediaController: `'author' => $author_id_raw`, and its own code reads it back as
		// `$author_id = $data['author']`). Read THAT field — do not invent an alias for it.
		//
		// This first shipped keyed on `m.author_id`, which no released WPMediaVerse emits: the
		// id came back undefined, currentAuthorId fell to 0, `mine` was therefore true, and BOTH
		// controls stayed hidden on every site. It passed my own browser check only because the
		// plugin dir here is a symlink to a working copy in which I had added the alias — the
		// one environment on earth where it worked. QA caught it by reading the INSTALLED code.
		currentAuthorId = parseInt( ( m && m.author ) || 0, 10 ) || 0;

		currentMedia = m || null;
		closeExtraPanel();

		// Edit follows the engine's own `can_edit` on the media payload — viewer
		// relative, and computed by the same code that enforces PATCH. Measured:
		// owner true, another member false, anonymous false. Re-deriving "is this
		// mine" here would be a second copy of a rule the server already answers.
		if ( panel.edit ) { panel.edit.hidden = ! ( LOGGED_IN && m && m.can_edit ); }
		// Save bookmarks the POST this photo belongs to, so it lands in the same
		// saved list the feed card's Save writes to. Media with no post parent -
		// the library, DM media - has nothing to bookmark, so the control does not
		// render there at all rather than offering a save with no object. Revealed
		// by the space-context response once the post parent is known.
		if ( panel.save ) {
			panel.save.hidden = true;
			setSaved( false );
		}

		var mine = ! currentAuthorId || currentAuthorId === ( parseInt( cfg.userId, 10 ) || 0 );
		// cfg.canReport mirrors WPMediaVerse's `mvs_reports_enabled` filter. If a site turns
		// reporting off, the endpoint answers 403 — so the button must not be there at all.
		var canReport = LOGGED_IN && ! mine && !! cfg.canReport && typeof window.bnReportDialog === 'function';
		var canBlock  = LOGGED_IN && ! mine && currentAuthorId > 0;

		if ( panel.report ) { panel.report.hidden = ! canReport; }
		if ( panel.block ) { panel.block.hidden = ! canBlock; }

		// Moderator unlink: hidden until a BN check says this media is on a space
		// drive AND the viewer may moderate that space. Reset first (the panel is
		// reused across media), then resolve async and re-sync the ⋯ visibility.
		currentSpaceId = 0;
		if ( panel.unlink ) { panel.unlink.hidden = true; }
		syncMore();
		// Seed the post parent from the TILE, which already carries it, instead of
		// waiting for space-context to tell us. The reaction state then paints one
		// request after open rather than two chained ones.
		//
		// Measured before this: the chip strip read "not reacted" for 200-300ms on
		// every open of a photo the member HAD reacted to, on a warm local site -
		// longer over a real network. A control that briefly states the opposite of
		// the truth is the thing our own standard forbids, and it is what made
		// J-562 (reaction persists across reopen) flaky.
		//
		// space-context still runs and still owns the answer: it is authoritative
		// for media reached without a tile (a direct link, the DM viewer) and it
		// corrects this seed if they ever disagree.
		var seededItem = gallery[ index ] || null;
		currentPostId  = ( seededItem && seededItem.postId ) ? seededItem.postId : 0;
		if ( LOGGED_IN && currentPostId > 0 ) {
			paintPostReactions( current, currentPostId );
		}
		// Runs for every viewer now, not only where an unlink control exists: the
		// same response carries the post parent that decides which object the
		// reactions belong to.
		if ( LOGGED_IN && current ) {
			var forId = current;
			window.buddynextRest.restFetch( '/media/' + current + '/space-context', {
				nonce: cfg.nonce || '', method: 'GET', toastOnError: false,
			} ).then( function ( res ) {
				// Ignore a stale response if the viewer already moved to another item.
				if ( forId !== current || ! res || ! res.ok || ! res.data ) { return; }

				if ( panel.unlink && res.data.can_unlink ) {
					currentSpaceId = parseInt( res.data.space_id, 10 ) || 0;
					if ( currentSpaceId > 0 ) {
						panel.unlink.hidden = false;
						syncMore();
					}
				}

				var resolvedPostId = parseInt( res.data.post_id, 10 ) || 0;
				var alreadySeeded  = ( resolvedPostId > 0 && resolvedPostId === currentPostId );
				currentPostId      = resolvedPostId;
				if ( currentPostId > 0 ) {
					// Only fetch when the tile did not already tell us, or told us
					// something different - otherwise this is a second request for an
					// answer already on screen.
					if ( ! alreadySeeded ) {
						paintPostReactions( forId, currentPostId );
					}

					// ...and the post's bookmark, which is what Save writes. The
					// same response carries it, so the control paints its real
					// state on first render instead of starting at "not saved"
					// and making the member's first click look like a no-op.
					if ( panel.save ) {
						panel.save.hidden = false;
						setSaved( !! res.data.bookmarked );
					}
				}
			} );
		}
	}

	function renderAuthor( m, target ) {
		if ( ! target ) { return; }
		clear( target );
		var av = m.author_avatar || ( m.author_data && m.author_data.avatar ) || '';
		var name = m.author_name || ( m.author_data && m.author_data.name ) || '';
		// The REST API resolves this to the member profile (via BuddyNext's
		// mvs_user_profile_url filter), so link the author there instead of
		// leaving the name as plain text / the raw /media/@{login}/ fallback.
		var url = m.author_url || ( m.author_data && m.author_data.profile_url ) || '';

		var holder = url ? document.createElement( 'a' ) : document.createElement( 'span' );
		if ( url ) { holder.setAttribute( 'href', url ); }
		holder.className = 'bn-lightbox__author-link';

		if ( av && /^(https?:)?\//.test( av ) ) {
			var img = document.createElement( 'img' );
			img.className = 'bn-lightbox__author-avatar';
			img.setAttribute( 'src', av );
			img.setAttribute( 'alt', '' );
			holder.appendChild( img );
		}
		var sp = document.createElement( 'span' );
		sp.className = 'bn-lightbox__author-name';
		sp.textContent = name;
		holder.appendChild( sp );

		target.appendChild( holder );
	}

	function resetReactions() {
		overlay.querySelectorAll( '.bn-lightbox__reaction' ).forEach( function ( btn ) {
			btn.setAttribute( 'aria-pressed', 'false' );
			btn.classList.remove( 'is-active' );
			var c = btn.querySelector( '[data-bn-lb-reaction-count]' );
			if ( c ) { c.hidden = true; c.textContent = '0'; }
		} );
	}

	function applyReactions( r ) {
		var counts = ( r && r.counts ) || {};
		var mine = r && r.user_reaction;
		overlay.querySelectorAll( '.bn-lightbox__reaction' ).forEach( function ( btn ) {
			var type = btn.getAttribute( 'data-reaction' );
			var n = parseInt( counts[ type ], 10 ) || 0;
			var c = btn.querySelector( '[data-bn-lb-reaction-count]' );
			if ( c ) { c.textContent = n; c.hidden = n <= 0; }
			var active = ( mine === type );
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
	}

	function react( type ) {
		if ( ! requireLogin() || ! current ) { return; }
		var id = current;

		// A photo that is a feed post reacts as the POST, so the chip the member
		// leaves here is the chip they see on the card. BuddyNext's toggle is one
		// call that both flips and returns the new summary, and it is idempotent
		// per member, so there is no separate delete branch.
		if ( currentPostId > 0 ) {
			var postId = currentPostId;
			bnApi( '/reactions/toggle', {
				method: 'POST',
				json: { object_type: 'post', object_id: postId, emoji: type },
			} ).then( function ( r ) {
				if ( current === id ) { applyReactions( bnReactionShape( r ) ); }
			} ).catch( function () {} );
			return;
		}

		var btn = overlay.querySelector( '.bn-lightbox__reaction[data-reaction="' + type + '"]' );
		var wasActive = btn && btn.classList.contains( 'is-active' );
		var req = wasActive
			? api( '/media/' + current + '/reactions', { method: 'DELETE' } )
			: api( '/media/' + current + '/reactions', { method: 'POST', json: { reaction_type: type } } );
		req.then( function () {
			return api( '/media/' + id + '/reactions' );
		} ).then( function ( r ) {
			if ( current === id ) { applyReactions( r ); }
		} ).catch( function () {} );
	}

	// Single source of truth for the favorite affordance: the pressed state
	// drives both the filled-heart styling (CSS [aria-pressed="true"]) and the
	// label, which swaps Favorite ↔ Favorited so the toggle is unmistakable.
	function setFavorite( on ) {
		var btn = panel.favorite;
		if ( ! btn ) { return; }
		btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		var label = btn.querySelector( 'span' );
		if ( label ) {
			label.textContent = on
				? ( I18N.favorited || 'Favorited' )
				: ( I18N.favorite || 'Favorite' );
		}
	}

	/*
	 * Report a piece of media.
	 *
	 * Posts to WPMediaVerse's OWN queue (mvs/v1/media/{id}/report) — the same queue its native
	 * media page reports into, and the one moderators already work. BuddyNext does not keep a
	 * second media-report store; the engine owns media.
	 *
	 * The reason list is MVS's enum, not BuddyNext's: MVS accepts nudity / violence / copyright
	 * and has no inappropriate / impersonation, so posting our own set would be rejected as an
	 * invalid reason.
	 */
	function report() {
		if ( ! requireLogin() || ! current ) { return; }

		/*
		 * NOT BuddyNext's report vocabulary, and deliberately not read from the
		 * shared one. A media report goes to WPMediaVerse's endpoint, which
		 * validates against its OWN enum — nudity / violence / copyright, and no
		 * inappropriate / impersonation. Passing BuddyNext's list (or a reason an
		 * owner added through buddynext_report_reasons) would be rejected as an
		 * invalid reason, so this list belongs to the queue that receives it.
		 *
		 * Reviewed as part of unifying the other four copies (card 10244744986):
		 * this one is a different contract, not a duplicate.
		 */
		var reasons = [
			[ 'spam',           __( 'Spam', 'buddynext' ) ],
			[ 'harassment',     __( 'Harassment or hate speech', 'buddynext' ) ],
			[ 'nudity',         __( 'Nudity or sexual content', 'buddynext' ) ],
			[ 'violence',       __( 'Violence or graphic content', 'buddynext' ) ],
			[ 'copyright',      __( 'Copyright infringement', 'buddynext' ) ],
			[ 'misinformation', __( 'Misinformation', 'buddynext' ) ],
			[ 'other',          __( 'Something else', 'buddynext' ) ],
		];

		window.bnReportDialog( {
			title: __( 'Report this media', 'buddynext' ),
			reasons: reasons,
		} ).then( function ( result ) {
			if ( ! result ) { return; }

			var mediaId = current;

			return api( '/media/' + mediaId + '/report', {
				method: 'POST',
				json: { reason: result.reason, details: result.notes || '' },
			} ).then( function () {
				notify( __( 'Thanks — this has been sent to the moderators.', 'buddynext' ), 'success' );
			} ).catch( function () {
				notify( __( 'Could not send that report. Try again.', 'buddynext' ), 'error' );
			} );
		} );
	}

	/*
	 * Block the member who uploaded this media — BuddyNext's own social-graph block
	 * (buddynext/v1 users/{id}/block), not an MVS concept. It is the person being blocked, not
	 * the file, so it goes to our namespace rather than the engine's.
	 */
	function blockAuthor() {
		if ( ! requireLogin() || ! currentAuthorId ) { return; }

		var authorId = currentAuthorId;
		var confirmFn = window.bnConfirm;

		var proceed = typeof confirmFn === 'function'
			? confirmFn( {
				title: __( 'Block this member?', 'buddynext' ),
				body: __( 'You will not see their posts or media, and they cannot message you. You can undo this from your settings.', 'buddynext' ),
				confirmLabel: __( 'Block', 'buddynext' ),
				tone: 'danger',
			} )
			// The accessible bnConfirm is exposed on window by shell/dialog.js.
			// If it is somehow not loaded we do NOT fall back to a native
			// window.confirm on a member-facing surface - skip the action, the
			// same way the Report button no-ops when its dialog is absent.
			: Promise.resolve( false );

		Promise.resolve( proceed ).then( function ( ok ) {
			if ( ! ok ) { return; }

			// BuddyNext's namespace, not the media engine's — so it cannot go through api(),
			// which is bound to the mvs/v1 base.
			return window.buddynextRest.restFetch( '/users/' + authorId + '/block', {
				method: 'POST',
				nonce: cfg.nonce || '', // wp_rest — valid for buddynext/v1 and mvs/v1 alike.
				toastOnError: false,
			} ).then( function ( res ) {
				if ( ! res.ok ) { throw res; }
				notify( __( 'Blocked. You will not see their content.', 'buddynext' ), 'success' );
				close();
			} ).catch( function () {
				notify( __( 'Could not block that member. Try again.', 'buddynext' ), 'error' );
			} );
		} );
	}

	function favorite() {
		if ( ! requireLogin() || ! current ) { return; }
		var on = panel.favorite.getAttribute( 'aria-pressed' ) === 'true';
		setFavorite( ! on ); // optimistic
		api( '/media/' + current + '/favorite', { method: 'POST' } ).then( function ( r ) {
			if ( r && typeof r.favorited !== 'undefined' ) {
				setFavorite( !! r.favorited );
			}
		} ).catch( function () { setFavorite( on ); } );
	}

	function share() {
		var item = gallery[ index ] || null;

		// DM media has no social layer (chrome is hidden) — never share it.
		if ( item && item.dm ) { return; }

		// When the media came from a post, open the SAME rich Share modal the
		// post cards use (Repost + Copy link), threading the source post id so
		// Repost works. The modal carries its own wp_rest nonce + buddynext/v1
		// restUrl, so the lightbox only supplies the post id + permalink.
		var modal = document.querySelector( '.bn-share-modal' );
		if ( item && item.postId && modal ) {
			// Hand off to the share modal — close the lightbox first so its
			// full-screen overlay does not sit on top of (and block) the modal.
			var detail = {
				postId:    item.postId,
				permalink: item.permalink || window.location.href,
				author:    '',
				excerpt:   '',
			};
			close();
			document.dispatchEvent( new CustomEvent( 'bn-open-share-modal', { detail: detail } ) );
			return;
		}

		// No source post (e.g. a direct upload) — copy the page link the media
		// lives on. Never a signed (expiring) file URL or the MediaVerse page.
		copyToClipboard( item && item.permalink ? item.permalink : window.location.href );
	}

	// Toast feedback that never goes silent: use the shared bnToast when it is
	// on the page, otherwise render a minimal toast (same DOM contract as
	// shell/extras.js) so a copy on a surface without bn-shell-extras still
	// tells the user it worked.
	function notify( msg, tone ) {
		if ( typeof window.bnToast === 'function' ) {
			window.bnToast( msg, tone );
			return;
		}
		var c = document.querySelector( '.bn-toast-container' );
		if ( ! c ) {
			c = document.createElement( 'div' );
			c.className = 'bn-toast-container';
			document.body.appendChild( c );
		}
		var t = document.createElement( 'div' );
		t.className = 'bn-toast' + ( 'success' === tone ? ' bn-toast--success' : ( 'info' === tone ? ' bn-toast--info' : '' ) );
		t.textContent = msg;
		c.appendChild( t );
		setTimeout( function () { t.remove(); }, 3000 );
	}

	function copyToClipboard( text ) {
		var done = function () {
			notify( __( 'Link copied', 'buddynext' ), 'success' );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done ).catch( function () { legacyCopy( text, done ); } );
		} else {
			legacyCopy( text, done );
		}
	}

	function legacyCopy( text, done ) {
		try {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.top = '-1000px';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			var ok = document.execCommand( 'copy' );
			document.body.removeChild( ta );
			if ( ok ) {
				if ( done ) { done(); }
				return;
			}
		} catch ( e ) {}
		// Last resort (no Clipboard API + execCommand failed): surface the link in
		// a toast for manual copy. No native prompt() — see the UX-audit F8 rule.
		notify( ( I18N.copyManual || 'Copy this link: ' ) + text, 'info' );
	}

	function renderComments( list ) {
		if ( ! panel.comments ) { return; }
		clear( panel.comments );
		if ( ! list.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'bn-lightbox__comments-empty';
			empty.textContent = I18N.noComments || 'No comments on this photo yet.';
			panel.comments.appendChild( empty );
			return;
		}
		list.forEach( function ( c ) { panel.comments.appendChild( commentEl( c ) ); } );
	}

	/**
	 * One comment row, with the controls the SERVER says this viewer may use.
	 *
	 * `can_edit` and `can_delete` come from the engine per comment and per
	 * viewer, computed from the same code that enforces the routes: author-only
	 * edit inside the `mvs_comment_edit_window`, delete for the author or a
	 * moderator. So this reads them rather than re-deriving ownership and the
	 * edit window here — a second copy of those rules is how a UI ends up
	 * offering a control that 403s, or hiding one the API allows.
	 */
	function commentEl( c ) {
		var row = document.createElement( 'div' );
		row.className = 'bn-lightbox__comment';

		var name = document.createElement( 'strong' );
		name.className = 'bn-lightbox__comment-author';
		name.textContent = c.author_name || c.author || c.name || '';

		var body = document.createElement( 'span' );
		body.className = 'bn-lightbox__comment-text';
		body.textContent = c.content || c.comment_content || c.text || '';

		row.appendChild( name );
		row.appendChild( body );

		if ( ! c.can_edit && ! c.can_delete ) {
			return row;
		}

		var actions = document.createElement( 'span' );
		actions.className = 'bn-lightbox__comment-actions';

		if ( c.can_edit ) {
			actions.appendChild( commentAction( __( 'Edit' ), 'edit', function () {
				startEditComment( row, c );
			} ) );
		}
		if ( c.can_delete ) {
			actions.appendChild( commentAction( __( 'Delete' ), 'delete', function () {
				deleteComment( row, c );
			} ) );
		}

		row.appendChild( actions );
		return row;
	}

	/** A micro text button for a comment row — not a full-size form button. */
	function commentAction( label, variant, onClick ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'bn-lightbox__comment-action bn-lightbox__comment-action--' + variant;
		b.textContent = label;
		b.addEventListener( 'click', onClick );
		return b;
	}
	// A real form button for the Edit / Save panels (primary or ghost), so their
	// footer reads as buttons, not the plain text-links the comment actions use.
	function panelBtn( label, variant, onClick ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'bn-btn';
		b.setAttribute( 'data-variant', variant );
		b.setAttribute( 'data-size', 'sm' );
		b.textContent = label;
		b.addEventListener( 'click', onClick );
		return b;
	}

	/** Swap a comment row for an inline edit field. */
	function startEditComment( row, c ) {
		if ( row.querySelector( '.bn-lightbox__comment-edit' ) ) { return; }

		var wrap = document.createElement( 'span' );
		wrap.className = 'bn-lightbox__comment-edit';

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'bn-lightbox__comment-edit-input';
		input.value = c.content || '';
		input.setAttribute( 'aria-label', __( 'Edit comment' ) );

		var save = commentAction( __( 'Save' ), 'save', function () {
			var text = ( input.value || '' ).trim();
			if ( ! text || ! current ) { return; }
			api( '/media/' + current + '/comments/' + c.id, { method: 'PATCH', json: { content: text } } )
				.then( reloadComments )
				.catch( function () {} );
		} );
		var cancel = commentAction( __( 'Cancel' ), 'cancel', reloadComments );

		wrap.appendChild( input );
		wrap.appendChild( save );
		wrap.appendChild( cancel );
		clear( row );
		row.appendChild( wrap );
		input.focus();
	}

	/**
	 * Delete, behind a two-step inline confirm.
	 *
	 * Inline rather than the shared modal: this script depends only on wp-i18n,
	 * so `window.bnConfirm` is not guaranteed to be on the page, and deleting on
	 * a single click when it happens to be absent is not an acceptable fallback.
	 * `window.confirm` is not an option either. Asking in the row itself needs
	 * nothing and cannot silently degrade.
	 */
	function deleteComment( row, c ) {
		if ( ! current ) { return; }

		var ask = document.createElement( 'span' );
		ask.className = 'bn-lightbox__comment-confirm';

		var label = document.createElement( 'span' );
		label.className = 'bn-lightbox__comment-confirm-text';
		label.textContent = __( 'Delete this comment?' );

		var yes = commentAction( __( 'Delete' ), 'delete', function () {
			api( '/media/' + current + '/comments/' + c.id, { method: 'DELETE' } )
				.then( reloadComments )
				.catch( reloadComments );
		} );
		var no = commentAction( __( 'Cancel' ), 'cancel', reloadComments );

		ask.appendChild( label );
		ask.appendChild( yes );
		ask.appendChild( no );
		clear( row );
		row.appendChild( ask );
		yes.focus();
	}

	/** Re-read the thread from the engine so every row's flags are current. */
	/**
	 * Fullscreen: give the media the whole overlay and stand the side panel down.
	 *
	 * A CSS class rather than the Fullscreen API — requestFullscreen() takes over
	 * the whole screen and swallows Escape, so the viewer's own close-on-Escape
	 * would stop working and a member would have to press it twice to get out.
	 * This keeps every existing control behaving exactly as it does normally.
	 */
	function toggleFullscreen() {
		if ( ! overlay || ! panel.fullscr ) { return; }
		var on = ! overlay.classList.contains( 'bn-lightbox--fullscreen' );
		overlay.classList.toggle( 'bn-lightbox--fullscreen', on );
		panel.fullscr.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
	}

	// ── Owner panel: Edit + Save ──────────────────────────────────────────────

	function closeExtraPanel() {
		if ( ! panel.extra ) { return; }
		clear( panel.extra );
		panel.extra.hidden = true;
	}

	function extraPanel() {
		if ( ! panel.extra ) { return null; }
		clear( panel.extra );
		panel.extra.hidden = false;
		return panel.extra;
	}

	function field( labelText, control ) {
		var wrap = document.createElement( 'label' );
		wrap.className = 'bn-lightbox__field';
		var span = document.createElement( 'span' );
		span.className = 'bn-lightbox__field-label';
		span.textContent = labelText;
		wrap.appendChild( span );
		wrap.appendChild( control );
		return wrap;
	}

	/**
	 * Edit title / description / privacy / download, via PATCH on the media.
	 *
	 * The privacy choices come from the payload's `privacy_options` rather than a
	 * list hardcoded here: the engine owns which levels exist (a Space level was
	 * added in 2.4.0), and a copy on this side would be wrong the next time that
	 * set changes.
	 */
	function openEditPanel() {
		var m = currentMedia;
		var host = extraPanel();
		if ( ! host || ! m || ! current ) { return; }

		var title = document.createElement( 'input' );
		title.type = 'text';
		title.className = 'bn-lightbox__field-input';
		title.value = m.title || '';

		var desc = document.createElement( 'textarea' );
		desc.className = 'bn-lightbox__field-input';
		desc.rows = 2;
		desc.value = m.description || '';

		var privacy = document.createElement( 'select' );
		privacy.className = 'bn-lightbox__field-input';
		var opts = m.privacy_options || {};
		Object.keys( opts ).forEach( function ( k ) {
			var o = document.createElement( 'option' );
			var v = opts[ k ];
			// privacy_options arrives either as {value: label} or as a list of
			// {value,label} objects depending on the engine version; accept both
			// rather than guessing one shape.
			o.value = ( v && v.value ) ? v.value : k;
			o.textContent = ( v && v.label ) ? v.label : String( v );
			if ( o.value === m.privacy ) { o.selected = true; }
			privacy.appendChild( o );
		} );

		var dl = document.createElement( 'input' );
		dl.type = 'checkbox';
		dl.className = 'bn-lightbox__field-check';
		dl.checked = !! m.allow_download;

		host.appendChild( field( __( 'Title' ), title ) );
		host.appendChild( field( __( 'Description' ), desc ) );
		host.appendChild( field( __( 'Privacy' ), privacy ) );
		host.appendChild( field( __( 'Allow downloads' ), dl ) );

		var actions = document.createElement( 'div' );
		actions.className = 'bn-lightbox__panel-actions';
		actions.appendChild( panelBtn( __( 'Save changes' ), 'primary', function () {
			api( '/media/' + current, {
				method: 'PATCH',
				json: {
					title: title.value,
					description: desc.value,
					privacy: privacy.value,
					allow_download: dl.checked,
				},
			} ).then( function ( updated ) {
				if ( updated && updated.id ) { currentMedia = updated; }
				closeExtraPanel();
			} ).catch( function () {} );
		} ) );
		actions.appendChild( panelBtn( __( 'Cancel' ), 'ghost', closeExtraPanel ) );
		host.appendChild( actions );
		title.focus();
	}

	/**
	 * Save = bookmark the POST this photo belongs to.
	 *
	 * It used to open a MediaVerse Pro COLLECTIONS panel. That put two different
	 * stores behind one icon: the identical-looking Save on the feed card writes
	 * a BuddyNext bookmark, so a member who saved a photo from the lightbox found
	 * nothing in their saved list, and on a site without MediaVerse Pro the
	 * control answered its own 404 by deleting itself mid-click (Basecamp
	 * 10259604003). Collections are MediaVerse's feature and stay MediaVerse's;
	 * BuddyNext does not need one at this end, so this surface is gone rather
	 * than repaired.
	 *
	 * One icon, one object, one saved list. The button only renders when the
	 * media has a post parent - there is nothing to bookmark otherwise - so
	 * there is no unavailable state left to explain.
	 */
	function setSaved( on ) {
		var btn = panel.save;
		if ( ! btn ) { return; }
		btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		var label = on ? ( I18N.saved || 'Saved' ) : ( I18N.save || 'Save' );
		btn.setAttribute( 'aria-label', label );
		btn.title = label;
	}

	function toggleSave() {
		if ( ! requireLogin() || ! currentPostId ) { return; }

		var postId = currentPostId;
		var was    = panel.save && 'true' === panel.save.getAttribute( 'aria-pressed' );
		setSaved( ! was ); // Optimistic, same as the heart above.

		bnApi( '/posts/' + postId + '/bookmark', { method: was ? 'DELETE' : 'POST' } )
			.then( function () {
				// A stale response must not repaint a photo the member has since
				// scrolled past.
				if ( postId !== currentPostId ) { return; }
				notify( was ? ( I18N.removedFromSaved || 'Removed from saved' ) : ( I18N.saved || 'Saved' ), 'success' );
			} )
			.catch( function () {
				if ( postId === currentPostId ) { setSaved( was ); }
			} );
	}

	function reloadComments() {
		if ( ! current ) { return; }
		var id = current;
		api( '/media/' + id + '/comments' ).then( function ( list ) {
			if ( current === id ) { renderComments( Array.isArray( list ) ? list : ( list.comments || [] ) ); }
		} ).catch( function () {} );
	}

	function addComment() {
		if ( ! requireLogin() || ! current ) { return; }
		var val = ( panel.input.value || '' ).trim();
		if ( ! val ) { return; }
		var id = current;
		panel.input.value = '';
		api( '/media/' + id + '/comments', { method: 'POST', json: { content: val } } ).then( function () {
			return api( '/media/' + id + '/comments' );
		} ).then( function ( list ) {
			if ( current === id ) { renderComments( Array.isArray( list ) ? list : ( list.comments || [] ) ); }
		} ).catch( function () {} );
	}

	function requireLogin() {
		if ( LOGGED_IN ) { return true; }
		if ( window.bnToast ) { window.bnToast( I18N.loginPrompt || 'Log in to react and comment.', 'info' ); }
		return false;
	}

	// ── Open / close / nav ────────────────────────────────────────────────────
	function open( tiles, startIndex ) {
		if ( ! shell() ) { return; }
		gallery = tiles.map( tileToItem ).filter( function ( i ) { return i.src; } );
		if ( ! gallery.length ) { return; }
		index = Math.max( 0, Math.min( startIndex, gallery.length - 1 ) );
		lastFocus = document.activeElement;
		renderMedia();
		if ( gallery[ index ].id ) { loadPanel( gallery[ index ].id ); }
		overlay.hidden = false;
		document.body.classList.add( 'bn-lightbox-open' );
		var closeBtn = overlay.querySelector( '[data-bn-lb-close].bn-lightbox__close' );
		if ( closeBtn ) { closeBtn.focus(); }
	}

	function close() {
		if ( ! overlay ) { return; }
		overlay.hidden = true;
		document.body.classList.remove( 'bn-lightbox-open' );
		clear( stage ); // stop video playback
		gallery = [];
		current = null;
		if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
	}

	function step( delta ) {
		if ( gallery.length < 2 ) { return; }
		index = ( index + delta + gallery.length ) % gallery.length;
		renderMedia();
		if ( gallery[ index ].id ) { loadPanel( gallery[ index ].id ); }
	}

	// Delegated open — image/video tiles only (audio plays inline).
	document.addEventListener( 'click', function ( e ) {
		var tile = e.target.closest( '.bn-media-tile[data-bn-media-id]' );
		if ( ! tile ) { return; }
		var type = tile.getAttribute( 'data-media-type' );
		if ( 'image' !== type && 'video' !== type ) { return; }
		e.preventDefault();
		var grid  = tile.closest( '[data-bn-media-grid]' ) || tile.parentElement;
		var tiles = Array.prototype.slice.call(
			grid.querySelectorAll( '.bn-media-tile[data-bn-media-id]' )
		).filter( function ( t ) {
			var ty = t.getAttribute( 'data-media-type' );
			return 'image' === ty || 'video' === ty;
		} );
		open( tiles, tiles.indexOf( tile ) );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ! overlay || overlay.hidden ) { return; }
		// Don't hijack arrows while typing a comment.
		var typing = document.activeElement && document.activeElement.matches( 'input, textarea' );
		if ( 'Escape' === e.key ) { close(); }
		else if ( ! typing && 'ArrowLeft' === e.key ) { step( -1 ); }
		else if ( ! typing && 'ArrowRight' === e.key ) { step( 1 ); }
	} );
}() );
