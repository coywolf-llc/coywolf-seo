/**
 * Editor UI for the coywolf-seo/breadcrumbs block.
 *
 * The trail depends on where the page sits in the site (its category or parent
 * chain), which only resolves on the front end, so the editor shows a labeled
 * placeholder. The real, accessible trail — and its BreadcrumbList schema — is
 * rendered on the server from the plugin's Site Details settings.
 *
 * No build step: authored against the wp.* globals the editor provides.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;

	blocks.registerBlockType( 'coywolf-seo/breadcrumbs', {
		edit: function () {
			return el(
				'div',
				useBlockProps(),
				el(
					Placeholder,
					{
						icon: 'menu-alt',
						label: __( 'Breadcrumbs', 'coywolf-seo' ),
						instructions: __(
							'The breadcrumb trail builds from this page’s category or parent structure and appears here when the page is viewed on the site. Configure it under Coywolf SEO → Site Details.',
							'coywolf-seo'
						),
					},
					el(
						'nav',
						{ className: 'coywolf-seo-breadcrumbs coywolf-seo-breadcrumbs--sep-slash', 'aria-hidden': 'true' },
						el(
							'ol',
							{ className: 'coywolf-seo-breadcrumbs__list' },
							el(
								'li',
								{ className: 'coywolf-seo-breadcrumbs__item coywolf-seo-breadcrumbs__item--home' },
								el( 'span', { className: 'coywolf-seo-breadcrumbs__link' }, __( 'Home', 'coywolf-seo' ) )
							),
							el(
								'li',
								{ className: 'coywolf-seo-breadcrumbs__item' },
								el( 'span', { className: 'coywolf-seo-breadcrumbs__link' }, __( 'Category', 'coywolf-seo' ) )
							),
							el(
								'li',
								{ className: 'coywolf-seo-breadcrumbs__item coywolf-seo-breadcrumbs__item--current' },
								el(
									'span',
									{ className: 'coywolf-seo-breadcrumbs__current' },
									__( 'This page', 'coywolf-seo' )
								)
							)
						)
					)
				)
			);
		},
		// Dynamic block: markup is produced by the PHP render callback.
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
