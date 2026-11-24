/**
 * AI Abilities Access cards: a count, quick choices and an open and close header for each area.
 *
 * @package UserRegistration
 */
/* global jQuery, urAbilityAccess */
( function ( $, text ) {
	var LEVELS = [ 'read', 'write', 'delete' ];

	function setup( $card ) {
		var $switches = $card.find( 'input[data-ur-ability-level]' );
		var $header = $card.children( '.user-registration-card__header' );
		var $body = $card.children( '.user-registration-card__body' );
		var $count = $( '<span class="ur-ability-access__count"></span>' );
		var $presets = $( '<div class="ur-ability-access__presets"></div>' );

		if ( ! $switches.length ) {
			return;
		}

		// Group the switches under Reads, Changes and Deletes, and mark the ones that ask first.
		LEVELS.forEach( function ( level ) {
			var $first = $switches.filter( '[data-ur-ability-level="' + level + '"]' ).first();

			if ( $first.length ) {
				$first.closest( '.user-registration-global-settings' ).before( $( '<div class="ur-ability-access__group"></div>' ).addClass( 'ur-ability-access__group--' + level ).text( text.groups[ level ] ) );
			}
		} );
		$switches.filter( '[data-ur-ability-confirm="yes"]' ).each( function () {
			$( this ).closest( '.user-registration-global-settings' ).children( 'label' ).first().append( $( '<span class="ur-ability-access__asks"></span>' ).text( text.asks ).attr( 'title', text.askstip ) );
		} );

		// The area description goes in a tooltip beside the title instead of a notice.
		var $desc = $card.children( '.ur-p-tag' );
		var $tip = $( '<span class="user-registration-help-tip ur-ability-access__tip"></span>' ).attr( 'data-tip', $( '<div></div>' ).text( $.trim( $desc.text() ) ).html() );

		if ( $.trim( $desc.text() ) ) {
			$header.find( '.user-registration-card__title' ).append( $tip );
			if ( 'function' === typeof window.ur_init_tooltips ) {
				window.ur_init_tooltips( $tip );
			}
		}
		$desc.remove();

		[ 'none', 'read', 'all' ].forEach( function ( preset ) {
			$( '<button type="button"></button>' ).text( text[ preset ] ).attr( 'data-preset', preset ).appendTo( $presets );
		} );

		$header.addClass( 'ur-ability-access__header' ).attr( { role: 'button', tabindex: 0 } ).find( '.user-registration-card__title' ).append( $count );
		$header.append( $presets );

		function refresh() {
			var on = $switches.filter( ':checked' ).length;
			var readOnly = $switches.toArray().every( function ( input ) {
				return input.checked === ( 'read' === input.getAttribute( 'data-ur-ability-level' ) );
			} );

			$count.text( text.count.replace( '%1$s', on ).replace( '%2$s', $switches.length ) );
			$presets.children().each( function () {
				var preset = this.getAttribute( 'data-preset' );
				var pressed = 'none' === preset ? 0 === on : 'all' === preset ? on === $switches.length : readOnly && on !== $switches.length;

				this.setAttribute( 'aria-pressed', pressed ? 'true' : 'false' );
			} );
		}

		function set( isOn ) {
			$switches.each( function () {
				if ( this.checked !== isOn( this ) ) {
					$( this ).prop( 'checked', isOn( this ) ).trigger( 'change' );
				}
			} );
			refresh();
		}

		function toggle() {
			$card.toggleClass( 'ur-ability-access--closed' );
			$header.attr( 'aria-expanded', $card.hasClass( 'ur-ability-access--closed' ) ? 'false' : 'true' );
		}

		$switches.on( 'change', refresh );
		$presets.on( 'click', 'button', function () {
			var preset = this.getAttribute( 'data-preset' );

			set( function ( input ) {
				return 'all' === preset || ( 'read' === preset && 'read' === input.getAttribute( 'data-ur-ability-level' ) );
			} );
		} );
		$presets.add( $tip ).on( 'click keydown', function ( event ) {
			event.stopPropagation();
		} );
		$header.on( 'click', toggle ).on( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				toggle();
			}
		} );

		// Every area starts closed, so the page stays short.
		$card.addClass( 'ur-ability-access--closed' );
		$header.attr( 'aria-expanded', 'false' );
		$body.addClass( 'ur-ability-access__body' );
		refresh();
	}

	// One search above the cards: shows only the abilities whose name, or whose area's name, matches, in open cards.
	function search( $cards ) {
		var $box = $( '<div class="ur-ability-access__search"></div>' );
		var $input = $( '<input type="search" id="ur-ability-access-search">' ).attr( { placeholder: text.search, 'aria-label': text.search } );
		var $empty = $( '<p class="ur-ability-access__empty"></p>' ).text( text.noMatch ).hide();
		var $enable = $( '#user_registration_enable_abilities' );
		var closed = null;

		// Only the visible name counts: descriptions mention other areas' actions and match too much.
		function words( $el ) {
			return $el.contents().filter( function () {
				return 3 === this.nodeType;
			} ).text().toLowerCase();
		}

		function run() {
			var query = $.trim( $input.val() ).toLowerCase();
			var found = 0;

			if ( query && null === closed ) {
				closed = $cards.filter( '.ur-ability-access--closed' );
			}

			$cards.each( function () {
				var $card = $( this );
				var $rows = $card.find( 'input[data-ur-ability-level]' ).closest( '.user-registration-global-settings' );
				var areaMatch = query && -1 !== words( $card.find( '.user-registration-card__title' ) ).indexOf( query );
				var shown = 0;

				$rows.each( function () {
					var show = ! query || areaMatch || -1 !== words( $( this ).children( 'label' ) ).indexOf( query );

					$( this ).toggle( show );
					shown += show ? 1 : 0;
				} );
				$card.find( '.ur-ability-access__group' ).each( function () {
					$( this ).toggle( $( this ).nextUntil( '.ur-ability-access__group', '.user-registration-global-settings' ).filter( function () {
						return 'none' !== this.style.display;
					} ).length > 0 );
				} );
				$card.toggle( shown > 0 );
				if ( query ) {
					$card.removeClass( 'ur-ability-access--closed' );
				}
				found += shown;
			} );

			if ( ! query && null !== closed ) {
				closed.addClass( 'ur-ability-access--closed' );
				closed = null;
			}
			$cards.each( function () {
				$( this ).children( '.ur-ability-access__header' ).attr( 'aria-expanded', $( this ).hasClass( 'ur-ability-access--closed' ) ? 'false' : 'true' );
			} );
			$empty.toggle( ! found );
		}

		function visibility() {
			$box.toggle( $enable.length ? $enable.is( ':checked' ) : true );
		}

		$box.append( $input, $empty );
		$cards.first().before( $box );
		$input.on( 'input search', run );
		$enable.on( 'change', visibility );
		visibility();
	}

	$( function () {
		var $cards = $( '.user-registration-card[id^="ur-ability-access-"]' );

		$cards.each( function () {
			setup( $( this ) );
		} );
		if ( $cards.length ) {
			search( $cards );
		}
	} );
}( jQuery, urAbilityAccess ) );
