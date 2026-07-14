/**
 * Coywolf SEO admin behavior: the Organization/Person toggle, the typed
 * property repeaters (Site Details and Authors), the media pickers, and
 * the Settings-page field visibility.
 *
 * Property input metadata arrives via the coywolf_seo_admin global
 * (wp_localize_script): propertyInputs maps a property name to either
 * { input: 'url'|'email'|... } or { fields: { sub: { label, input } } }.
 */
( function ( $ ) {
	'use strict';

	var config = window.coywolf_seo_admin || { propertyInputs: {}, i18n: {} };

	/**
	 * Build the value cell contents for a property, mirroring the PHP
	 * renderer in Coywolf_SEO_Admin::render_property_value_cell().
	 */
	function buildValueCell( nameBase, prop, label ) {
		var meta = config.propertyInputs[ prop ] || { input: 'text' };
		var $cell = $( '<td class="coywolf-seo-prop-value"></td>' );
		// Accessible name for the value input, derived from the property label
		// (mirrors the PHP renderer's aria-label="<label> value").
		var valueAria = ( config.i18n.propValue || '%s value' ).replace( '%s', label || prop || '' );

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
			// New rows start empty, so always in URL mode. Picking an image
			// from the Media Library swaps this to a preview (see the
			// .coywolf-seo-media-btn handler), mirroring the PHP renderer.
			var $field = $( '<span class="coywolf-seo-image-field" data-mode="url"></span>' );
			$field.append(
				$( '<input/>', {
					type: 'url',
					class: 'regular-text coywolf-seo-image-url',
					name: nameBase,
					placeholder: config.i18n.pasteOrSelect || '',
					'aria-label': valueAria
				} )
			);
			$field.append( ' ' );
			$field.append(
				$( '<button/>', {
					type: 'button',
					class: 'button coywolf-seo-media-btn',
					text: config.i18n.selectImage || 'Select image'
				} )
			);
			$field.append( $( '<span class="coywolf-seo-image-preview"></span>' ) );
			$field.append(
				$( '<button/>', {
					type: 'button',
					class: 'button coywolf-seo-image-remove',
					text: config.i18n.removeImage || 'Remove image'
				} )
			);
			$cell.append( $field );
			return $cell;
		}

		$cell.append(
			$( '<input/>', {
				type: meta.input || 'text',
				class: 'regular-text',
				name: nameBase,
				'aria-label': valueAria
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
		// chosen property when the Add button is pressed. Adding on the SELECT's
		// change event was a keyboard trap — arrowing through the options fired
		// a change per option and spawned a junk row each time (WCAG 3.2.2). The
		// select now only holds the choice; the explicit Add button commits it.
		$( document ).on( 'click', '.coywolf-seo-add-btn', function () {
			var target = $( this ).data( 'target' );
			var $picker = $( '.coywolf-seo-add-select[data-target="' + target + '"]' );
			var prop = $picker.val();
			if ( ! prop ) {
				if ( window.wp && wp.a11y && wp.a11y.speak ) {
					wp.a11y.speak( config.i18n.chooseProperty || 'Choose a property first.', 'assertive' );
				}
				$picker.trigger( 'focus' );
				return;
			}
			var $table = $( '#' + target );
			var $tbody = $table.find( 'tbody' );
			var field = $table.data( 'field' );
			var index = parseInt( $table.attr( 'data-next-index' ), 10 ) || $tbody.find( 'tr' ).length;
			$table.attr( 'data-next-index', index + 1 );

			// The row's select carries the same catalog as the picker,
			// minus its placeholder option.
			var $select = $picker.clone();
			$select
				.removeClass( 'coywolf-seo-add-select' )
				.addClass( 'coywolf-seo-prop-select' )
				.removeAttr( 'data-target' )
				.attr( 'aria-label', config.i18n.propertyLabel || 'Property' )
				.attr( 'name', 'coywolf_seo[' + field + '][' + index + '][prop]' );
			$select.find( 'option[value=""]' ).remove();
			$select.val( prop );
			var propLabel = $select.find( 'option:selected' ).text();

			var $row = $( '<tr class="coywolf-seo-prop-row"></tr>' );
			$row.append( $( '<td></td>' ).append( $select ) );
			$row.append( buildValueCell( 'coywolf_seo[' + field + '][' + index + '][value]', prop, propLabel ) );
			var $moveUp = $( '<button/>', {
				type: 'button',
				class: 'button coywolf-seo-move-up',
				'aria-label': config.i18n.moveUp || 'Move property up'
			} ).append( $( '<span aria-hidden="true">↑</span>' ) );
			var $moveDown = $( '<button/>', {
				type: 'button',
				class: 'button coywolf-seo-move-down',
				'aria-label': config.i18n.moveDown || 'Move property down'
			} ).append( $( '<span aria-hidden="true">↓</span>' ) );
			$row.append(
				$( '<td></td>' )
					.append( $moveUp )
					.append( $moveDown )
					.append(
						$( '<button/>', {
							type: 'button',
							class: 'button coywolf-seo-remove-row',
							'aria-label': config.i18n.removeProperty || 'Remove property',
							text: config.i18n.remove || 'Remove'
						} )
					)
			);
			$tbody.append( $row );
			$picker.val( '' );
			// Move focus into the new row's property select and announce it,
			// so keyboard and screen-reader users land on the added row.
			$select.trigger( 'focus' );
			if ( window.wp && wp.a11y && wp.a11y.speak ) {
				wp.a11y.speak( ( config.i18n.propertyAdded || 'Property added' ) + ': ' + propLabel );
			}
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
			var propLabel = $select.find( 'option:selected' ).text();
			$select.closest( 'tr' ).find( '.coywolf-seo-prop-value' ).replaceWith( buildValueCell( nameBase, $select.val(), propLabel ) );
		} );

		// Rows can all be removed — the picker below adds them back. Move focus
		// to a sensible target (a sibling Remove button, else the Add picker) so
		// it never falls to <body>, and announce the removal for screen readers.
		$( document ).on( 'click', '.coywolf-seo-remove-row', function () {
			var $row = $( this ).closest( 'tr' );
			var $table = $row.closest( 'table' );
			var $sibling = $row.next( 'tr.coywolf-seo-prop-row' ).add( $row.prev( 'tr.coywolf-seo-prop-row' ) ).first();
			$row.remove();
			if ( $sibling.length ) {
				$sibling.find( '.coywolf-seo-remove-row' ).trigger( 'focus' );
			} else if ( $table.attr( 'id' ) ) {
				$( '.coywolf-seo-add-select[data-target="' + $table.attr( 'id' ) + '"]' ).trigger( 'focus' );
			}
			if ( window.wp && wp.a11y && wp.a11y.speak ) {
				wp.a11y.speak( config.i18n.propertyRemoved || 'Property removed' );
			}
		} );

		// Keyboard alternative to drag-reordering: Move up / Move down buttons.
		// The saved order is read from DOM order at submit time, so swapping the
		// rows is enough — no index rewriting needed.
		$( document ).on( 'click', '.coywolf-seo-move-up, .coywolf-seo-move-down', function () {
			var $btn = $( this );
			var $tr = $btn.closest( 'tr.coywolf-seo-prop-row' );
			if ( $btn.hasClass( 'coywolf-seo-move-up' ) ) {
				var $prev = $tr.prev( 'tr.coywolf-seo-prop-row' );
				if ( $prev.length ) {
					$tr.insertBefore( $prev );
				}
			} else {
				var $next = $tr.next( 'tr.coywolf-seo-prop-row' );
				if ( $next.length ) {
					$tr.insertAfter( $next );
				}
			}
			$btn.trigger( 'focus' );
		} );

		// Property rows are reordered with the keyboard-accessible Move up/down
		// buttons (above); the saved order is read from DOM order at submit time.

		// Media picker inside repeaters: writes the chosen image URL into the
		// field's hidden-by-CSS URL input (uploads land in the Media Library
		// via wp.media), then swaps the field to its image preview.
		var repeaterFrame = null;
		$( document ).on( 'click', '.coywolf-seo-media-btn', function ( e ) {
			e.preventDefault();
			var $field = $( this ).closest( '.coywolf-seo-image-field' );
			var $input = $field.find( '.coywolf-seo-image-url' ).first();
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
				var thumb =
					attachment.sizes && attachment.sizes.medium
						? attachment.sizes.medium.url
						: attachment.url;
				// Store the full-size URL (what the schema outputs); show a
				// medium thumbnail as the preview.
				$input.val( attachment.url ).trigger( 'change' );
				$field
					.find( '.coywolf-seo-image-preview' )
					.html( $( '<img/>', { src: thumb, alt: '' } ) );
				$field.attr( 'data-mode', 'preview' );
			} );
			// The frame is shared across image rows; clear any prior selection
			// so it doesn't carry one field's pick into another as pre-highlighted.
			if ( repeaterFrame.state() ) {
				repeaterFrame.state().get( 'selection' ).reset();
			}
			repeaterFrame.open();
		} );

		// Removing a chosen image clears the URL and reverts to the text input
		// + "Select image" button (a pasted URL is left for the user to edit).
		$( document ).on( 'click', '.coywolf-seo-image-remove', function ( e ) {
			e.preventDefault();
			var $field = $( this ).closest( '.coywolf-seo-image-field' );
			$field.find( '.coywolf-seo-image-url' ).val( '' );
			$field.find( '.coywolf-seo-image-preview' ).empty();
			$field.attr( 'data-mode', 'url' );
		} );

		// Authors: the user is loaded via the explicit "Load" submit button (or
		// Enter), not on select 'change' — arrowing through options must not
		// trigger navigation (WCAG 3.2.2 On Input) or discard unsaved edits.

		// News sitemap: the Include and Categories rows only apply when the
		// News sitemap itself is enabled.
		function syncNewsRows() {
			$( '.coywolf-seo-news-row' ).toggle( $( '#coywolf-seo-news-enabled' ).prop( 'checked' ) );
		}
		$( '#coywolf-seo-news-enabled' ).on( 'change', syncNewsRows );
		if ( $( '#coywolf-seo-news-enabled' ).length ) {
			syncNewsRows();
		}

		// LLMs.txt: the Markdown-endpoints, Entities, and Content-licence rows
		// only apply when llms.txt itself is enabled.
		function syncLlmsRows() {
			$( '.coywolf-seo-llms-row' ).toggle( $( '#coywolf-seo-llms-enabled' ).prop( 'checked' ) );
		}
		$( '#coywolf-seo-llms-enabled' ).on( 'change', syncLlmsRows );
		if ( $( '#coywolf-seo-llms-enabled' ).length ) {
			syncLlmsRows();
		}

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

		// AI service selector: show only the chosen service's key/model/status
		// rows, hide the rest. One active service at a time.
		function syncAiServiceRows() {
			var service = $( '#coywolf-seo-ai-service' ).val();
			$( '.coywolf-seo-ai-svc' ).each( function () {
				$( this ).toggle( $( this ).attr( 'data-service' ) === service );
			} );
		}
		if ( $( '#coywolf-seo-ai-service' ).length ) {
			$( '#coywolf-seo-ai-service' ).on( 'change', syncAiServiceRows );
			syncAiServiceRows();
		}

		// Bulk enrichment controls update in place: the forms post over
		// AJAX (the plain submit stays as the no-JS fallback), the server
		// re-renders the controls, and the area swaps without a reload.
		var $bulkArea = $( '#coywolf-seo-bulk-area' );
		var bulkPollTimer = null;

		function bulkRender( html, status ) {
			// The whole area is replaced, destroying the button the user just
			// activated (Start/Stop/Resume/Cancel) and dropping focus to <body>.
			// Note which op had focus so we can restore it to the equivalent new
			// control after the swap.
			var hadFocus = $bulkArea[ 0 ] && $.contains( $bulkArea[ 0 ], document.activeElement );
			var focusedOp = hadFocus ? $( document.activeElement ).closest( '.coywolf-seo-bulk-op' ).data( 'op' ) : null;
			$bulkArea.html( html ).attr( 'data-status', status );
			if ( hadFocus ) {
				var $target = focusedOp ? $bulkArea.find( '.coywolf-seo-bulk-op[data-op="' + focusedOp + '"] button' ).first() : $();
				if ( ! $target.length ) {
					$target = $bulkArea.find( 'button' ).first();
				}
				if ( $target.length ) {
					$target.trigger( 'focus' );
				} else {
					$bulkArea.attr( 'tabindex', '-1' ).trigger( 'focus' );
				}
			}
			window.clearTimeout( bulkPollTimer );
			if ( 'running' === status ) {
				bulkPollTimer = window.setTimeout( pollBulk, 4000 );
			} else if ( typeof loadEstimate === 'function' ) {
				loadEstimate( '', false ); // Stale-post counts changed.
			}
		}

		function bulkForceChecked() {
			return $bulkArea.find( '#coywolf-seo-bulk-force' ).is( ':checked' ) ? 1 : 0;
		}

		function bulkRealtimeChecked() {
			return $bulkArea.find( '#coywolf-seo-bulk-realtime' ).is( ':checked' ) ? 1 : 0;
		}

		function bulkOp( op ) {
			$bulkArea.css( 'opacity', 0.5 ).find( 'button' ).prop( 'disabled', true );
			$.post( config.ajaxUrl, {
				action: 'coywolf_seo_bulk_action',
				_ajax_nonce: config.bulkActionNonce,
				op: op,
				force: 'start' === op ? bulkForceChecked() : 0,
				realtime: 'start' === op ? bulkRealtimeChecked() : 0
			} ).done( function ( res ) {
				if ( res && res.success ) {
					bulkRender( res.data.html, res.data.status );
				}
			} ).always( function () {
				$bulkArea.css( 'opacity', 1 ).find( 'button' ).prop( 'disabled', false );
			} );
		}

		$( document ).on( 'submit', '.coywolf-seo-bulk-op', function ( e ) {
			e.preventDefault();
			var op = $( this ).data( 'op' );
			if ( 'start' === op ) {
				var startMessage = bulkForceChecked()
					? ( config.i18n.confirmBulkForce || 'Re-analyze everything now?' )
					: ( config.i18n.confirmBulkEnrich || 'Enrich all posts and pages now?' );
				if ( ! window.confirm( startMessage ) ) {
					return;
				}
			}
			if ( 'cancel' === op && ! window.confirm( config.i18n.confirmBulkCancel || 'Cancel this run for good?' ) ) {
				return;
			}
			bulkOp( op );
		} );

		function pollBulk() {
			$.post( config.ajaxUrl, {
				action: 'coywolf_seo_bulk_status',
				_ajax_nonce: config.bulkStatusNonce
			} ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				var d = res.data;
				var $box = $bulkArea.find( '#coywolf-seo-bulk-progress' );
				var $prog = $box.find( '.coywolf-seo-progress' );
				$prog.find( '.coywolf-seo-progress-bar' ).css( 'width', d.percent + '%' );
				$prog.attr( 'aria-valuenow', d.percent );
				var bulkText = d.done + ' / ' + d.total + ' (' + d.percent + '%)';
				if ( d.stage_label ) {
					bulkText += ' — ' + d.stage_label;
				}
				if ( d.failed > 0 ) {
					bulkText += ' — ' + d.failed + ' failed';
				}
				$box.find( '.coywolf-seo-bulk-text' ).text( bulkText );
				if ( 'running' === d.status ) {
					bulkPollTimer = window.setTimeout( pollBulk, 4000 );
				} else {
					bulkOp( 'refresh' ); // Re-render done/paused in place.
				}
			} );
		}
		if ( $bulkArea.length && 'running' === $bulkArea.data( 'status' ) ) {
			bulkPollTimer = window.setTimeout( pollBulk, 4000 );
		}

		// Bulk cost estimator: load on the settings page, refresh when the
		// Model dropdown changes (previewing without saving).
		var $estimate = $( '#coywolf-seo-bulk-estimate' );
		// Format a dollar amount without rounding a real-but-tiny cost down to
		// "0.00" (which reads as free) — sub-cent totals keep more precision.
		function fmtCost( value ) {
			var v = Number( value ) || 0;
			if ( v > 0 && v < 0.01 ) {
				return v.toFixed( 4 );
			}
			return v.toFixed( 2 );
		}
		function loadEstimate( model, unsaved ) {
			$estimate.html( '<em>' + $estimate.data( 'loading' ) + '</em>' );
			$.post( config.ajaxUrl, {
				action: 'coywolf_seo_bulk_estimate',
				_ajax_nonce: config.bulkStatusNonce,
				model: model || '',
				force: bulkForceChecked(),
				realtime: bulkRealtimeChecked()
			} ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				var d = res.data;
				if ( ! d.posts ) {
					if ( d.force_posts > 0 ) {
						$estimate.text(
							( config.i18n.estimateNone || 'Everything is current — re-analyzing all %POSTS% posts costs ~$%COST%.' )
								.replace( '%POSTS%', d.force_posts )
								.replace( '%COST%', false === d.priced ? '?' : fmtCost( d.force_cost ) )
						);
					} else {
						$estimate.text( config.i18n.estimateEmpty || 'There is no published content to enrich yet.' );
					}
					return;
				}
				// Unpriced model: never render a misleading "$0.00" as if free.
				if ( false === d.priced ) {
					$estimate.text(
						( config.i18n.estimateUnpriced || '%POSTS% posts need enrichment; no pricing data for %MODEL%.' )
							.replace( '%POSTS%', d.posts )
							.replace( '%SKIPPED%', d.skipped )
							.replace( '%MODEL%', d.model )
					);
					return;
				}
				var template = d.realtime
					? ( config.i18n.estimateLineRT || config.i18n.estimateLine || '%POSTS% posts, ~$%COST% (%MODEL%)' )
					: ( config.i18n.estimateLine || '%POSTS% posts, ~$%COST%, reserve $%RESERVE% (%MODEL%)' );
				var line = template
					.replace( '%POSTS%', d.posts )
					.replace( '%SKIPPED%', d.skipped )
					.replace( '%COST%', fmtCost( d.est_cost ) )
					.replace( '%RESERVE%', fmtCost( d.reserve_cost ) )
					.replace( '%MODEL%', d.model );
				line += ' ' + ( d.from_history ? ( config.i18n.estimateHistory || '' ) : ( config.i18n.estimateHeuristic || '' ) );
				if ( unsaved ) {
					line += ' ' + ( config.i18n.estimateUnsaved || '' );
				}
				$estimate.text( line );
			} );
		}
		if ( $estimate.length ) {
			loadEstimate( '', false );
			// One model <select> per provider row (class, not id, since the row is
			// emitted per service). Preview only the currently visible provider's
			// model — the others are display:none.
			$( document ).on( 'change', '.coywolf-seo-ai-model', function () {
				if ( $( this ).closest( '.coywolf-seo-ai-svc' ).is( ':visible' ) ) {
					loadEstimate( $( this ).val(), true );
				}
			} );
			$( document ).on( 'change', '#coywolf-seo-bulk-force', function () {
				loadEstimate( '', false );
			} );
			$( document ).on( 'change', '#coywolf-seo-bulk-realtime', function () {
				loadEstimate( '', false );
			} );
		}

		// API access test: one tiny real-time call and one tiny batch
		// (cancelled immediately) — their pattern pinpoints billing issues.
		$( '#coywolf-seo-ai-test' ).on( 'click', function () {
			var $result = $( '#coywolf-seo-ai-test-result' );
			$result.text( config.i18n.testRunning || 'Testing…' );
			$.post( config.ajaxUrl, {
				action: 'coywolf_seo_ai_test',
				_ajax_nonce: config.bulkStatusNonce
			} ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					$result.text( 'Test failed to run.' );
					return;
				}
				var d = res.data;
				var parts = [
					( config.i18n.testMessages || 'Regular API:' ) + ' ' + ( d.messages_ok ? '✓' : '✗ ' + d.messages_error ),
					( config.i18n.testBatches || 'Batches API:' ) + ' ' + ( d.batch_ok ? '✓' : '✗ ' + d.batch_error )
				];
				if ( d.hint ) {
					parts.push( d.hint );
				}
				$result.text( parts.join( ' — ' ) );
			} );
		} );

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
						// Give the live preview a meaningful alt so the selection
						// isn't invisible to screen readers, and announce it.
						$( '#coywolf-seo-term-og-preview' )
							.attr( { src: size.url, alt: attachment.alt || attachment.title || '' } )
							.show();
						$( '#coywolf-seo-term-og-remove' ).show();
						if ( window.wp && wp.a11y && wp.a11y.speak ) {
							wp.a11y.speak( config.i18n.imageSelected || 'Image selected' );
						}
					} );
				}
				termFrame.open();
			} );
			$( document ).on( 'click', '#coywolf-seo-term-og-remove', function ( e ) {
				e.preventDefault();
				$( '#coywolf-seo-term-og-id' ).val( '' );
				$( '#coywolf-seo-term-og-preview' ).hide().attr( { src: '', alt: '' } );
				$( this ).hide();
				if ( window.wp && wp.a11y && wp.a11y.speak ) {
					wp.a11y.speak( config.i18n.imageRemoved || 'Image removed' );
				}
			} );

			// WordPress adds terms over AJAX and clears its own fields —
			// clear ours too once the new term is in.
			$( document ).ajaxComplete( function ( event, xhr, settings ) {
				// WordPress's add-tag endpoint returns HTTP 200 even on failure
				// (a wp_error wrapped in <wp_error> XML — e.g. a duplicate term
				// name). Core keeps the Name/Description in that case; only clear
				// our fields on an actual success, so a retry doesn't lose them.
				if (
					settings.data &&
					-1 !== String( settings.data ).indexOf( 'action=add-tag' ) &&
					xhr.status === 200 &&
					-1 === String( xhr.responseText ).indexOf( '<wp_error' )
				) {
					$( '#coywolf-seo-term-title' ).val( '' );
					$( '#coywolf-seo-term-og-id' ).val( '' );
					$( '#coywolf-seo-term-og-preview' ).hide().attr( 'src', '' );
					$( '#coywolf-seo-term-og-remove' ).hide();
				}
			} );
		}

		// Redirects: quick-add extras, inline edit rows, delete confirm.
		$( '#coywolf-seo-qa-more' ).on( 'click', function () {
			var $btn = $( this );
			var expanded = 'true' === $btn.attr( 'aria-expanded' );
			$btn.attr( 'aria-expanded', expanded ? 'false' : 'true' );
			$( '#coywolf-seo-qa-more-fields' ).slideToggle( 120 );
		} );
		$( document ).on( 'click', '.coywolf-seo-edit-toggle', function () {
			var ruleId = $( this ).data( 'rule' );
			var $row = $( '#coywolf-seo-edit-' + ruleId );
			$row.toggle();
			$( '.coywolf-seo-edit-toggle[data-rule="' + ruleId + '"]' )
				.attr( 'aria-expanded', $row.is( ':visible' ) ? 'true' : 'false' );
		} );
		$( document ).on( 'submit', '.coywolf-seo-delete-form', function ( e ) {
			if ( ! window.confirm( ( window.coywolf_seo_admin && coywolf_seo_admin.i18n.confirmDelete ) || 'Delete this redirect?' ) ) {
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
			if ( 'delete' === action && ! window.confirm( ( window.coywolf_seo_admin && coywolf_seo_admin.i18n.confirmBulkDelete ) || 'Delete the selected redirects?' ) ) {
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

	/* ------------------------------------------------------------------ *
	 * Robots.txt Manager: "restore your robots.txt?" prompt, shown when the
	 * feature is turned off in Settings or the plugin is deactivated while it
	 * is managing a physical robots.txt file.
	 * ------------------------------------------------------------------ */
	$( function () {
		var rr = config.robotsRestore;
		if ( ! rr ) {
			return;
		}
		var t = rr.i18n || {};

		function showModal( onRestore, onKeep, onCancel ) {
			// Remember what had focus so we can restore it when the dialog closes.
			var lastFocus = document.activeElement;
			var overlay = document.createElement( 'div' );
			overlay.className = 'coywolf-seo-modal';
			var box = document.createElement( 'div' );
			box.className = 'coywolf-seo-modal-box';
			// Dialog semantics: labelled by the title, described by the message.
			box.setAttribute( 'role', 'dialog' );
			box.setAttribute( 'aria-modal', 'true' );
			var h2 = document.createElement( 'h2' );
			h2.id = 'coywolf-seo-modal-title';
			h2.textContent = t.title || 'Restore robots.txt?';
			var p = document.createElement( 'p' );
			p.id = 'coywolf-seo-modal-desc';
			p.textContent = t.message || '';
			box.setAttribute( 'aria-labelledby', h2.id );
			box.setAttribute( 'aria-describedby', p.id );
			var actions = document.createElement( 'p' );
			actions.className = 'coywolf-seo-modal-actions';
			function button( label, cls ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'button ' + cls;
				b.textContent = label;
				return b;
			}
			var restoreBtn = button( t.restore || 'Restore', 'button-primary' );
			var keepBtn = button( t.keep || 'Keep', 'button-secondary' );
			var cancelBtn = button( t.cancel || 'Cancel', 'button-secondary' );
			var buttons = [ restoreBtn, keepBtn, cancelBtn ];
			function close() {
				document.removeEventListener( 'keydown', onKeydown, true );
				if ( overlay.parentNode ) {
					overlay.parentNode.removeChild( overlay );
				}
				// Return focus to wherever it was before the dialog opened.
				if ( lastFocus && typeof lastFocus.focus === 'function' ) {
					lastFocus.focus();
				}
			}
			// Escape cancels; Tab / Shift+Tab cycle within the three buttons.
			function onKeydown( e ) {
				if ( 'Escape' === e.key || 'Esc' === e.key ) {
					e.preventDefault();
					close();
					if ( onCancel ) { onCancel(); }
					return;
				}
				if ( 'Tab' === e.key ) {
					var idx = buttons.indexOf( document.activeElement );
					if ( -1 === idx ) {
						idx = 0;
					}
					var next = e.shiftKey ? idx - 1 : idx + 1;
					if ( next < 0 ) {
						next = buttons.length - 1;
					} else if ( next >= buttons.length ) {
						next = 0;
					}
					e.preventDefault();
					buttons[ next ].focus();
				}
			}
			restoreBtn.addEventListener( 'click', function () { close(); onRestore(); } );
			keepBtn.addEventListener( 'click', function () { close(); onKeep(); } );
			cancelBtn.addEventListener( 'click', function () { close(); if ( onCancel ) { onCancel(); } } );
			overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { close(); if ( onCancel ) { onCancel(); } } } );
			actions.appendChild( restoreBtn );
			actions.appendChild( document.createTextNode( ' ' ) );
			actions.appendChild( keepBtn );
			actions.appendChild( document.createTextNode( ' ' ) );
			actions.appendChild( cancelBtn );
			box.appendChild( h2 );
			box.appendChild( p );
			box.appendChild( actions );
			overlay.appendChild( box );
			document.body.appendChild( overlay );
			document.addEventListener( 'keydown', onKeydown, true );
			restoreBtn.focus();
		}

		// Plugins screen: intercept this plugin's Deactivate link and append the
		// chosen action to it (the deactivation hook reads it).
		if ( rr.basename ) {
			var link = null;
			var row = document.querySelector( 'tr[data-plugin="' + rr.basename + '"]' );
			if ( row ) {
				link = row.querySelector( 'span.deactivate a, .deactivate a' );
			}
			if ( ! link ) {
				var cands = document.querySelectorAll( 'a[href*="action=deactivate"]' );
				for ( var i = 0; i < cands.length; i++ ) {
					var href = cands[ i ].getAttribute( 'href' ) || '';
					if ( -1 !== href.indexOf( encodeURIComponent( rr.basename ) ) || -1 !== href.indexOf( rr.basename ) ) {
						link = cands[ i ];
						break;
					}
				}
			}
			if ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var href = link.getAttribute( 'href' ) || link.href;
					var withParam = function ( v ) {
						return href + ( -1 === href.indexOf( '?' ) ? '?' : '&' ) + rr.param + '=' + v;
					};
					showModal(
						function () { window.location.href = withParam( 'restore' ); },
						function () { window.location.href = withParam( 'keep' ); },
						null
					);
				} );
			}
		}

		// Settings page: prompt when the form is SAVED while the user is turning
		// the manager off, so the choice rides in a hidden field that
		// save_settings() reads alongside every other change on the page.
		var toggle = rr.toggleId ? document.getElementById( rr.toggleId ) : null;
		var hidden = rr.hiddenId ? document.getElementById( rr.hiddenId ) : null;
		var form = toggle ? toggle.closest( 'form' ) : null;
		if ( form && toggle && hidden ) {
			var robotsResolved = false;
			form.addEventListener( 'submit', function ( e ) {
				// Only prompt when the manager is being turned OFF (the box is now
				// checked but started unchecked) and it is active.
				if ( robotsResolved || ! rr.show || ! toggle.checked || toggle.defaultChecked ) {
					return; // Save normally.
				}
				e.preventDefault();
				// WordPress's Save button is <input name="submit">, which shadows
				// form.submit — call the native method so the form actually posts.
				var save = function () { robotsResolved = true; HTMLFormElement.prototype.submit.call( form ); };
				showModal(
					function () { hidden.value = 'restore'; save(); },
					function () { hidden.value = 'keep'; save(); },
					// Cancel undoes ONLY the turn-off: leave the manager on, but
					// still save every other change on the page.
					function () { toggle.checked = false; hidden.value = ''; save(); }
				);
			} );
		}
	} );
	/* ------------------------------------------------------------------ *
	 * Settings: "Fix missing image IDs" maintenance tool. Runs in chunks via
	 * admin-ajax; Preview is a dry run, Fix applies the changes.
	 * ------------------------------------------------------------------ */
	$( function () {
		var $idfix = $( '#coywolf-seo-idfix' );
		if ( ! $idfix.length ) {
			return;
		}
		var $preview = $( '#coywolf-seo-idfix-preview' );
		var $run = $( '#coywolf-seo-idfix-run' );
		var $stop = $( '#coywolf-seo-idfix-stop' );
		var $progress = $( '#coywolf-seo-idfix-progress' );
		var $result = $( '#coywolf-seo-idfix-result' );
		var $samples = $( '#coywolf-seo-idfix-samples' );
		var running = false;

		function post( data ) {
			return $.post( config.ajaxUrl, $.extend( { action: 'coywolf_seo_idfix', _ajax_nonce: config.idFixNonce }, data ) );
		}
		function buttons( on ) {
			// Disabling/hiding the control that currently holds focus drops focus
			// to <body>. Move it to the control that stays operable so a keyboard
			// user keeps their place. Only act when focus is inside this tool.
			var active = document.activeElement;
			var inTool = active && $.contains( $idfix[ 0 ], active );
			$preview.prop( 'disabled', on );
			$run.prop( 'disabled', on );
			$stop.toggleClass( 'hidden', ! on );
			if ( ! inTool ) {
				return;
			}
			if ( on && ( active === $preview[ 0 ] || active === $run[ 0 ] ) ) {
				$stop.trigger( 'focus' );
			} else if ( ! on && active === $stop[ 0 ] ) {
				$preview.trigger( 'focus' );
			}
		}
		function fill( tmpl, d ) {
			return String( tmpl )
				.replace( '%IMG%', d.images_fixed )
				.replace( '%CONV%', d.images_converted || 0 )
				.replace( '%POSTS%', d.posts_updated )
				.replace( '%UNM%', d.unmatched )
				.replace( '%SCAN%', d.posts_scanned )
				.replace( '%TOTAL%', d.total );
		}
		function renderSamples( d ) {
			var list = ( d && d.samples && d.samples.length ) ? d.samples : [];
			if ( ! list.length ) {
				$samples.empty().addClass( 'hidden' );
				return;
			}
			var $ul = $( '<ul class="coywolf-seo-idfix-sample-list" />' );
			$.each( list, function ( i, s ) {
				var $li = $( '<li />' );
				if ( s.thumb ) {
					$( '<img />' ).attr( { src: s.thumb, alt: '', width: 48, height: 48 } ).addClass( 'coywolf-seo-idfix-thumb' ).appendTo( $li );
				}
				var $link = s.edit ? $( '<a />' ).attr( 'href', s.edit ) : $( '<span />' );
				$li.append( $link.text( s.title ) );
				$ul.append( $li );
			} );
			$samples.empty().removeClass( 'hidden' )
				.append( $( '<p class="description" />' ).text( config.i18n.idFixSamples || 'Examples:' ) )
				.append( $ul );
		}
		function render( d ) {
			// Always show 100% once done, even if the candidate set shrank mid-run.
			var pct = 'done' === d.status ? 100 : ( d.total > 0 ? Math.min( 100, Math.round( ( d.posts_scanned / d.total ) * 100 ) ) : 0 );
			$progress.removeClass( 'hidden' ).attr( 'aria-valuenow', pct ).find( '.coywolf-seo-progress-bar' ).css( 'width', pct + '%' );
			if ( 'done' === d.status ) {
				$result.text( fill( d.dry_run ? ( config.i18n.idFixPreview || 'Preview: %IMG% images in %POSTS% posts (%UNM% unmatched).' ) : ( config.i18n.idFixDone || 'Fixed %IMG% images in %POSTS% posts (%UNM% unmatched).' ), d ) );
			} else {
				$result.text( fill( config.i18n.idFixProgress || 'Scanning %SCAN% of %TOTAL% posts…', d ) );
			}
			renderSamples( d );
		}
		function failed() {
			running = false;
			buttons( false );
			$result.text( config.i18n.idFixError || 'The run stopped unexpectedly — reload the page and try again.' );
		}
		function loop() {
			post( { op: 'step' } ).done( function ( res ) {
				if ( ! res || ! res.success ) { failed(); return; }
				render( res.data );
				if ( 'running' === res.data.status && running ) { loop(); } else { running = false; buttons( false ); }
			} ).fail( failed );
		}
		function startRun( dry ) {
			running = true;
			buttons( true );
			$result.text( '' );
			$samples.empty().addClass( 'hidden' );
			post( { op: 'start', dry_run: dry ? 1 : 0 } ).done( function ( res ) {
				if ( ! res || ! res.success ) { failed(); return; }
				render( res.data );
				if ( 'running' === res.data.status ) { loop(); } else { running = false; buttons( false ); }
			} ).fail( failed );
		}
		$preview.on( 'click', function () { startRun( true ); } );
		$run.on( 'click', function () {
			if ( window.confirm( config.i18n.idFixConfirm || 'Add missing image IDs to your posts and pages? This edits post content.' ) ) {
				startRun( false );
			}
		} );
		$stop.on( 'click', function () { running = false; post( { op: 'cancel' } ); buttons( false ); } );
	} );
	/* ------------------------------------------------------------------ *
	 * "Replace Yoast tables of contents": a Preview/Replace tool in a modal
	 * opened from the after-activation banner. Runs in chunks via admin-ajax;
	 * Preview is a dry run, Replace applies the changes.
	 * ------------------------------------------------------------------ */
	$( function () {
		var $tool = $( '#coywolf-seo-toc-convert' );
		if ( ! $tool.length ) {
			return;
		}
		var $preview = $( '#coywolf-seo-toc-convert-preview' );
		var $run = $( '#coywolf-seo-toc-convert-run' );
		var $stop = $( '#coywolf-seo-toc-convert-stop' );
		var $progress = $( '#coywolf-seo-toc-convert-progress' );
		var $result = $( '#coywolf-seo-toc-convert-result' );
		var $samples = $( '#coywolf-seo-toc-convert-samples' );
		var running = false;

		// Modal open/close + focus handling. The tool markup lives in a hidden
		// modal; the banner's "Review and replace" button opens it.
		var $modal = $( '#coywolf-seo-toc-convert-modal' );
		var $openBtn = $( '#coywolf-seo-toc-convert-open' );
		var $closeBtn = $( '#coywolf-seo-toc-convert-close' );
		var lastFocus = null;

		function focusable() {
			return $modal.find( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' )
				.filter( ':visible' ).filter( function () { return ! this.disabled; } );
		}
		function onModalKeydown( event ) {
			if ( 'Escape' === event.key ) {
				closeModal();
				return;
			}
			if ( 'Tab' !== event.key ) {
				return;
			}
			// Keep focus inside the dialog.
			var items = focusable();
			if ( ! items.length ) {
				return;
			}
			var first = items[ 0 ];
			var last = items[ items.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
		function openModal() {
			lastFocus = document.activeElement;
			$modal.removeClass( 'hidden' );
			var items = focusable();
			( items.length ? $( items[ 0 ] ) : $modal ).trigger( 'focus' );
			$( document ).on( 'keydown.coywolfSeoTocModal', onModalKeydown );
		}
		function closeModal() {
			$modal.addClass( 'hidden' );
			$( document ).off( 'keydown.coywolfSeoTocModal' );
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}
		$openBtn.on( 'click', openModal );
		$closeBtn.on( 'click', closeModal );
		// A click on the overlay (outside the box) closes it.
		$modal.on( 'click', function ( event ) {
			if ( event.target === $modal[ 0 ] ) {
				closeModal();
			}
		} );

		function post( data ) {
			return $.post( config.ajaxUrl, $.extend( { action: 'coywolf_seo_toc_convert', _ajax_nonce: config.tocConvertNonce }, data ) );
		}
		function buttons( on ) {
			// Keep keyboard focus on a control that stays operable when the one
			// holding focus is disabled/hidden (mirrors the image-ID tool).
			var active = document.activeElement;
			var inTool = active && $.contains( $tool[ 0 ], active );
			$preview.prop( 'disabled', on );
			$run.prop( 'disabled', on );
			$stop.toggleClass( 'hidden', ! on );
			if ( ! inTool ) {
				return;
			}
			if ( on && ( active === $preview[ 0 ] || active === $run[ 0 ] ) ) {
				$stop.trigger( 'focus' );
			} else if ( ! on && active === $stop[ 0 ] ) {
				$preview.trigger( 'focus' );
			}
		}
		function fill( tmpl, d ) {
			return String( tmpl )
				.replace( '%BLOCKS%', d.blocks_replaced )
				.replace( '%POSTS%', d.posts_updated )
				.replace( '%SCAN%', d.posts_scanned )
				.replace( '%TOTAL%', d.total );
		}
		function renderSamples( d ) {
			var list = ( d && d.samples && d.samples.length ) ? d.samples : [];
			if ( ! list.length ) {
				$samples.empty().addClass( 'hidden' );
				return;
			}
			var $ul = $( '<ul class="coywolf-seo-idfix-sample-list" />' );
			$.each( list, function ( i, s ) {
				var $li = $( '<li />' );
				var $link = s.edit ? $( '<a />' ).attr( 'href', s.edit ) : $( '<span />' );
				$li.append( $link.text( s.title ) );
				$ul.append( $li );
			} );
			$samples.empty().removeClass( 'hidden' )
				.append( $( '<p class="description" />' ).text( config.i18n.tocConvertSamples || 'Sample conversions:' ) )
				.append( $ul );
		}
		function render( d ) {
			var pct = 'done' === d.status ? 100 : ( d.total > 0 ? Math.min( 100, Math.round( ( d.posts_scanned / d.total ) * 100 ) ) : 0 );
			$progress.removeClass( 'hidden' ).attr( 'aria-valuenow', pct ).find( '.coywolf-seo-progress-bar' ).css( 'width', pct + '%' );
			if ( 'done' === d.status ) {
				$result.text( fill( d.dry_run ? ( config.i18n.tocConvertPreview || 'Preview: %BLOCKS% tables in %POSTS% posts would be replaced.' ) : ( config.i18n.tocConvertDone || 'Done: replaced %BLOCKS% tables across %POSTS% posts.' ), d ) );
			} else {
				$result.text( fill( config.i18n.tocConvertProgress || 'Scanning %SCAN% of %TOTAL% posts…', d ) );
			}
			renderSamples( d );
		}
		function failed() {
			running = false;
			buttons( false );
			$result.text( config.i18n.tocConvertError || 'The run stopped unexpectedly — reload the page and try again.' );
		}
		function loop() {
			post( { op: 'step' } ).done( function ( res ) {
				if ( ! res || ! res.success ) { failed(); return; }
				render( res.data );
				if ( 'running' === res.data.status && running ) { loop(); } else { running = false; buttons( false ); }
			} ).fail( failed );
		}
		function startRun( dry ) {
			running = true;
			buttons( true );
			$result.text( '' );
			$samples.empty().addClass( 'hidden' );
			post( { op: 'start', dry_run: dry ? 1 : 0 } ).done( function ( res ) {
				if ( ! res || ! res.success ) { failed(); return; }
				render( res.data );
				if ( 'running' === res.data.status ) { loop(); } else { running = false; buttons( false ); }
			} ).fail( failed );
		}
		$preview.on( 'click', function () { startRun( true ); } );
		$run.on( 'click', function () {
			if ( window.confirm( config.i18n.tocConvertConfirm || 'Replace every Yoast SEO table of contents with the Coywolf SEO one? This edits post content.' ) ) {
				startRun( false );
			}
		} );
		$stop.on( 'click', function () { running = false; post( { op: 'cancel' } ); buttons( false ); } );
	} );
} )( jQuery );
