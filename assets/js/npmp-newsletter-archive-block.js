/**
 * Nonprofit Manager · the Newsletters block, a list of past newsletters.
 *
 * A dynamic block: save() returns null and the page renders through the same
 * PHP as [npmp_newsletters], so the preview matches the live page. Written
 * against the wp.* globals, so the plugin needs no JS build step. Pro adds a
 * Groups panel through blocks.registerBlockType and editor.BlockEdit.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	if ( ! blocks || ! element || ! serverSideRender ) {
		return;
	}

	var el                = element.createElement;
	var Fragment          = element.Fragment;
	var __                = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;
	var TextControl       = components.TextControl;
	var RangeControl      = components.RangeControl;
	var ToggleControl     = components.ToggleControl;

	// '' is the shortcode's default: shown for most, hidden for the link.
	var shownByDefault = [
		{ label: __( 'Show', 'nonprofit-manager' ), value: '' },
		{ label: __( 'Hide', 'nonprofit-manager' ), value: 'no' }
	];
	var hiddenByDefault = [
		{ label: __( 'Hide', 'nonprofit-manager' ), value: '' },
		{ label: __( 'Show', 'nonprofit-manager' ), value: 'yes' }
	];

	blocks.registerBlockType( 'nonprofit-manager/newsletters', {
		apiVersion: 3,
		title: __( 'Newsletters', 'nonprofit-manager' ),
		description: __( 'A list of past newsletters you made public. Tick "Show in the public archive" on a sent newsletter to list the newsletter here.', 'nonprofit-manager' ),
		icon: 'email-alt',
		category: 'widgets',
		keywords: [ __( 'newsletter', 'nonprofit-manager' ), __( 'archive', 'nonprofit-manager' ), __( 'email', 'nonprofit-manager' ), __( 'past', 'nonprofit-manager' ) ],
		attributes: {
			limit: { type: 'number', default: 10 },
			topic: { type: 'string', default: '' },
			order: { type: 'string', default: '' },
			layout: { type: 'string', default: '' },
			columns: { type: 'number', default: 0 },
			date: { type: 'string', default: '' },
			excerpt: { type: 'string', default: '' },
			excerptLength: { type: 'number', default: 0 },
			readmore: { type: 'string', default: '' },
			pagination: { type: 'string', default: '' },
			eventEmails: { type: 'boolean', default: false }
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
						{ title: __( 'Newsletters', 'nonprofit-manager' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Newsletters per page', 'nonprofit-manager' ),
							help: __( 'Set to 0 to show every public newsletter on one page.', 'nonprofit-manager' ),
							value: a.limit,
							min: 0,
							max: 100,
							onChange: set( 'limit' )
						} ),
						el( SelectControl, {
							label: __( 'Links to older newsletters', 'nonprofit-manager' ),
							value: a.pagination,
							options: shownByDefault,
							onChange: set( 'pagination' )
						} ),
						el( TextControl, {
							label: __( 'Topic slug', 'nonprofit-manager' ),
							help: __( 'Show only newsletters with this topic. Leave blank for all.', 'nonprofit-manager' ),
							value: a.topic,
							onChange: set( 'topic' )
						} ),
						el( SelectControl, {
							label: __( 'Order', 'nonprofit-manager' ),
							value: a.order,
							options: [
								{ label: __( 'Newest to oldest', 'nonprofit-manager' ), value: '' },
								{ label: __( 'Oldest to newest', 'nonprofit-manager' ), value: 'asc' }
							],
							onChange: set( 'order' )
						} ),
						el( ToggleControl, {
							label: __( 'Include event emails', 'nonprofit-manager' ),
							help: __( 'Event emails still need "Show in the public archive" ticked.', 'nonprofit-manager' ),
							checked: a.eventEmails,
							onChange: set( 'eventEmails' )
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Layout', 'nonprofit-manager' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Layout', 'nonprofit-manager' ),
							value: a.layout,
							options: [
								{ label: __( 'List', 'nonprofit-manager' ), value: '' },
								{ label: __( 'Grid', 'nonprofit-manager' ), value: 'grid' },
								{ label: __( 'Cards', 'nonprofit-manager' ), value: 'cards' }
							],
							onChange: set( 'layout' )
						} ),
						el( SelectControl, {
							label: __( 'Columns', 'nonprofit-manager' ),
							value: String( a.columns || '' ),
							options: [
								{ label: '3', value: '' },
								{ label: '2', value: '2' },
								{ label: '4', value: '4' }
							],
							onChange: function ( v ) { props.setAttributes( { columns: v ? parseInt( v, 10 ) : 0 } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Content', 'nonprofit-manager' ), initialOpen: false },
						el( SelectControl, { label: __( 'Date sent', 'nonprofit-manager' ), value: a.date, options: shownByDefault, onChange: set( 'date' ) } ),
						el( SelectControl, { label: __( 'Excerpt', 'nonprofit-manager' ), value: a.excerpt, options: shownByDefault, onChange: set( 'excerpt' ) } ),
						el( RangeControl, {
							label: __( 'Excerpt words', 'nonprofit-manager' ),
							help: __( 'Set to 0 for 30 words.', 'nonprofit-manager' ),
							value: a.excerptLength,
							min: 0,
							max: 200,
							onChange: set( 'excerptLength' )
						} ),
						el( SelectControl, { label: __( '"Read the newsletter" link', 'nonprofit-manager' ), value: a.readmore, options: hiddenByDefault, onChange: set( 'readmore' ) } )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRenderOr( serverSideRender ), { block: 'nonprofit-manager/newsletters', attributes: a } )
				)
			);
		},
		save: function () { return null; }
	} );

	function ServerSideRenderOr( ssr ) {
		return ssr && ssr.default ? ssr.default : ssr;
	}

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
