/**
 * Coywolf SEO — Image Text bulk writer.
 *
 * Drives the client-side bulk loop (write alt text, title, caption, and
 * description for the Media Library) against the plugin's REST step
 * endpoints, with a live cost estimate, progress, resume, and a circuit
 * breaker for repeated API failures. No dependencies.
 */
( function () {
	'use strict';

	var cfg = window.coywolfSEOImageText || {};
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
					throw new Error( ( data && data.message ) || cfg.i18n.requestErr );
				}
				return data;
			} );
		} );
	}

	function show( el, yes ) {
		if ( el ) {
			el.classList.toggle( 'hidden', ! yes );
		}
	}

	function setProgress( box, done, total, text ) {
		if ( ! box ) {
			return;
		}
		var bar = box.querySelector( '.coywolf-seo-it-progress-bar' );
		var label = box.querySelector( '.coywolf-seo-it-progress-text' );
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		if ( bar ) {
			bar.style.width = pct + '%';
		}
		box.setAttribute( 'aria-valuenow', pct );
		if ( label ) {
			label.textContent = text;
		}
	}

	function fillErrors( box, errors, failed ) {
		if ( ! box ) {
			return;
		}
		var list = box.querySelector( 'ul' );
		var summary = box.querySelector( 'summary' );
		if ( ! errors || ! errors.length ) {
			show( box, false );
			return;
		}
		summary.textContent = failed + ' ' + cfg.i18n.errorsOf;
		list.textContent = '';
		errors.forEach( function ( err ) {
			var li = document.createElement( 'li' );
			li.textContent = ( err.name || '#' + err.id ) + ' — ' + err.message;
			list.appendChild( li );
		} );
		show( box, true );
	}

	/* ------------------------------------------------------------------ *
	 * Image Text: bulk loop
	 * ------------------------------------------------------------------ */
	var aiPanel = document.getElementById( 'coywolf-seo-it-ai' );
	if ( aiPanel ) {
		var aiProgress = document.getElementById( 'coywolf-seo-it-ai-progress' );
		var aiCurrent = document.getElementById( 'coywolf-seo-it-ai-current' );
		var aiMessage = document.getElementById( 'coywolf-seo-it-ai-message' );
		var aiErrors = document.getElementById( 'coywolf-seo-it-ai-errors' );
		var aiStart = document.getElementById( 'coywolf-seo-it-ai-start' );
		var aiReanalyze = document.getElementById( 'coywolf-seo-it-ai-reanalyze' );
		var aiStop = document.getElementById( 'coywolf-seo-it-ai-stop' );
		var aiResume = document.getElementById( 'coywolf-seo-it-ai-resume' );
		var aiModel = document.getElementById( 'coywolf-seo-it-ai-model' );
		var aiEstimate = document.getElementById( 'coywolf-seo-it-ai-estimate' );
		var aiCancelled = false;

		/* ---- Cost estimator -------------------------------------------- */
		var aiFieldInputs = function () {
			return aiPanel.querySelectorAll( 'input[name="coywolf-seo-it-ai-field"]' );
		};
		var aiSelection = function () {
			var fields = [];
			aiFieldInputs().forEach( function ( box ) {
				if ( box.checked ) {
					fields.push( box.value );
				}
			} );
			var pdfsBox = document.getElementById( 'coywolf-seo-it-ai-pdfs' );
			var propBox = document.getElementById( 'coywolf-seo-it-ai-propagate' );
			return {
				fields: fields,
				overwrite: document.getElementById( 'coywolf-seo-it-ai-overwrite' ).checked,
				include_pdfs: !! ( pdfsBox && pdfsBox.checked ),
				propagate_captions: !! ( propBox && propBox.checked && ! propBox.disabled ),
			};
		};
		// Propagation only applies when a per-block field (alt text or
		// caption) is being written; disable (and clear) the option otherwise
		// so the UI can't promise something the run won't do.
		var propagatableSelected = function () {
			var on = false;
			aiFieldInputs().forEach( function ( box ) {
				if ( ( 'alt_text' === box.value || 'caption' === box.value ) && box.checked ) {
					on = true;
				}
			} );
			return on;
		};
		var syncPropagate = function () {
			var propBox = document.getElementById( 'coywolf-seo-it-ai-propagate' );
			if ( ! propBox || propBox.dataset.runDisabled ) {
				return;
			}
			propBox.disabled = ! propagatableSelected();
			if ( propBox.disabled ) {
				propBox.checked = false;
			}
		};
		var formatUSD = function ( value, decimals ) {
			return '$' + value.toFixed( decimals );
		};
		// Placeholder substitution that replaces every occurrence and never
		// treats '$' in the inserted value as special.
		var fmt = function ( template, replacements ) {
			var out = template;
			Object.keys( replacements ).forEach( function ( token ) {
				out = out.split( token ).join( replacements[ token ] );
			} );
			return out;
		};
		var aiCount = aiEstimate ? parseInt( aiEstimate.getAttribute( 'data-count' ), 10 ) || 0 : 0;
		var aiCountText = aiEstimate ? aiEstimate.getAttribute( 'data-count-text' ) || String( aiCount ) : '';
		var aiCountAll = aiEstimate ? parseInt( aiEstimate.getAttribute( 'data-count-all' ), 10 ) || 0 : 0;
		var aiCountAllText = aiEstimate ? aiEstimate.getAttribute( 'data-count-all-text' ) || String( aiCountAll ) : '';

		// Per-file cost for the selected model, or null when the model has no
		// pricing data.
		var aiPerFile = function () {
			var option = aiModel ? aiModel.options[ aiModel.selectedIndex ] : null;
			var inPrice = option ? parseFloat( option.getAttribute( 'data-in' ) ) : NaN;
			var outPrice = option ? parseFloat( option.getAttribute( 'data-out' ) ) : NaN;
			if ( isNaN( inPrice ) || isNaN( outPrice ) ) {
				return null;
			}
			return (
				( cfg.estTokens.in / 1000000 ) * inPrice +
				( cfg.estTokens.out / 1000000 ) * outPrice
			);
		};
		var aiTotalText = function ( perFile, count ) {
			var total = perFile * count;
			return total < 0.01 ? '<$0.01' : formatUSD( total, 2 );
		};

		var renderEstimate = function () {
			if ( ! aiEstimate || ! aiModel ) {
				return;
			}
			var emptyEl = aiEstimate.querySelector( '.coywolf-seo-it-estimate-empty' );
			var allEl = aiEstimate.querySelector( '.coywolf-seo-it-estimate-all' );
			var noteEl = aiEstimate.querySelector( '.coywolf-seo-it-estimate-note' );
			if ( 0 === aiSelection().fields.length ) {
				emptyEl.textContent = cfg.i18n.estNoFields;
				allEl.textContent = '';
				noteEl.textContent = '';
				return;
			}
			var perFile = aiPerFile();

			// Line 1 — the unanalyzed set (Start, fill empty fields).
			if ( 0 === aiCount ) {
				emptyEl.textContent = cfg.i18n.estNothing;
			} else if ( null === perFile ) {
				emptyEl.textContent = fmt( cfg.i18n.estEmptyUnknown, { '%s': aiCountText } );
			} else {
				emptyEl.textContent = fmt( cfg.i18n.estEmpty, {
					'%1$s': aiCountText,
					'%2$s': aiTotalText( perFile, aiCount ),
				} );
			}

			// Line 2 — re-analyze all images.
			if ( 0 === aiCountAll ) {
				allEl.textContent = '';
			} else if ( null === perFile ) {
				allEl.textContent = fmt( cfg.i18n.estAllUnknown, { '%s': aiCountAllText } );
			} else {
				allEl.textContent = fmt( cfg.i18n.estAll, {
					'%1$s': aiCountAllText,
					'%2$s': aiTotalText( perFile, aiCountAll ),
				} );
			}

			noteEl.textContent = cfg.i18n.estNote;
		};

		var estimateTimer = null;
		var estimateGeneration = 0;
		var refreshCount = function () {
			window.clearTimeout( estimateTimer );
			estimateTimer = window.setTimeout( function () {
				var selection = aiSelection();
				if ( 0 === selection.fields.length ) {
					renderEstimate();
					return;
				}
				var generation = ++estimateGeneration;
				api( '/image-text/estimate', selection ).then( function ( data ) {
					if ( generation !== estimateGeneration ) {
						return; // A newer request superseded this one.
					}
					aiCount = parseInt( data.count, 10 ) || 0;
					aiCountText = data.count_text || String( aiCount );
					aiCountAll = parseInt( data.count_all, 10 ) || 0;
					aiCountAllText = data.count_all_text || String( aiCountAll );
					renderEstimate();
				} ).catch( function () {
					// Leave the previous estimate in place; the run itself
					// recomputes its total server-side.
				} );
			}, 350 );
		};

		// While a run is active (or resumable), the selection controls don't
		// apply to it — disable them and hide the fresh-run estimate so the
		// UI can't contradict what Resume/the running batch will actually do.
		var aiControls = function ( enabled ) {
			aiFieldInputs().forEach( function ( box ) {
				box.disabled = ! enabled;
			} );
			var overwriteBox = document.getElementById( 'coywolf-seo-it-ai-overwrite' );
			if ( overwriteBox ) {
				overwriteBox.disabled = ! enabled;
			}
			var pdfsBox = document.getElementById( 'coywolf-seo-it-ai-pdfs' );
			if ( pdfsBox ) {
				pdfsBox.disabled = ! enabled;
			}
			var propBox = document.getElementById( 'coywolf-seo-it-ai-propagate' );
			if ( propBox ) {
				if ( enabled ) {
					delete propBox.dataset.runDisabled;
					syncPropagate();
				} else {
					propBox.dataset.runDisabled = '1';
					propBox.disabled = true;
				}
			}
			if ( aiModel ) {
				aiModel.disabled = ! enabled;
			}
			show( aiEstimate, enabled );
		};

		if ( aiModel ) {
			aiModel.addEventListener( 'change', renderEstimate );
		}
		aiFieldInputs().forEach( function ( box ) {
			box.addEventListener( 'change', refreshCount );
			box.addEventListener( 'change', syncPropagate );
		} );
		syncPropagate();
		var aiOverwriteBox = document.getElementById( 'coywolf-seo-it-ai-overwrite' );
		if ( aiOverwriteBox ) {
			aiOverwriteBox.addEventListener( 'change', refreshCount );
		}
		var aiPdfsBox = document.getElementById( 'coywolf-seo-it-ai-pdfs' );
		if ( aiPdfsBox ) {
			aiPdfsBox.addEventListener( 'change', refreshCount );
		}
		renderEstimate();

		var aiButtons = function ( running, error ) {
			show( aiStart, ! running && ! error );
			show( aiReanalyze, ! running && ! error );
			show( aiStop, running );
			show( aiResume, ! running && error );
			aiControls( ! running && ! error );
		};

		var renderAI = function ( state ) {
			var text = fmt( cfg.i18n.progress, { '%1$s': state.done, '%2$s': state.total } );
			if ( state.failed > 0 ) {
				text += ' (' + state.failed + ' ' + cfg.i18n.failed + ')';
			}
			if ( state.posts_updated > 0 ) {
				text += ' · ' + fmt( cfg.i18n.postsUpdated, { '%s': state.posts_updated } );
			}
			setProgress( aiProgress, state.done, state.total, text );
			if ( state.current ) {
				aiCurrent.textContent = fmt( cfg.i18n.analyzingFile, { '%s': state.current } );
				show( aiCurrent, true );
			}
			fillErrors( aiErrors, state.errors, state.failed );
			if ( 'error' === state.status && state.message ) {
				aiMessage.querySelector( 'p' ).textContent = state.message;
				show( aiMessage, true );
			} else {
				show( aiMessage, false );
			}
		};

		var loopAI = function () {
			api( '/image-text/step' ).then( function ( state ) {
				renderAI( state );
				if ( 'running' === state.status && ! aiCancelled ) {
					loopAI();
				} else {
					aiButtons( false, 'error' === state.status );
					if ( 'done' === state.status ) {
						setProgress( aiProgress, 1, 1, cfg.i18n.doneReload );
						window.setTimeout( function () {
							window.location.reload();
						}, 1200 );
					}
				}
			} ).catch( function ( err ) {
				window.alert( err.message );
				aiButtons( false, true );
			} );
		};

		var startAI = function ( resume, force ) {
			var selection = aiSelection();
			window.clearTimeout( estimateTimer );
			estimateGeneration++; // Invalidate any in-flight estimate response.
			aiCancelled = false;
			aiButtons( true, false );
			show( aiProgress, true );
			setProgress( aiProgress, 0, 1, cfg.i18n.starting );
			api( '/image-text/start', {
				fields: selection.fields,
				// "Re-analyze all" forces overwrite; otherwise honor the checkbox.
				overwrite: force ? true : selection.overwrite,
				include_pdfs: selection.include_pdfs,
				propagate_captions: selection.propagate_captions,
				resume: !! resume,
				model: aiModel ? aiModel.value : '',
			} )
				.then( loopAI )
				.catch( function ( err ) {
					window.alert( err.message );
					aiButtons( false, false );
				} );
		};

		if ( aiStart ) {
			aiStart.addEventListener( 'click', function () {
				startAI( false, false );
			} );
		}
		if ( aiReanalyze ) {
			aiReanalyze.addEventListener( 'click', function () {
				if ( window.confirm( cfg.i18n.confirmReanalyze ) ) {
					startAI( false, true );
				}
			} );
		}
		if ( aiResume ) {
			aiResume.addEventListener( 'click', function () {
				startAI( true );
			} );
		}
		if ( aiStop ) {
			aiStop.addEventListener( 'click', function () {
				aiCancelled = true;
				api( '/image-text/cancel' ).then( function () {
					window.location.reload();
				} );
			} );
		}

		var aiStatus = aiPanel.getAttribute( 'data-status' );
		if ( 'running' === aiStatus ) {
			aiButtons( true, false );
			show( aiProgress, true );
			loopAI();
		} else if ( 'error' === aiStatus ) {
			aiButtons( false, true );
			api( '/image-text/status' ).then( renderAI );
		}
	}
} )();
