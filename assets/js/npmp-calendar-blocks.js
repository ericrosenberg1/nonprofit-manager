/**
 * Nonprofit Manager — editor blocks for the calendar and upcoming events.
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

	/* ---- Calendar block ------------------------------------------------ */
	blocks.registerBlockType( 'nonprofit-manager/calendar', {
		title: __( 'Events Calendar', 'nonprofit-manager' ),
		description: __( 'A month, week, or list calendar of your events, with built-in navigation.', 'nonprofit-manager' ),
		icon: 'calendar-alt',
		category: 'widgets',
		keywords: [ __( 'calendar', 'nonprofit-manager' ), __( 'events', 'nonprofit-manager' ), __( 'nonprofit', 'nonprofit-manager' ) ],
		supports: { html: false, align: [ 'wide', 'full' ] },
		attributes: {
			view: { type: 'string', default: '' },
			category: { type: 'string', default: '' }
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
						} )
					)
				),
				el(
					'div',
					{ className: 'npmp-block-preview' },
					el( ServerSideRender, { block: 'nonprofit-manager/calendar', attributes: a } )
				)
			);
		},
		save: function () { return null; }
	} );

	/* ---- Upcoming events block ----------------------------------------- */
	blocks.registerBlockType( 'nonprofit-manager/events', {
		title: __( 'Upcoming Events', 'nonprofit-manager' ),
		description: __( 'A simple, styled list of upcoming events.', 'nonprofit-manager' ),
		icon: 'list-view',
		category: 'widgets',
		keywords: [ __( 'events', 'nonprofit-manager' ), __( 'upcoming', 'nonprofit-manager' ), __( 'list', 'nonprofit-manager' ) ],
		supports: { html: false, align: [ 'wide' ] },
		attributes: {
			limit: { type: 'number', default: 10 },
			category: { type: 'string', default: '' },
			past: { type: 'boolean', default: false }
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
						{ title: __( 'Events settings', 'nonprofit-manager' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Number of events', 'nonprofit-manager' ),
							value: a.limit,
							min: 1,
							max: 50,
							onChange: function ( v ) { props.setAttributes( { limit: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Category slug', 'nonprofit-manager' ),
							help: __( 'Show only events in this category. Leave blank for all.', 'nonprofit-manager' ),
							value: a.category,
							onChange: function ( v ) { props.setAttributes( { category: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show past events instead', 'nonprofit-manager' ),
							checked: a.past,
							onChange: function ( v ) { props.setAttributes( { past: v } ); }
						} )
					)
				),
				el(
					'div',
					{ className: 'npmp-block-preview' },
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
