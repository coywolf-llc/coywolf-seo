/**
 * Coywolf SEO — Image Text block editor integration.
 *
 * Extends the core Image block's settings sidebar with the full set of
 * image text fields. Core only exposes Alternative Text there; this adds
 * Title (text), Caption (textarea), and Description (textarea), plus a
 * "Generate with Claude" button when an API key is configured.
 *
 * On WP 7.0+ the panel renders into the inspector's "content" group, so it
 * appears on the Content tab next to core's own Media panel (cfg.contentTab,
 * set server-side from the WP version). Older editors don't have that group
 * and would drop the panel, so they keep the default group (Settings tab).
 *
 * Alt and caption live on the block; title and description live on the
 * attachment (saved through the core media REST endpoint). The generate
 * button saves all four to the Media Library and fills the block fields.
 *
 * Built with wp.element directly — no build step.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.blockEditor ) {
		return;
	}

	var cfg = window.coywolfSEOImageTextEditor || {};
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;
	var useSelect = wp.data.useSelect;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;

	function ImageTextPanel( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var id = attributes.id;

		var media = useSelect(
			function ( select ) {
				return id ? select( 'core' ).getMedia( id ) : null;
			},
			[ id ]
		);

		var titleState = useState( null );
		var title = titleState[ 0 ];
		var setTitle = titleState[ 1 ];
		var descState = useState( null );
		var description = descState[ 0 ];
		var setDescription = descState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var noticeState = useState( '' );
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];

		// While this panel is mounted (an image block is selected), hide core's
		// redundant "Alternative text" control — this panel edits the same block
		// alt. Done in JS rather than CSS so it works regardless of the browser's
		// :has() support or Gutenberg's exact class names: core's alt control is
		// the one whose help links to the WCAG image tutorial (a stable anchor);
		// we hide that control's container and re-apply across the inspector's
		// React re-renders. On unmount the controls are restored, so other media
		// blocks (Cover, Media & Text…) keep their own alt field.
		useEffect( function () {
			var hidden = [];
			var apply = function () {
				var links = document.querySelectorAll( 'a[href*="w3.org/WAI"]' );
				for ( var i = 0; i < links.length; i++ ) {
					var item = links[ i ].closest( '.components-tools-panel-item' ) ||
						links[ i ].closest( '.components-base-control' );
					if ( item && 'none' !== item.style.display ) {
						item.style.display = 'none';
						if ( -1 === hidden.indexOf( item ) ) {
							hidden.push( item );
						}
					}
				}
			};
			var scheduled = false;
			var schedule = function () {
				if ( scheduled ) {
					return;
				}
				scheduled = true;
				window.requestAnimationFrame( function () {
					scheduled = false;
					apply();
				} );
			};
			apply();
			var observer = null;
			if ( window.MutationObserver && document.body ) {
				observer = new window.MutationObserver( schedule );
				observer.observe( document.body, { childList: true, subtree: true } );
			}
			return function () {
				if ( observer ) {
					observer.disconnect();
				}
				for ( var j = 0; j < hidden.length; j++ ) {
					if ( hidden[ j ] ) {
						hidden[ j ].style.display = '';
					}
				}
			};
		}, [] );

		var titleValue = null !== title ? title : ( media && media.title ? media.title.raw : '' );
		var descValue = null !== description ? description : ( media && media.description ? media.description.raw : '' );

		function generate() {
			setBusy( true );
			setNotice( '' );
			// Pass the post being edited so the AI gets this article's context
			// (and the image's position within it) for better alt/caption text.
			var postId = 0;
			try {
				postId = wp.data.select( 'core/editor' ).getCurrentPostId() || 0;
			} catch ( e ) {
				postId = 0;
			}
			apiFetch( {
				path: '/coywolf-seo/v1/image-text/generate',
				method: 'POST',
				data: { id: id, save: true, post_id: postId },
			} )
				.then( function ( res ) {
					setAttributes( { alt: res.alt_text, caption: res.caption } );
					setTitle( res.title );
					setDescription( res.description );
					setNotice( __( 'Generated and saved to the Media Library.', 'coywolf-seo' ) );
				} )
				.catch( function ( err ) {
					setNotice( err && err.message ? err.message : __( 'The request failed.', 'coywolf-seo' ) );
				} )
				.then( function () {
					setBusy( false );
				} );
		}

		function saveToLibrary() {
			setBusy( true );
			setNotice( '' );
			apiFetch( {
				path: '/wp/v2/media/' + id,
				method: 'POST',
				data: { title: titleValue, description: descValue },
			} )
				.then( function () {
					setNotice( __( 'Saved to the Media Library.', 'coywolf-seo' ) );
				} )
				.catch( function ( err ) {
					setNotice( err && err.message ? err.message : __( 'The request failed.', 'coywolf-seo' ) );
				} )
				.then( function () {
					setBusy( false );
				} );
		}

		return el(
			PanelBody,
			{ title: __( 'Image text', 'coywolf-seo' ), initialOpen: false },
			el( TextareaControl, {
				label: __( 'Alternative text', 'coywolf-seo' ),
				value: attributes.alt ? String( attributes.alt ) : '',
				rows: 2,
				__nextHasNoMarginBottom: true,
				onChange: function ( value ) {
					setAttributes( { alt: value } );
				},
			} ),
			el( TextControl, {
				label: __( 'Title', 'coywolf-seo' ),
				value: titleValue,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				onChange: setTitle,
				help: '',
			} ),
			el( TextareaControl, {
				label: __( 'Caption', 'coywolf-seo' ),
				value: attributes.caption ? String( attributes.caption ) : '',
				rows: 2,
				__nextHasNoMarginBottom: true,
				onChange: function ( value ) {
					setAttributes( { caption: value } );
				},
			} ),
			el( TextareaControl, {
				label: __( 'Description', 'coywolf-seo' ),
				value: descValue,
				rows: 3,
				__nextHasNoMarginBottom: true,
				onChange: setDescription,
				help: __( 'Title and Description are stored on the image in the Media Library.', 'coywolf-seo' ),
			} ),
			el(
				'div',
				{ className: 'coywolf-seo-it-editor-buttons' },
				cfg.configured
					? el(
						Button,
						{
							variant: 'primary',
							isBusy: busy,
							disabled: busy,
							onClick: generate,
						},
						cfg.genLabel || __( 'Generate with Claude', 'coywolf-seo' )
					)
					: null,
				el(
					Button,
					{
						variant: 'secondary',
						disabled: busy,
						onClick: saveToLibrary,
					},
					__( 'Save to Media Library', 'coywolf-seo' )
				)
			),
			notice ? el( 'p', { className: 'coywolf-seo-it-editor-notice' }, notice ) : null
		);
	}

	var withImageText = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( 'core/image' !== props.name || ! props.attributes.id ) {
				return el( BlockEdit, props );
			}
			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					cfg.contentTab ? { group: 'content' } : null,
					el( ImageTextPanel, props )
				)
			);
		};
	}, 'withCoywolfSEOImageText' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'coywolf-seo/image-text', withImageText );
} )( window.wp );
