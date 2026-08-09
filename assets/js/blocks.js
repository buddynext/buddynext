/**
 * BuddyNext — blocks.js
 *
 * Editor script for all 19 BuddyNext Gutenberg blocks.
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

	/* ── Block settings (InspectorControls) ─────────────────────────────
	 *
	 * Every BuddyNext block declares attributes in its block.json and every
	 * render callback reads them — but until now NOTHING in the editor could
	 * write one. The registration loop below passed only edit/save, so a site
	 * owner inserting a block from the inserter could not choose the member, the
	 * space, the layout or the page size, and the seven blocks whose target
	 * defaults to 0 simply rendered empty (Basecamp 10182432045).
	 *
	 * The controls live in ONE map keyed by block name, so adding a setting is a
	 * data change rather than a bespoke edit function per block.
	 */
	var components   = wp.components || {};
	var InspectorControls = blockEditor.InspectorControls;
	var useState     = element.useState;
	var useEffect    = element.useEffect;

	/**
	 * Fetch pickable entities (members or spaces) for the target selects.
	 *
	 * Uses window.bnBlocks (localised on this script) rather than adding a
	 * wp-api-fetch dependency, so the editor bundle is unchanged.
	 *
	 * @param {string} kind 'members' or 'spaces'.
	 * @return {Array} [ { value, label } ] including a context-default option.
	 */
	function useEntityOptions( kind ) {
		var state = useState( null );
		var options = state[0];
		var setOptions = state[1];

		useEffect( function () {
			if ( ! window.bnBlocks || ! window.bnBlocks.restUrl ) {
				setOptions( [] );
				return;
			}
			var url = window.bnBlocks.restUrl + '/' + kind + '?per_page=100';
			window.fetch( url, { headers: { 'X-WP-Nonce': window.bnBlocks.nonce } } )
				.then( function ( r ) { return r.ok ? r.json() : []; } )
				.then( function ( rows ) {
					var list = Array.isArray( rows ) ? rows : ( rows && rows.items ) || [];
					setOptions( list.map( function ( row ) {
						return {
							value: String( row.id || row.user_id || 0 ),
							label: row.name || row.display_name || row.title || ( '#' + ( row.id || 0 ) ),
						};
					} ) );
				} )
				.catch( function () { setOptions( [] ); } );
		}, [ kind ] );

		return options;
	}

	/**
	 * A target picker: choose a specific member/space, or inherit the page.
	 *
	 * 0 means "whoever/whatever this page is about", which is how these blocks
	 * behave inside BuddyNext's own templates. Saying so explicitly is the
	 * difference between a block that looks broken and one that is contextual.
	 */
	function targetControl( kind, label, attr ) {
		return function ( props ) {
			var options = useEntityOptions( kind );
			var choices = [ { value: '0', label: __( 'Current page context', 'buddynext' ) } ]
				.concat( options || [] );

			return el( components.SelectControl, {
				label: label,
				value: String( props.attributes[ attr ] || 0 ),
				options: choices,
				help: null === options
					? __( 'Loading…', 'buddynext' )
					: __( 'Leave on “Current page context” to follow whoever the page is about.', 'buddynext' ),
				onChange: function ( v ) {
					var next = {};
					next[ attr ] = parseInt( v, 10 ) || 0;
					props.setAttributes( next );
				},
			} );
		};
	}

	/**
	 * A target picker with NO page-context option — the target must be chosen.
	 *
	 * targetControl() above offers "Current page context", and for the member
	 * blocks that is real: they fall back to the author of the page carrying the
	 * block. A space has no equivalent — nothing on an ordinary WordPress page
	 * says which space it is about, and BuddyNext's own space routes render PHP
	 * templates rather than blocks, so the context can never be resolved. Offering
	 * it there was a setting that saved and did nothing, and left the front end
	 * silently empty with no explanation anywhere.
	 */
	function requiredTargetControl( kind, label, attr, emptyLabel, help ) {
		return function ( props ) {
			var options = useEntityOptions( kind );
			var choices = [ { value: '0', label: emptyLabel } ].concat( options || [] );

			return el( components.SelectControl, {
				label: label,
				value: String( props.attributes[ attr ] || 0 ),
				options: choices,
				help: null === options ? __( 'Loading…', 'buddynext' ) : help,
				onChange: function ( v ) {
					var next = {};
					next[ attr ] = parseInt( v, 10 ) || 0;
					props.setAttributes( next );
				},
			} );
		};
	}

	function rangeControl( attr, label, min, max ) {
		return function ( props ) {
			return el( components.RangeControl, {
				label: label,
				value: props.attributes[ attr ],
				min: min,
				max: max,
				onChange: function ( v ) {
					var next = {}; next[ attr ] = v; props.setAttributes( next );
				},
			} );
		};
	}

	function selectControl( attr, label, choices ) {
		return function ( props ) {
			return el( components.SelectControl, {
				label: label,
				value: props.attributes[ attr ],
				options: choices,
				onChange: function ( v ) {
					var next = {}; next[ attr ] = v; props.setAttributes( next );
				},
			} );
		};
	}

	function toggleControl( attr, label ) {
		return function ( props ) {
			return el( components.ToggleControl, {
				label: label,
				checked: !! props.attributes[ attr ],
				onChange: function ( v ) {
					var next = {}; next[ attr ] = v; props.setAttributes( next );
				},
			} );
		};
	}

	/**
	 * A toggle that says what it does.
	 *
	 * The bare toggleControl above is fine when the label is self-evident
	 * ("Show description"). It is not when the toggle governs more than its name
	 * ("Show follow action" also hides Connect) or when the label names a word
	 * the owner has to guess at ("Show stats" - which stats?). A control an owner
	 * has to try in order to understand is a control they will leave alone.
	 */
	function helpToggleControl( attr, label, help ) {
		return function ( props ) {
			return el( components.ToggleControl, {
				label: label,
				help: help,
				checked: !! props.attributes[ attr ],
				onChange: function ( v ) {
					var next = {}; next[ attr ] = v; props.setAttributes( next );
				},
			} );
		};
	}

	function textControl( attr, label ) {
		return function ( props ) {
			return el( components.TextControl, {
				label: label,
				value: props.attributes[ attr ] || '',
				onChange: function ( v ) {
					var next = {}; next[ attr ] = v; props.setAttributes( next );
				},
			} );
		};
	}

	var GRID_LIST = [
		{ value: 'grid', label: __( 'Grid', 'buddynext' ) },
		{ value: 'list', label: __( 'List', 'buddynext' ) },
	];

	var blockControls = {
		'buddynext/activity-feed': [
			selectControl( 'scope', __( 'Feed', 'buddynext' ), [
				{ value: 'home', label: __( 'Home (personalised)', 'buddynext' ) },
				{ value: 'explore', label: __( 'Explore (community-wide)', 'buddynext' ) },
			] ),
			rangeControl( 'perPage', __( 'Posts per page', 'buddynext' ), 1, 50 ),
		],
		'buddynext/member-directory': [
			rangeControl( 'perPage', __( 'Members per page', 'buddynext' ), 1, 60 ),
			selectControl( 'layout', __( 'Layout', 'buddynext' ), GRID_LIST ),
		],
		'buddynext/space-directory': [
			rangeControl( 'perPage', __( 'Spaces per page', 'buddynext' ), 1, 60 ),
			selectControl( 'layout', __( 'Layout', 'buddynext' ), GRID_LIST ),
		],
		'buddynext/my-spaces': [ rangeControl( 'limit', __( 'Spaces to show', 'buddynext' ), 1, 50 ) ],
		'buddynext/trending-hashtags': [
			rangeControl( 'count', __( 'Hashtags to show', 'buddynext' ), 1, 50 ),
			selectControl( 'display', __( 'Display as', 'buddynext' ), [
				{ value: 'list', label: __( 'List', 'buddynext' ) },
				{ value: 'cloud', label: __( 'Cloud', 'buddynext' ) },
			] ),
		],
		'buddynext/member-card': [
			targetControl( 'members', __( 'Member', 'buddynext' ), 'userId' ),
			selectControl( 'size', __( 'Size', 'buddynext' ), [
				{ value: 'full', label: __( 'Full', 'buddynext' ) },
				{ value: 'compact', label: __( 'Compact (no cover)', 'buddynext' ) },
			] ),
			helpToggleControl(
				'showFollowAction',
				__( 'Show follow action', 'buddynext' ),
				__( 'Off hides Follow and Connect. Visitors can still open the profile.', 'buddynext' )
			),
			helpToggleControl(
				'showStats',
				__( 'Show stats', 'buddynext' ),
				__( 'Shows how many connections you have in common with this member.', 'buddynext' )
			),
		],
		'buddynext/follow-button':          [ targetControl( 'members', __( 'Member', 'buddynext' ), 'userId' ) ],
		'buddynext/connection-button':      [ targetControl( 'members', __( 'Member', 'buddynext' ), 'userId' ) ],
		'buddynext/profile-completion-bar': [ targetControl( 'members', __( 'Member', 'buddynext' ), 'userId' ) ],
		'buddynext/profile-fields':         [
			targetControl( 'members', __( 'Member', 'buddynext' ), 'userId' ),
			textControl( 'group', __( 'Field group (slug)', 'buddynext' ) ),
		],
		'buddynext/profile-header': [
			targetControl( 'members', __( 'Member', 'buddynext' ), 'userId' ),
			toggleControl( 'showStats', __( 'Show stats', 'buddynext' ) ),
			toggleControl( 'showActions', __( 'Show actions', 'buddynext' ) ),
		],
		'buddynext/spaces-showcase': [
			selectControl( 'source', __( 'Choose spaces by', 'buddynext' ), [
				{ value: 'popular', label: __( 'Most members', 'buddynext' ) },
				{ value: 'newest', label: __( 'Newest', 'buddynext' ) },
				{ value: 'name', label: __( 'Name (A-Z)', 'buddynext' ) },
				{ value: 'picked', label: __( 'Hand-picked', 'buddynext' ) },
			] ),
			rangeControl( 'count', __( 'How many', 'buddynext' ), 1, 6 ),
			selectControl( 'layout', __( 'Layout', 'buddynext' ), GRID_LIST ),
			toggleControl( 'showDescription', __( 'Show description', 'buddynext' ) ),
		],
		'buddynext/members-showcase': [
			selectControl( 'source', __( 'Choose members by', 'buddynext' ), [
				{ value: 'newest', label: __( 'Newest', 'buddynext' ) },
				{ value: 'most_active', label: __( 'Most active', 'buddynext' ) },
				{ value: 'online', label: __( 'Online now', 'buddynext' ) },
				{ value: 'member_type', label: __( 'Member type', 'buddynext' ) },
				{ value: 'picked', label: __( 'Hand-picked', 'buddynext' ) },
			] ),
			textControl( 'memberType', __( 'Member type (slug)', 'buddynext' ) ),
			rangeControl( 'count', __( 'How many', 'buddynext' ), 1, 8 ),
			selectControl( 'layout', __( 'Layout', 'buddynext' ), [
				{ value: 'list', label: __( 'List', 'buddynext' ) },
				{ value: 'grid', label: __( 'Grid', 'buddynext' ) },
			] ),
			toggleControl( 'showHeadline', __( 'Show headline', 'buddynext' ) ),
		],
		'buddynext/community-activity': [
			rangeControl( 'count', __( 'How many', 'buddynext' ), 1, 10 ),
			selectControl( 'show', __( 'Show', 'buddynext' ), [
				{ value: 'all', label: __( 'Everything', 'buddynext' ) },
				{ value: 'posts', label: __( 'Posts', 'buddynext' ) },
				{ value: 'discussions', label: __( 'Discussions', 'buddynext' ) },
				{ value: 'media', label: __( 'Media', 'buddynext' ) },
			] ),
			toggleControl( 'showSpaceName', __( 'Show space name', 'buddynext' ) ),
		],
		'buddynext/space-card': [
			requiredTargetControl(
				'spaces',
				__( 'Space', 'buddynext' ),
				'spaceId',
				__( 'Choose a space…', 'buddynext' ),
				__( 'Pick the space this card features. Without one the block renders nothing.', 'buddynext' )
			),
			selectControl( 'size', __( 'Size', 'buddynext' ), [
				{ value: 'full', label: __( 'Full', 'buddynext' ) },
				{ value: 'compact', label: __( 'Compact (no cover)', 'buddynext' ) },
			] ),
			toggleControl( 'showJoinAction', __( 'Show join action', 'buddynext' ) ),
		],
		'buddynext/post-composer': [ textControl( 'placeholder', __( 'Placeholder text', 'buddynext' ) ) ],
		'buddynext/search-bar':    [ textControl( 'placeholder', __( 'Placeholder text', 'buddynext' ) ) ],
	};

	/**
	 * Render the settings panel for a block, or nothing when it has no settings.
	 *
	 * @param {string} name  Block name.
	 * @param {Object} props Edit props.
	 * @return {Object|null} InspectorControls element.
	 */
	function inspectorFor( name, props ) {
		var controls = blockControls[ name ];
		if ( ! controls || ! InspectorControls || ! components.PanelBody ) {
			return null;
		}
		return el(
			InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __( 'Settings', 'buddynext' ), initialOpen: true },
				controls.map( function ( Control, i ) {
					return el( Control, Object.assign( { key: 'bn-ctrl-' + i }, props ) );
				} )
			)
		);
	}

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
				element.Fragment,
				null,
				inspectorFor( name, props ),
				el(
					'div',
					blockProps,
					el( SSR, {
						block:      name,
						attributes: props.attributes,
					} )
				)
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
	function placeholderEdit( label, icon, name ) {
		return function ( props ) {
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
			return el(
				element.Fragment,
				null,
				name ? inspectorFor( name, props ) : null,
				el( 'div', blockProps, iconEl )
			);
		};
	}

	/**
	 * Block definitions: all 19 BuddyNext blocks.
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
		{ name: 'buddynext/spaces-showcase',        label: __( 'Spaces showcase', 'buddynext' ),        ssr: true  },
		{ name: 'buddynext/members-showcase',       label: __( 'Members showcase', 'buddynext' ),       ssr: true  },
		{ name: 'buddynext/community-activity',     label: __( 'Community activity', 'buddynext' ),     ssr: true  },
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
			edit: def.ssr ? ssrEdit( def.name ) : placeholderEdit( def.label, def.icon, def.name ),
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
