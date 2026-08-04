/**
 * Post ↔ Page type switcher — list screens (Bulk Actions + Quick Edit) and the
 * classic editor Publish box.
 *
 * The conversion runs through one nonce-protected AJAX endpoint; this file only
 * gathers the selection, offers an accessible modal to choose a parent/category
 * (or to confirm), and reloads on success. No build step — plain DOM + fetch,
 * jQuery available only as a dependency for inline-edit interop.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.coywolf_seo_type_switch || {};
	var i18n = cfg.i18n || {};
	var speak = ( wp && wp.a11y && wp.a11y.speak ) ? wp.a11y.speak : function () {};
	var lastFocus = null;

	function fmt( str, n ) {
		return String( str || '' ).replace( '%d', n );
	}

	/* ------------------------------------------------------------------ *
	 * Accessible modal (role="dialog", focus-trapped, Escape to close).
	 * Reuses the plugin's .coywolf-seo-modal* styles.
	 * ------------------------------------------------------------------ */
	function openModal( opts ) {
		lastFocus = document.activeElement;

		var overlay = document.createElement( 'div' );
		overlay.className = 'coywolf-seo-modal';

		var box = document.createElement( 'div' );
		box.className = 'coywolf-seo-modal-box';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-labelledby', 'coywolf-seo-switch-title' );
		box.setAttribute( 'aria-describedby', 'coywolf-seo-switch-desc' );

		var h2 = document.createElement( 'h2' );
		h2.id = 'coywolf-seo-switch-title';
		h2.textContent = opts.title;
		box.appendChild( h2 );

		var desc = document.createElement( 'p' );
		desc.id = 'coywolf-seo-switch-desc';
		desc.textContent = opts.note || '';
		box.appendChild( desc );

		if ( opts.chooserHtml ) {
			var body = document.createElement( 'div' );
			body.className = 'coywolf-seo-switch-fields';
			body.innerHTML = opts.chooserHtml;
			box.appendChild( body );
		}

		var err = document.createElement( 'p' );
		err.className = 'coywolf-seo-switch-error';
		err.setAttribute( 'role', 'alert' );
		err.style.display = 'none';
		box.appendChild( err );

		var actions = document.createElement( 'div' );
		actions.className = 'coywolf-seo-modal-actions';

		var confirm = document.createElement( 'button' );
		confirm.type = 'button';
		confirm.className = 'button button-primary';
		confirm.textContent = i18n.convert || 'Convert';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = i18n.cancel || 'Cancel';

		actions.appendChild( confirm );
		actions.appendChild( cancel );
		box.appendChild( actions );
		overlay.appendChild( box );
		document.body.appendChild( overlay );

		function close() {
			document.removeEventListener( 'keydown', onKeydown, true );
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}

		function focusables() {
			return Array.prototype.slice.call(
				box.querySelectorAll( 'a[href], button:not([disabled]), select, input, textarea, [tabindex]:not([tabindex="-1"])' )
			);
		}

		function onKeydown( e ) {
			if ( 'Escape' === e.key || 'Esc' === e.key ) {
				e.preventDefault();
				close();
				return;
			}
			if ( 'Tab' === e.key ) {
				var f = focusables();
				if ( ! f.length ) {
					return;
				}
				var idx = f.indexOf( document.activeElement );
				var next = e.shiftKey ? idx - 1 : idx + 1;
				if ( next < 0 ) {
					next = f.length - 1;
				} else if ( next >= f.length ) {
					next = 0;
				}
				e.preventDefault();
				f[ next ].focus();
			}
		}

		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		cancel.addEventListener( 'click', close );
		confirm.addEventListener( 'click', function () {
			opts.onConfirm( {
				overlay: overlay,
				confirmBtn: confirm,
				value: chooserValue( box ),
				showError: function ( msg ) {
					err.textContent = msg;
					err.style.display = '';
					speak( msg, 'assertive' );
				},
				close: close,
			} );
		} );

		document.addEventListener( 'keydown', onKeydown, true );
		var first = box.querySelector( 'select, input' ) || confirm;
		first.focus();
	}

	function chooserTemplateHtml() {
		var tpl = document.getElementById( 'coywolf-seo-switch-chooser-tpl' );
		return tpl ? tpl.innerHTML : '';
	}

	function chooserValue( box ) {
		var sel = box.querySelector( '#coywolf-seo-switch-parent, #coywolf-seo-switch-category' );
		return sel ? ( parseInt( sel.value, 10 ) || 0 ) : 0;
	}

	function convert( ids, target, extra, context ) {
		var fd = new window.FormData();
		fd.append( 'action', cfg.action );
		fd.append( '_ajax_nonce', cfg.nonce );
		ids.forEach( function ( id ) {
			fd.append( 'ids[]', id );
		} );
		fd.append( 'target', target );
		fd.append( 'parent', ( extra && extra.parent ) || 0 );
		fd.append( 'category', ( extra && extra.category ) || 0 );
		fd.append( 'context', context );
		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
			return r.json();
		} );
	}

	function openChooserModal( ids, context ) {
		var target = cfg.target;
		var title = fmt( 'page' === target ? i18n.confirmToPage : i18n.confirmToPost, ids.length );
		openModal( {
			title: title,
			note: i18n.redirectNote,
			chooserHtml: chooserTemplateHtml(),
			onConfirm: function ( m ) {
				var extra = {};
				if ( 'parent' === cfg.chooser ) {
					extra.parent = m.value;
				} else {
					extra.category = m.value;
				}
				m.confirmBtn.disabled = true;
				m.confirmBtn.textContent = i18n.working || 'Changing…';
				convert( ids, target, extra, context ).then( function ( res ) {
					if ( res && res.success ) {
						speak( res.data.message );
						window.location = res.data.redirectTo;
					} else {
						m.confirmBtn.disabled = false;
						m.confirmBtn.textContent = i18n.convert || 'Convert';
						m.showError( ( res && res.data && res.data.message ) || i18n.failed );
					}
				} ).catch( function () {
					m.confirmBtn.disabled = false;
					m.confirmBtn.textContent = i18n.convert || 'Convert';
					m.showError( i18n.failed );
				} );
			},
		} );
	}

	function openConfirmModal( id, target ) {
		openModal( {
			title: 'page' === target ? i18n.changeToPage : i18n.changeToPost,
			note: i18n.editorNote,
			chooserHtml: null,
			onConfirm: function ( m ) {
				m.confirmBtn.disabled = true;
				m.confirmBtn.textContent = i18n.working || 'Changing…';
				convert( [ id ], target, {}, 'editor' ).then( function ( res ) {
					if ( res && res.success ) {
						window.location = res.data.redirectTo;
					} else {
						m.confirmBtn.disabled = false;
						m.confirmBtn.textContent = i18n.convert || 'Convert';
						m.showError( ( res && res.data && res.data.message ) || i18n.failed );
					}
				} ).catch( function () {
					m.confirmBtn.disabled = false;
					m.confirmBtn.textContent = i18n.convert || 'Convert';
					m.showError( i18n.failed );
				} );
			},
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Wiring.
	 * ------------------------------------------------------------------ */
	function ready() {
		// Bulk Actions: intercept the list form submit when our action is chosen.
		var form = document.getElementById( 'posts-filter' );
		if ( form && 'list' === cfg.context ) {
			form.addEventListener( 'submit', function ( e ) {
				var a1 = form.querySelector( 'select[name="action"]' );
				var a2 = form.querySelector( 'select[name="action2"]' );
				var chosen = ( a1 && a1.value === cfg.bulkAction ) || ( a2 && a2.value === cfg.bulkAction );
				if ( ! chosen ) {
					return;
				}
				e.preventDefault();
				var ids = Array.prototype.map.call(
					form.querySelectorAll( 'input[name="post[]"]:checked' ),
					function ( cb ) {
						return parseInt( cb.value, 10 );
					}
				).filter( Boolean );
				if ( ! ids.length ) {
					speak( i18n.noneSelected, 'assertive' );
					window.alert( i18n.noneSelected );
					return;
				}
				openChooserModal( ids, 'list' );
			} );
		}

		// Quick Edit: move our control beneath the Password field and tag it
		// with the row's post id, by wrapping core's inline editor.
		if ( 'list' === cfg.context && window.inlineEditPost && 'function' === typeof window.inlineEditPost.edit ) {
			var original = window.inlineEditPost.edit;
			window.inlineEditPost.edit = function ( id ) {
				var result = original.apply( this, arguments );
				var postId = 0;
				if ( 'object' === typeof id && this.getId ) {
					postId = parseInt( this.getId( id ), 10 );
				} else {
					postId = parseInt( id, 10 );
				}
				var row = document.getElementById( 'edit-' + postId );
				if ( row ) {
					var ctl = row.querySelector( '.coywolf-seo-switch-qe' );
					if ( ctl ) {
						ctl.setAttribute( 'data-id', postId );
						ctl.style.display = '';
						var pw = row.querySelector( '.inline-edit-password-input' );
						if ( pw ) {
							var group = pw.closest( '.inline-edit-group' ) || pw.parentNode;
							if ( group && group.parentNode ) {
								group.parentNode.insertBefore( ctl, group.nextSibling );
							}
						}
					}
				}
				return result;
			};
		}

		// Trigger delegation for Quick Edit + classic-editor buttons.
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target && e.target.closest ? e.target.closest( '.coywolf-seo-switch-trigger' ) : null;
			if ( ! btn ) {
				return;
			}
			var ctx = btn.getAttribute( 'data-context' );
			if ( 'quick' === ctx ) {
				var wrap = btn.closest( '.coywolf-seo-switch-qe' );
				var qid = parseInt( wrap && wrap.getAttribute( 'data-id' ), 10 );
				if ( qid ) {
					openChooserModal( [ qid ], 'list' );
				}
			} else if ( 'classic' === ctx ) {
				var box = btn.closest( '.coywolf-seo-switch-submitbox' );
				var cid = parseInt( box && box.getAttribute( 'data-post' ), 10 );
				var target = box && box.getAttribute( 'data-target' );
				if ( cid && target ) {
					openConfirmModal( cid, target );
				}
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )( window.wp );
