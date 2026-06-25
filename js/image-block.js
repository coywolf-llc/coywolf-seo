/**
 * Coywolf SEO — "Add mobile alternative" control on the core Image block.
 *
 * Registers a `coywolfMobileImageId` attribute on core/image and renders a
 * media picker in the block inspector (below the "Add image" button and
 * Alternative text). The author picks an image meant for phones; the front-end
 * srcset swap that serves it at the small breakpoints lives in
 * Coywolf_SEO_Mobile_Image (PHP).
 *
 * On WordPress 7.0+ the image block's "Alternative text" lives in a dedicated
 * "Content" inspector tab, so the control is placed in that group; older WP has
 * no such group (it would silently drop the fill), so it falls back to the
 * default Settings panel. The flag comes from PHP (coywolf_seo_image_block).
 *
 * No build step: plain wp.element.createElement against the wp globals.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.blockEditor || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var addFilter = wp.hooks.addFilter;
	var __ = wp.i18n.__;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	var Button = wp.components.Button;
	var useSelect = wp.data && wp.data.useSelect;

	var config = window.coywolf_seo_image_block || { contentGroup: false };
	var ATTR = 'coywolfMobileImageId';
	var ALLOWED_TYPES = [ 'image' ];

	/*
	 * 1. Declare the attribute on core/image so the editor serializes it into
	 *    the block delimiter comment (and PHP sees it in $block['attrs']). The
	 *    attribute has no `source`, so it is stored in the comment JSON and the
	 *    static save markup is unchanged — block validation still passes.
	 */
	addFilter(
		'blocks.registerBlockType',
		'coywolf-seo/image-mobile-attribute',
		function ( settings, name ) {
			if ( 'core/image' !== name ) {
				return settings;
			}
			settings.attributes = Object.assign( {}, settings.attributes, {
				coywolfMobileImageId: { type: 'number' }
			} );
			return settings;
		}
	);

	/*
	 * 2. The inspector control: a description, an optional preview of the
	 *    chosen image, and the add/replace/remove buttons.
	 */
	function MobileAltControl( props ) {
		var mobileId = props.attributes[ ATTR ] || 0;

		// The id is the source of truth; resolve the picked image only to show
		// a preview thumbnail in the editor (its URL is never stored).
		var media = useSelect( function ( select ) {
			return mobileId ? select( 'core' ).getMedia( mobileId ) : null;
		}, [ mobileId ] );

		function onSelect( image ) {
			props.setAttributes( {
				coywolfMobileImageId: image && image.id ? image.id : undefined
			} );
		}

		function onRemove() {
			props.setAttributes( { coywolfMobileImageId: undefined } );
		}

		var preview = null;
		if ( mobileId ) {
			var sizes = media && media.media_details && media.media_details.sizes;
			var thumb = sizes && sizes.thumbnail
				? sizes.thumbnail.source_url
				: ( media && media.source_url ) || '';
			var label = media && media.title && media.title.rendered
				? media.title.rendered
				: '#' + mobileId;
			preview = el(
				'div',
				{ className: 'coywolf-seo-mobile-alt__preview' },
				thumb ? el( 'img', { src: thumb, alt: '' } ) : null,
				el( 'span', null, label )
			);
		}

		return el(
			'div',
			{ className: 'coywolf-seo-mobile-alt' },
			preview,
			el(
				MediaUploadCheck,
				null,
				el( MediaUpload, {
					onSelect: onSelect,
					allowedTypes: ALLOWED_TYPES,
					value: mobileId,
					render: function ( renderProps ) {
						return el(
							'div',
							{ className: 'coywolf-seo-mobile-alt__actions' },
							el(
								Button,
								{
									variant: 'secondary',
									__next40pxDefaultSize: true,
									className: 'coywolf-seo-mobile-alt__button',
									onClick: renderProps.open
								},
								mobileId
									? __( 'Replace mobile alternative', 'coywolf-seo' )
									: __( 'Add mobile alternative', 'coywolf-seo' )
							),
							mobileId
								? el(
									Button,
									{
										variant: 'tertiary',
										isDestructive: true,
										__next40pxDefaultSize: true,
										onClick: onRemove
									},
									__( 'Remove mobile alternative', 'coywolf-seo' )
								)
								: null
						);
					}
				} )
			),
			el(
				'p',
				{ className: 'coywolf-seo-mobile-alt__desc' },
				__(
					'Add an alternative image for mobile devices (e.g., different dimensions or content) to improve the UX.',
					'coywolf-seo'
				)
			)
		);
	}

	/*
	 * 3. Inject the control into the core/image inspector. Rendering our fill
	 *    after <BlockEdit> mounts it last, so it lands below the core "Media"
	 *    panel (the "Add image" button and Alternative text) in the same
	 *    inspector group.
	 */
	var withMobileAltControl = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( 'core/image' !== props.name ) {
				return el( BlockEdit, props );
			}
			// Only meaningful for media-library images: an attachment id is
			// what makes WordPress emit the srcset we rewrite on the front end.
			if ( ! props.attributes || ! props.attributes.id ) {
				return el( BlockEdit, props );
			}

			var inspectorProps = config.contentGroup ? { group: 'content' } : {};

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el( InspectorControls, inspectorProps, el( MobileAltControl, props ) )
			);
		};
	}, 'withCoywolfSeoMobileAlt' );

	// Priority 9 (ahead of the default 10) keeps the control adjacent to the
	// core "Media" panel — directly below it, and above panels other plugins
	// add to the Image block (e.g. Coywolf Media Manager's "Image text").
	addFilter( 'editor.BlockEdit', 'coywolf-seo/image-mobile-control', withMobileAltControl, 9 );
} )( window.wp );
