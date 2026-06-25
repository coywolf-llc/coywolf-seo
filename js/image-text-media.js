/**
 * Coywolf SEO — Image Text Media Library button.
 *
 * Wires the "Generate image text with Claude" button the plugin adds to the
 * attachment details pane (media modal, grid details, and the Edit Media
 * screen). The button is rendered server-side via attachment_fields_to_edit;
 * clicks are handled here by delegation so they work inside Backbone-rendered
 * modals.
 */
( function () {
	'use strict';

	var cfg = window.coywolf_seo_image_text_media || {};
	if ( ! cfg.restRoot ) {
		return;
	}

	function api( path, body ) {
		return fetch( cfg.restRoot + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( body || {} ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || 'Request failed' );
				}
				return data;
			} );
		} );
	}

	function status( button, text, isError ) {
		var box = button.closest( '.coywolf-seo-it-tools' );
		var span = box ? box.querySelector( '.coywolf-seo-it-tools-status' ) : null;
		if ( span ) {
			span.textContent = text;
			span.className = 'coywolf-seo-it-tools-status' + ( isError ? ' coywolf-seo-it-err' : '' );
		}
	}

	function refresh( id ) {
		// Media modal / grid: re-fetch the Backbone model so the alt, title,
		// caption, and description fields re-render with the saved values.
		if ( window.wp && window.wp.media && window.wp.media.attachment ) {
			var model = window.wp.media.attachment( id );
			if ( model ) {
				model.fetch();
			}
		}
		// Edit Media screen: the fields (including the TinyMCE description)
		// were saved server-side; reload to show them.
		if ( document.body.classList.contains( 'post-type-attachment' ) && document.getElementById( 'post' ) ) {
			window.location.reload();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var generate = event.target.closest( '.coywolf-seo-it-generate' );
		if ( ! generate ) {
			return;
		}
		event.preventDefault();
		var id = parseInt( generate.getAttribute( 'data-id' ), 10 );
		generate.disabled = true;
		status( generate, cfg.i18n.working );
		api( '/image-text/generate', { id: id, save: true } )
			.then( function () {
				status( generate, cfg.i18n.saved );
				refresh( id );
				generate.disabled = false;
			} )
			.catch( function ( err ) {
				status( generate, err.message, true );
				generate.disabled = false;
			} );
	} );
} )();
