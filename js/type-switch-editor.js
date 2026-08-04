/**
 * Post ↔ Page type switcher — block editor.
 *
 * Adds a "Type: … / Change to …" control to the Status & Visibility panel
 * (PluginPostStatusInfo). Switching a post's type reloads the editor as the new
 * type (parent/category are then set via the native Page Attributes / Categories
 * panels); a 301 redirect is created automatically if the URL changes, and the
 * whole thing can be undone from the notice that appears after the reload.
 *
 * No build step — authored against the wp.* globals the editor provides.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.coywolf_seo_type_switch_editor || {};
	if ( ! cfg.canSwitch ) {
		return;
	}

	var el = wp.element && wp.element.createElement;
	var useState = wp.element && wp.element.useState;
	var useSelect = wp.data && wp.data.useSelect;
	var registerPlugin = wp.plugins && wp.plugins.registerPlugin;
	// WP 6.6+ exports the slot from wp.editor; older from wp.editPost.
	var PluginPostStatusInfo =
		( wp.editor && wp.editor.PluginPostStatusInfo ) ||
		( wp.editPost && wp.editPost.PluginPostStatusInfo );
	var Button = wp.components && wp.components.Button;
	var Modal = wp.components && wp.components.Modal;

	if ( ! el || ! registerPlugin || ! PluginPostStatusInfo || ! Button || ! Modal ) {
		return;
	}

	var i18n = cfg.i18n || {};
	var changeLabel = ( 'page' === cfg.target ) ? i18n.changeToPage : i18n.changeToPost;

	function Control() {
		var openState = useState( false );
		var busyState = useState( false );
		var isDirty = useSelect( function ( select ) {
			return select( 'core/editor' ).isEditedPostDirty();
		}, [] );

		function doSwitch() {
			busyState[ 1 ]( true );
			var fd = new window.FormData();
			fd.append( 'action', cfg.action );
			fd.append( '_ajax_nonce', cfg.nonce );
			fd.append( 'ids[]', cfg.postId );
			fd.append( 'target', cfg.target );
			fd.append( 'context', 'editor' );
			window
				.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( res && res.success ) {
						window.location.href = res.data.redirectTo;
					} else {
						busyState[ 1 ]( false );
						openState[ 1 ]( false );
						window.alert( ( res && res.data && res.data.message ) || i18n.failed );
					}
				} )
				.catch( function () {
					busyState[ 1 ]( false );
					openState[ 1 ]( false );
					window.alert( i18n.failed );
				} );
		}

		var children = [
			el(
				'div',
				{ className: 'coywolf-seo-switch-editor', key: 'row' },
				el(
					'span',
					{ className: 'coywolf-seo-switch-editor-current' },
					( i18n.typeLabel || 'Type:' ) + ' '
				),
				el( 'strong', {}, cfg.currentLabel ),
				el(
					Button,
					{
						variant: 'link',
						className: 'coywolf-seo-switch-editor-btn',
						onClick: function () {
							if ( isDirty ) {
								window.alert( i18n.saveFirst );
								return;
							}
							openState[ 1 ]( true );
						},
					},
					changeLabel
				)
			),
		];

		if ( openState[ 0 ] ) {
			children.push(
				el(
					Modal,
					{
						key: 'modal',
						title: changeLabel,
						onRequestClose: function () {
							if ( ! busyState[ 0 ] ) {
								openState[ 1 ]( false );
							}
						},
					},
					el( 'p', {}, i18n.editorNote ),
					el(
						'div',
						{ className: 'coywolf-seo-switch-editor-actions' },
						el(
							Button,
							{ variant: 'primary', isBusy: busyState[ 0 ], disabled: busyState[ 0 ], onClick: doSwitch },
							i18n.convert
						),
						el(
							Button,
							{ variant: 'tertiary', disabled: busyState[ 0 ], onClick: function () {
								openState[ 1 ]( false );
							} },
							i18n.cancel
						)
					)
				)
			);
		}

		return el( PluginPostStatusInfo, {}, children );
	}

	registerPlugin( 'coywolf-seo-type-switch', { render: Control } );
} )( window.wp );
