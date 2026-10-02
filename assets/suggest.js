/*
 * The "this page is in your language" banner (tz-s8). It OFFERS, it never redirects: search
 * engines crawl without a browser language and must see every page at its own address. Data comes
 * from the page (window.__PREFIX__Lsg); `__PREFIX__` is the neutral class prefix.
 */
( function () {
	'use strict';
	const data = window.__PREFIX__Lsg;
	if ( ! data || ! data.offers || ! window.navigator ) {
		return;
	}
	const key = '__PREFIX__-lsg-dismissed';
	try {
		if ( window.localStorage.getItem( key ) ) {
			return;
		}
	} catch {
		// Storage blocked: the banner still works, it just cannot remember a dismissal.
	}
	const norm = function ( code ) {
		return String( code || '' )
			.toLowerCase()
			.replace( '_', '-' );
	};
	const wanted = window.navigator.languages || [
		window.navigator.language || '',
	];
	const current = norm( data.current ).split( '-' )[ 0 ];
	const offers = data.offers;
	let pick = null;
	for ( let i = 0; i < wanted.length && ! pick; i++ ) {
		const w = norm( wanted[ i ] );
		const primary = w.split( '-' )[ 0 ];
		if ( primary === current ) {
			return; // The visitor reads the page's language already.
		}
		for ( let j = 0; j < offers.length && ! pick; j++ ) {
			if ( norm( offers[ j ].tag ) === w ) {
				pick = offers[ j ];
			}
		}
		for ( let k = 0; k < offers.length && ! pick; k++ ) {
			if ( norm( offers[ k ].tag ).split( '-' )[ 0 ] === primary ) {
				pick = offers[ k ];
			}
		}
	}
	if ( ! pick ) {
		return;
	}
	const box = document.createElement( 'div' );
	box.className = '__PREFIX__-lsg';
	box.setAttribute( 'role', 'region' );
	box.setAttribute( 'aria-label', pick.label );
	box.setAttribute( 'lang', pick.tag );
	box.setAttribute( 'dir', pick.dir );
	const text = document.createElement( 'p' );
	text.className = '__PREFIX__-lsg__text';
	text.textContent = pick.text;
	const go = document.createElement( 'a' );
	go.className = '__PREFIX__-lsg__go';
	go.href = pick.url;
	go.setAttribute( 'hreflang', pick.tag );
	go.textContent = pick.yes;
	const close = document.createElement( 'button' );
	close.type = 'button';
	close.className = '__PREFIX__-lsg__close';
	close.setAttribute( 'aria-label', pick.no );
	close.textContent = '×';
	close.addEventListener( 'click', function () {
		try {
			window.localStorage.setItem( key, '1' );
		} catch {
			// Storage blocked: the banner closes for this page only.
		}
		if ( box.parentNode ) {
			box.parentNode.removeChild( box );
		}
	} );
	box.appendChild( text );
	box.appendChild( go );
	box.appendChild( close );
	document.body.appendChild( box );
	window.document.dispatchEvent(
		new window.CustomEvent( '__PREFIX__-lsg-shown' )
	);
} )();
