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

	var el            = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder   = wp.components && wp.components.Placeholder;

	/**
	 * Edit function: server-side-rendered live preview via REST.
	 *
	 * @param {string} name Block name (e.g. buddynext/activity-feed).
	 * @return {Function} Edit component.
	 */
	function ssrEdit( name ) {
		return function ( props ) {
			var blockProps = useBlockProps();
			return el(
				'div',
				blockProps,
				el( serverSideRender, {
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
					instructions: 'This block is rendered on the frontend.',
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
					el( 'p', { style: { margin: '4px 0 0', color: '#aeaca8' } }, 'Rendered on the frontend' )
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
		{ name: 'buddynext/activity-feed',         label: 'Activity Feed',          ssr: true  },
		{ name: 'buddynext/post-composer',          label: 'Post Composer',          ssr: false },
		{ name: 'buddynext/trending-hashtags',      label: 'Trending Hashtags',      ssr: true  },
		{ name: 'buddynext/member-directory',       label: 'Member Directory',       ssr: true  },
		{ name: 'buddynext/member-card',            label: 'Member Card',            ssr: true  },
		{ name: 'buddynext/follow-button',          label: 'Follow Button',          ssr: false },
		{ name: 'buddynext/connection-button',      label: 'Connection Button',      ssr: false },
		{ name: 'buddynext/space-directory',        label: 'Space Directory',        ssr: true  },
		{ name: 'buddynext/space-card',             label: 'Space Card',             ssr: true  },
		{ name: 'buddynext/my-spaces',              label: 'My Spaces',              ssr: true  },
		{ name: 'buddynext/profile-header',         label: 'Profile Header',         ssr: true  },
		{ name: 'buddynext/profile-fields',         label: 'Profile Fields',         ssr: true  },
		{ name: 'buddynext/profile-completion-bar', label: 'Profile Completion Bar', ssr: false },
		{ name: 'buddynext/registration-form',      label: 'Registration Form',      ssr: false },
		{ name: 'buddynext/login-form',             label: 'Login Form',             ssr: false },
		{ name: 'buddynext/notification-bell',      label: 'Notification Bell',      ssr: false },
		{ name: 'buddynext/search-bar',             label: 'Search Bar',             ssr: false },
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
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/feed?page=' + ctx.page,
					{
						headers: {
							'X-WP-Nonce': window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
					}
				);
				ctx.loading = false;
			},
		},
	} );

	/* ── Follow button ─────────────────────────────────────────────────── */

	store( 'buddynext/follow-button', {
		state: {
			loading: false,
		},
		actions: {
			toggle: function* () {
				var ctx = getContext();
				if ( ctx.loading ) {
					return;
				}
				ctx.loading = true;
				var method  = ctx.following ? 'DELETE' : 'POST';
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/follows/' + ctx.userId,
					{
						method:  method,
						headers: {
							'X-WP-Nonce': window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
					}
				);
				ctx.following = ! ctx.following;
				ctx.loading   = false;
			},
		},
	} );

	/* ── Connection button ─────────────────────────────────────────────── */

	store( 'buddynext/connection-button', {
		state: {
			loading: false,
		},
		actions: {
			toggle: function* () {
				var ctx = getContext();
				if ( ctx.loading ) {
					return;
				}
				ctx.loading = true;
				var method  = ctx.connected ? 'DELETE' : 'POST';
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/connections/' + ctx.userId,
					{
						method:  method,
						headers: {
							'X-WP-Nonce': window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
					}
				);
				ctx.loading = false;
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
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/notifications/mark-all-read',
					{
						method:  'POST',
						headers: {
							'X-WP-Nonce': window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
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
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/posts/' + ctx.postId + '/react',
					{
						method:  'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce':   window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
						body: JSON.stringify( { emoji: ctx.reactionEmoji } ),
					}
				);
				ctx.loading = false;
			},
			toggleBookmark: function* () {
				var ctx    = getContext();
				ctx.loading = true;
				var method  = ctx.bookmarked ? 'DELETE' : 'POST';
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/posts/' + ctx.postId + '/bookmark',
					{
						method:  method,
						headers: {
							'X-WP-Nonce': window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
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
				yield window.fetch(
					( window.bnBlocks && window.bnBlocks.restUrl ? window.bnBlocks.restUrl : '' )
					+ '/buddynext/v1/posts',
					{
						method:  'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce':   window.bnBlocks && window.bnBlocks.nonce ? window.bnBlocks.nonce : '',
						},
						body: JSON.stringify( {
							content: ctx.content,
							privacy: ctx.privacy,
							type:    ctx.type,
						} ),
					}
				);
				ctx.content    = '';
				ctx.submitting = false;
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
