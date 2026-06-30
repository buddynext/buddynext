/**
 * BuddyNext — blocks.js
 *
 * Editor script for all 17 BuddyNext Gutenberg blocks.
 * Registers edit functions (server-side-rendered previews) and
 * WordPress Interactivity API stores for frontend block interactivity.
 */

/* ── Block editor registrations ─────────────────────────────────────── */

( function ( blocks, element, blockEditor, serverSideRender ) {
	'use strict';

	if ( ! blocks || ! element || ! blockEditor ) {
		return;
	}

	var __ = ( wp.i18n && wp.i18n.__ ) || function ( text ) { return text; };

	var el            = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder   = wp.components && wp.components.Placeholder;

	/**
	 * Edit function: server-side-rendered live preview via REST.
	 *
	 * Falls back to a static placeholder when `wp.serverSideRender` has not
	 * been loaded yet — this prevents React Error #130 ("element type is
	 * invalid — got: undefined") which occurs in the block editor if the
	 * `wp-server-side-render` script was not enqueued before blocks.js runs.
	 *
	 * @param {string} name Block name (e.g. buddynext/activity-feed).
	 * @return {Function} Edit component.
	 */
	function ssrEdit( name ) {
		return function ( props ) {
			var blockProps = useBlockProps();

			// Guard: if ServerSideRender is not yet available, render a neutral
			// placeholder so React never receives undefined as an element type.
			var SSR = serverSideRender || ( window.wp && window.wp.serverSideRender );
			if ( ! SSR ) {
				return el(
					'div',
					Object.assign( {}, blockProps, {
						style: {
							padding:      '16px',
							background:   '#f8f8f7',
							border:       '1px dashed #aeaca8',
							borderRadius: '8px',
							textAlign:    'center',
							color:        '#787774',
							fontSize:     '13px',
						},
					} ),
					el( 'p', { className: 'buddynext-editor-loading', style: { margin: 0 } }, __( 'BuddyNext loading\u2026', 'buddynext' ) )
				);
			}

			return el(
				'div',
				blockProps,
				el( SSR, {
					block:      name,
					attributes: props.attributes,
				} )
			);
		};
	}

	/**
	 * Edit function: static placeholder for blocks with no SSR endpoint.
	 *
	 * @param {string} label Human-readable block label.
	 * @param {string} icon  Dashicon class without the 'dashicons-' prefix.
	 * @return {Function} Edit component.
	 */
	function placeholderEdit( label, icon ) {
		return function () {
			var blockProps = useBlockProps( {
				className: 'bn-editor-placeholder',
				style: {
					fontFamily: 'Inter, sans-serif',
				},
			} );
			var iconEl = Placeholder
				? el( Placeholder, {
					icon:        'buddynext' === icon ? 'admin-site' : ( icon || 'admin-site' ),
					label:       'BuddyNext — ' + label,
					instructions: __( 'This block is rendered on the frontend.', 'buddynext' ),
				  } )
				: el(
					'div',
					{
						style: {
							padding:      '24px',
							background:   '#f8f8f7',
							border:       '1px dashed #aeaca8',
							borderRadius: '8px',
							textAlign:    'center',
							color:        '#787774',
							fontSize:     '13px',
						},
					},
					el( 'strong', null, 'BuddyNext — ' + label ),
					el( 'p', { style: { margin: '4px 0 0', color: '#aeaca8' } }, __( 'Rendered on the frontend', 'buddynext' ) )
				);
			return el( 'div', blockProps, iconEl );
		};
	}

	/**
	 * Block definitions: all 17 BuddyNext blocks.
	 *
	 * ssr:true  → use serverSideRender for live preview in editor
	 * ssr:false → show static placeholder (block has no PHP REST callback)
	 */
	var blockDefs = [
		{ name: 'buddynext/activity-feed',         label: __( 'Activity Feed', 'buddynext' ),          ssr: true  },
		{ name: 'buddynext/post-composer',          label: __( 'Post Composer', 'buddynext' ),          ssr: false },
		{ name: 'buddynext/trending-hashtags',      label: __( 'Trending Hashtags', 'buddynext' ),      ssr: true  },
		{ name: 'buddynext/member-directory',       label: __( 'Member Directory', 'buddynext' ),       ssr: true  },
		{ name: 'buddynext/member-card',            label: __( 'Member Card', 'buddynext' ),            ssr: true  },
		{ name: 'buddynext/follow-button',          label: __( 'Follow Button', 'buddynext' ),          ssr: false },
		{ name: 'buddynext/connection-button',      label: __( 'Connection Button', 'buddynext' ),      ssr: false },
		{ name: 'buddynext/space-directory',        label: __( 'Space Directory', 'buddynext' ),        ssr: true  },
		{ name: 'buddynext/space-card',             label: __( 'Space Card', 'buddynext' ),             ssr: true  },
		{ name: 'buddynext/my-spaces',              label: __( 'My Spaces', 'buddynext' ),              ssr: true  },
		{ name: 'buddynext/profile-header',         label: __( 'Profile Header', 'buddynext' ),         ssr: true  },
		{ name: 'buddynext/profile-fields',         label: __( 'Profile Fields', 'buddynext' ),         ssr: true  },
		{ name: 'buddynext/profile-completion-bar', label: __( 'Profile Completion Bar', 'buddynext' ), ssr: false },
		{ name: 'buddynext/notification-bell',      label: __( 'Notification Bell', 'buddynext' ),      ssr: false },
		{ name: 'buddynext/search-bar',             label: __( 'Search Bar', 'buddynext' ),             ssr: false },
		{ name: 'buddynext/header-user-menu',       label: __( 'Header User Menu', 'buddynext' ),       ssr: true  },
	];

	blockDefs.forEach( function ( def ) {
		// Skip if already registered (double-load guard).
		if ( blocks.getBlockType( def.name ) ) {
			return;
		}
		blocks.registerBlockType( def.name, {
			edit: def.ssr ? ssrEdit( def.name ) : placeholderEdit( def.label ),
			save: function () {
				// All blocks are server-side rendered — save() returns null.
				return null;
			},
		} );
	} );

} )(
	window.wp && window.wp.blocks,
	window.wp && window.wp.element,
	window.wp && window.wp.blockEditor,
	window.wp && window.wp.serverSideRender
);

