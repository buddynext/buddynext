/**
 * BuddyNext — blocks.js
 *
 * EDITOR script for all 19 BuddyNext Gutenberg blocks: block registrations,
 * their InspectorControls, and server-side-rendered previews. Registered as
 * `buddynext-blocks-editor` and referenced only as `editorScript` in block.json,
 * so it never loads on the front end.
 *
 * Frontend behaviour lives in script modules under assets/js/, declared per
 * block as `viewScriptModule`. Nothing in this file runs for a visitor — do not
 * add an Interactivity store here. It used to carry eight of them, and every one
 * was dead: the file cannot load on the front end, and no markup declared their
 * namespaces. See the commit that removed them.
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

	/**
	 * An ORDERED multi-picker for the showcase blocks' "Hand-picked" source.
	 *
	 * Both showcase blocks offered Hand-picked with nothing to pick with: the
	 * attribute existed, the template honoured it, and no control could write it.
	 * spaces-showcase hid that by falling through to the popularity query, so an
	 * owner who chose Hand-picked was shown spaces they had not chosen; members-
	 * showcase rendered its empty state forever.
	 *
	 * Order is the point of picking - an owner curating their three best wants
	 * them in that order - and FormTokenField preserves insertion order, which a
	 * checkbox list would not. Only renders when the source is actually 'picked',
	 * so the panel does not carry a control that does nothing.
	 */
	function pickListControl( kind, label, attr, help ) {
		return function ( props ) {
			var options = useEntityOptions( kind );

			if ( 'picked' !== props.attributes.source ) {
				return null;
			}
			if ( ! components.FormTokenField ) {
				return null;
			}

			var list    = options || [];
			var byId    = {};
			var byLabel = {};
			list.forEach( function ( o ) {
				byId[ o.value ] = o.label;
				// Two entities can share a display name; the id in the token keeps
				// them distinct and keeps the mapping reversible.
				byLabel[ o.label + ' (#' + o.value + ')' ] = o.value;
			} );

			var suggestions = list.map( function ( o ) { return o.label + ' (#' + o.value + ')'; } );
			var current     = ( props.attributes[ attr ] || [] ).map( function ( id ) {
				return ( byId[ String( id ) ] || '#' + id ) + ' (#' + id + ')';
			} );

			return el( components.FormTokenField, {
				label: label,
				help: null === options ? __( 'Loading…', 'buddynext' ) : help,
				value: current,
				suggestions: suggestions,
				__experimentalExpandOnFocus: true,
				onChange: function ( tokens ) {
					var ids = tokens
						.map( function ( t ) {
							if ( byLabel[ t ] ) {
								return parseInt( byLabel[ t ], 10 );
							}
							// Typed free-form: accept a bare id, drop anything else
							// rather than silently storing a token that resolves to
							// nothing on the front end.
							var m = String( t ).match( /#?(\d+)\)?$/ );
							return m ? parseInt( m[1], 10 ) : 0;
						} )
						.filter( function ( id ) { return id > 0; } );

					var next = {};
					next[ attr ] = ids;
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

	/**
	 * A select that says what the choice means. See helpToggleControl below for
	 * why some controls need the sentence and some do not.
	 */
	function helpSelectControl( attr, label, choices, help ) {
		return function ( props ) {
			return el( components.SelectControl, {
				label: label,
				help: help,
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
			rangeControl( 'count', __( 'Hashtags to show', 'buddynext' ), 1, 20 ),
			selectControl( 'display', __( 'Display as', 'buddynext' ), [
				{ value: 'list', label: __( 'List', 'buddynext' ) },
				{ value: 'cloud', label: __( 'Cloud', 'buddynext' ) },
			] ),
			helpSelectControl( 'from', __( 'From', 'buddynext' ), [
				{ value: '24h', label: __( 'Last 24 hours', 'buddynext' ) },
				{ value: '7d', label: __( 'Last 7 days', 'buddynext' ) },
				{ value: '30d', label: __( 'Last 30 days', 'buddynext' ) },
			], __( 'How far back to look for activity. A quiet community has more to show over a longer window.', 'buddynext' ) ),
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
			pickListControl(
				'spaces',
				__( 'Spaces to feature', 'buddynext' ),
				'spaceIds',
				__( 'Shown in the order you add them. Pick none and the block shows its empty state.', 'buddynext' )
			),
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
			pickListControl(
				'members',
				__( 'Members to feature', 'buddynext' ),
				'userIds',
				__( 'Shown in the order you add them. Pick none and the block shows its empty state.', 'buddynext' )
			),
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
		'buddynext/search-bar': [
			textControl( 'placeholder', __( 'Placeholder text', 'buddynext' ) ),
			selectControl( 'searchIn', __( 'Search in', 'buddynext' ), [
				{ value: 'all', label: __( 'Everything', 'buddynext' ) },
				{ value: 'members', label: __( 'People', 'buddynext' ) },
				{ value: 'spaces', label: __( 'Spaces', 'buddynext' ) },
				{ value: 'posts', label: __( 'Posts', 'buddynext' ) },
			] ),
		],
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
	 * Block definitions: all 19 BuddyNext blocks.
	 *
	 * Every one is server-rendered, so every one previews live in the editor via
	 * wp.serverSideRender. There used to be an `ssr` flag with a static-placeholder
	 * fallback "for blocks with no PHP REST callback" — but all 19 have a render
	 * callback, so the five marked ssr:false were showing an owner a grey
	 * "Rendered on the frontend" box for no reason (Basecamp 10184505157,
	 * 10184505171, 10184505544, 10184505547). Flag and fallback both removed:
	 * a block with no callback could not render on the front end either.
	 */
	var blockDefs = [
		{ name: 'buddynext/activity-feed',         label: __( 'Activity Feed', 'buddynext' ) },
		{ name: 'buddynext/post-composer',          label: __( 'Post Composer', 'buddynext' ) },
		{ name: 'buddynext/trending-hashtags',      label: __( 'Trending Hashtags', 'buddynext' ) },
		{ name: 'buddynext/member-directory',       label: __( 'Member Directory', 'buddynext' ) },
		{ name: 'buddynext/member-card',            label: __( 'Member Card', 'buddynext' ) },
		{ name: 'buddynext/follow-button',          label: __( 'Follow Button', 'buddynext' ) },
		{ name: 'buddynext/connection-button',      label: __( 'Connection Button', 'buddynext' ) },
		{ name: 'buddynext/space-directory',        label: __( 'Space Directory', 'buddynext' ) },
		{ name: 'buddynext/spaces-showcase',        label: __( 'Spaces showcase', 'buddynext' ) },
		{ name: 'buddynext/members-showcase',       label: __( 'Members showcase', 'buddynext' ) },
		{ name: 'buddynext/community-activity',     label: __( 'Community activity', 'buddynext' ) },
		{ name: 'buddynext/space-card',             label: __( 'Space Card', 'buddynext' ) },
		{ name: 'buddynext/my-spaces',              label: __( 'My Spaces', 'buddynext' ) },
		{ name: 'buddynext/profile-header',         label: __( 'Profile Header', 'buddynext' ) },
		{ name: 'buddynext/profile-fields',         label: __( 'Profile Fields', 'buddynext' ) },
		{ name: 'buddynext/profile-completion-bar', label: __( 'Profile Completion Bar', 'buddynext' ) },
		{ name: 'buddynext/notification-bell',      label: __( 'Notification Bell', 'buddynext' ) },
		{ name: 'buddynext/search-bar',             label: __( 'Search Bar', 'buddynext' ) },
		{ name: 'buddynext/header-user-menu',       label: __( 'Header User Menu', 'buddynext' ) },
	];

	blockDefs.forEach( function ( def ) {
		// Skip if already registered (double-load guard).
		if ( blocks.getBlockType( def.name ) ) {
			return;
		}
		blocks.registerBlockType( def.name, {
			edit: ssrEdit( def.name ),
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
