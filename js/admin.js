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

		// AI enrichment: show the API key when either AI feature is on.
		function syncAiKeyFields() {
			$( '#coywolf-seo-ai-fields' ).toggle(
				$( '#coywolf-seo-ai-enabled' ).prop( 'checked' ) || $( '#coywolf-seo-ai-descriptions' ).prop( 'checked' )
			);
		}
		$( '#coywolf-seo-ai-enabled, #coywolf-seo-ai-descriptions' ).on( 'change', syncAiKeyFields );

		// Bulk enrichment: confirm before starting; poll progress while a
		// run is active (each poll also gives WP-Cron a chance to fire).
		$( '#coywolf-seo-bulk-form' ).on( 'submit', function ( e ) {
			if ( ! window.confirm( config.i18n.confirmBulkEnrich || 'Enrich all posts and pages now?' ) ) {
				e.preventDefault();
			}
		} );
		$( '#coywolf-seo-bulk-cancel-form' ).on( 'submit', function ( e ) {
			if ( ! window.confirm( config.i18n.confirmBulkCancel || 'Cancel this run for good?' ) ) {
				e.preventDefault();
			}
		} );
		var $bulkBox = $( '#coywolf-seo-bulk-progress' );
		if ( $bulkBox.length && $bulkBox.data( 'running' ) ) {
			var pollBulk = function () {
				$.post( config.ajaxUrl, {
					action: 'coywolf_seo_bulk_status',
					_ajax_nonce: config.bulkStatusNonce
				} ).done( function ( res ) {
					if ( ! res || ! res.success ) {
						return;
					}
					var d = res.data;
					$bulkBox.find( '.coywolf-seo-progress-bar' ).css( 'width', d.percent + '%' );
					var bulkText = d.done + ' / ' + d.total + ' (' + d.percent + '%)';
					if ( d.stage_label ) {
						bulkText += ' — ' + d.stage_label;
					}
					if ( d.failed > 0 ) {
						bulkText += ' — ' + d.failed + ' failed';
					}
					$bulkBox.find( '.coywolf-seo-bulk-text' ).text( bulkText );
					if ( 'running' === d.status ) {
						window.setTimeout( pollBulk, 4000 );
					} else {
						window.location.reload();
					}
				} );
			};
			window.setTimeout( pollBulk, 4000 );
		}

		// Excluding meta descriptions hides the AI meta-description option
		// immediately — and unchecking brings it right back.
		function syncAiDescriptionRow() {
			$( '#coywolf-seo-ai-desc-row' ).toggle( ! $( '#coywolf-seo-exclude-desc' ).prop( 'checked' ) );
		}
		if ( $( '#coywolf-seo-exclude-desc' ).length && $( '#coywolf-seo-ai-desc-row' ).length ) {
			$( '#coywolf-seo-exclude-desc' ).on( 'change', syncAiDescriptionRow );
			syncAiDescriptionRow();
		}

		// Category/Tag term fields: the form hooks can only append at the
		// end, so move the rows where they belong — Page Title below Name
		// (above Slug), the Open Graph image below Description.
		if ( $( '#coywolf-seo-term-title-row' ).length ) {
			$( '#coywolf-seo-term-title-row' ).insertAfter( $( '.form-field.term-name-wrap' ).first() );
			$( '#coywolf-seo-term-og-row' ).insertAfter( $( '.form-field.term-description-wrap' ).first() );

			// The Page Title placeholder mirrors the Name as it is typed
			// (#tag-name on the add form, #name on the edit screen).
			$( '#tag-name, #name' ).on( 'input', function () {
				$( '#coywolf-seo-term-title' ).attr( 'placeholder', this.value );
			} );
			if ( $( '#tag-name' ).length && $( '#tag-name' ).val() ) {
				$( '#coywolf-seo-term-title' ).attr( 'placeholder', $( '#tag-name' ).val() );
			}

			var termFrame = null;
			$( document ).on( 'click', '#coywolf-seo-term-og-select', function ( e ) {
				e.preventDefault();
				if ( ! termFrame ) {
					termFrame = wp.media( {
						title: config.i18n.selectImage || 'Select image',
						library: { type: 'image' },
						multiple: false
					} );
					termFrame.on( 'select', function () {
						var attachment = termFrame.state().get( 'selection' ).first().toJSON();
						var size = ( attachment.sizes && attachment.sizes.medium ) || attachment;
						$( '#coywolf-seo-term-og-id' ).val( attachment.id );
						$( '#coywolf-seo-term-og-preview' ).attr( 'src', size.url ).show();
						$( '#coywolf-seo-term-og-remove' ).show();
					} );
				}
				termFrame.open();
			} );
			$( document ).on( 'click', '#coywolf-seo-term-og-remove', function ( e ) {
				e.preventDefault();
				$( '#coywolf-seo-term-og-id' ).val( '' );
				$( '#coywolf-seo-term-og-preview' ).hide().attr( 'src', '' );
				$( this ).hide();
			} );

			// WordPress adds terms over AJAX and clears its own fields —
			// clear ours too once the new term is in.
			$( document ).ajaxComplete( function ( event, xhr, settings ) {
				if ( settings.data && -1 !== String( settings.data ).indexOf( 'action=add-tag' ) && xhr.status === 200 ) {
					$( '#coywolf-seo-term-title' ).val( '' );
					$( '#coywolf-seo-term-og-id' ).val( '' );
					$( '#coywolf-seo-term-og-preview' ).hide().attr( 'src', '' );
					$( '#coywolf-seo-term-og-remove' ).hide();
				}
			} );
		}

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
