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
			$row.append( $( '<td class="coywolf-seo-drag-cell"></td>' ).append( $( '<span class="coywolf-seo-drag-handle dashicons dashicons-sort" aria-hidden="true"></span>' ) ) );
			$row.append( $( '<td></td>' ).append( $select ) );
			$row.append( buildValueCell( 'coywolf_seo[' + field + '][' + index + '][value]', prop ) );
			$row.append(
				$( '<td></td>' ).append(
					$( '<button/>', {
						type: 'button',
						class: 'button coywolf-seo-remove-row',
						'aria-label': config.i18n.removeProperty || 'Remove property',
						text: config.i18n.remove || 'Remove'
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

		// Drag-to-reorder property rows (mouse/touch enhancement). The saved
		// order is read from the DOM at submit time, so no index rewriting is
		// needed when rows move.
		if ( $.fn.sortable ) {
			$( '#coywolf-seo-org-props tbody, #coywolf-seo-author-props tbody' ).sortable( {
				items: 'tr.coywolf-seo-prop-row',
				handle: '.coywolf-seo-drag-handle',
				axis: 'y',
				containment: 'parent',
				tolerance: 'pointer',
				placeholder: 'coywolf-seo-sortable-placeholder',
				forcePlaceholderSize: true,
				helper: function ( event, $tr ) {
					// Lock cell widths so the row keeps its shape while dragging.
					var $originals = $tr.children();
					var $helper = $tr.clone();
					$helper.children().each( function ( i ) {
						$( this ).width( $originals.eq( i ).width() );
					} );
					return $helper;
				}
			} );
		}

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

		// News sitemap: the Include and Categories rows only apply when the
		// News sitemap itself is enabled.
		function syncNewsRows() {
			$( '.coywolf-seo-news-row' ).toggle( $( '#coywolf-seo-news-enabled' ).prop( 'checked' ) );
		}
		$( '#coywolf-seo-news-enabled' ).on( 'change', syncNewsRows );
		if ( $( '#coywolf-seo-news-enabled' ).length ) {
			syncNewsRows();
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
			$bulkArea.html( html ).attr( 'data-status', status );
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
			$( '#coywolf-seo-ai-model' ).on( 'change', function () {
				loadEstimate( $( this ).val(), true );
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
			var overlay = document.createElement( 'div' );
			overlay.className = 'coywolf-seo-modal';
			var box = document.createElement( 'div' );
			box.className = 'coywolf-seo-modal-box';
			var h2 = document.createElement( 'h2' );
			h2.textContent = t.title || 'Restore robots.txt?';
			var p = document.createElement( 'p' );
			p.textContent = t.message || '';
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
			var keepBtn = button( t.keep || 'Keep', '' );
			var cancelBtn = button( t.cancel || 'Cancel', 'button-link' );
			function close() {
				if ( overlay.parentNode ) {
					overlay.parentNode.removeChild( overlay );
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

		// Settings page: prompt the moment the user turns the feature off (only
		// when a physical robots.txt is being managed). The choice rides in a
		// hidden field that save_settings() reads.
		var toggle = rr.toggleId ? document.getElementById( rr.toggleId ) : null;
		var hidden = rr.hiddenId ? document.getElementById( rr.hiddenId ) : null;
		if ( toggle && hidden ) {
			toggle.addEventListener( 'change', function () {
				if ( ! rr.show || ! toggle.checked || toggle.defaultChecked ) {
					hidden.value = '';
					return;
				}
				showModal(
					function () { hidden.value = 'restore'; },
					function () { hidden.value = 'keep'; },
					function () { toggle.checked = false; hidden.value = ''; }
				);
			} );
		}
	} );
} )( jQuery );
