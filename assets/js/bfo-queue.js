/* global bfoQueueConfig */
/**
 * bfo-queue.js
 *
 * Order queue page controller.
 *  - Auto-refreshes the queue table at a configurable interval.
 *  - Handles "Start Packing" AJAX button clicks with redirect.
 *  - Supports scanning an order barcode directly in the queue search box.
 *
 * Requires bfoQueueConfig (localised by BFO_Order_Queue::enqueue_scripts()):
 *  {
 *    ajaxUrl     : string,
 *    nonces      : { refresh: string, start: string },
 *    refresh     : number,   // seconds (0 = disabled)
 *    i18n        : { starting: string, startFailed: string, networkError: string }
 *  }
 *
 * @package BarcodeFullfillmentOrders
 * @since   1.0.0
 */
( function () {
	'use strict';

	const cfg  = bfoQueueConfig;
	const i18n = cfg.i18n || {};

	let refreshTimer = null;

	document.addEventListener( 'DOMContentLoaded', function () {
		bindStartButtons();
		bindBarcodeSearch();
		scheduleRefresh();
	} );

	// -------------------------------------------------------------------------
	// Auto-refresh
	// -------------------------------------------------------------------------
	function scheduleRefresh() {
		const interval = parseInt( cfg.refresh, 10 );
		if ( ! interval || interval < 10 ) return;

		refreshTimer = setInterval( refreshTable, interval * 1000 );
	}

	function refreshTable() {
		const body = new URLSearchParams( {
			action:   'bfo_queue_data',
			security: cfg.nonces.refresh,
		} );

		fetch( cfg.ajaxUrl, { method: 'POST', body } )
			.then( r => r.json() )
			.then( function ( res ) {
				if ( res.success && res.data?.html ) {
					const tableWrap = document.getElementById( 'bfo-queue-table-wrap' );
					if ( tableWrap ) {
						tableWrap.innerHTML = res.data.html;
						// Re-bind start buttons after DOM replacement.
						bindStartButtons();
					}
				}
			} )
			.catch( function () {
				// Silently fail — temporary network hiccup should not disrupt the worker.
			} );
	}

	// -------------------------------------------------------------------------
	// Start Packing
	// -------------------------------------------------------------------------
	function bindStartButtons() {
		document.querySelectorAll( '.bfo-start-packing-btn' ).forEach( function ( btn ) {
			if ( btn.dataset.bound ) return;
			btn.dataset.bound = '1';

			btn.addEventListener( 'click', function () {
				const orderId = btn.dataset.orderId;
				if ( ! orderId ) return;

				btn.disabled    = true;
				btn.textContent = i18n.starting || 'Starting…';

				const body = new URLSearchParams( {
					action:   'bfo_start_packing_session',
					security: btn.dataset.nonce,
					order_id: orderId,
				} );

				fetch( cfg.ajaxUrl, { method: 'POST', body } )
					.then( r => r.json() )
					.then( function ( res ) {
						if ( res.success && res.data?.redirect ) {
							// Stop the auto-refresh so we don't fight the navigation.
							if ( refreshTimer ) clearInterval( refreshTimer );
							window.location.href = res.data.redirect;
						} else {
							btn.disabled    = false;
							btn.textContent = btn.dataset.originalLabel || 'Start Packing';
							alert( res.data?.message || i18n.startFailed || 'Could not start packing session.' );
						}
					} )
					.catch( function () {
						btn.disabled    = false;
						btn.textContent = btn.dataset.originalLabel || 'Start Packing';
						alert( i18n.networkError || 'Network error.' );
					} );
			} );

			// Stash original label.
			btn.dataset.originalLabel = btn.textContent;
		} );
	}

	// -------------------------------------------------------------------------
	// Barcode Search (scan order barcode to jump straight to pack screen)
	// -------------------------------------------------------------------------
	function bindBarcodeSearch() {
		const input = document.getElementById( 'bfo-queue-barcode-search' );
		if ( ! input ) return;

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' ) return;
			e.preventDefault();

			const barcode = input.value.trim();
			if ( ! barcode ) return;
			input.value = '';

			const body = new URLSearchParams( {
				action:   'bfo_queue_barcode_search',
				security: cfg.nonces.refresh,
				barcode:  barcode,
			} );

			fetch( cfg.ajaxUrl, { method: 'POST', body } )
				.then( r => r.json() )
				.then( function ( res ) {
					if ( res.success && res.data?.redirect ) {
						window.location.href = res.data.redirect;
					} else {
						const msg = document.getElementById( 'bfo-queue-search-msg' );
						if ( msg ) {
							msg.textContent = res.data?.message || ( i18n.notFound || 'Order not found.' );
							msg.style.display = '';
							setTimeout( () => { msg.style.display = 'none'; }, 3000 );
						}
					}
				} )
				.catch( () => {} );
		} );
	}

} )();
