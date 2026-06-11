/**
 * Coywolf SEO admin behavior: the Organization/Person toggle, the property
 * repeater, and the Open Graph image picker.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// Organization / Person toggle.
		function syncEntityRows() {
			var type = $( '.coywolf-seo-entity-toggle:checked' ).val();
			$( '.coywolf-seo-org-row' ).toggle( type === 'organization' );
			$( '.coywolf-seo-person-row' ).toggle( type === 'person' );
		}
		$( '.coywolf-seo-entity-toggle' ).on( 'change', syncEntityRows );

		// Property repeater: clone the first row, clear its value.
		$( '#coywolf-seo-add-prop' ).on( 'click', function () {
			var $tbody = $( '#coywolf-seo-org-props tbody' );
			var $row = $tbody.find( 'tr' ).first().clone();
			$row.find( 'input' ).val( '' );
			$row.find( 'select' ).prop( 'selectedIndex', 0 );
			$tbody.append( $row );
		} );

		$( document ).on( 'click', '.coywolf-seo-remove-row', function () {
			var $rows = $( this ).closest( 'tbody' ).find( 'tr' );
			if ( $rows.length > 1 ) {
				$( this ).closest( 'tr' ).remove();
			} else {
				// Last row: clear it instead of removing, so the repeater
				// always has a template row to clone.
				$rows.first().find( 'input' ).val( '' );
			}
		} );

		// Open Graph image picker.
		var frame = null;
		$( '#coywolf-seo-og-select' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: $( this ).text(),
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var thumb =
						attachment.sizes && attachment.sizes.medium
							? attachment.sizes.medium.url
							: attachment.url;
					$( '#coywolf-seo-og-image' ).val( attachment.id );
					$( '#coywolf-seo-og-preview' ).html(
						$( '<img/>', { src: thumb, alt: '' } )
					);
					$( '#coywolf-seo-og-remove' ).show();
				} );
			}
			frame.open();
		} );

		$( '#coywolf-seo-og-remove' ).on( 'click', function () {
			$( '#coywolf-seo-og-image' ).val( '0' );
			$( '#coywolf-seo-og-preview' ).empty();
			$( this ).hide();
		} );
	} );
} )( jQuery );
