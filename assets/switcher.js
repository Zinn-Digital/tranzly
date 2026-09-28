/*
 * The dropdown and floating language switchers are native <details> disclosures: they open and
 * close with Enter/Space and are announced by screen readers with no script. This adds what a
 * disclosure lacks: Escape closes it and returns focus to its button, a click elsewhere or moving
 * focus out closes it, and only one is open at a time. `__PREFIX__` is the neutral class prefix.
 */
( function () {
	'use strict';
	var menus = document.querySelectorAll( '.__PREFIX__-lsw__menu' );
	if ( ! menus.length ) {
		return;
	}
	var closeAll = function ( except ) {
		for ( var i = 0; i < menus.length; i++ ) {
			if ( menus[ i ] !== except ) {
				menus[ i ].open = false;
			}
		}
	};
	for ( var i = 0; i < menus.length; i++ ) {
		( function ( menu ) {
			menu.addEventListener( 'toggle', function () {
				if ( menu.open ) {
					closeAll( menu );
				}
			} );
			menu.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && menu.open ) {
					menu.open = false;
					var toggle = menu.querySelector( 'summary' );
					if ( toggle ) {
						toggle.focus();
					}
					event.preventDefault();
				}
			} );
			menu.addEventListener( 'focusout', function ( event ) {
				if ( menu.open && event.relatedTarget && ! menu.contains( event.relatedTarget ) ) {
					menu.open = false;
				}
			} );
		} )( menus[ i ] );
	}
	document.addEventListener( 'click', function ( event ) {
		for ( var j = 0; j < menus.length; j++ ) {
			if ( menus[ j ].open && ! menus[ j ].contains( event.target ) ) {
				menus[ j ].open = false;
			}
		}
	} );
} )();
