/**
 * AI Abilities Access cards: a count, quick choices and an open and close header for each area.
 *
 * @package UserRegistration
 */
/* global jQuery, urAbilityAccess */
( function ( $, text ) {
	var LEVELS = [ 'read', 'write', 'delete' ];

	function setup( $card, index ) {
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

		// The first two areas start open, the rest closed, so the page stays short.
		if ( index > 1 ) {
			$card.addClass( 'ur-ability-access--closed' );
		}
		$header.attr( 'aria-expanded', index > 1 ? 'false' : 'true' );
		$body.addClass( 'ur-ability-access__body' );
		refresh();
	}

	$( function () {
		$( '.user-registration-card[id^="ur-ability-access-"]' ).each( function ( index ) {
			setup( $( this ), index );
		} );
	} );
}( jQuery, urAbilityAccess ) );
