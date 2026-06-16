/* global CoywolfSEOLM */
( function () {
	'use strict';

	var cfg = window.CoywolfSEOLM || {};
	var i18n = cfg.i18n || {};
	var perPage = parseInt( cfg.perPage, 10 ) || 20;
	var pageKind = cfg.page || 'all'; // 'all' | 'edit'

	var URL_MAX = 90; // Visible URL length before truncation.

	var pollTimer = null;
	var els = {};

	// All Links table state.
	var currentLinks = [];
	var linkById = {};      // id -> link, for O(1) lookups
	var lastFiltered = [];  // last applySortFilter() result, reused by handlers
	var analyzed = !! cfg.analyzed;
	var selection = {}; // link id -> true
	var sortKey = null; // 'code' | 'type' | null
	var sortDir = null; // 'asc' | 'desc'
	var filterText = '';
	var SEARCH_KEY = 'coywolfLmSearch'; // sessionStorage key for the search term
	var codeFilter = '';
	var typeFilter = '';
	var currentPage = 1;
	var lastSummary = '';
	var viewMode = 'all'; // 'all' (non-ignored) | 'ignored'
	var bulkPlaceholder = 'Bulk actions'; // captured from the localized markup at wire time

	function $( id ) {
		return document.getElementById( id );
	}

	function ready( fn ) {
		if ( 'loading' !== document.readyState ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	/** POST to admin-ajax as form-encoded data; resolve with parsed JSON. */
	function request( action, params ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		if ( params ) {
			Object.keys( params ).forEach( function ( k ) {
				body.set( k, params[ k ] );
			} );
		}
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function show( el ) { if ( el ) { el.style.display = ''; } }
	function hide( el ) { if ( el ) { el.style.display = 'none'; } }

	function format( str, args ) {
		var i = 0;
		return String( str ).replace( /%(\d+)\$s|%s/g, function ( match, idx ) {
			if ( idx ) { return args[ parseInt( idx, 10 ) - 1 ]; }
			return args[ i++ ];
		} );
	}

	function escapeHtml( str ) {
		var s = null === str || undefined === str ? '' : String( str );
		return s
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// Only allow http(s) and root-relative admin URLs in href.
	function safeHref( url ) {
		var s = null === url || undefined === url ? '' : String( url );
		if ( /^https?:\/\//i.test( s ) || /^\//.test( s ) ) { return s; }
		return '';
	}

	function getDomain( url ) {
		try {
			return new URL( url ).hostname.toLowerCase().replace( /^www\./, '' );
		} catch ( e ) {
			return '';
		}
	}

	// Anchor opening in a new tab. Long URLs are truncated, and the title
	// (full URL on hover) is added only when the text was actually truncated.
	function urlAnchor( url, cls ) {
		var truncated = url.length > URL_MAX;
		var text = truncated ? url.slice( 0, URL_MAX ) + '…' : url;
		var title = truncated ? ' title="' + escapeHtml( url ) + '"' : '';
		return '<a class="' + cls + '" href="' + escapeHtml( safeHref( url ) ) +
			'" target="_blank" rel="noopener nofollow"' + title + '>' + escapeHtml( text ) +
			' <span class="dashicons dashicons-external coywolf-seo-lm-extlink" aria-hidden="true"></span></a>';
	}

	/* ---------------------------------------------------------------------
	 * Response badge helpers (inventory rows).
	 * ------------------------------------------------------------------- */

	function displayCode( r ) {
		if ( r.redirect && ( r.code || 0 ) < 400 && r.redirectCode ) {
			return r.redirectCode;
		}
		return r.code || 0;
	}

	function linkBadge( r ) {
		if ( ! r.checked ) {
			return { cls: 'coywolf-seo-lm-code--none', text: '—', title: i18n.pending || '' };
		}
		var code = r.code || 0;
		if ( r.blocked ) {
			return {
				cls: 'coywolf-seo-lm-code--blocked',
				text: r.short || 'Blocked',
				title: r.label || r.short || 'Blocked'
			};
		}
		if ( r.redirect && code < 400 && r.redirectCode ) {
			return {
				cls: 'coywolf-seo-lm-code--redirect',
				text: String( r.redirectCode ),
				title: r.label || r.short || String( r.redirectCode )
			};
		}
		var cls;
		if ( 0 === code ) { cls = 'coywolf-seo-lm-code--err'; }
		else if ( code >= 500 ) { cls = 'coywolf-seo-lm-code--5xx'; }
		else if ( code >= 400 ) { cls = 'coywolf-seo-lm-code--4xx'; }
		else if ( code >= 300 ) { cls = 'coywolf-seo-lm-code--redirect'; }
		else { cls = 'coywolf-seo-lm-code--ok'; }
		var text = 0 === code ? i18n.noResponse : String( code );
		return { cls: cls, text: text, title: r.label || r.short || text };
	}

	/* ---------------------------------------------------------------------
	 * Pagination helper (shared between both tables).
	 * ------------------------------------------------------------------- */

	function paginate( rows, page ) {
		var total = rows.length;
		var totalPages = Math.max( 1, Math.ceil( total / perPage ) );
		if ( page < 1 ) { page = 1; }
		if ( page > totalPages ) { page = totalPages; }
		var start = ( page - 1 ) * perPage;
		return {
			page: page,
			totalPages: totalPages,
			total: total,
			rows: rows.slice( start, start + perPage )
		};
	}

	function renderPaginationNavs( containerIds, info, onChange ) {
		containerIds.forEach( function ( id ) {
			var nav = document.getElementById( id );
			if ( ! nav ) { return; }
			if ( info.total <= 0 ) { hide( nav ); return; }
			show( nav );

			nav.querySelector( '[data-role="total"]' ).textContent =
				format( i18n.nItems, [ info.total.toLocaleString() ] );
			nav.querySelector( '[data-role="total-pages"]' ).textContent = String( info.totalPages );
			var input = nav.querySelector( '[data-role="current"]' );
			if ( input && document.activeElement !== input ) {
				input.value = String( info.page );
			}

			var first = nav.querySelector( '.first-page' );
			var prev  = nav.querySelector( '.prev-page' );
			var next  = nav.querySelector( '.next-page' );
			var last  = nav.querySelector( '.last-page' );
			var atFirst = info.page <= 1;
			var atLast  = info.page >= info.totalPages;
			first.disabled = atFirst;
			prev.disabled  = atFirst;
			next.disabled  = atLast;
			last.disabled  = atLast;

			nav._onChange = onChange;
			if ( ! nav.dataset.bound ) {
				nav.dataset.bound = '1';
				nav.addEventListener( 'click', function ( e ) {
					var btn = e.target.closest( 'button[data-page]' );
					if ( ! btn || btn.disabled ) { return; }
					nav._onChange( btn.getAttribute( 'data-page' ), null );
				} );
				var inp = nav.querySelector( '[data-role="current"]' );
				if ( inp ) {
					inp.addEventListener( 'change', function () {
						var n = parseInt( inp.value, 10 );
						if ( ! isNaN( n ) ) { nav._onChange( 'goto', n ); }
					} );
					inp.addEventListener( 'keydown', function ( e ) {
						if ( 'Enter' === e.key ) {
							e.preventDefault();
							var n = parseInt( inp.value, 10 );
							if ( ! isNaN( n ) ) { nav._onChange( 'goto', n ); }
						}
					} );
				}
			}
		} );
	}

	function nextPage( which, target, page, totalPages ) {
		switch ( which ) {
			case 'first': return 1;
			case 'prev':  return Math.max( 1, page - 1 );
			case 'next':  return Math.min( totalPages, page + 1 );
			case 'last':  return totalPages;
			case 'goto':  return Math.max( 1, Math.min( totalPages, target || 1 ) );
		}
		return page;
	}

	/* ---------------------------------------------------------------------
	 * All Links: filter, sort, render.
	 * ------------------------------------------------------------------- */

	function applySortFilter( rows ) {
		var out = rows;

		if ( '' !== codeFilter ) {
			out = out.filter( function ( r ) {
				return String( displayCode( r ) ) === codeFilter;
			} );
		}

		if ( '' !== typeFilter ) {
			out = out.filter( function ( r ) {
				return r.type === typeFilter;
			} );
		}

		if ( filterText ) {
			var needle = filterText.toLowerCase();
			out = out.filter( function ( r ) {
				var hay = [ r.url, r.finalUrl, r.type, r.short, r.label ]
					.join( ' ' ).toLowerCase();
				return hay.indexOf( needle ) !== -1;
			} );
		}

		if ( sortKey && ( 'asc' === sortDir || 'desc' === sortDir ) ) {
			out = out.slice().sort( function ( a, b ) {
				var diff;
				if ( 'type' === sortKey ) {
					diff = String( a.type ).localeCompare( String( b.type ) );
				} else {
					diff = displayCode( a ) - displayCode( b );
				}
				return 'asc' === sortDir ? diff : -diff;
			} );
		}
		return out;
	}

	function setSortIndicator( th, key ) {
		if ( ! th ) { return; }
		th.classList.remove( 'sorted', 'asc', 'desc' );
		if ( sortKey === key ) {
			th.classList.add( 'sorted', sortDir );
		} else {
			th.classList.add( 'desc' );
		}
	}

	function updateSortIndicator() {
		setSortIndicator( els.thCode, 'code' );
		setSortIndicator( els.thType, 'type' );
	}

	function onSort( key ) {
		if ( sortKey === key ) {
			sortDir = 'asc' === sortDir ? 'desc' : 'asc';
		} else {
			sortKey = key;
			sortDir = 'asc';
		}
		currentPage = 1;
		renderTable();
	}

	function countCell( n, url ) {
		if ( n > 0 && url ) {
			return '<a href="' + escapeHtml( safeHref( url ) ) + '">' + escapeHtml( String( n ) ) + '</a>';
		}
		return '<span class="coywolf-seo-lm-zero">0</span>';
	}

	function reconcileSelection( rows ) {
		var present = {};
		rows.forEach( function ( r ) { present[ r.id ] = true; } );
		Object.keys( selection ).forEach( function ( k ) {
			if ( ! present[ k ] ) { delete selection[ k ]; }
		} );
	}

	function syncSelectAll( rows ) {
		if ( ! els.cbAll ) { return; }
		var total = rows.length;
		var sel = rows.filter( function ( r ) { return selection[ r.id ]; } ).length;
		els.cbAll.checked = total > 0 && sel === total;
		els.cbAll.indeterminate = sel > 0 && sel < total;
	}

	function renderRow( r ) {
		var badge = linkBadge( r );
		var domain = getDomain( r.url );
		var checked = selection[ r.id ] ? ' checked' : '';

		var urlHtml = urlAnchor( r.url, 'coywolf-seo-lm-url-main' );
		if ( r.redirect && r.finalUrl ) {
			urlHtml += '<div class="coywolf-seo-lm-redirect-to">' +
				'<span class="coywolf-seo-lm-redirect-arrow" aria-hidden="true">↳</span> ' +
				urlAnchor( r.finalUrl, 'coywolf-seo-lm-redirect-link' ) + '</div>';
		}

		var sep = ' <span aria-hidden="true">|</span> ';
		var actions = '<span class="edit"><a href="' + escapeHtml( safeHref( r.editUrl ) ) + '">' +
			escapeHtml( i18n.edit ) + '</a></span>';
		if ( 'ignored' === viewMode ) {
			// Ignored view: "Unignore" (the Restore analogue), then the same
			// destructive remove. The per-link Ignore actions make no sense here.
			actions += sep +
				'<span class="unignore"><a href="#" class="coywolf-seo-lm-unignore">' +
				escapeHtml( i18n.unignore ) + '</a></span>';
			actions += sep +
				'<span class="delete"><a href="#" class="coywolf-seo-lm-remove-link">' +
				escapeHtml( i18n.removeLink ) + '</a></span>';
		} else {
			actions += sep +
				'<span class="delete"><a href="#" class="coywolf-seo-lm-remove-link">' +
				escapeHtml( i18n.removeLink ) + '</a></span>';
			if ( r.redirect && r.finalUrl ) {
				actions += sep +
					'<span class="replace"><a href="#" class="coywolf-seo-lm-replace-link">' +
					escapeHtml( i18n.replaceLink ) + '</a></span>';
			}
			if ( domain ) {
				actions += sep +
					'<span class="ignore-domain"><a href="#" class="coywolf-seo-lm-ignore-domain" data-domain="' +
					escapeHtml( domain ) + '">' + escapeHtml( i18n.ignoreDomainLink ) + '</a></span>';
			}
			actions += sep +
				'<span class="ignore-url"><a href="#" class="coywolf-seo-lm-ignore-url">' +
				escapeHtml( i18n.ignoreUrlLink ) + '</a></span>';
			actions += sep +
				'<span class="ignore-wildcard"><a href="#" class="coywolf-seo-lm-wildcard-link">' +
				escapeHtml( i18n.wildcardIgnoreLink ) + '</a></span>';
		}
		urlHtml += '<div class="row-actions coywolf-seo-lm-url-actions">' + actions + '</div>';

		var typeLabel = 'internal' === r.type ? i18n.internal : i18n.external;

		return '<tr data-id="' + escapeHtml( String( r.id ) ) + '">' +
			'<th scope="row" class="check-column"><input type="checkbox" class="coywolf-seo-lm-cb" value="' +
				escapeHtml( String( r.id ) ) + '"' + checked + ' /></th>' +
			'<td class="column-url has-row-actions">' + urlHtml + '</td>' +
			'<td class="column-code"><span class="coywolf-seo-lm-code ' + badge.cls +
				'" title="' + escapeHtml( badge.title ) + '" tabindex="0">' + escapeHtml( badge.text ) + '</span></td>' +
			'<td class="column-type"><span class="coywolf-seo-lm-type coywolf-seo-lm-type--' +
				escapeHtml( r.type ) + '">' + escapeHtml( typeLabel ) + '</span></td>' +
			'<td class="column-posts">' + countCell( r.posts, r.postsUrl ) + '</td>' +
			'<td class="column-pages">' + countCell( r.pages, r.pagesUrl ) + '</td>' +
			'</tr>';
	}

	function syncResultsHeader() {
		if ( ! els.resultsHeader ) { return; }
		var anyVisible =
			( els.summary && 'none' !== els.summary.style.display ) ||
			( els.searchBox && 'none' !== els.searchBox.style.display );
		els.resultsHeader.style.display = anyVisible ? '' : 'none';
	}

	function setCurrentLinks( arr ) {
		currentLinks = arr || [];
		linkById = {};
		for ( var i = 0; i < currentLinks.length; i++ ) {
			linkById[ currentLinks[ i ].id ] = currentLinks[ i ];
		}
	}

	function findLink( id ) {
		return linkById[ id ] || null;
	}

	// Drop links from the in-memory view after a confirmed remove, instead of
	// re-fetching the whole inventory (removing a link cannot change another
	// link's counts).
	function removeLinksFromView( ids ) {
		var gone = {};
		ids.forEach( function ( id ) { gone[ id ] = true; delete selection[ id ]; } );
		setCurrentLinks( currentLinks.filter( function ( r ) { return ! gone[ r.id ]; } ) );
		renderTable();
	}

	// Rows belonging to the active view ('all' = not ignored, 'ignored' = ignored).
	function rowsForView( rows ) {
		return rows.filter( function ( r ) {
			return 'ignored' === viewMode ? !! r.ignored : ! r.ignored;
		} );
	}

	// Update the "All (n) | Ignored (n)" view links and the active state.
	function renderViews() {
		if ( ! els.views ) { return; }
		var allCount = 0;
		var ignoredCount = 0;
		currentLinks.forEach( function ( r ) {
			if ( r.ignored ) { ignoredCount++; } else { allCount++; }
		} );
		var allEl = els.views.querySelector( '[data-role="count-all"]' );
		var igEl  = els.views.querySelector( '[data-role="count-ignored"]' );
		if ( allEl ) { allEl.textContent = allCount.toLocaleString(); }
		if ( igEl ) { igEl.textContent = ignoredCount.toLocaleString(); }
		var links = els.views.querySelectorAll( 'a[data-view]' );
		Array.prototype.forEach.call( links, function ( a ) {
			var active = a.getAttribute( 'data-view' ) === viewMode;
			a.classList.toggle( 'current', active );
			if ( active ) { a.setAttribute( 'aria-current', 'page' ); }
			else { a.removeAttribute( 'aria-current' ); }
		} );
		show( els.views );
	}

	// Swap the Bulk actions options to match the active view, keeping the
	// localized "Bulk actions" placeholder captured from the original markup.
	function updateBulkOptions() {
		if ( ! els.bulkAction ) { return; }
		var html = '<option value="-1">' + escapeHtml( bulkPlaceholder ) + '</option>';
		if ( 'ignored' === viewMode ) {
			html += '<option value="unignore">' + escapeHtml( i18n.unignoreBulk ) + '</option>';
		} else {
			html += '<option value="remove-links">' + escapeHtml( i18n.removeBulk ) + '</option>';
			html += '<option value="replace-links">' + escapeHtml( i18n.replaceBulk ) + '</option>';
		}
		els.bulkAction.innerHTML = html;
		els.bulkAction.value = '-1';
	}

	function populateCodeFilter( rows ) {
		if ( ! els.codeFilter ) { return; }
		var seen = {};
		rows.forEach( function ( r ) {
			if ( r.checked ) { seen[ String( displayCode( r ) ) ] = true; }
		} );
		var codes = Object.keys( seen ).sort( function ( a, b ) {
			return parseInt( a, 10 ) - parseInt( b, 10 );
		} );
		if ( '' !== codeFilter && ! seen[ codeFilter ] ) { codeFilter = ''; }
		var html = '<option value="">' + escapeHtml( i18n.allCodes ) + '</option>';
		codes.forEach( function ( c ) {
			var label = '0' === c ? i18n.noResponse : c;
			var sel = c === codeFilter ? ' selected' : '';
			html += '<option value="' + escapeHtml( c ) + '"' + sel + '>' + escapeHtml( label ) + '</option>';
		} );
		els.codeFilter.innerHTML = html;
	}

	function renderTable() {
		var tbody = els.results.querySelector( 'tbody' );

		// No inventory at all — hide the views and everything below them.
		if ( ! currentLinks.length ) {
			hide( els.views );
			hide( els.searchBox );
			hide( els.toolbar );
			hide( els.toolbarBottom );
			hide( els.results );
			els.empty.textContent = analyzed ? i18n.noneShown : i18n.noLinks;
			show( els.empty );
			syncResultsHeader();
			return;
		}

		renderViews();

		var viewRows = rowsForView( currentLinks );

		// The active view has no links (e.g. nothing ignored yet). Hide the
		// search/toolbar chrome and show a view-specific note. The Ignored view
		// keeps its (empty) table so the column headers stay visible.
		if ( ! viewRows.length ) {
			hide( els.searchBox );
			hide( els.toolbar );
			hide( els.toolbarBottom );
			els.empty.textContent = 'ignored' === viewMode ? i18n.noIgnoredLinks : ( analyzed ? i18n.noneShown : i18n.noLinks );
			show( els.empty );
			if ( 'ignored' === viewMode ) {
				tbody.innerHTML = '';
				show( els.results );
			} else {
				hide( els.results );
			}
			syncResultsHeader();
			return;
		}

		show( els.searchBox );
		show( els.toolbar );
		show( els.toolbarBottom );
		show( els.results );
		syncResultsHeader();
		updateSortIndicator();
		populateCodeFilter( viewRows );

		var filtered = applySortFilter( viewRows );
		lastFiltered = filtered;
		reconcileSelection( filtered );
		var page = paginate( filtered, currentPage );
		currentPage = page.page;

		if ( ! filtered.length ) {
			tbody.innerHTML = '';
			els.empty.textContent = i18n.noneShown;
			show( els.empty );
		} else {
			var html = '';
			for ( var i = 0; i < page.rows.length; i++ ) {
				html += renderRow( page.rows[ i ] );
			}
			tbody.innerHTML = html;
			hide( els.empty );
		}

		renderPaginationNavs(
			[ 'coywolf-seo-lm-pagination-top', 'coywolf-seo-lm-pagination-bottom' ],
			{ total: filtered.length, totalPages: page.totalPages, page: page.page },
			function ( which, target ) {
				currentPage = nextPage( which, target, page.page, page.totalPages );
				renderTable();
			}
		);

		syncSelectAll( page.rows );
	}

	/* ---------------------------------------------------------------------
	 * All Links: load + analyze flow.
	 * ------------------------------------------------------------------- */

	function loadLinks() {
		return request( 'coywolf_seo_lm_links' ).then( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			analyzed = !! res.data.analyzed;
			setCurrentLinks( res.data.links || [] );
			renderState();
		} );
	}

	function renderState() {
		// The button stays available, labelled by whether an inventory exists.
		if ( els.analyze ) {
			els.analyze.textContent = analyzed ? i18n.reanalyzeBtn : i18n.analyzeBtn;
			els.analyze.disabled = false;
			show( els.analyze );
		}
		if ( lastSummary ) {
			els.summary.textContent = lastSummary;
			show( els.summary );
		}
		renderTable();
	}

	function applyProgress( state ) {
		var running = 'running' === state.status;
		if ( els.analyze ) { els.analyze.disabled = running; }

		if ( running ) {
			hide( els.analyze );
			show( els.progress );
			show( els.cancel );
			var total = state.totalPosts || 0;
			var done = state.processed || 0;
			if ( done <= 0 ) {
				els.bar.classList.add( 'is-indeterminate' );
				els.bar.style.width = '';
				els.percent.textContent = '';
				els.statusText.textContent = total ? format( i18n.initiating, [ total ] ) : i18n.starting;
			} else {
				els.bar.classList.remove( 'is-indeterminate' );
				var pct = total ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
				els.bar.style.width = pct + '%';
				els.percent.textContent = pct + '%';
				els.statusText.textContent = format( i18n.scanning, [ done + 1 > total ? total : done + 1, total ] );
			}
		} else {
			els.bar.classList.remove( 'is-indeterminate' );
			hide( els.progress );
			hide( els.cancel );
		}
	}

	function poll() {
		request( 'coywolf_seo_lm_status' ).then( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			applyProgress( res.data );
			if ( 'running' === res.data.status ) {
				pollTimer = window.setTimeout( poll, cfg.pollMs || 2000 );
			} else {
				pollTimer = null;
				loadLinks().then( function () {
					lastSummary = format( i18n.analyzedSummary, [
						currentLinks.length.toLocaleString(),
						( res.data.processed || 0 ).toLocaleString()
					] );
					els.summary.textContent = lastSummary;
					show( els.summary );
					syncResultsHeader();
				} );
			}
		} ).catch( function () {
			pollTimer = window.setTimeout( poll, cfg.pollMs || 2000 );
		} );
	}

	function startPolling() {
		if ( pollTimer ) { window.clearTimeout( pollTimer ); }
		poll();
	}

	function onAnalyze() {
		els.analyze.disabled = true;
		hide( els.analyze );
		hide( els.summary );
		lastSummary = '';
		selection = {};
		els.statusText.textContent = i18n.starting;
		els.bar.classList.add( 'is-indeterminate' );
		els.bar.style.width = '';
		els.percent.textContent = '';
		show( els.progress );
		// Hide the (old) inventory table while (re-)analysis runs — it is being
		// replaced. The poll's completion/cancel path reloads and re-shows it.
		hide( els.results );
		hide( els.toolbar );
		hide( els.toolbarBottom );
		hide( els.searchBox );
		hide( els.empty );
		syncResultsHeader();

		request( 'coywolf_seo_lm_start' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				els.statusText.textContent = i18n.error;
				show( els.analyze );
				els.analyze.disabled = false;
				return;
			}
			applyProgress( res.data );
			startPolling();
		} ).catch( function () {
			els.statusText.textContent = i18n.error;
			hide( els.progress );
			show( els.analyze );
			els.analyze.disabled = false;
		} );
	}

	function onCancel() {
		els.cancel.disabled = true;
		els.statusText.textContent = i18n.cancelling;
		request( 'coywolf_seo_lm_cancel' ).finally( function () {
			els.cancel.disabled = false;
		} );
	}

	function addIgnores( rules ) {
		return request( 'coywolf_seo_lm_ignore_add', { rules: JSON.stringify( rules ) } )
			.then( function ( res ) {
				if ( res && res.success ) { loadLinks(); }
			} );
	}

	// Add a rule from the "Add ignore rule" controls on the heading line.
	function onAddRule() {
		if ( ! els.ignoreType || ! els.ignoreValue ) { return; }
		var type = els.ignoreType.value;
		var value = ( els.ignoreValue.value || '' ).trim();
		if ( ! value ) { return; }
		els.ignoreValue.value = '';
		addIgnores( [ { type: type, value: value } ] );
	}

	// Stop ignoring a single link (Ignored view row action).
	function unignoreLink( id ) {
		if ( ! window.confirm( i18n.confirmUnignoreOne ) ) { return; }
		request( 'coywolf_seo_lm_unignore', { link_id: id } ).then( function ( res ) {
			if ( res && res.success ) {
				delete selection[ id ];
				loadLinks(); // Removing a rule can surface its other matches too.
			}
		} );
	}

	function onBulkApply() {
		var action = els.bulkAction.value;
		if ( '-1' === action ) { window.alert( i18n.pickAction ); return; }
		var ids = Object.keys( selection );
		if ( ! ids.length ) { window.alert( i18n.noSelection ); return; }
		if ( 'remove-links' === action ) {
			if ( ! window.confirm( format( i18n.confirmRemoveBulk, [ ids.length ] ) ) ) { return; }
			request( 'coywolf_seo_lm_remove_links_bulk', { link_ids: JSON.stringify( ids ) } )
				.then( function ( res ) {
					if ( res && res.success ) {
						els.bulkAction.value = '-1';
						removeLinksFromView( ids );
					}
				} );
		}
		if ( 'replace-links' === action ) {
			// Replace only applies to links that redirect; refuse the whole action
			// if any selected link is not a redirect.
			var nonRedirect = ids.filter( function ( id ) {
				var l = findLink( id );
				return ! ( l && l.redirect && l.finalUrl );
			} );
			if ( nonRedirect.length ) { window.alert( i18n.replaceNeedsRedirect ); return; }
			if ( ! window.confirm( format( i18n.confirmReplaceBulk, [ ids.length ] ) ) ) { return; }
			request( 'coywolf_seo_lm_replace_links_bulk', { link_ids: JSON.stringify( ids ) } )
				.then( function ( res ) {
					if ( res && res.success ) {
						els.bulkAction.value = '-1';
						loadLinks();
					}
				} );
		}
		if ( 'unignore' === action ) {
			if ( ! window.confirm( format( i18n.confirmUnignoreBulk, [ ids.length ] ) ) ) { return; }
			request( 'coywolf_seo_lm_unignore_bulk', { link_ids: JSON.stringify( ids ) } )
				.then( function ( res ) {
					if ( res && res.success ) {
						els.bulkAction.value = '-1';
						selection = {};
						loadLinks(); // Removing a rule can surface its other matches too.
					}
				} );
		}
	}

	/* ---------------------------------------------------------------------
	 * Wildcard ignore modal.
	 * ------------------------------------------------------------------- */

	function openWildcardModal( url ) {
		els.wcInput.value = url || '';
		hideWildcardError();
		els.wcSave.disabled = false;
		els.wcCancel.disabled = false;
		els.wcSave.textContent = i18n.save;
		show( els.wcModal );
		window.setTimeout( function () {
			els.wcInput.focus();
			var len = els.wcInput.value.length;
			try { els.wcInput.setSelectionRange( len, len ); } catch ( e ) {}
		}, 0 );
		document.addEventListener( 'keydown', onWildcardKeydown );
	}

	function closeWildcardModal() {
		hide( els.wcModal );
		document.removeEventListener( 'keydown', onWildcardKeydown );
	}

	function onWildcardKeydown( e ) {
		if ( 'Escape' === e.key ) { closeWildcardModal(); }
		else if ( 'Enter' === e.key && document.activeElement === els.wcInput ) {
			e.preventDefault();
			onWildcardSave();
		}
	}

	function showWildcardError( msg ) { els.wcError.textContent = msg; show( els.wcError ); }
	function hideWildcardError() { els.wcError.textContent = ''; hide( els.wcError ); }

	function onWildcardSave() {
		var value = ( els.wcInput.value || '' ).trim();
		if ( '' === value ) { showWildcardError( i18n.wildcardEmpty ); return; }
		hideWildcardError();
		els.wcSave.disabled = true;
		els.wcCancel.disabled = true;
		els.wcSave.textContent = i18n.wildcardSaving;
		addIgnores( [ { type: 'wildcard', value: value } ] )
			.then( function () { els.wcSave.textContent = i18n.save; closeWildcardModal(); } )
			.catch( function () {
				els.wcSave.textContent = i18n.save;
				els.wcSave.disabled = false;
				els.wcCancel.disabled = false;
				showWildcardError( i18n.error );
			} );
	}

	/* ---------------------------------------------------------------------
	 * Remove / Replace confirmation modal (operates on a whole link).
	 * ------------------------------------------------------------------- */

	var confirmState = { kind: 'remove', link: null };

	function openConfirmRemove( link ) {
		confirmState = { kind: 'remove', link: link };
		els.confirmMsg.textContent = i18n.removeEverywhere;
		clearConfirmDetail();
		prepConfirm();
	}

	function openConfirmReplace( link ) {
		confirmState = { kind: 'replace', link: link };
		els.confirmMsg.textContent = format( i18n.replaceEverywhere, [ link.finalUrl ] );
		setConfirmDetail( renderReplaceDetail( link.url, link.finalUrl ) );
		prepConfirm();
	}

	function renderReplaceDetail( fromUrl, toUrl ) {
		return '<div class="coywolf-seo-lm-replace-pair">' +
			'<div><span class="coywolf-seo-lm-replace-label">' + escapeHtml( i18n.replaceFrom ) + '</span> ' +
				'<code>' + escapeHtml( fromUrl ) + '</code></div>' +
			'<div><span class="coywolf-seo-lm-replace-label">' + escapeHtml( i18n.replaceTo ) + '</span> ' +
				'<code>' + escapeHtml( toUrl ) + '</code></div></div>';
	}

	function setConfirmDetail( html ) {
		if ( ! els.confirmDetail ) { return; }
		els.confirmDetail.innerHTML = html;
		show( els.confirmDetail );
	}

	function clearConfirmDetail() {
		if ( ! els.confirmDetail ) { return; }
		els.confirmDetail.innerHTML = '';
		hide( els.confirmDetail );
	}

	function prepConfirm() {
		var isReplace = 'replace' === confirmState.kind;
		els.confirmTitle.textContent = isReplace ? i18n.replaceTitle : i18n.confirmTitle;
		els.confirmHelp.textContent  = isReplace ? i18n.replaceHelp  : i18n.confirmHelp;
		els.confirmRemove.textContent = isReplace ? i18n.replaceLink : i18n.removeLink;
		els.confirmRemove.classList.toggle( 'coywolf-seo-lm-danger', ! isReplace );
		els.confirmRemove.classList.toggle( 'button-primary', isReplace );
		hideConfirmError();
		els.confirmRemove.disabled = false;
		els.confirmCancel.disabled = false;
		show( els.confirm );
		window.setTimeout( function () { els.confirmCancel.focus(); }, 0 );
		document.addEventListener( 'keydown', onConfirmKeydown );
	}

	function closeConfirm() {
		hide( els.confirm );
		confirmState = { kind: 'remove', link: null };
		clearConfirmDetail();
		document.removeEventListener( 'keydown', onConfirmKeydown );
	}

	function onConfirmKeydown( e ) { if ( 'Escape' === e.key ) { closeConfirm(); } }
	function showConfirmError( msg ) { els.confirmError.textContent = msg; show( els.confirmError ); }
	function hideConfirmError() { els.confirmError.textContent = ''; hide( els.confirmError ); }

	function onConfirmAction() {
		var s = confirmState;
		if ( ! s.link ) { return; }
		var isReplace = 'replace' === s.kind;
		var action = isReplace ? 'coywolf_seo_lm_replace_link' : 'coywolf_seo_lm_remove_link';
		var primaryLabel = isReplace ? i18n.replaceLink : i18n.removeLink;
		var workingLabel = isReplace ? i18n.replacing : i18n.removing;

		hideConfirmError();
		els.confirmRemove.disabled = true;
		els.confirmCancel.disabled = true;
		els.confirmRemove.textContent = workingLabel;

		request( action, { link_id: s.link.id } ).then( function ( res ) {
			els.confirmRemove.textContent = primaryLabel;
			if ( res && res.success ) {
				closeConfirm();
				if ( isReplace ) {
					loadLinks(); // URL changed; counts/merge may shift — refetch.
				} else {
					removeLinksFromView( [ s.link.id ] );
				}
			} else {
				els.confirmRemove.disabled = false;
				els.confirmCancel.disabled = false;
				showConfirmError( res && res.data && res.data.message ? res.data.message : i18n.error );
			}
		} ).catch( function () {
			els.confirmRemove.textContent = primaryLabel;
			els.confirmRemove.disabled = false;
			els.confirmCancel.disabled = false;
			showConfirmError( i18n.error );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Wiring.
	 * ------------------------------------------------------------------- */

	function wireAllLinksPage() {
		els = {
			analyze: $( 'coywolf-seo-lm-analyze' ),
			cancel: $( 'coywolf-seo-lm-cancel' ),
			progress: $( 'coywolf-seo-lm-progress' ),
			bar: $( 'coywolf-seo-lm-bar' ),
			percent: $( 'coywolf-seo-lm-percent' ),
			statusText: $( 'coywolf-seo-lm-status-text' ),
			summary: $( 'coywolf-seo-lm-summary' ),
			toolbar: $( 'coywolf-seo-lm-toolbar' ),
			toolbarBottom: $( 'coywolf-seo-lm-toolbar-bottom' ),
			resultsHeader: $( 'coywolf-seo-lm-results-header' ),
			searchBox: $( 'coywolf-seo-lm-search-box' ),
			search: $( 'coywolf-seo-lm-search' ),
			searchBtn: $( 'coywolf-seo-lm-search-btn' ),
			codeFilter: $( 'coywolf-seo-lm-code-filter' ),
			typeFilter: $( 'coywolf-seo-lm-type-filter' ),
			views: $( 'coywolf-seo-lm-views' ),
			bulkAction: $( 'coywolf-seo-lm-bulk-action' ),
			bulkApply: $( 'coywolf-seo-lm-bulk-apply' ),
			cbAll: $( 'coywolf-seo-lm-cb-all' ),
			ignoreType: $( 'coywolf-seo-lm-ignore-type' ),
			ignoreValue: $( 'coywolf-seo-lm-ignore-value' ),
			ignoreAddBtn: $( 'coywolf-seo-lm-ignore-add-btn' ),
			thCode: $( 'coywolf-seo-lm-th-code' ),
			thType: $( 'coywolf-seo-lm-th-type' ),
			results: $( 'coywolf-seo-lm-results' ),
			empty: $( 'coywolf-seo-lm-empty' ),
			wcModal: $( 'coywolf-seo-lm-wildcard' ),
			wcInput: $( 'coywolf-seo-lm-wildcard-input' ),
			wcError: $( 'coywolf-seo-lm-wildcard-error' ),
			wcSave: $( 'coywolf-seo-lm-wildcard-save' ),
			wcCancel: $( 'coywolf-seo-lm-wildcard-cancel' ),
			confirm: $( 'coywolf-seo-lm-confirm' ),
			confirmTitle: $( 'coywolf-seo-lm-confirm-title' ),
			confirmMsg: $( 'coywolf-seo-lm-confirm-msg' ),
			confirmDetail: $( 'coywolf-seo-lm-confirm-detail' ),
			confirmHelp: $( 'coywolf-seo-lm-confirm-help' ),
			confirmError: $( 'coywolf-seo-lm-confirm-error' ),
			confirmRemove: $( 'coywolf-seo-lm-confirm-remove' ),
			confirmCancel: $( 'coywolf-seo-lm-confirm-cancel' )
		};

		if ( ! els.results ) { return; }

		// Capture the localized "Bulk actions" placeholder before rebuilding the
		// option list per view, then set the initial (All-view) options.
		if ( els.bulkAction && els.bulkAction.options.length ) {
			bulkPlaceholder = els.bulkAction.options[ 0 ].textContent;
		}
		updateBulkOptions();

		els.analyze.addEventListener( 'click', onAnalyze );
		els.cancel.addEventListener( 'click', onCancel );

		// View switch (All / Ignored), styled like the posts-list status links.
		if ( els.views ) {
			els.views.addEventListener( 'click', function ( e ) {
				var a = e.target.closest( 'a[data-view]' );
				if ( ! a ) { return; }
				e.preventDefault();
				var v = a.getAttribute( 'data-view' );
				if ( v === viewMode ) { return; }
				viewMode = v;
				selection = {};
				currentPage = 1;
				updateBulkOptions();
				renderTable();
			} );
		}

		// Add ignore rule (relocated from the old Ignored URLs page).
		if ( els.ignoreAddBtn ) {
			els.ignoreAddBtn.addEventListener( 'click', onAddRule );
			els.ignoreValue.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) { e.preventDefault(); onAddRule(); }
			} );
		}

		els.thCode.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			onSort( 'code' );
		} );
		if ( els.thType ) {
			els.thType.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				onSort( 'type' );
			} );
		}

		function persistSearch() {
			try {
				if ( filterText ) { window.sessionStorage.setItem( SEARCH_KEY, filterText ); }
				else { window.sessionStorage.removeItem( SEARCH_KEY ); }
			} catch ( e ) {}
		}
		function runSearch() {
			filterText = els.search.value.trim();
			persistSearch();
			currentPage = 1;
			renderTable();
		}
		// Clearing the field (the native "×" or deleting the text) resets the table.
		function clearIfEmpty() {
			if ( '' === els.search.value.trim() && '' !== filterText ) {
				filterText = '';
				persistSearch();
				currentPage = 1;
				renderTable();
			}
		}
		els.searchBtn.addEventListener( 'click', runSearch );
		els.search.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) { e.preventDefault(); runSearch(); }
		} );
		els.search.addEventListener( 'input', clearIfEmpty );
		els.search.addEventListener( 'search', clearIfEmpty );

		if ( els.codeFilter ) {
			els.codeFilter.addEventListener( 'change', function () {
				codeFilter = els.codeFilter.value;
				currentPage = 1;
				renderTable();
			} );
		}
		if ( els.typeFilter ) {
			els.typeFilter.addEventListener( 'change', function () {
				typeFilter = els.typeFilter.value;
				currentPage = 1;
				renderTable();
			} );
		}

		els.bulkApply.addEventListener( 'click', onBulkApply );

		els.cbAll.addEventListener( 'change', function () {
			var page = paginate( lastFiltered, currentPage );
			page.rows.forEach( function ( r ) {
				if ( els.cbAll.checked ) { selection[ r.id ] = true; }
				else { delete selection[ r.id ]; }
			} );
			renderTable();
		} );

		els.results.querySelector( 'tbody' ).addEventListener( 'click', function ( e ) {
			var tr = e.target.closest( 'tr' );
			var link = tr ? findLink( tr.getAttribute( 'data-id' ) ) : null;

			var unignoreBtn = e.target.closest( '.coywolf-seo-lm-unignore' );
			if ( unignoreBtn ) { e.preventDefault(); if ( link ) { unignoreLink( link.id ); } return; }

			var removeBtn = e.target.closest( '.coywolf-seo-lm-remove-link' );
			if ( removeBtn ) { e.preventDefault(); if ( link ) { openConfirmRemove( link ); } return; }

			var replaceBtn = e.target.closest( '.coywolf-seo-lm-replace-link' );
			if ( replaceBtn ) { e.preventDefault(); if ( link && link.redirect && link.finalUrl ) { openConfirmReplace( link ); } return; }

			var igDomain = e.target.closest( '.coywolf-seo-lm-ignore-domain' );
			if ( igDomain ) {
				e.preventDefault();
				var d = igDomain.getAttribute( 'data-domain' );
				if ( d ) { addIgnores( [ { type: 'domain', value: d } ] ); }
				return;
			}
			var igUrl = e.target.closest( '.coywolf-seo-lm-ignore-url' );
			if ( igUrl ) { e.preventDefault(); if ( link && link.url ) { addIgnores( [ { type: 'url', value: link.url } ] ); } return; }

			var wcBtn = e.target.closest( '.coywolf-seo-lm-wildcard-link' );
			if ( wcBtn ) { e.preventDefault(); if ( link && link.url ) { openWildcardModal( link.url ); } }
		} );

		els.results.querySelector( 'tbody' ).addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'coywolf-seo-lm-cb' ) ) {
				var id = e.target.value;
				if ( e.target.checked ) { selection[ id ] = true; }
				else { delete selection[ id ]; }
				syncSelectAll( paginate( lastFiltered, currentPage ).rows );
			}
		} );

		if ( els.wcModal ) {
			els.wcCancel.addEventListener( 'click', closeWildcardModal );
			els.wcSave.addEventListener( 'click', onWildcardSave );
			els.wcModal.addEventListener( 'click', function ( e ) {
				if ( e.target.getAttribute( 'data-close' ) ) { closeWildcardModal(); }
			} );
		}

		els.confirmCancel.addEventListener( 'click', closeConfirm );
		els.confirmRemove.addEventListener( 'click', onConfirmAction );
		els.confirm.addEventListener( 'click', function ( e ) {
			if ( e.target.getAttribute( 'data-close' ) ) { closeConfirm(); }
		} );

		// Restore a persisted search term so the table stays filtered when the
		// user returns from the Edit Link page.
		try {
			var savedSearch = window.sessionStorage.getItem( SEARCH_KEY );
			if ( savedSearch ) {
				filterText = savedSearch;
				els.search.value = savedSearch;
			}
		} catch ( e ) {}

		// On load: resume a running analysis, otherwise load the inventory.
		request( 'coywolf_seo_lm_status' ).then( function ( res ) {
			if ( res && res.success && 'running' === res.data.status ) {
				applyProgress( res.data );
				startPolling();
			} else {
				loadLinks();
			}
		} ).catch( function () { loadLinks(); } );
	}

	function wireEditLinkPage() {
		var removeBtn = $( 'coywolf-seo-lm-edit-remove' );
		var modal = $( 'coywolf-seo-lm-edit-confirm' );
		if ( ! removeBtn || ! modal ) { return; }
		var cancel = $( 'coywolf-seo-lm-edit-confirm-cancel' );

		function closeModal() {
			modal.style.display = 'none';
			document.removeEventListener( 'keydown', onKey );
		}
		function onKey( e ) {
			if ( 'Escape' === e.key ) { closeModal(); }
		}

		// Open the styled confirmation; the modal's own Remove button is a real
		// submit (name=coywolf_seo_lm_remove) so confirming removes the link.
		removeBtn.addEventListener( 'click', function () {
			modal.style.display = '';
			document.addEventListener( 'keydown', onKey );
			if ( cancel ) {
				window.setTimeout( function () { cancel.focus(); }, 0 );
			}
		} );
		if ( cancel ) {
			cancel.addEventListener( 'click', closeModal );
		}
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target.getAttribute( 'data-close' ) ) { closeModal(); }
		} );
	}

	ready( function () {
		if ( 'edit' === pageKind ) {
			wireEditLinkPage();
		} else if ( 'all' === pageKind ) {
			wireAllLinksPage();
		}
	} );
}() );
