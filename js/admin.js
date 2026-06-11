/**
 * Coywolf SEO admin behavior: the Organization/Person toggle, the typed
 * property repeaters (Site Details and Authors), the media pickers, and
 * the Settings-page field visibility.
 *
 * Property input metadata arrives via the CoywolfSEOAdmin global
 * (wp_localize_script): propertyInputs maps a property name to either
 * { input: 'url'|'email'|... } or { fields: { sub: { label, input } } }.
 */
( function ( $ ) {
	'use strict';

	var config = window.CoywolfSEOAdmin || { propertyInputs: {}, i18n: {} };

	/**
	 * Build the value cell contents for a property, mirroring the PHP
	 * renderer in Coywolf_SEO_Admin::render_property_value_cell().
	 */
	function buildValueCell( nameBase, prop ) {
		var meta = config.propertyInputs[ prop ] || { input: 'text' };
		var $cell = $( '<td class="coywolf-seo-prop-value"></td>' );

		if ( meta.fields ) {
			var $wrap = $( '<div class="coywolf-seo-subfields"></div>' );
			$.each( meta.fields, function ( sub, subMeta ) {
				var $label = $( '<label></label>' );
				$label.append( $( '<span></span>' ).text( subMeta.label ) );
				$label.append(
					$( '<input/>', {
						type: subMeta.input,
						name: nameBase + '[' + sub + ']'
					} )
				);
				$wrap.append( $label );
			} );
			$cell.append( $wrap );
			return $cell;
		}

		if ( meta.input === 'image' ) {
			$cell.append(
				$( '<input/>', {
					type: 'url',
					class: 'regular-text',
					name: nameBase,
					placeholder: config.i18n.pasteOrSelect || ''
				} )
			);
			$cell.append( ' ' );
			$cell.append(
				$( '<button/>', {
					type: 'button',
					class: 'button coywolf-seo-media-btn',
					text: config.i18n.selectImage || 'Select image'
				} )
			);
			return $cell;
		}

		$cell.append(
			$( '<input/>', {
				type: meta.input || 'text',
				class: 'regular-text',
				name: nameBase
			} )
		);
		return $cell;
	}

	$( function () {
		// Organization / Person toggle.
		function syncEntityRows() {
			var type = $( '.coywolf-seo-entity-toggle:checked' ).val();
			$( '.coywolf-seo-org-row' ).toggle( type === 'organization' );
			$( '.coywolf-seo-person-row' ).toggle( type === 'person' );
		}
		$( '.coywolf-seo-entity-toggle' ).on( 'change', syncEntityRows );

		// Property repeaters: the picker below the rows adds a row for the
		// chosen property, then resets itself.
		$( '.coywolf-seo-add-select' ).on( 'change', function () {
			var prop = $( this ).val();
			if ( ! prop ) {
				return;
			}
			var $table = $( '#' + $( this ).data( 'target' ) );
			var $tbody = $table.find( 'tbody' );
			var field = $table.data( 'field' );
			var index = parseInt( $table.attr( 'data-next-index' ), 10 ) || $tbody.find( 'tr' ).length;
			$table.attr( 'data-next-index', index + 1 );

			// The row's select carries the same catalog as the picker,
			// minus its placeholder option.
			var $select = $( this ).clone();
			$select
				.removeClass( 'coywolf-seo-add-select' )
				.addClass( 'coywolf-seo-prop-select' )
				.removeAttr( 'data-target aria-label' )
				.attr( 'name', 'coywolf_seo[' + field + '][' + index + '][prop]' );
			$select.find( 'option[value=""]' ).remove();
			$select.val( prop );

			var $row = $( '<tr class="coywolf-seo-prop-row"></tr>' );
			$row.append( $( '<td></td>' ).append( $select ) );
			$row.append( buildValueCell( 'coywolf_seo[' + field + '][' + index + '][value]', prop ) );
			$row.append(
				$( '<td></td>' ).append(
					$( '<button/>', {
						type: 'button',
						class: 'button-link coywolf-seo-remove-row',
						'aria-label': config.i18n.removeProperty || 'Remove property',
						html: '&times;'
					} )
				)
			);
			$tbody.append( $row );
			$( this ).val( '' );
		} );

		// Changing a row's property swaps its value cell to the matching
		// input type, keeping the row's index.
		$( document ).on( 'change', '.coywolf-seo-prop-select', function () {
			var $select = $( this );
			var match = /\[(\w+)\]\[(\d+)\]\[prop\]$/.exec( $select.attr( 'name' ) || '' );
			if ( ! match ) {
				return;
			}
			var nameBase = 'coywolf_seo[' + match[ 1 ] + '][' + match[ 2 ] + '][value]';
			$select.closest( 'tr' ).find( '.coywolf-seo-prop-value' ).replaceWith( buildValueCell( nameBase, $select.val() ) );
		} );

		// Rows can all be removed — the picker below adds them back.
		$( document ).on( 'click', '.coywolf-seo-remove-row', function () {
			$( this ).closest( 'tr' ).remove();
		} );

		// Media picker inside repeaters: writes the chosen image URL into
		// the sibling input (uploads land in the Media Library via wp.media).
		var repeaterFrame = null;
		$( document ).on( 'click', '.coywolf-seo-media-btn', function ( e ) {
			e.preventDefault();
			var $input = $( this ).closest( 'td' ).find( 'input[type="url"]' ).first();
			if ( ! repeaterFrame ) {
				repeaterFrame = wp.media( {
					title: config.i18n.selectImage || 'Select image',
					library: { type: 'image' },
					multiple: false
				} );
			}
			repeaterFrame.off( 'select' );
			repeaterFrame.on( 'select', function () {
				var attachment = repeaterFrame.state().get( 'selection' ).first().toJSON();
				$input.val( attachment.url ).trigger( 'change' );
			} );
			repeaterFrame.open();
		} );

		// Authors: load the selected user's details.
		$( '#coywolf-seo-author-select' ).on( 'change', function () {
			$( this ).closest( 'form' ).trigger( 'submit' );
		} );

		// News sitemap: only show the category list when it applies.
		$( '#coywolf-seo-news-cat-mode' ).on( 'change', function () {
			$( '#coywolf-seo-news-cats' ).toggle( $( this ).val() !== 'all' );
		} );

		// AI enrichment: only show the API key once entity detection is on.
		$( '#coywolf-seo-ai-enabled' ).on( 'change', function () {
			$( '#coywolf-seo-ai-fields' ).toggle( this.checked );
		} );

		// Redirects: quick-add extras, inline edit rows, delete confirm.
		$( '#coywolf-seo-qa-more' ).on( 'click', function () {
			$( '#coywolf-seo-qa-more-fields' ).slideToggle( 120 );
		} );
		$( document ).on( 'click', '.coywolf-seo-edit-toggle', function () {
			$( '#coywolf-seo-edit-' + $( this ).data( 'rule' ) ).toggle();
		} );
		$( document ).on( 'submit', '.coywolf-seo-delete-form', function ( e ) {
			if ( ! window.confirm( ( window.CoywolfSEOAdmin && CoywolfSEOAdmin.i18n.confirmDelete ) || 'Delete this redirect?' ) ) {
				e.preventDefault();
			}
		} );
		// Bulk actions: select-all + a confirm before bulk delete.
		$( '#coywolf-seo-cb-all' ).on( 'change', function () {
			$( '.coywolf-seo-cb' ).prop( 'checked', this.checked );
		} );
		$( '#coywolf-seo-bulk' ).on( 'submit', function ( e ) {
			var action = $( this ).find( '[name="bulk_action"]' ).val();
			if ( ! action || ! $( '.coywolf-seo-cb:checked' ).length ) {
				e.preventDefault();
				return;
			}
			if ( 'delete' === action && ! window.confirm( ( window.CoywolfSEOAdmin && CoywolfSEOAdmin.i18n.confirmBulkDelete ) || 'Delete the selected redirects?' ) ) {
				e.preventDefault();
			}
		} );
		// A 404 "Redirect…" link pre-fills the quick-add bar; focus target.
		if ( $( '#coywolf-seo-qa-source' ).length && $( '#coywolf-seo-qa-source' ).val() ) {
			$( '#coywolf-seo-qa-target' ).trigger( 'focus' );
		}

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
