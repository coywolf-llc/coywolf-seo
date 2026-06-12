/**
 * Coywolf SEO — Table of Contents front-end enhancements.
 *
 * Loaded (deferred) only on pages whose table asked for smooth scrolling or
 * current-section highlighting. Collapsing needs no JS (details/summary),
 * and plain jump links work before — and without — this script.
 */
( function () {
	'use strict';

	var reduceMotion =
		window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function targetOf( link ) {
		var href = link.getAttribute( 'href' ) || '';
		if ( '#' !== href.charAt( 0 ) ) {
			return null;
		}
		try {
			return document.getElementById( decodeURIComponent( href.slice( 1 ) ) );
		} catch ( e ) {
			return null;
		}
	}

	function smooth( links ) {
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				var target = targetOf( link );
				if ( ! target ) {
					return;
				}
				event.preventDefault();
				target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				// Keep the URL shareable and keyboard/reader position correct.
				if ( window.history && window.history.pushState ) {
					window.history.pushState( null, '', link.getAttribute( 'href' ) );
				}
				if ( ! target.hasAttribute( 'tabindex' ) ) {
					target.setAttribute( 'tabindex', '-1' );
				}
				target.focus( { preventScroll: true } );
			} );
		} );
	}

	function spy( links ) {
		var entries = [];
		links.forEach( function ( link ) {
			var target = targetOf( link );
			if ( target ) {
				entries.push( { link: link, target: target } );
			}
		} );
		if ( ! entries.length ) {
			return;
		}

		var current = null;
		var ticking = false;

		function update() {
			// The section being read: the last heading above the reading
			// line (40% down the viewport).
			var line = window.innerHeight * 0.4;
			var active = null;
			for ( var i = 0; i < entries.length; i++ ) {
				if ( entries[ i ].target.getBoundingClientRect().top <= line ) {
					active = entries[ i ];
				}
			}
			if ( active === current ) {
				return;
			}
			if ( current ) {
				current.link.removeAttribute( 'aria-current' );
			}
			current = active;
			if ( current ) {
				current.link.setAttribute( 'aria-current', 'location' );
			}
		}

		function onScroll() {
			if ( ticking ) {
				return;
			}
			ticking = true;
			window.requestAnimationFrame( function () {
				ticking = false;
				update();
			} );
		}

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		window.addEventListener( 'resize', onScroll, { passive: true } );
		update();
	}

	document.querySelectorAll( 'nav.coywolf-seo-toc' ).forEach( function ( nav ) {
		var links = Array.prototype.slice.call( nav.querySelectorAll( 'a[href^="#"]' ) );
		if ( ! links.length ) {
			return;
		}
		if ( nav.hasAttribute( 'data-smooth' ) && ! reduceMotion ) {
			smooth( links );
		}
		if ( nav.hasAttribute( 'data-spy' ) ) {
			spy( links );
		}
	} );
} )();
