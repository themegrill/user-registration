/**
 * AI Abilities Access card: puts each area's few switches under the area name, and adds a search in the card header.
 *
 * @package UserRegistration
 */
/* global jQuery, urAbilityAccess */
( function ( $, text ) {
	function rowOf( input ) {
		return $( input ).closest( '.user-registration-global-settings' );
	}

	function setup( slug, $switches ) {
		var area = text.areas[ slug ] || { label: slug, tip: '', pro: false };
		var $area = $( '<div class="ur-ability-access__area"></div>' ).attr( 'data-area', slug );
		var $title = $( '<h4 class="ur-ability-access__title"></h4>' ).text( area.label );
		var $list = $( '<div class="ur-ability-access__list"></div>' );
		var $tip = $();

		rowOf( $switches.first() ).before( $area );
		$switches.each( function () {
			var $row = rowOf( this );
			var $label = $row.children( 'label' ).first();

			// The abilities a switch covers sit under its name, and "Asks first" beside it.
			$label.wrapInner( '<span class="ur-ability-access__name"></span>' );
			if ( 'yes' === this.getAttribute( 'data-ur-ability-confirm' ) ) {
				$label.children( '.ur-ability-access__name' ).append( $( '<span class="ur-ability-access__asks"></span>' ).text( text.asks ).attr( 'title', text.askstip ) );
			}
			$label.append( $row.find( '.description' ).first().addClass( 'ur-ability-access__covers' ) );
			$list.append( $row );
		} );

		if ( area.tip ) {
			$tip = $( '<span class="user-registration-help-tip ur-ability-access__tip"></span>' ).attr( 'data-tip', $( '<div></div>' ).text( area.tip ).html() );
			$title.append( $tip );
		}
		if ( area.pro ) {
			$title.append( $( '<img class="ur-ability-access__pro" alt="Pro">' ).attr( 'src', text.proIcon ) );
		}

		$area.append( $title, $list );

		if ( $tip.length && 'function' === typeof window.ur_init_tooltips ) {
			window.ur_init_tooltips( $tip );
		}
	}

	// A search in the card header: shows only the switches whose name, covered abilities or area name match.
	function search( $card ) {
		var $areas = $card.find( '.ur-ability-access__area' );
		var $box = $( '<label class="ur-ability-access__search"></label>' );
		var $input = $( '<input type="search" id="ur-ability-access-search">' ).attr( { placeholder: text.example, 'aria-label': text.search } );
		var $empty = $( '<p class="ur-ability-access__empty"></p>' ).text( text.noMatch ).hide();

		function run() {
			var query = $.trim( $input.val() ).toLowerCase();
			var found = 0;

			$areas.each( function () {
				var $area = $( this );
				var areaMatch = query && -1 !== $area.children( '.ur-ability-access__title' ).contents().first().text().toLowerCase().indexOf( query );
				var shown = 0;

				$area.find( '.user-registration-global-settings' ).each( function () {
					var show = ! query || areaMatch || -1 !== $( this ).children( 'label' ).text().toLowerCase().indexOf( query );

					$( this ).toggle( show );
					shown += show ? 1 : 0;
				} );
				$area.toggle( shown > 0 );
				found += shown;
			} );
			$empty.toggle( ! found );
		}

		$box.append( $input, '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>' );
		$card.children( '.user-registration-card__header' ).append( $box );
		$areas.last().after( $empty );
		$input.on( 'input search', run ).on( 'keydown', function ( event ) {
			// Enter would submit the settings form.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
			}
		} );
	}

	$( function () {
		var $switches = $( '#ur-ability-access input[data-ur-ability-area]' );
		var slugs = [];

		$switches.each( function () {
			var slug = this.getAttribute( 'data-ur-ability-area' );

			if ( -1 === slugs.indexOf( slug ) ) {
				slugs.push( slug );
			}
		} );
		slugs.forEach( function ( slug ) {
			setup( slug, $switches.filter( '[data-ur-ability-area="' + slug + '"]' ) );
		} );
		if ( slugs.length ) {
			search( $( '#ur-ability-access' ) );
		}
	} );
}( jQuery, urAbilityAccess ) );
