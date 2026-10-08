/**
 * Date picker for the plugin's date fields on the WordPress profile screens.
 *
 * Mirrors the date field handler in form-builder.js so those screens no longer need the Form Builder bundle.
 */
jQuery( function ( $ ) {
	$( document.body ).on( 'click', '.ur-flatpickr-field', function () {
		var $field = $( this );

		if ( ! this._flatpickr ) {
			// Flatpickr reads data-default-date, which holds a "1"/"true" flag here, not a date.
			$field.attr( 'data-default-date', $field.val() );
			$field.flatpickr( {
				disableMobile: true,
				onChange: function ( selectedDates, dateString ) {
					$( '#' + $field.data( 'id' ) ).val( dateString );
				},
			} );
		}

		this._flatpickr.open();
	} );
} );
