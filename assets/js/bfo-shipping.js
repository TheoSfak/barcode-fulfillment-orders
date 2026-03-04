/* global bfoShipping, jQuery */
/**
 * BFO Shipping — rate picker modal and label management.
 *
 * Handles:
 *   - "Get Rates" button → AJAX → display carrier rate modal
 *   - "Buy Label" inside modal → AJAX → update meta box DOM
 *   - "Void Label" button → AJAX → reload page
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.1.0
 */
( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Get Rates
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.bfo-get-rates-btn', function () {
		var $btn     = $( this );
		var orderId  = $btn.data( 'order-id' );
		var nonce    = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true ).text( bfoShipping.i18n.loading );

		$.ajax( {
			url    : bfoShipping.ajaxUrl,
			method : 'POST',
			data   : {
				action   : 'bfo_get_shipping_rates',
				order_id : orderId,
				nonce    : nonce,
			},
			success : function ( res ) {
				$btn.prop( 'disabled', false ).text( bfoShipping.i18n.getRates );
				if ( ! res.success ) {
					bfoAlert( res.data.message || bfoShipping.i18n.error );
					return;
				}
				showRatesModal( orderId, nonce, res.data );
			},
			error : function () {
				$btn.prop( 'disabled', false ).text( bfoShipping.i18n.getRates );
				bfoAlert( bfoShipping.i18n.error );
			},
		} );
	} );

	// -------------------------------------------------------------------------
	// Rates modal
	// -------------------------------------------------------------------------

	function showRatesModal( orderId, nonce, data ) {
		$( '#bfo-rates-modal' ).remove();

		var rates       = data.rates || [];
		var shipmentId  = data.shipment_id || '';

		var html = '<div id="bfo-rates-modal" class="bfo-modal-overlay" role="dialog" aria-modal="true">'
			+ '<div class="bfo-modal">'
			+ '<button class="bfo-modal-close" aria-label="' + esc( bfoShipping.i18n.close ) + '">&times;</button>'
			+ '<h2 class="bfo-modal-title">' + esc( bfoShipping.i18n.selectRate ) + '</h2>';

		if ( rates.length === 0 ) {
			html += '<p class="bfo-modal-empty">' + esc( bfoShipping.i18n.noRates ) + '</p>';
		} else {
			html += '<div class="bfo-rates-list" role="radiogroup">';
			rates.forEach( function ( rate ) {
				var days = rate.days ? ' &nbsp;<span class="bfo-rate-days">(' + esc( rate.days ) + ' ' + esc( bfoShipping.i18n.days ) + ')</span>' : '';
				html += '<label class="bfo-rate-row">'
					+ '<input type="radio" name="bfo_rate" value="' + esc( rate.id ) + '">'
					+ '<span class="bfo-rate-badge">' + esc( rate.carrier ) + '</span>'
					+ '<span class="bfo-rate-service">' + esc( rate.service ) + '</span>'
					+ days
					+ '<span class="bfo-rate-price">' + esc( rate.currency ) + '&nbsp;' + esc( rate.price ) + '</span>'
					+ '</label>';
			} );
			html += '</div>';
			html += '<div class="bfo-modal-footer">'
				+ '<button type="button" class="button button-primary bfo-buy-label-btn"'
				+ ' data-order-id="' + esc( orderId ) + '"'
				+ ' data-nonce="' + esc( nonce ) + '"'
				+ ' data-shipment-id="' + esc( shipmentId ) + '">'
				+ esc( bfoShipping.i18n.buyLabel )
				+ '</button>'
				+ '</div>';
		}

		html += '</div></div>';

		$( 'body' ).append( html );

		// Auto-select cheapest (first row).
		$( '#bfo-rates-modal input[type="radio"]:first' ).prop( 'checked', true );

		// Close handlers.
		$( document ).on( 'click.bfoModal', '#bfo-rates-modal .bfo-modal-close', function () {
			closeModal();
		} );
		$( document ).on( 'click.bfoModal', '#bfo-rates-modal.bfo-modal-overlay', function ( e ) {
			if ( $( e.target ).is( '#bfo-rates-modal' ) ) {
				closeModal();
			}
		} );
		$( document ).on( 'keydown.bfoModal', function ( e ) {
			if ( 27 === e.which ) {
				closeModal();
			}
		} );
	}

	function closeModal() {
		$( '#bfo-rates-modal' ).remove();
		$( document ).off( 'click.bfoModal keydown.bfoModal' );
	}

	// -------------------------------------------------------------------------
	// Buy Label
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.bfo-buy-label-btn', function () {
		var $btn        = $( this );
		var orderId     = $btn.data( 'order-id' );
		var nonce       = $btn.data( 'nonce' );
		var shipmentId  = $btn.data( 'shipment-id' );
		var $radio      = $( 'input[name="bfo_rate"]:checked' );

		if ( ! $radio.length ) {
			bfoAlert( bfoShipping.i18n.selectRateFirst );
			return;
		}

		$btn.prop( 'disabled', true ).text( bfoShipping.i18n.buying );

		$.ajax( {
			url    : bfoShipping.ajaxUrl,
			method : 'POST',
			data   : {
				action       : 'bfo_buy_shipping_label',
				order_id     : orderId,
				nonce        : nonce,
				rate_id      : $radio.val(),
				shipment_id  : shipmentId,
			},
			success : function ( res ) {
				closeModal();
				if ( ! res.success ) {
					bfoAlert( res.data.message || bfoShipping.i18n.error );
					return;
				}
				// Update meta box content inline.
				$( '#bfo_shipping .bfo-shipping-info' ).html( res.data.html );
				// Reload to reflect new order status badge etc.
				setTimeout( function () {
					location.reload();
				}, 1200 );
			},
			error : function () {
				$btn.prop( 'disabled', false ).text( bfoShipping.i18n.buyLabel );
				bfoAlert( bfoShipping.i18n.error );
			},
		} );
	} );

	// -------------------------------------------------------------------------
	// Void Label
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.bfo-void-label-btn', function () {
		if ( ! window.confirm( bfoShipping.i18n.voidConfirm ) ) {
			return;
		}

		var $btn    = $( this );
		var orderId = $btn.data( 'order-id' );
		var nonce   = $btn.data( 'nonce' );

		$btn.prop( 'disabled', true );

		$.ajax( {
			url    : bfoShipping.ajaxUrl,
			method : 'POST',
			data   : {
				action   : 'bfo_void_shipping_label',
				order_id : orderId,
				nonce    : nonce,
			},
			success : function ( res ) {
				if ( ! res.success ) {
					bfoAlert( res.data.message || bfoShipping.i18n.error );
					$btn.prop( 'disabled', false );
					return;
				}
				location.reload();
			},
			error : function () {
				$btn.prop( 'disabled', false );
				bfoAlert( bfoShipping.i18n.error );
			},
		} );
	} );

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function esc( str ) {
		if ( null == str ) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function bfoAlert( msg ) {
		// Use WP admin notice if available, otherwise fall back to alert().
		window.alert( msg );
	}

} )( jQuery );
