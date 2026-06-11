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

	var config = window.CoywolfSEOEditor || { pageTypeOptions: [], articleTypeOptions: [], i18n: {} };
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var useState = wp.element.useState;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;

	var META_KEY = '_coywolf_seo';
	var DEFAULTS = {
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
		var editPost = useDispatch( 'core/editor' ).editPost;
		var statusState = useState( config.entityStatus || '' );
		var entityStatus = statusState[ 0 ];
		var setEntityStatus = statusState[ 1 ];

		function reanalyze() {
			var data = new window.FormData();
			data.append( 'action', 'coywolf_seo_reanalyze' );
			data.append( '_ajax_nonce', config.reanalyzeNonce );
			data.append( 'post_id', config.postId );
			setEntityStatus( '…' );
			window
				.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					setEntityStatus( ( json.data && json.data.message ) || '' );
				} )
				.catch( function () {
					setEntityStatus( 'Request failed.' );
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

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'coywolf-seo-panel',
				title: config.i18n.panelTitle || 'SEO',
				className: 'coywolf-seo-panel'
			},
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
			entityStatus
				? el( 'p', { className: 'coywolf-seo-entity-status', style: { color: '#50575e', marginBottom: 0 } }, entityStatus )
				: null,
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
