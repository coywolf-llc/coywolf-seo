/**
 * Coywolf Robots.txt Manager — admin editor.
 *
 * Drives the rule editor: reveals fields once a rule type is chosen, builds
 * the type-specific path inputs, auto-fills name/description, powers the bot
 * category select-all + custom-bot controls, and runs the testing tool.
 */
( function () {
	'use strict';

	var cfg = window.CoywolfSEORobots || {};
	var types = cfg.types || {};
	var i18n = cfg.i18n || {};

	// Feather-style icons (same set the Coywolf Code Block Enhancer uses), so the
	// Robots.txt copy button matches that plugin's copy button.
	var copyIcon =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
	var checkIcon =
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"></polyline></svg>';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'coywolf-seo-robots-form' );
		if ( form ) {
			initEditor( form );
		}
		initSitemapSettings();
		initRulesTable();
		initRobotsCopy();
	} );

	/* --------------------------------------------------------------- *
	 * Robots.txt page: copy-to-clipboard button
	 * --------------------------------------------------------------- */

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		// Fallback for non-HTTPS / older browsers.
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			} finally {
				document.body.removeChild( ta );
			}
		} );
	}

	// Wrap the robots.txt textarea and pin a copy button to its top-right. On
	// copy the icon swaps to a check and a "Copied" tooltip fades in to the LEFT
	// of the button, reverting after a moment — mirroring the Code Block Enhancer.
	function initRobotsCopy() {
		var textarea = document.getElementById( 'coywolf-seo-robots-robots' );
		if ( ! textarea ) {
			return;
		}

		var wrapper = document.createElement( 'div' );
		wrapper.className = 'coywolf-seo-robots-copy-wrap';
		textarea.parentNode.insertBefore( wrapper, textarea );
		wrapper.appendChild( textarea );

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'coywolf-seo-robots-copy-btn';
		button.setAttribute( 'aria-label', i18n.copy || 'Copy to clipboard' );
		button.innerHTML = copyIcon;

		var feedback = document.createElement( 'span' );
		feedback.className = 'coywolf-seo-robots-copy-feedback';
		feedback.setAttribute( 'role', 'status' );
		feedback.setAttribute( 'aria-live', 'polite' );

		wrapper.appendChild( button );
		wrapper.appendChild( feedback );

		var timer;
		button.addEventListener( 'click', function () {
			copyText( textarea.value ).then( function () {
				button.innerHTML = checkIcon;
				feedback.textContent = i18n.copied || 'Copied';
				feedback.classList.add( 'is-visible' );
				clearTimeout( timer );
				timer = setTimeout( function () {
					button.innerHTML = copyIcon;
					feedback.classList.remove( 'is-visible' );
					feedback.textContent = '';
				}, 2000 );
			} ).catch( function () {
				feedback.textContent = i18n.copyFailed || 'Copy failed';
				feedback.classList.add( 'is-visible' );
				clearTimeout( timer );
				timer = setTimeout( function () {
					feedback.classList.remove( 'is-visible' );
					feedback.textContent = '';
				}, 2000 );
			} );
		} );
	}

	/* --------------------------------------------------------------- *
	 * Rules table: select-all checkbox
	 * --------------------------------------------------------------- */

	function initRulesTable() {
		var all = document.getElementById( 'coywolf-seo-robots-cb-all' );
		if ( ! all ) {
			return;
		}
		all.addEventListener( 'change', function () {
			var boxes = document.querySelectorAll( 'input[name="rule_ids[]"]' );
			Array.prototype.forEach.call( boxes, function ( b ) {
				b.checked = all.checked;
			} );
		} );
	}

	/* --------------------------------------------------------------- *
	 * Settings: XML Sitemaps
	 * --------------------------------------------------------------- */

	// Wire the sitemap URL adder on the Settings page and hide the whole list
	// while "Exclude XML sitemap link(s)" is checked.
	function initSitemapSettings() {
		var wrap = document.getElementById( 'coywolf-seo-robots-sitemaps-wrap' );
		if ( ! wrap ) {
			return;
		}
		initSitemaps();

		var omit = document.getElementById( 'coywolf-seo-robots-omit-sitemaps' );
		if ( omit ) {
			var sync = function () {
				wrap.style.display = omit.checked ? 'none' : '';
			};
			omit.addEventListener( 'change', sync );
			sync();
		}
	}

	function initEditor( form ) {
		var typeSelect = document.getElementById( 'coywolf-seo-robots-type' ); // Rule Path.
		var fields = document.getElementById( 'coywolf-seo-robots-fields' );
		var pathRow = document.getElementById( 'coywolf-seo-robots-pathrow' );
		var pathWrap = document.getElementById( 'coywolf-seo-robots-path-fields' );
		var nameInput = document.getElementById( 'coywolf-seo-robots-name' );
		var descInput = document.getElementById( 'coywolf-seo-robots-description' );
		var allCb = document.getElementById( 'coywolf-seo-robots-all' );
		var preCancel = document.getElementById( 'coywolf-seo-robots-precancel' );

		var rule = {};
		try {
			rule = JSON.parse( form.getAttribute( 'data-rule' ) || '{}' );
		} catch ( e ) {
			rule = {};
		}

		// Wire the bot controls and the "All robots" lock up front so applyType()
		// (called below, including on first load) can drive them.
		initBots( form );
		var syncAllRobots = initAllRobotsLock( form, allCb );

		// Apply a Rule Path: reveal the rest of the form, (re)build the path
		// inputs, and fill the name/description. The Rule Type (Disallow/Allow)
		// is a plain select that needs no dynamic behaviour. When `source` is
		// provided we are initialising an existing rule and keep its stored
		// values; otherwise we use the path type's defaults (a fresh choice).
		function applyType( type, source ) {
			if ( ! type || ! types[ type ] ) {
				fields.style.display = 'none';
				// No Rule Path yet: show the standalone Cancel back to the list.
				if ( preCancel ) {
					preCancel.style.display = '';
				}
				return;
			}
			fields.style.display = '';
			// The editor (with its own Cancel) is now visible.
			if ( preCancel ) {
				preCancel.style.display = 'none';
			}
			var def = types[ type ];

			nameInput.value = source && source.name ? source.name : def.name;
			descInput.value = source && source.description ? source.description : ( def.description || '' );

			buildPathFields( def, source );

			if ( allCb ) {
				// For a whole-site rule, don't pre-check "All robots" — make the
				// user consciously choose who it applies to.
				if ( ! source && type === 'entire_site' ) {
					allCb.checked = false;
				}
				if ( syncAllRobots ) {
					syncAllRobots();
				}
			}
		}

		function buildPathFields( def, source ) {
			pathWrap.innerHTML = '';
			var defFields = def.fields || [];
			if ( ! defFields.length ) {
				pathRow.style.display = 'none';
				return;
			}
			pathRow.style.display = '';

			defFields.forEach( function ( f ) {
				var wrap = document.createElement( 'p' );
				wrap.className = 'coywolf-seo-robots-field';
				var val = source && typeof source[ f.key ] !== 'undefined' ? source[ f.key ] : '';

				if ( f.type === 'checkbox' ) {
					var lbl = document.createElement( 'label' );
					var cb = document.createElement( 'input' );
					cb.type = 'checkbox';
					cb.name = f.key;
					cb.value = '1';
					if ( val ) {
						cb.checked = true;
					}
					lbl.appendChild( cb );
					lbl.appendChild( document.createTextNode( ' ' + f.label ) );
					wrap.appendChild( lbl );
				} else if ( f.type === 'select' ) {
					wrap.appendChild( labelEl( f.label ) );
					var sel = document.createElement( 'select' );
					sel.name = f.key;
					Object.keys( f.options || {} ).forEach( function ( ov ) {
						var opt = document.createElement( 'option' );
						opt.value = ov;
						opt.textContent = f.options[ ov ];
						if ( String( val ) === ov ) {
							opt.selected = true;
						}
						sel.appendChild( opt );
					} );
					wrap.appendChild( sel );
				} else {
					wrap.appendChild( labelEl( f.label ) );
					var input = document.createElement( 'input' );
					input.type = 'text';
					input.name = f.key;
					input.className = 'regular-text code';
					input.value = val;
					if ( f.placeholder ) {
						input.placeholder = f.placeholder;
					}
					wrap.appendChild( input );
				}
				pathWrap.appendChild( wrap );
			} );
		}

		function labelEl( text ) {
			var l = document.createElement( 'label' );
			l.className = 'coywolf-seo-robots-field-label';
			l.textContent = text;
			return l;
		}

		typeSelect.addEventListener( 'change', function () {
			applyType( typeSelect.value, null );
		} );

		// Initialise from the existing rule (or hidden if no type yet).
		applyType( rule.type || '', rule.type ? rule : null );

		initTester( form, typeSelect );
	}

	/* --------------------------------------------------------------- *
	 * Bot selection
	 * --------------------------------------------------------------- */

	function initBots( form ) {
		var cats = form.querySelectorAll( '.coywolf-seo-robots-cat' );
		Array.prototype.forEach.call( cats, function ( cat ) {
			var catAll = cat.querySelector( '.coywolf-seo-robots-cat-all' );
			var bots = cat.querySelectorAll( '.coywolf-seo-robots-bot' );

			function syncCat() {
				var total = bots.length;
				var on = 0;
				Array.prototype.forEach.call( bots, function ( b ) {
					if ( b.checked ) {
						on++;
					}
				} );
				if ( catAll ) {
					catAll.checked = total > 0 && on === total;
					catAll.indeterminate = on > 0 && on < total;
				}
			}

			function setAll( state ) {
				Array.prototype.forEach.call( bots, function ( b ) {
					b.checked = state;
				} );
				syncCat();
			}

			if ( catAll ) {
				catAll.addEventListener( 'change', function () {
					setAll( catAll.checked );
				} );
			}
			Array.prototype.forEach.call( bots, function ( b ) {
				b.addEventListener( 'change', syncCat );
			} );

			var selAll = cat.querySelector( '.coywolf-seo-robots-sel-all' );
			var selNone = cat.querySelector( '.coywolf-seo-robots-sel-none' );
			if ( selAll ) {
				selAll.addEventListener( 'click', function () {
					setAll( true );
				} );
			}
			if ( selNone ) {
				selNone.addEventListener( 'click', function () {
					setAll( false );
				} );
			}

			syncCat();
		} );

		// Custom bots.
		var addBtn = document.getElementById( 'coywolf-seo-robots-custom-add' );
		var input = document.getElementById( 'coywolf-seo-robots-custom-input' );
		var list = document.getElementById( 'coywolf-seo-robots-custom-list' );

		function addCustom() {
			var token = ( input.value || '' ).trim();
			if ( ! token ) {
				window.alert( i18n.addBotEmpty || 'Enter a user-agent token first.' );
				return;
			}
			var li = document.createElement( 'li' );
			var code = document.createElement( 'code' );
			code.textContent = token;
			var hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = 'agents[]';
			hidden.value = token;
			var rm = document.createElement( 'button' );
			rm.type = 'button';
			rm.className = 'button-link coywolf-seo-robots-custom-remove';
			rm.textContent = i18n.remove || 'Remove';
			rm.addEventListener( 'click', function () {
				li.parentNode.removeChild( li );
			} );
			li.appendChild( code );
			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( hidden );
			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( rm );
			list.appendChild( li );
			input.value = '';
			input.focus();
		}

		if ( addBtn && input && list ) {
			addBtn.addEventListener( 'click', addCustom );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					addCustom();
				}
			} );
		}

		// Existing custom-bot remove buttons (rendered server-side).
		var existing = form.querySelectorAll( '.coywolf-seo-robots-custom-remove' );
		Array.prototype.forEach.call( existing, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var li = btn.closest( 'li' );
				if ( li ) {
					li.parentNode.removeChild( li );
				}
			} );
		} );
	}

	/* --------------------------------------------------------------- *
	 * Sitemap URLs (Settings → XML sitemaps)
	 * --------------------------------------------------------------- */

	// Add / list / remove sitemap URLs, mirroring the custom-bot adder. Each
	// added URL is backed by a hidden sitemaps[] input the server reads.
	function initSitemaps() {
		var addBtn = document.getElementById( 'coywolf-seo-robots-sitemap-add' );
		var input = document.getElementById( 'coywolf-seo-robots-sitemap-input' );
		var list = document.getElementById( 'coywolf-seo-robots-sitemap-list' );
		if ( ! addBtn || ! input || ! list ) {
			return;
		}

		function addSitemap() {
			var url = ( input.value || '' ).trim();
			if ( ! url ) {
				window.alert( i18n.addSitemapEmpty || 'Enter a sitemap URL first.' );
				return;
			}
			var li = document.createElement( 'li' );
			var code = document.createElement( 'code' );
			code.textContent = url;
			var hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = 'sitemaps[]';
			hidden.value = url;
			var rm = document.createElement( 'button' );
			rm.type = 'button';
			rm.className = 'button-link coywolf-seo-robots-sitemap-remove';
			rm.textContent = i18n.remove || 'Remove';
			rm.addEventListener( 'click', function () {
				li.parentNode.removeChild( li );
			} );
			li.appendChild( code );
			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( hidden );
			li.appendChild( document.createTextNode( ' ' ) );
			li.appendChild( rm );
			list.appendChild( li );
			input.value = '';
			input.focus();
		}

		addBtn.addEventListener( 'click', addSitemap );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				addSitemap();
			}
		} );

		// Existing sitemap remove buttons (rendered server-side).
		var existing = list.querySelectorAll( '.coywolf-seo-robots-sitemap-remove' );
		Array.prototype.forEach.call( existing, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var li = btn.closest( 'li' );
				if ( li ) {
					li.parentNode.removeChild( li );
				}
			} );
		} );
	}

	/* --------------------------------------------------------------- *
	 * "All robots (*)" lock
	 * --------------------------------------------------------------- */

	// When "All robots (*)" is checked the rule applies to every crawler, so
	// the Custom bots adder and the Bot categories are contradictory: disable
	// (and dim) them. Disabling the hidden agents[] inputs also keeps them out
	// of the submitted form, so the saved rule carries only "*". Returns a
	// sync() the editor can call after programmatically toggling the checkbox.
	function initAllRobotsLock( form, allCb ) {
		var botsWrap = form.querySelector( '.coywolf-seo-robots-bots' );
		if ( ! allCb || ! botsWrap ) {
			return null;
		}

		function sync() {
			var lock = allCb.checked;
			var controls = botsWrap.querySelectorAll(
				'.coywolf-seo-robots-custom input, .coywolf-seo-robots-custom button, .coywolf-seo-robots-cat input, .coywolf-seo-robots-cat button'
			);
			Array.prototype.forEach.call( controls, function ( el ) {
				el.disabled = lock;
			} );
			botsWrap.classList.toggle( 'coywolf-seo-robots-bots-locked', lock );
		}

		allCb.addEventListener( 'change', sync );
		sync();
		return sync;
	}

	/* --------------------------------------------------------------- *
	 * Testing tool
	 * --------------------------------------------------------------- */

	function initTester( form, typeSelect ) {
		var btn = document.getElementById( 'coywolf-seo-robots-test-btn' );
		var urlInput = document.getElementById( 'coywolf-seo-robots-test-url' );
		var out = document.getElementById( 'coywolf-seo-robots-test-result' );
		if ( ! btn || ! urlInput || ! out ) {
			return;
		}

		function fieldVal( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			if ( ! el ) {
				return '';
			}
			if ( el.type === 'checkbox' ) {
				return el.checked ? '1' : '';
			}
			return el.value;
		}

		btn.addEventListener( 'click', function () {
			var url = ( urlInput.value || '' ).trim();
			if ( ! url ) {
				showResult( 'none', i18n.enterUrl || 'Enter a URL or path to test.', '' );
				return;
			}

			out.style.display = '';
			out.className = 'coywolf-seo-robots-test-result coywolf-seo-robots-test-pending';
			out.textContent = i18n.testing || 'Testing…';

			var body = new URLSearchParams();
			body.append( 'action', 'coywolf_seo_robots_test' );
			body.append( 'nonce', cfg.nonce || '' );
			body.append( 'url', url );
			body.append( 'type', typeSelect.value );
			body.append( 'path', fieldVal( 'path' ) );
			body.append( 'ext', fieldVal( 'ext' ) );
			body.append( 'allow', fieldVal( 'allow' ) );
			body.append( 'strict', fieldVal( 'strict' ) );
			body.append( 'directive', fieldVal( 'directive' ) );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						var msg = res && res.data && res.data.message ? res.data.message : ( i18n.error || 'Error.' );
						showResult( 'none', msg, '' );
						return;
					}
					var d = res.data;
					var detail = ( d.directives && d.directives.length ? d.directives.join( '  ·  ' ) : '' );
					if ( d.pattern ) {
						detail += ( detail ? '  —  ' : '' ) + 'matched: ' + d.pattern;
					}
					if ( d.effect === 'disallow' ) {
						showResult( 'blocked', i18n.blocked || 'Blocked.', detail );
					} else if ( d.effect === 'allow' ) {
						showResult( 'allowed', i18n.allowed || 'Allowed.', detail );
					} else {
						showResult( 'none', i18n.noMatch || 'No match.', detail );
					}
				} )
				.catch( function () {
					showResult( 'none', i18n.error || 'Error.', '' );
				} );
		} );

		function showResult( kind, msg, detail ) {
			out.style.display = '';
			out.className = 'coywolf-seo-robots-test-result coywolf-seo-robots-test-' + kind;
			out.textContent = msg;
			if ( detail ) {
				var small = document.createElement( 'span' );
				small.className = 'coywolf-seo-robots-test-detail';
				small.textContent = detail;
				out.appendChild( document.createElement( 'br' ) );
				out.appendChild( small );
			}
		}
	}
} )();
