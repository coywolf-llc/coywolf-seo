/**
 * Coywolf SEO — Table of Contents block (editor side).
 *
 * The block is dynamic: nothing is saved into the post content, the server
 * builds the table from the final HTML at view time. Here we mirror that
 * with a live preview built from the heading blocks in the editor, which
 * updates as headings are typed, reordered, or deleted.
 *
 * Also adds an "Exclude from table of contents" toggle to every core
 * heading block, and a transform so a Yoast SEO table-of-contents block
 * converts to this one in one click.
 *
 * No build step: plain wp.element.createElement against the wp globals.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.blockEditor ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var useSelect = wp.data.useSelect;

	var blockEditor = wp.blockEditor;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var RichText = blockEditor.RichText;

	var components = wp.components;
	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var Placeholder = components.Placeholder;

	var BLOCK = 'coywolf-seo/table-of-contents';
	var EXCLUDE_CLASS = 'coywolf-seo-toc-exclude';

	var tocIcon = el(
		'svg',
		{ viewBox: '0 0 24 24', width: 24, height: 24, xmlns: 'http://www.w3.org/2000/svg' },
		el( 'path', {
			fill: 'currentColor',
			d: 'M4 5.5h2v2H4v-2Zm4.5 0H20v2H8.5v-2ZM7 11h2v2H7v-2Zm4.5 0H20v2h-11.5v-2ZM7 16.5h2v2H7v-2Zm4.5 0H20v2h-11.5v-2Z'
		} )
	);

	/* ------------------------------------------------------------------ *
	 * Heading collection (mirrors the server's scan, against the editor's
	 * block list)
	 * ------------------------------------------------------------------ */

	function headingText( content ) {
		var value = content || '';
		// WP 6.5+ may hand RichTextData; older versions a plain HTML string.
		if ( typeof value !== 'string' ) {
			value = value.toString();
		}
		var div = document.createElement( 'div' );
		div.innerHTML = value;
		return ( div.textContent || '' ).trim();
	}

	function isExcluded( className ) {
		return ( ' ' + ( className || '' ) + ' ' ).indexOf( ' ' + EXCLUDE_CLASS + ' ' ) !== -1;
	}

	// Display-only approximation of the server's slug (jump- + sanitize_title).
	function previewSlug( text ) {
		var base = text
			.toLowerCase()
			.replace( /[^\w\s-]+/g, '' )
			.replace( /[\s_-]+/g, '-' )
			.replace( /^-+|-+$/g, '' ) || 'section';
		return 'jump-' + base;
	}

	function collectHeadings( blocks, out ) {
		blocks.forEach( function ( block ) {
			if ( 'core/heading' === block.name ) {
				var text = headingText( block.attributes.content );
				if ( text && ! isExcluded( block.attributes.className ) ) {
					out.push( {
						level: block.attributes.level || 2,
						text: text,
						id: block.attributes.anchor || previewSlug( text )
					} );
				}
			}
			if ( block.innerBlocks && block.innerBlocks.length ) {
				collectHeadings( block.innerBlocks, out );
			}
		} );
		return out;
	}

	/* ------------------------------------------------------------------ *
	 * Preview list (same nesting rules as the server)
	 * ------------------------------------------------------------------ */

	function buildList( items, listTag ) {
		var root = { children: [] };
		var stack = [ { node: root, level: items[ 0 ].level } ];

		items.forEach( function ( item ) {
			var top = stack[ stack.length - 1 ];
			if ( item.level > top.level ) {
				var parentItems = top.node.children;
				var host = parentItems[ parentItems.length - 1 ];
				stack.push( { node: host, level: item.level } );
			} else {
				while ( stack.length > 1 && item.level < stack[ stack.length - 1 ].level ) {
					stack.pop();
				}
			}
			stack[ stack.length - 1 ].node.children.push( { item: item, children: [] } );
		} );

		function render( node, key ) {
			return el(
				listTag,
				{ key: key },
				node.children.map( function ( child, i ) {
					return el(
						'li',
						{ key: i },
						el( 'a', {
							href: '#' + child.item.id,
							onClick: function ( event ) {
								event.preventDefault();
							}
						}, child.item.text ),
						child.children.length ? render( child, 'sub-' + i ) : null
					);
				} )
			);
		}

		return render( root, 'root' );
	}

	/* ------------------------------------------------------------------ *
	 * Edit component
	 * ------------------------------------------------------------------ */

	function levelLabel( level ) {
		/* translators: %d: heading level number. */
		return sprintf( __( 'Include H%d headings', 'coywolf-seo' ), level );
	}

	function TocEdit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;

		var headings = useSelect( function ( select ) {
			return collectHeadings( select( 'core/block-editor' ).getBlocks(), [] );
		}, [] );

		var levels = attributes.levels && attributes.levels.length ? attributes.levels : [ 2, 3 ];
		var items = headings.filter( function ( heading ) {
			return levels.indexOf( heading.level ) !== -1;
		} );

		var minHeadings = Math.max( 1, attributes.minHeadings || 0 );
		var hiddenOnSite = items.length < minHeadings;

		function toggleLevel( level ) {
			var next = levels.slice();
			var at = next.indexOf( level );
			if ( at === -1 ) {
				next.push( level );
				next.sort();
			} else if ( next.length > 1 ) {
				next.splice( at, 1 );
			}
			setAttributes( { levels: next } );
		}

		var inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Title', 'coywolf-seo' ), initialOpen: true },
				el( ToggleControl, {
					label: __( 'Show title', 'coywolf-seo' ),
					checked: !! attributes.showTitle,
					onChange: function ( value ) {
						setAttributes( { showTitle: !! value } );
					}
				} ),
				attributes.showTitle ? el( TextControl, {
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
				label: __( 'Title text', 'coywolf-seo' ),
				placeholder: __( 'Table of contents', 'coywolf-seo' ),
				help: __( 'Leave empty to use “Table of contents”.', 'coywolf-seo' ),
				value: attributes.title || '',
				onChange: function ( value ) {
					setAttributes( { title: value } );
				}
			} ) : null,
			attributes.showTitle ? el( SelectControl, {
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					label: __( 'Title heading level', 'coywolf-seo' ),
					value: String( attributes.titleLevel || 2 ),
					options: [ 2, 3, 4, 5, 6 ].map( function ( level ) {
						return { label: 'H' + level, value: String( level ) };
					} ),
					onChange: function ( value ) {
						setAttributes( { titleLevel: parseInt( value, 10 ) || 2 } );
					}
				} ) : null
			),
			el(
				PanelBody,
				{ title: __( 'Headings', 'coywolf-seo' ), initialOpen: true },
				[ 2, 3, 4, 5, 6 ].map( function ( level ) {
					return el( ToggleControl, {
						key: level,
						label: levelLabel( level ),
						checked: levels.indexOf( level ) !== -1,
						disabled: levels.length === 1 && levels[ 0 ] === level,
						onChange: function () {
							toggleLevel( level );
						}
					} );
				} ),
				el( RangeControl, {
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					label: __( 'Hide when fewer headings than', 'coywolf-seo' ),
					help: __( 'The table is left out of the page when it would list fewer headings than this.', 'coywolf-seo' ),
					min: 1,
					max: 10,
					value: minHeadings,
					onChange: function ( value ) {
						setAttributes( { minHeadings: value } );
					}
				} ),
				el( 'p', { className: 'coywolf-seo-toc-inspector-note' },
					__( 'Each heading block also has its own “Exclude from table of contents” toggle. Headings added by shortcodes appear on the published page.', 'coywolf-seo' ) )
			),
			el(
				PanelBody,
				{ title: __( 'Behavior', 'coywolf-seo' ), initialOpen: false },
				el( ToggleControl, {
					label: __( 'Collapsible', 'coywolf-seo' ),
					help: __( 'Readers can fold the table behind its title. Works without JavaScript.', 'coywolf-seo' ),
					checked: !! attributes.collapsible,
					onChange: function ( value ) {
						setAttributes( { collapsible: !! value } );
					}
				} ),
				attributes.collapsible ? el( ToggleControl, {
					label: __( 'Collapsed by default', 'coywolf-seo' ),
					checked: !! attributes.initiallyCollapsed,
					onChange: function ( value ) {
						setAttributes( { initiallyCollapsed: !! value } );
					}
				} ) : null,
				el( ToggleControl, {
					label: __( 'Smooth scrolling', 'coywolf-seo' ),
					help: __( 'Glide to the heading instead of jumping. Visitors who prefer reduced motion always get the jump.', 'coywolf-seo' ),
					checked: !! attributes.smoothScroll,
					onChange: function ( value ) {
						setAttributes( { smoothScroll: !! value } );
					}
				} ),
				el( ToggleControl, {
					label: __( 'Highlight current section', 'coywolf-seo' ),
					help: __( 'Marks the entry for the section being read while scrolling.', 'coywolf-seo' ),
					checked: !! attributes.highlightCurrent,
					onChange: function ( value ) {
						setAttributes( { highlightCurrent: !! value } );
					}
				} ),
				el( RangeControl, {
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					label: __( 'Scroll offset (px)', 'coywolf-seo' ),
					help: __( 'Space kept above a heading when jumped to — set this when a sticky header would cover it.', 'coywolf-seo' ),
					min: 0,
					max: 300,
					value: attributes.scrollOffset || 0,
					onChange: function ( value ) {
						setAttributes( { scrollOffset: value } );
					}
				} )
			),
			el(
				PanelBody,
				{ title: __( 'List style', 'coywolf-seo' ), initialOpen: false },
				el( SelectControl, {
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
					label: __( 'Marker', 'coywolf-seo' ),
					value: attributes.listStyle || 'none',
					options: [
						{ label: __( 'None', 'coywolf-seo' ), value: 'none' },
						{ label: __( 'Bullets', 'coywolf-seo' ), value: 'disc' },
						{ label: __( 'Numbers', 'coywolf-seo' ), value: 'decimal' }
					],
					onChange: function ( value ) {
						setAttributes( { listStyle: value } );
					}
				} )
			)
		);

		var blockProps = useBlockProps( {
			className: 'coywolf-seo-toc coywolf-seo-toc-list-' + ( attributes.listStyle || 'none' )
		} );

		var title = attributes.showTitle ? el( RichText, {
			tagName: 'h' + ( attributes.titleLevel || 2 ),
			className: 'coywolf-seo-toc-title',
			value: attributes.title,
			allowedFormats: [],
			withoutInteractiveFormatting: true,
			placeholder: __( 'Table of contents', 'coywolf-seo' ),
			onChange: function ( value ) {
				setAttributes( { title: value } );
			}
		} ) : null;

		var body;
		if ( ! items.length ) {
			body = el(
				Placeholder,
				{
					icon: tocIcon,
					label: __( 'Table of contents', 'coywolf-seo' ),
					instructions: __( 'Add headings to the post and they will be listed here automatically.', 'coywolf-seo' )
				}
			);
		} else {
			body = el(
				Fragment,
				null,
				title,
				buildList( items, 'decimal' === attributes.listStyle ? 'ol' : 'ul' ),
				hiddenOnSite ? el( 'p', { className: 'coywolf-seo-toc-hidden-note' },
					sprintf(
						/* translators: %d: the configured minimum number of headings. */
						__( 'Fewer than %d headings — the table will be left out of the published page.', 'coywolf-seo' ),
						minHeadings
					) ) : null
			);
		}

		return el( 'nav', blockProps, inspector, body );
	}

	registerBlockType( BLOCK, {
		icon: tocIcon,
		edit: TocEdit,
		save: function () {
			return null;
		},
		transforms: {
			from: [
				{
					type: 'block',
					blocks: [ 'yoast-seo/table-of-contents' ],
					transform: function ( attributes ) {
						// Carry the Yoast block's settings across so the converted
						// table matches: its title text + heading level, and the
						// "maximum heading level" as Coywolf's H2…max level set.
						var attrs = attributes || {};
						var mapped = {};

						var titleLevel = parseInt( attrs.level, 10 );
						mapped.titleLevel = ( titleLevel >= 2 && titleLevel <= 6 ) ? titleLevel : 2;

						var max = parseInt( attrs.maxHeadingLevel, 10 );
						if ( ! ( max >= 2 && max <= 6 ) ) {
							max = 3;
						}
						var levels = [];
						for ( var level = 2; level <= max; level++ ) {
							levels.push( level );
						}
						mapped.levels = levels;

						// Keep a genuinely custom title; leave a default one to the
						// Coywolf block's own default.
						var title = ( typeof attrs.title === 'string' ) ? attrs.title.trim() : '';
						if ( title && title.toLowerCase() !== 'table of contents' ) {
							mapped.title = title;
						}

						return createBlock( BLOCK, mapped );
					}
				}
			]
		}
	} );

	/* ------------------------------------------------------------------ *
	 * "Exclude from table of contents" toggle on core/heading
	 * ------------------------------------------------------------------ */

	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var addFilter = wp.hooks.addFilter;

	var withTocExcludeToggle = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( 'core/heading' !== props.name ) {
				return el( BlockEdit, props );
			}

			var className = props.attributes.className || '';
			var excluded = isExcluded( className );

			function toggle( value ) {
				var classes = className.split( /\s+/ ).filter( function ( name ) {
					return name && name !== EXCLUDE_CLASS;
				} );
				if ( value ) {
					classes.push( EXCLUDE_CLASS );
				}
				props.setAttributes( { className: classes.join( ' ' ) || undefined } );
			}

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Table of contents', 'coywolf-seo' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Exclude from table of contents', 'coywolf-seo' ),
							checked: excluded,
							onChange: toggle
						} )
					)
				)
			);
		};
	}, 'withCoywolfSeoTocExcludeToggle' );

	addFilter( 'editor.BlockEdit', 'coywolf-seo/toc-exclude-toggle', withTocExcludeToggle );
} )( window.wp );
