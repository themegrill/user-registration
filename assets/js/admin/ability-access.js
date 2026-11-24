/**
 * AI Abilities Access card: groups the switches by area into panels, each with a count, quick choices and an open and close header, plus a search.
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
		if ( slugs.length ) {
			search( $( '#ur-ability-access' ) );
		}
	} );

	// A search in the card header: shows only the abilities whose name, or whose area's name, matches, in open areas.
	function search( $card ) {
		var $areas = $card.find( '.ur-ability-access__area' );
		var $box = $( '<label class="ur-ability-access__search"></label>' );
		var $input = $( '<input type="search" id="ur-ability-access-search">' ).attr( { placeholder: text.search, 'aria-label': text.search } );
		var $empty = $( '<p class="ur-ability-access__empty"></p>' ).text( text.noMatch ).hide();
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
				closed = $areas.filter( '.ur-ability-access--closed' );
			}

			$areas.each( function () {
				var $area = $( this );
				var $rows = $area.find( '.user-registration-global-settings' );
				var areaMatch = query && -1 !== words( $area.find( '.ur-ability-access__title' ) ).indexOf( query );
				var shown = 0;

				$rows.each( function () {
					var show = ! query || areaMatch || -1 !== words( $( this ).children( 'label' ) ).indexOf( query );

					$( this ).toggle( show );
					shown += show ? 1 : 0;
				} );
				$area.find( '.ur-ability-access__group' ).each( function () {
					$( this ).toggle( $( this ).nextUntil( '.ur-ability-access__group', '.user-registration-global-settings' ).filter( function () {
						return 'none' !== this.style.display;
					} ).length > 0 );
				} );
				$area.toggle( shown > 0 );
				if ( query ) {
					$area.removeClass( 'ur-ability-access--closed' );
				}
				found += shown;
			} );

			if ( ! query && null !== closed ) {
				closed.addClass( 'ur-ability-access--closed' );
				closed = null;
			}
			$areas.each( function () {
				$( this ).children( '.ur-ability-access__header' ).attr( 'aria-expanded', $( this ).hasClass( 'ur-ability-access--closed' ) ? 'false' : 'true' );
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
}( jQuery, urAbilityAccess ) );
