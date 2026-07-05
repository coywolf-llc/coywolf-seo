/**
 * Coywolf SEO — the SEO panel in the block editor's document sidebar.
 *
 * Replaces the classic meta box (which WordPress 7.0 hides behind a
 * collapsed "Meta Boxes" bar) with a PluginDocumentSettingPanel bound to
 * the `_coywolf_seo` post meta registered in Coywolf_SEO_Metabox.
 *
 * No build step: plain wp.element.createElement against the wp globals.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	// WP 6.6+ exports the panel from wp.editor; 6.0–6.5 only from wp.editPost.
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! registerPlugin || ! PluginDocumentSettingPanel ) {
		return;
	}

	var config = window.coywolf_seo_editor || { pageTypeOptions: [], articleTypeOptions: [], i18n: {} };
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button = wp.components.Button;

	var META_KEY = '_coywolf_seo';
	var DEFAULTS = {
		title: '',
		description: '',
		page_type: '',
		article_type: '',
		noindex: false,
		nofollow: false,
		canonical: ''
	};

	function CoywolfSeoPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' );
		}, [] );
		// Live sources for the default Title and Description. They re-render as
		// the author edits the post title or excerpt, so an un-overridden field
		// tracks its source in real time.
		var postTitle = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' );
		}, [] ) || '';
		var postExcerpt = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'excerpt' );
		}, [] ) || '';
		var editPost = useDispatch( 'core/editor' ).editPost;
		var statusState = useState( config.entityStatus || '' );
		var entityStatus = statusState[ 0 ];
		var setEntityStatus = statusState[ 1 ];
		// While a Title/Description field has focus, show the raw in-progress
		// string instead of the computed override-or-default. Without this,
		// deleting the last character would re-fill the controlled input with
		// the default mid-typing, so clearing-then-retyping would silently
		// store a corrupted override.
		var titleDraft = useState( null );
		var descDraft = useState( null );

		function reanalyze() {
			var data = new window.FormData();
			data.append( 'action', 'coywolf_seo_reanalyze' );
			data.append( '_ajax_nonce', config.reanalyzeNonce );
			data.append( 'post_id', config.postId );
			setEntityStatus( config.i18n.analyzing || 'Analyzing…' );
			window
				.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					setEntityStatus( ( json.data && json.data.message ) || '' );
				} )
				.catch( function () {
					setEntityStatus( config.i18n.requestFailed || 'Request failed.' );
				} );
		}

		var raw = meta ? meta[ META_KEY ] : null;
		var seo = Object.assign( {}, DEFAULTS, raw && ! Array.isArray( raw ) ? raw : {} );

		function update( field, value ) {
			// core-data merges meta per key only: always write the full object.
			var next = Object.assign( {}, seo );
			next[ field ] = value;
			var edit = {};
			edit[ META_KEY ] = next;
			editPost( { meta: edit } );
		}

		// The default Title is the post's own title; the default Description
		// mirrors the front end — the AI-written summary when one exists, else
		// the manual excerpt. A field only stores a value once it differs from
		// its default (matching how the canonical field treats the permalink).
		var defaultTitle = postTitle;
		var defaultDescription = ( config.aiDescription && '' !== config.aiDescription )
			? config.aiDescription
			: postExcerpt;

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'coywolf-seo-panel',
				title: config.i18n.panelTitle || 'SEO',
				className: 'coywolf-seo-panel'
			},
			// The front/posts pages take their title and description from Site
			// Details, so per-post overrides would be ignored — hide both
			// fields there (config.specialPage).
			config.specialPage
				? null
				: el( TextControl, {
					label: config.i18n.title || 'Title',
					value: null !== titleDraft[ 0 ] ? titleDraft[ 0 ] : ( seo.title || defaultTitle ),
					onFocus: function () {
						titleDraft[ 1 ]( seo.title || defaultTitle );
					},
					onChange: function ( v ) {
						titleDraft[ 1 ]( v );
						update( 'title', v === defaultTitle ? '' : v );
					},
					onBlur: function () {
						titleDraft[ 1 ]( null );
					},
					help: config.i18n.titleHelp || ''
				} ),
			// "Exclude meta description" hides the Description field; a stored
			// override is left untouched (update() always writes the full
			// object, so the hidden value round-trips unchanged).
			( config.specialPage || config.excludeDescription )
				? null
				: el( TextareaControl, {
					label: config.i18n.description || 'Description',
					value: null !== descDraft[ 0 ] ? descDraft[ 0 ] : ( seo.description || defaultDescription ),
					rows: 3,
					onFocus: function () {
						descDraft[ 1 ]( seo.description || defaultDescription );
					},
					onChange: function ( v ) {
						descDraft[ 1 ]( v );
						update( 'description', v === defaultDescription ? '' : v );
					},
					onBlur: function () {
						descDraft[ 1 ]( null );
					},
					help: config.i18n.descriptionHelp || ''
				} ),
			el( SelectControl, {
				label: config.i18n.pageType || 'Schema page type',
				value: seo.page_type,
				options: config.pageTypeOptions,
				onChange: function ( v ) {
					update( 'page_type', v );
				}
			} ),
			el( SelectControl, {
				label: config.i18n.articleType || 'Schema article type',
				value: seo.article_type,
				options: config.articleTypeOptions,
				onChange: function ( v ) {
					update( 'article_type', v );
				}
			} ),
			el( 'p', { className: 'coywolf-seo-robots-label' }, config.i18n.robots || 'Robots' ),
			el( ToggleControl, {
				label: config.i18n.noindex || 'Noindex',
				checked: !! seo.noindex,
				onChange: function ( v ) {
					update( 'noindex', !! v );
				}
			} ),
			el( ToggleControl, {
				label: config.i18n.nofollow || 'Nofollow',
				checked: !! seo.nofollow,
				onChange: function ( v ) {
					update( 'nofollow', !! v );
				}
			} ),
			el( TextControl, {
				label: config.i18n.canonical || 'Canonical link',
				type: 'url',
				// Show the URL actually in use; only a changed value is
				// stored as an override.
				value: seo.canonical || config.permalink || '',
				onChange: function ( v ) {
					update( 'canonical', v === config.permalink ? '' : v );
				}
			} ),
			el(
				'p',
				{
					className: 'coywolf-seo-entity-status',
					role: 'status',
					'aria-live': 'polite',
					style: { color: '#50575e', marginBottom: 0 }
				},
				entityStatus
			),
			config.aiEnabled && config.postId
				? el(
					Button,
					{ variant: 'secondary', isSecondary: true, onClick: reanalyze, style: { marginTop: '8px' } },
					config.i18n.reanalyze || 'Re-analyze entities'
				)
				: null
		);
	}

	registerPlugin( 'coywolf-seo', { render: CoywolfSeoPanel } );
} )( window.wp );