/* ── Interactivity API stores ────────────────────────────────────────── */

( function () {
	'use strict';

	if ( ! window.wp || ! window.wp.interactivity ) {
		return;
	}

	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function ( text ) { return text; };

	var store      = window.wp.interactivity.store;
	var getContext = window.wp.interactivity.getContext;
	var getElement = window.wp.interactivity.getElement;

	/* ── Activity feed ────────────────────────────────────────────────── */

	store( 'buddynext/activity-feed', {
		state: {
			loading: false,
			page:    1,
		},
		actions: {
			loadMore: function* () {
				var ctx = getContext();
				if ( ctx.loading ) {
					return;
				}
				ctx.loading = true;
				ctx.page    = ( ctx.page || 1 ) + 1;
				yield window.buddynextRest.restFetch(
					'/buddynext/v1/feed?page=' + ctx.page,
					{
						base:  window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '',
						nonce: window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
					}
				);
				ctx.loading = false;
			},
		},
	} );

	/* ── Follow button ─────────────────────────────────────────────────── */

	store( 'buddynext/follow-button', {
		state: {
			get buttonClass() {
				var ctx = getContext();
				return ctx.isFollowing
					? 'bn-btn bn-btn--sm bn-btn--secondary bn-following'
					: 'bn-btn bn-btn--sm bn-btn--primary';
			},
			get label() {
				return getContext().isFollowing ? __( 'Following', 'buddynext' ) : __( 'Follow', 'buddynext' );
			},
		},
		actions: {
			toggleFollow: function* () {
				var ctx    = getContext();
				var method = ctx.isFollowing ? 'DELETE' : 'POST';
				var res    = yield window.buddynextRest.restFetch( '/users/' + ctx.userId + '/follow', {
					base:   ctx.restUrl,
					nonce:  ctx.nonce,
					method: method,
				} );
				if ( res.ok ) {
					ctx.isFollowing = ! ctx.isFollowing;
				}
			},
		},
	} );

	/* ── Connection button ─────────────────────────────────────────────── */

	store( 'buddynext/connection-button', {
		state: {
			get showConnect() {
				return getContext().status === '';
			},
			get showPending() {
				return getContext().status === 'pending-sent';
			},
			get showAcceptDecline() {
				return getContext().status === 'pending-received';
			},
			get showConnected() {
				return getContext().status === 'accepted';
			},
		},
		actions: {
			sendRequest: function* () {
				var ctx = getContext();
				var res = yield window.buddynextRest.restFetch( '/users/' + ctx.userId + '/connect', {
					base:   ctx.restUrl,
					nonce:  ctx.nonce,
					method: 'POST',
				} );
				if ( res.ok ) {
					ctx.status = 'pending-sent';
				}
			},
			withdrawRequest: function* () {
				var ctx = getContext();
				var res = yield window.buddynextRest.restFetch( '/users/' + ctx.userId + '/connect', {
					base:   ctx.restUrl,
					nonce:  ctx.nonce,
					method: 'DELETE',
				} );
				if ( res.ok ) {
					ctx.status = '';
				}
			},
			acceptRequest: function* () {
				var ctx = getContext();
				var res = yield window.buddynextRest.restFetch( '/users/' + ctx.userId + '/connect/accept', {
					base:   ctx.restUrl,
					nonce:  ctx.nonce,
					method: 'POST',
				} );
				if ( res.ok ) {
					ctx.status = 'accepted';
				}
			},
			declineRequest: function* () {
				var ctx = getContext();
				var res = yield window.buddynextRest.restFetch( '/users/' + ctx.userId + '/connect/decline', {
					base:   ctx.restUrl,
					nonce:  ctx.nonce,
					method: 'POST',
				} );
				if ( res.ok ) {
					ctx.status = '';
				}
			},
			disconnect: function* () {
				var ctx = getContext();
				var res = yield window.buddynextRest.restFetch( '/users/' + ctx.userId + '/connect', {
					base:   ctx.restUrl,
					nonce:  ctx.nonce,
					method: 'DELETE',
				} );
				if ( res.ok ) {
					ctx.status = '';
				}
			},
		},
	} );

	/* ── Notification bell ─────────────────────────────────────────────── */

	store( 'buddynext/notification-bell', {
		state: {
			open:    false,
			loading: false,
		},
		actions: {
			toggleDropdown: function () {
				var ctx  = getContext();
				ctx.open = ! ctx.open;
			},
			markAllRead: function* () {
				var ctx     = getContext();
				ctx.loading = true;
				yield window.buddynextRest.restFetch(
					'/buddynext/v1/notifications/mark-all-read',
					{
						base:   window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '',
						nonce:  window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						method: 'POST',
					}
				);
				ctx.unreadCount = 0;
				ctx.loading     = false;
			},
			closeOnOutsideClick: function ( event ) {
				var ctx     = getContext();
				var wrapper = getElement();
				if ( ctx.open && wrapper && wrapper.ref && ! wrapper.ref.contains( event.target ) ) {
					ctx.open = false;
				}
			},
		},
	} );

	/* ── Search bar ───────────────────────────────────────────────────── */

	store( 'buddynext/search-bar', {
		state: {
			query:   '',
			loading: false,
		},
		actions: {
			onInput: function () {
				var ctx = getContext();
				var el  = getElement();
				ctx.query = el && el.ref ? el.ref.value : '';
			},
			submit: function () {
				var ctx = getContext();
				if ( ! ctx.query || ! ctx.query.trim() ) {
					return;
				}
				var searchUrl = window.bnBlocks && window.bnBlocks.searchUrl
					? window.bnBlocks.searchUrl
					: window.location.origin;
				window.location.href = searchUrl + '?s=' + encodeURIComponent( ctx.query.trim() );
			},
		},
	} );

	/* ── Profile completion bar ──────────────────────────────────────── */

	store( 'buddynext/profile-completion-bar', {
		state: {
			animated: false,
		},
		callbacks: {
			onMount: function () {
				var ctx = getContext();
				// Defer to next frame so CSS transition runs after initial paint.
				window.requestAnimationFrame( function () {
					ctx.animated = true;
				} );
			},
		},
	} );

	/* ── Member directory ────────────────────────────────────────────── */

	store( 'buddynext/member-directory', {
		state: {
			loading: false,
		},
		actions: {
			applyFilter: function () {
				var ctx = getContext();
				var url = new URL( window.location.href );
				url.searchParams.set( 'member_type', ctx.memberType || '' );
				url.searchParams.set( 'order',       ctx.order      || 'newest' );
				url.searchParams.set( 's',           ctx.search     || '' );
				url.searchParams.set( 'paged',       '1' );
				window.location.href = url.toString();
			},
		},
	} );

	/* ── Space directory ─────────────────────────────────────────────── */

	store( 'buddynext/space-directory', {
		state: {
			loading: false,
		},
		actions: {
			applyFilter: function () {
				var ctx = getContext();
				var url = new URL( window.location.href );
				url.searchParams.set( 'category', ctx.category || '' );
				url.searchParams.set( 'order',    ctx.order    || 'newest' );
				url.searchParams.set( 's',        ctx.search   || '' );
				url.searchParams.set( 'paged',    '1' );
				window.location.href = url.toString();
			},
		},
	} );

	/* ── Post card ──────────────────────────────────────────────────── */

	store( 'buddynext/post-card', {
		state: {
			loading:       false,
			reactionOpen:  false,
			bookmarked:    false,
		},
		actions: {
			toggleReaction: function* () {
				var ctx = getContext();
				if ( ctx.loading ) {
					return;
				}
				ctx.loading = true;
				yield window.buddynextRest.restFetch(
					'/buddynext/v1/posts/' + ctx.postId + '/react',
					{
						base:   window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '',
						nonce:  window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						method: 'POST',
						body:   { emoji: ctx.reactionEmoji },
					}
				);
				ctx.loading = false;
			},
			toggleBookmark: function* () {
				var ctx    = getContext();
				ctx.loading = true;
				var method  = ctx.bookmarked ? 'DELETE' : 'POST';
				yield window.buddynextRest.restFetch(
					'/buddynext/v1/posts/' + ctx.postId + '/bookmark',
					{
						base:   window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '',
						nonce:  window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						method: method,
					}
				);
				ctx.bookmarked = ! ctx.bookmarked;
				ctx.loading    = false;
			},
			dismissContentWarning: function () {
				var ctx           = getContext();
				ctx.warningDismissed = true;
			},
			openReactionPicker: function () {
				var ctx          = getContext();
				ctx.reactionOpen = true;
			},
			closeReactionPicker: function () {
				var ctx          = getContext();
				ctx.reactionOpen = false;
			},
		},
	} );

	/* ── Post composer ──────────────────────────────────────────────── */

	store( 'buddynext/post-composer', {
		state: {
			submitting: false,
			content:    '',
			privacy:    'public',
			type:       'text',
		},
		actions: {
			onInput: function () {
				var ctx = getContext();
				var el  = getElement();
				ctx.content = el && el.ref ? el.ref.value : '';
			},
			submit: function* () {
				var ctx = getContext();
				if ( ctx.submitting || ! ctx.content.trim() ) {
					return;
				}
				ctx.submitting = true;
				var res = yield window.buddynextRest.restFetch(
					'/buddynext/v1/posts',
					{
						base:   window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '',
						nonce:  window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						method: 'POST',
						body:   {
							content: ctx.content,
							privacy: ctx.privacy,
							type:    ctx.type,
						},
					}
				);

				var data = res.data || {};

				ctx.content    = '';
				ctx.submitting = false;

				// A held (pre-moderated) post is not live yet — reloading would hide
				// it and leave the author confused. Tell them it is awaiting review
				// instead of reloading into a feed that does not show their post.
				if ( res && res.ok && data && 'pending' === data.status ) {
					if ( typeof window.bnToast === 'function' ) {
						window.bnToast( __( 'Your post was submitted and is awaiting approval by a moderator.', 'buddynext' ), { tone: 'info' } );
					}
					return;
				}

				window.location.reload();
			},
			setPrivacy: function () {
				var ctx = getContext();
				var el  = getElement();
				ctx.privacy = el && el.ref ? el.ref.value : 'public';
			},
		},
	} );

} )();
