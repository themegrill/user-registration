/**
 * AI Abilities Access card: groups the switches by area, each with a count, quick choices and an open and close header.
 *
 * @package UserRegistration
 */
/* global jQuery, urAbilityAccess */
( function ( $, text ) {
	var LEVELS = [ 'read', 'write', 'delete' ];

	function rowOf( input ) {
		return $( input ).closest( '.user-registration-global-settings' );
	}

	function setup( slug, $switches ) {
		var area = text.areas[ slug ] || { label: slug, tip: '', pro: false };
		var $area = $( '<div class="ur-ability-access__area ur-ability-access--closed"></div>' ).attr( 'data-area', slug );
		var $header = $( '<div class="ur-ability-access__header" role="button" tabindex="0" aria-expanded="false"></div>' );
		var $title = $( '<span class="ur-ability-access__title"></span>' ).text( area.label );
		var $count = $( '<span class="ur-ability-access__count"></span>' );
		var $presets = $( '<div class="ur-ability-access__presets"></div>' );
		var $body = $( '<div class="ur-ability-access__body"></div>' );
		var $tip = $();

		rowOf( $switches.first() ).before( $area );
		$switches.each( function () {
			$body.append( rowOf( this ) );
		} );

		// Group the switches under Reads, Changes and Deletes, and mark the ones that ask first.
		LEVELS.forEach( function ( level ) {
			var $first = $switches.filter( '[data-ur-ability-level="' + level + '"]' ).first();

			if ( $first.length ) {
				rowOf( $first ).before( $( '<div class="ur-ability-access__group"></div>' ).addClass( 'ur-ability-access__group--' + level ).text( text.groups[ level ] ) );
			}
		} );
		$switches.filter( '[data-ur-ability-confirm="yes"]' ).each( function () {
			rowOf( this ).children( 'label' ).first().append( $( '<span class="ur-ability-access__asks"></span>' ).text( text.asks ).attr( 'title', text.askstip ) );
		} );

		if ( area.tip ) {
			$tip = $( '<span class="user-registration-help-tip ur-ability-access__tip"></span>' ).attr( 'data-tip', $( '<div></div>' ).text( area.tip ).html() );
			$title.append( $tip );
		}
		if ( area.pro ) {
			$title.append( $( '<img class="ur-ability-access__pro" alt="Pro">' ).attr( 'src', text.proIcon ) );
		}
		$title.append( $count );

		[ 'none', 'read', 'all' ].forEach( function ( preset ) {
			$( '<button type="button"></button>' ).text( text[ preset ] ).attr( 'data-preset', preset ).appendTo( $presets );
		} );

		$header.append( $title, $presets );
		$area.append( $header, $body );

		if ( $tip.length && 'function' === typeof window.ur_init_tooltips ) {
			window.ur_init_tooltips( $tip );
		}

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

		function toggle() {
			$area.toggleClass( 'ur-ability-access--closed' );
			$header.attr( 'aria-expanded', $area.hasClass( 'ur-ability-access--closed' ) ? 'false' : 'true' );
		}

		$switches.on( 'change', refresh );
		$presets.on( 'click', 'button', function () {
			var preset = this.getAttribute( 'data-preset' );

			$switches.each( function () {
				var isOn = 'all' === preset || ( 'read' === preset && 'read' === this.getAttribute( 'data-ur-ability-level' ) );

				if ( this.checked !== isOn ) {
					$( this ).prop( 'checked', isOn ).trigger( 'change' );
				}
			} );
			refresh();
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
		refresh();
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
	} );
}( jQuery, urAbilityAccess ) );
