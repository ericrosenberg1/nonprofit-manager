/**
 * Nonprofit Manager — editor blocks for the member/donor content widgets.
 *
 * All are dynamic (server-rendered) blocks: save() returns null and the
 * front-end output comes from the same PHP that powers the matching shortcodes,
 * so the editor preview matches the live page. Written against the wp.* globals
 * so the plugin needs no JS build step. The three wrapper blocks only register
 * when PHP reports their feature is active (window.npmpContentBlocks).
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	if ( ! blocks || ! element || ! serverSideRender ) {
		return;
	}

	var el                = element.createElement;
	var Fragment          = element.Fragment;
	var __                = i18n.__;
	var ServerSideRender  = serverSideRender;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var TextControl       = components.TextControl;
	var ToggleControl     = components.ToggleControl;
	var avail             = window.npmpContentBlocks || { signup: true, unsubscribe: true, donation: true };

	function preview( block, attributes ) {
		return el(
			'div',
			{ className: 'npmp-block-preview' },
			el( ServerSideRender, { block: block, attributes: attributes || {} } )
		);
	}

	/* ---- Email signup (wraps [npmp_email_signup]) ---------------------- */
	if ( avail.signup ) {
		blocks.registerBlockType( 'nonprofit-manager/email-signup', {
			title: __( 'Email Signup Form', 'nonprofit-manager' ),
			description: __( 'Your newsletter signup form, styled to match your theme.', 'nonprofit-manager' ),
			icon: 'email-alt',
			category: 'widgets',
			keywords: [ __( 'signup', 'nonprofit-manager' ), __( 'newsletter', 'nonprofit-manager' ), __( 'subscribe', 'nonprofit-manager' ) ],
			supports: { html: false },
			edit: function () { return preview( 'nonprofit-manager/email-signup' ); },
			save: function () { return null; }
		} );
	}

	/* ---- Unsubscribe (wraps [npmp_email_unsubscribe]) ------------------ */
	if ( avail.unsubscribe ) {
		blocks.registerBlockType( 'nonprofit-manager/email-unsubscribe', {
			title: __( 'Email Unsubscribe Form', 'nonprofit-manager' ),
			description: __( 'A one-field form for people to unsubscribe from your emails.', 'nonprofit-manager' ),
			icon: 'dismiss',
			category: 'widgets',
			keywords: [ __( 'unsubscribe', 'nonprofit-manager' ), __( 'newsletter', 'nonprofit-manager' ), __( 'opt out', 'nonprofit-manager' ) ],
			supports: { html: false },
			edit: function () { return preview( 'nonprofit-manager/email-unsubscribe' ); },
			save: function () { return null; }
		} );
	}

	/* ---- Donation form (wraps [npmp_donation_form]) -------------------- */
	if ( avail.donation ) {
		blocks.registerBlockType( 'nonprofit-manager/donation-form', {
			title: __( 'Donation Form', 'nonprofit-manager' ),
			description: __( 'Your donation form with the payment options you have enabled.', 'nonprofit-manager' ),
			icon: 'heart',
			category: 'widgets',
			keywords: [ __( 'donation', 'nonprofit-manager' ), __( 'donate', 'nonprofit-manager' ), __( 'give', 'nonprofit-manager' ) ],
			supports: { html: false },
			edit: function () { return preview( 'nonprofit-manager/donation-form' ); },
			save: function () { return null; }
		} );
	}

	/* ---- Social share (new) -------------------------------------------- */
	blocks.registerBlockType( 'nonprofit-manager/social-share', {
		title: __( 'Social Share', 'nonprofit-manager' ),
		description: __( 'Let visitors share the current page on their own social networks.', 'nonprofit-manager' ),
		icon: 'share',
		category: 'widgets',
		keywords: [ __( 'share', 'nonprofit-manager' ), __( 'social', 'nonprofit-manager' ), __( 'facebook', 'nonprofit-manager' ) ],
		supports: { html: false, align: [ 'wide' ] },
		attributes: {
			networks: { type: 'string', default: 'facebook,x,linkedin,reddit,email,copy' },
			label: { type: 'string', default: '' }
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
						{ title: __( 'Share settings', 'nonprofit-manager' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Networks', 'nonprofit-manager' ),
							help: __( 'Comma-separated: facebook, x, linkedin, reddit, email, copy.', 'nonprofit-manager' ),
							value: a.networks,
							onChange: function ( v ) { props.setAttributes( { networks: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Label (optional)', 'nonprofit-manager' ),
							help: __( 'Short text before the buttons, e.g. "Share this".', 'nonprofit-manager' ),
							value: a.label,
							onChange: function ( v ) { props.setAttributes( { label: v } ); }
						} )
					)
				),
				preview( 'nonprofit-manager/social-share', a )
			);
		},
		save: function () { return null; }
	} );

	/* ---- Contact form (new) -------------------------------------------- */
	blocks.registerBlockType( 'nonprofit-manager/contact-form', {
		title: __( 'Contact Form', 'nonprofit-manager' ),
		description: __( 'A simple contact form that emails your organization.', 'nonprofit-manager' ),
		icon: 'email',
		category: 'widgets',
		keywords: [ __( 'contact', 'nonprofit-manager' ), __( 'form', 'nonprofit-manager' ), __( 'message', 'nonprofit-manager' ) ],
		supports: { html: false },
		attributes: {
			heading: { type: 'string', default: '' },
			button: { type: 'string', default: '' },
			subject: { type: 'boolean', default: true }
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
						{ title: __( 'Contact form settings', 'nonprofit-manager' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Heading', 'nonprofit-manager' ),
							value: a.heading,
							placeholder: __( 'Contact us', 'nonprofit-manager' ),
							onChange: function ( v ) { props.setAttributes( { heading: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Button label', 'nonprofit-manager' ),
							value: a.button,
							placeholder: __( 'Send message', 'nonprofit-manager' ),
							onChange: function ( v ) { props.setAttributes( { button: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show subject field', 'nonprofit-manager' ),
							checked: a.subject,
							onChange: function ( v ) { props.setAttributes( { subject: v } ); }
						} )
					)
				),
				preview( 'nonprofit-manager/contact-form', a )
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
