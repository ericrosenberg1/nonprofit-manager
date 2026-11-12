/**
 * Nonprofit Manager · editor blocks for the calendar and upcoming events.
 *
 * Both are dynamic (server-rendered) blocks: save() returns null and the
 * front-end output comes from the same PHP that powers the [npmp_calendar] and
 * [npmp_events] shortcodes, so the editor preview matches the live page. Written
 * against the wp.* globals so the plugin needs no JS build step.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	if ( ! blocks || ! element || ! serverSideRender ) {
		return;
	}

	var el               = element.createElement;
	var Fragment         = element.Fragment;
	var __               = i18n.__;
	var ServerSideRender = serverSideRender;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody        = components.PanelBody;
	var SelectControl    = components.SelectControl;
	var TextControl      = components.TextControl;
	var RangeControl     = components.RangeControl;
	var ToggleControl    = components.ToggleControl;

	var printChoices = [
		{ label: __( 'Site default', 'nonprofit-manager' ), value: '' },
		{ label: __( 'Show', 'nonprofit-manager' ), value: 'yes' },
		{ label: __( 'Hide', 'nonprofit-manager' ), value: 'no' }
	];

	/* ---- Calendar block ------------------------------------------------ */
	blocks.registerBlockType( 'nonprofit-manager/calendar', {
		apiVersion: 3,
		title: __( 'Events Calendar', 'nonprofit-manager' ),
		description: __( 'A month, week, or list calendar of your events, with built-in navigation.', 'nonprofit-manager' ),
		icon: 'calendar-alt',
		category: 'widgets',
		keywords: [ __( 'calendar', 'nonprofit-manager' ), __( 'events', 'nonprofit-manager' ), __( 'nonprofit', 'nonprofit-manager' ) ],
		supports: { html: false, align: [ 'wide', 'full' ] },
		attributes: {
			view: { type: 'string', default: '' },
			category: { type: 'string', default: '' },
			print: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Calendar settings', 'nonprofit-manager' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Opening view', 'nonprofit-manager' ),
							value: a.view,
							options: [
								{ label: __( 'Site default', 'nonprofit-manager' ), value: '' },
								{ label: __( 'Month', 'nonprofit-manager' ), value: 'month' },
								{ label: __( 'Week', 'nonprofit-manager' ), value: 'week' },
								{ label: __( 'List', 'nonprofit-manager' ), value: 'list' }
							],
							onChange: function ( v ) { props.setAttributes( { view: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Category slug', 'nonprofit-manager' ),
							help: __( 'Show only events in this category. Leave blank for all.', 'nonprofit-manager' ),
							value: a.category,
							onChange: function ( v ) { props.setAttributes( { category: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Print or save as PDF button', 'nonprofit-manager' ),
							help: __( 'Site default follows Calendar Settings.', 'nonprofit-manager' ),
							value: a.print,
							options: printChoices,
							onChange: function ( v ) { props.setAttributes( { print: v } ); }
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps( { className: 'npmp-block-preview' } ),
					el( ServerSideRender, { block: 'nonprofit-manager/calendar', attributes: a } )
				)
			);
		},
		save: function () { return null; }
	} );

	/* ---- Events loop block --------------------------------------------- */
	var siteDefault = { label: __( 'Site default', 'nonprofit-manager' ), value: '' };
	var showHide = [
		siteDefault,
		{ label: __( 'Show', 'nonprofit-manager' ), value: 'yes' },
		{ label: __( 'Hide', 'nonprofit-manager' ), value: 'no' }
	];

	blocks.registerBlockType( 'nonprofit-manager/events', {
		apiVersion: 3,
		title: __( 'Events', 'nonprofit-manager' ),
		description: __( 'A list, grid, cards, or mosaic of your events. Colors, type, spacing, and borders are in the Styles tab.', 'nonprofit-manager' ),
		icon: 'list-view',
		category: 'widgets',
		keywords: [ __( 'events', 'nonprofit-manager' ), __( 'upcoming', 'nonprofit-manager' ), __( 'archive', 'nonprofit-manager' ), __( 'meetings', 'nonprofit-manager' ), __( 'grid', 'nonprofit-manager' ) ],
		attributes: {
			limit: { type: 'number', default: 10 },
			category: { type: 'string', default: '' },
			past: { type: 'boolean', default: false },
			layout: { type: 'string', default: '' },
			columns: { type: 'number', default: 0 },
			order: { type: 'string', default: '' },
			ratio: { type: 'string', default: '' },
			image: { type: 'string', default: '' },
			excerpt: { type: 'string', default: '' },
			excerptLength: { type: 'number', default: 0 },
			date: { type: 'string', default: '' },
			location: { type: 'string', default: '' },
			readmore: { type: 'string', default: '' },
			print: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = blockEditor.useBlockProps();
			function set( key ) {
				return function ( v ) {
					var o = {};
					o[ key ] = v;
					props.setAttributes( o );
				};
			}
			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Events', 'nonprofit-manager' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Number of events', 'nonprofit-manager' ),
							help: __( 'Set to 0 to show every event.', 'nonprofit-manager' ),
							value: a.limit,
							min: 0,
							max: 100,
							onChange: set( 'limit' )
						} ),
						el( TextControl, {
							label: __( 'Category slug', 'nonprofit-manager' ),
							help: __( 'Show only events in this category. Leave blank for all.', 'nonprofit-manager' ),
							value: a.category,
							onChange: set( 'category' )
						} ),
						el( ToggleControl, {
							label: __( 'Include past events', 'nonprofit-manager' ),
							checked: a.past,
							onChange: set( 'past' )
						} ),
						el( SelectControl, {
							label: __( 'Order', 'nonprofit-manager' ),
							value: a.order,
							options: [
								siteDefault,
								{ label: __( 'Newest to oldest', 'nonprofit-manager' ), value: 'desc' },
								{ label: __( 'Oldest to newest', 'nonprofit-manager' ), value: 'asc' }
							],
							onChange: set( 'order' )
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Layout', 'nonprofit-manager' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Layout', 'nonprofit-manager' ),
							value: a.layout,
							options: [
								siteDefault,
								{ label: __( 'List', 'nonprofit-manager' ), value: 'list' },
								{ label: __( 'Grid', 'nonprofit-manager' ), value: 'grid' },
								{ label: __( 'Cards', 'nonprofit-manager' ), value: 'cards' },
								{ label: __( 'Mosaic', 'nonprofit-manager' ), value: 'mosaic' }
							],
							onChange: set( 'layout' )
						} ),
						el( SelectControl, {
							label: __( 'Columns', 'nonprofit-manager' ),
							value: String( a.columns || '' ),
							options: [
								siteDefault,
								{ label: '2', value: '2' },
								{ label: '3', value: '3' },
								{ label: '4', value: '4' }
							],
							onChange: function ( v ) { props.setAttributes( { columns: v ? parseInt( v, 10 ) : 0 } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Content', 'nonprofit-manager' ), initialOpen: false },
						el( SelectControl, { label: __( 'Featured image', 'nonprofit-manager' ), value: a.image, options: showHide, onChange: set( 'image' ) } ),
						el( SelectControl, {
							label: __( 'Image shape', 'nonprofit-manager' ),
							value: a.ratio,
							options: [
								siteDefault,
								{ label: __( 'Wide (16:9)', 'nonprofit-manager' ), value: '16-9' },
								{ label: __( 'Landscape (3:2)', 'nonprofit-manager' ), value: '3-2' },
								{ label: __( 'Classic (4:3)', 'nonprofit-manager' ), value: '4-3' },
								{ label: __( 'Square (1:1)', 'nonprofit-manager' ), value: '1-1' }
							],
							onChange: set( 'ratio' )
						} ),
						el( SelectControl, { label: __( 'Excerpt', 'nonprofit-manager' ), value: a.excerpt, options: showHide, onChange: set( 'excerpt' ) } ),
						el( RangeControl, {
							label: __( 'Excerpt words', 'nonprofit-manager' ),
							help: __( 'Set to 0 to use the site default.', 'nonprofit-manager' ),
							value: a.excerptLength,
							min: 0,
							max: 200,
							onChange: set( 'excerptLength' )
						} ),
						el( SelectControl, { label: __( 'Date and time', 'nonprofit-manager' ), value: a.date, options: showHide, onChange: set( 'date' ) } ),
						el( SelectControl, { label: __( 'Location', 'nonprofit-manager' ), value: a.location, options: showHide, onChange: set( 'location' ) } ),
						el( SelectControl, { label: __( 'Read more link', 'nonprofit-manager' ), value: a.readmore, options: showHide, onChange: set( 'readmore' ) } ),
						el( SelectControl, {
							label: __( 'Print or save as PDF button', 'nonprofit-manager' ),
							help: __( 'Site default follows Calendar Settings.', 'nonprofit-manager' ),
							value: a.print,
							options: printChoices,
							onChange: set( 'print' )
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, { block: 'nonprofit-manager/events', attributes: a } )
				)
			);
		},
		save: function () { return null; }
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
