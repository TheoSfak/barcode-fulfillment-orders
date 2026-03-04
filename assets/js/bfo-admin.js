/**
 * bfo-admin.js
 *
 * General admin utilities for product/order screens:
 *  - "Auto-Generate" barcode button on the product edit page.
 *  - Inline barcode preview refresh after barcode field change.
 *  - Dismissible admin notices.
 *
 * @package BarcodeFullfillmentOrders
 * @since   1.0.0
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		bindAutoGenerateButtons();
		bindBarcodeFieldPreview();
		bindDismissibleNotices();
	} );

	// -------------------------------------------------------------------------
	// Auto-Generate Barcode Button (product edit page)
	// -------------------------------------------------------------------------
	function bindAutoGenerateButtons() {
		document.querySelectorAll( '.bfo-auto-generate-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				const productId = btn.dataset.productId;
				if ( ! productId ) return;

				btn.disabled    = true;
				btn.textContent = bfoAdminConfig.i18n.generating || 'Generating…';

				const body = new URLSearchParams( {
					action:     'bfo_generate_product_barcode',
					security:   bfoAdminConfig.nonce,
					product_id: productId,
				} );

				fetch( bfoAdminConfig.ajaxUrl, { method: 'POST', body } )
					.then( r => r.json() )
					.then( function ( res ) {
						btn.disabled    = false;
						btn.textContent = bfoAdminConfig.i18n.generate || 'Auto-Generate';

						if ( res.success && res.data ) {
							// Update barcode text input.
							const input = document.getElementById( 'bfo_product_barcode' );
							if ( input ) input.value = res.data.barcode;

							// Update SVG preview.
							const preview = document.getElementById( 'bfo-barcode-preview' );
							if ( preview ) preview.innerHTML = res.data.svg;
						} else {
							alert( res.data?.message || 'Failed to generate barcode.' );
						}
					} )
					.catch( function () {
						btn.disabled    = false;
						btn.textContent = bfoAdminConfig.i18n.generate || 'Auto-Generate';
						alert( bfoAdminConfig.i18n.networkError || 'Network error.' );
					} );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Barcode Field Live Preview (re-generate SVG when user edits the field)
	// -------------------------------------------------------------------------
	function bindBarcodeFieldPreview() {
		const input   = document.getElementById( 'bfo_product_barcode' );
		const preview = document.getElementById( 'bfo-barcode-preview' );

		if ( ! input || ! preview ) return;

		let debounceTimer = null;

		input.addEventListener( 'input', function () {
			clearTimeout( debounceTimer );
			debounceTimer = setTimeout( function () {
				const barcode = input.value.trim();
				if ( ! barcode ) {
					preview.innerHTML = '';
					return;
				}

				const body = new URLSearchParams( {
					action:     'bfo_preview_barcode_svg',
					security:   bfoAdminConfig.nonce,
					barcode:    barcode,
				} );

				fetch( bfoAdminConfig.ajaxUrl, { method: 'POST', body } )
					.then( r => r.json() )
					.then( function ( res ) {
						if ( res.success && res.data?.svg ) {
							preview.innerHTML = res.data.svg;
						}
					} )
					.catch( () => {} );
			}, 600 );
		} );
	}

	// -------------------------------------------------------------------------
	// Dismissible Admin Notices
	// -------------------------------------------------------------------------
	function bindDismissibleNotices() {
		document.querySelectorAll( '.bfo-notice.is-dismissible' ).forEach( function ( notice ) {
			const btn = notice.querySelector( '.notice-dismiss' );
			if ( btn ) {
				btn.addEventListener( 'click', function () {
					notice.remove();
				} );
			}
		} );
	}

} )();
