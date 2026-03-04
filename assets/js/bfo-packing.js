/* global bfoPackConfig, Html5Qrcode */
/**
 * bfo-packing.js
 *
 * Main controller for the BFO fulfillment / packing screen.
 *
 * Dependencies:
 *  - bfoPackConfig  (localized via BFO_Fulfillment_Screen::render_page())
 *  - bfo-camera-scanner.js  (optional, loaded when camera enabled)
 *
 * @package BarcodeFullfillmentOrders
 * @since   1.0.0
 */
( function () {
	'use strict';

	// -------------------------------------------------------------------------
	// Config shorthand
	// -------------------------------------------------------------------------
	const cfg    = bfoPackConfig;
	const i18n   = cfg.i18n;

	// -------------------------------------------------------------------------
	// DOM references (resolved after DOMContentLoaded)
	// -------------------------------------------------------------------------
	let scanInput, alertArea, progressFill, progressLabel,
		completeBtn, pauseBtn, cancelBtn, addBoxBtn,
		missingModal, missingForm, missingProductId, missingQty, missingReason, missingNotes,
		unacctModal;

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------
	const state = {
		items:       {}, // keyed by product_id, value: {needed, scanned, status}
		activeBoxId: cfg.firstBoxId || null,
		heartbeatId: null,
		alertTimer:  null,
	};

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		resolveDOM();
		initItems();
		bindScanInput();
		bindActionButtons();
		bindMissingModal();
		bindBoxPanel();
		startHeartbeat();
	} );

	function resolveDOM() {
		scanInput     = document.getElementById( 'bfo-scan-input' );
		alertArea     = document.getElementById( 'bfo-alert-area' );
		progressFill  = document.getElementById( 'bfo-progress-fill' );
		progressLabel = document.getElementById( 'bfo-progress-label' );
		completeBtn   = document.getElementById( 'bfo-complete-btn' );
		pauseBtn      = document.getElementById( 'bfo-pause-btn' );
		cancelBtn     = document.getElementById( 'bfo-cancel-btn' );
		addBoxBtn     = document.getElementById( 'bfo-add-box-btn' );
		missingModal  = document.getElementById( 'bfo-missing-modal' );
		missingForm   = document.getElementById( 'bfo-missing-form' );
		missingProductId = document.getElementById( 'bfo-missing-product-id' );
		missingQty    = document.getElementById( 'bfo-missing-qty' );
		missingReason = document.getElementById( 'bfo-missing-reason' );
		missingNotes  = document.getElementById( 'bfo-missing-notes' );
		unacctModal   = document.getElementById( 'bfo-unaccounted-modal' );
	}

	function initItems() {
		if ( ! cfg.items ) return;
		cfg.items.forEach( function ( item ) {
			state.items[ item.product_id ] = {
				needed:  item.needed,
				scanned: item.scanned,
				status:  item.status,
			};
		} );
		refreshAllRows();
		refreshProgress();
	}

	// -------------------------------------------------------------------------
	// Scan Input
	// -------------------------------------------------------------------------
	function bindScanInput() {
		if ( ! scanInput ) return;

		scanInput.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				const value = scanInput.value.trim();
				if ( value ) {
					processScan( value );
					scanInput.value = '';
				}
			}
		} );

		// Autofocus when page is clicked (scanner guns lose focus).
		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest( '.bfo-modal, button, a, input, select, textarea' ) ) {
				scanInput.focus();
			}
		} );
	}

	/**
	 * Sends a barcode scan to the server and processes the response.
	 *
	 * @param {string} barcode
	 */
	function processScan( barcode ) {
		const body = new URLSearchParams( {
			action:     'bfo_process_scan',
			security:   cfg.nonces.scan,
			session_id: cfg.sessionId,
			order_id:   cfg.orderId,
			barcode:    barcode,
			box_id:     state.activeBoxId || '',
		} );

		fetch( cfg.ajaxUrl, { method: 'POST', body } )
			.then( r => r.json() )
			.then( handleScanResponse )
			.catch( () => showAlert( i18n.networkError || 'Network error.', 'error' ) );
	}

	/**
	 * Handles the JSON response from bfo_process_scan.
	 *
	 * @param {Object} res
	 */
	function handleScanResponse( res ) {
		if ( ! res.success ) {
			showAlert( res.data?.message || i18n.unknownError, 'error' );
			playSound( 'error' );
			flashInput( 'bfo-input--error' );
			return;
		}

		const data = res.data;

		switch ( data.type ) {
			case 'success':
				showAlert( data.message || i18n.scanSuccess, 'success' );
				playSound( 'success' );
				flashInput( 'bfo-input--success' );
				break;

			case 'order_barcode':
				showAlert( data.message || i18n.orderBarcodeScanned, 'info' );
				playSound( 'info' );
				break;

			case 'over_scan':
				showAlert( data.message || i18n.overScan, 'warning' );
				playSound( 'warning' );
				flashInput( 'bfo-input--error' );
				break;

			case 'wrong_product':
				showAlert( data.message || i18n.wrongProduct, 'error' );
				playSound( 'error' );
				flashInput( 'bfo-input--error' );
				break;

			case 'unknown':
			default:
				showAlert( data.message || i18n.unknownBarcode, 'error' );
				playSound( 'error' );
				flashInput( 'bfo-input--error' );
				break;
		}

		if ( data.summary ) {
			applySummary( data.summary );
		}
	}

	// -------------------------------------------------------------------------
	// Summary Sync
	// -------------------------------------------------------------------------
	/**
	 * Updates item rows and progress bar from a summary array returned by the server.
	 *
	 * @param {Array} summary
	 */
	function applySummary( summary ) {
		summary.forEach( function ( item ) {
			state.items[ item.product_id ] = {
				needed:  item.needed !== undefined ? item.needed : item.ordered,
				scanned: item.scanned,
				status:  item.status,
			};
			refreshRow( item.product_id, item );
		} );
		refreshProgress();
	}

	/**
	 * Refreshes a single product row in the table.
	 *
	 * @param {number|string} productId
	 * @param {Object}        item
	 */
	function refreshRow( productId, item ) {
		const row = document.querySelector( '[data-product-id="' + productId + '"]' );
		if ( ! row ) return;

		const scannedCell = row.querySelector( '.bfo-cell-scanned' );
		const badgeCell   = row.querySelector( '.bfo-qty-badge' );
		const statusCell  = row.querySelector( '.bfo-cell-status' );
		const missingBtn  = row.querySelector( '.bfo-missing-btn' );

		if ( scannedCell ) scannedCell.textContent = item.scanned;

		if ( badgeCell ) {
			badgeCell.textContent = item.scanned + '/' + item.needed;
			badgeCell.className   = 'bfo-qty-badge bfo-qty-badge--' + item.status;
		}

		if ( statusCell ) {
			const icons = { pending: '⬜', partial: '🔵', complete: '✅', missing: '⚠️' };
			statusCell.textContent = icons[ item.status ] || '';
		}

		// Update row class.
		row.classList.remove( 'bfo-row--complete', 'bfo-row--missing', 'bfo-row--partial' );
		if ( item.status !== 'pending' ) {
			row.classList.add( 'bfo-row--' + item.status );
		}

		// Hide missing button for complete items.
		if ( missingBtn ) {
			missingBtn.style.display = item.status === 'complete' ? 'none' : '';
		}
	}

	function refreshAllRows() {
		Object.keys( state.items ).forEach( function ( id ) {
			refreshRow( id, state.items[ id ] );
		} );
	}

	/**
	 * Updates the top progress bar.
	 */
	function refreshProgress() {
		const items  = Object.values( state.items );
		const total  = items.reduce( ( acc, i ) => acc + i.needed,  0 );
		const scanned = items.reduce( ( acc, i ) => acc + Math.min( i.scanned, i.needed ), 0 );
		const pct    = total > 0 ? Math.round( ( scanned / total ) * 100 ) : 0;

		if ( progressFill ) {
			progressFill.style.width = pct + '%';
			progressFill.classList.toggle( 'bfo-progress-bar-fill--complete', pct === 100 );
		}

		if ( progressLabel ) {
			progressLabel.textContent = scanned + ' / ' + total + ' (' + pct + '%)';
		}
	}

	// -------------------------------------------------------------------------
	// Action Buttons
	// -------------------------------------------------------------------------
	function bindActionButtons() {
		if ( completeBtn ) {
			completeBtn.addEventListener( 'click', function () {
				handleCompleteOrder();
			} );
		}

		if ( pauseBtn ) {
			pauseBtn.addEventListener( 'click', function () {
				sessionAction( 'bfo_pause_session', cfg.nonces.pause, function ( url ) {
					window.location.href = url;
				} );
			} );
		}

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				if ( ! confirm( i18n.confirmCancel ) ) return;
				sessionAction( 'bfo_cancel_session', cfg.nonces.cancel, function ( url ) {
					window.location.href = url;
				} );
			} );
		}
	}

	function handleCompleteOrder() {
		const body = new URLSearchParams( {
			action:     'bfo_complete_order',
			security:   cfg.nonces.complete,
			session_id: cfg.sessionId,
			order_id:   cfg.orderId,
		} );

		fetch( cfg.ajaxUrl, { method: 'POST', body } )
			.then( r => r.json() )
			.then( function ( res ) {
				if ( res.success ) {
					if ( res.data?.unaccounted && res.data.unaccounted.length ) {
						showUnacctModal( res.data.unaccounted );
					} else {
						window.location.href = res.data?.redirect || cfg.queueUrl;
					}
				} else {
					showAlert( res.data?.message || i18n.completeFailed, 'error' );
				}
			} )
			.catch( () => showAlert( i18n.networkError, 'error' ) );
	}

	function sessionAction( action, nonce, onSuccess ) {
		const body = new URLSearchParams( {
			action:     action,
			security:   nonce,
			session_id: cfg.sessionId,
		} );

		fetch( cfg.ajaxUrl, { method: 'POST', body } )
			.then( r => r.json() )
			.then( function ( res ) {
				if ( res.success ) {
					onSuccess( res.data?.redirect || cfg.queueUrl );
				} else {
					showAlert( res.data?.message || i18n.actionFailed, 'error' );
				}
			} )
			.catch( () => showAlert( i18n.networkError, 'error' ) );
	}

	// -------------------------------------------------------------------------
	// Missing Items Modal
	// -------------------------------------------------------------------------
	function bindMissingModal() {
		// Open modal from "Mark Missing" button in the table.
		document.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( '.bfo-missing-btn' );
			if ( btn ) {
				e.preventDefault();
				openMissingModal( btn.dataset.productId, btn.dataset.remaining || 1 );
			}
		} );

		// Close button.
		const closeBtn = document.getElementById( 'bfo-missing-modal-close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', closeMissingModal );
		}

		// Click outside modal.
		if ( missingModal ) {
			missingModal.addEventListener( 'click', function ( e ) {
				if ( e.target === missingModal ) closeMissingModal();
			} );
		}

		// Form submit.
		if ( missingForm ) {
			missingForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				submitMissing();
			} );
		}
	}

	function openMissingModal( productId, remaining ) {
		if ( ! missingModal ) return;
		missingProductId.value = productId;
		missingQty.value       = remaining;
		missingQty.max         = remaining;
		missingReason.value    = '';
		if ( missingNotes ) missingNotes.value = '';
		missingModal.classList.add( 'bfo-modal--open' );
		missingReason.focus();
	}

	function closeMissingModal() {
		if ( missingModal ) missingModal.classList.remove( 'bfo-modal--open' );
	}

	function submitMissing() {
		const body = new URLSearchParams( {
			action:     'bfo_mark_missing',
			security:   cfg.nonces.missing,
			session_id: cfg.sessionId,
			order_id:   cfg.orderId,
			product_id: missingProductId.value,
			qty:        missingQty.value,
			reason:     missingReason.value,
			notes:      missingNotes ? missingNotes.value : '',
		} );

		fetch( cfg.ajaxUrl, { method: 'POST', body } )
			.then( r => r.json() )
			.then( function ( res ) {
				closeMissingModal();
				if ( res.success ) {
					showAlert( res.data?.message || i18n.markedMissing, 'warning' );
					if ( res.data?.summary ) applySummary( res.data.summary );
				} else {
					showAlert( res.data?.message || i18n.actionFailed, 'error' );
				}
			} )
			.catch( () => showAlert( i18n.networkError, 'error' ) );
	}

	// -------------------------------------------------------------------------
	// Unaccounted Items Modal
	// -------------------------------------------------------------------------
	function showUnacctModal( unaccounted ) {
		if ( ! unacctModal ) return;

		const list = document.getElementById( 'bfo-unaccounted-list' );
		if ( list ) {
			list.innerHTML = '';
			unaccounted.forEach( function ( item ) {
				const li = document.createElement( 'li' );
				li.textContent = item.name + ' — ' +
					( i18n.needed || 'needed' ) + ': ' + item.needed + ', ' +
					( i18n.scanned || 'scanned' ) + ': ' + item.scanned;
				list.appendChild( li );
			} );
		}

		unacctModal.classList.add( 'bfo-modal--open' );

		// Close button.
		const closeBtn = document.getElementById( 'bfo-unaccounted-close' );
		if ( closeBtn ) {
			closeBtn.onclick = function () {
				unacctModal.classList.remove( 'bfo-modal--open' );
			};
		}

		// Force-complete button.
		const forceBtn = document.getElementById( 'bfo-force-complete-btn' );
		if ( forceBtn ) {
			forceBtn.onclick = function () {
				unacctModal.classList.remove( 'bfo-modal--open' );
				window.location.href = cfg.queueUrl;
			};
		}
	}

	// -------------------------------------------------------------------------
	// Box Panel
	// -------------------------------------------------------------------------
	function bindBoxPanel() {
		if ( ! addBoxBtn || ! cfg.multiBox ) return;

		addBoxBtn.addEventListener( 'click', function () {
			const body = new URLSearchParams( {
				action:     'bfo_add_box',
				security:   cfg.nonces.box,
				session_id: cfg.sessionId,
			} );

			fetch( cfg.ajaxUrl, { method: 'POST', body } )
				.then( r => r.json() )
				.then( function ( res ) {
					if ( res.success && res.data?.box_id ) {
						appendBoxTab( res.data.box_id, res.data.label );
						state.activeBoxId = res.data.box_id;
					} else {
						showAlert( res.data?.message || i18n.actionFailed, 'error' );
					}
				} )
				.catch( () => showAlert( i18n.networkError, 'error' ) );
		} );

		// Switch active box when a tab is clicked.
		document.addEventListener( 'click', function ( e ) {
			const tab = e.target.closest( '.bfo-box-tab' );
			if ( tab ) {
				document.querySelectorAll( '.bfo-box-tab' ).forEach( t => t.classList.remove( 'bfo-box-tab--active' ) );
				tab.classList.add( 'bfo-box-tab--active' );
				state.activeBoxId = tab.dataset.boxId;
			}
		} );
	}

	function appendBoxTab( boxId, label ) {
		const tabContainer = document.getElementById( 'bfo-box-tabs' );
		if ( ! tabContainer ) return;

		// Deactivate all existing tabs.
		tabContainer.querySelectorAll( '.bfo-box-tab' ).forEach( t => t.classList.remove( 'bfo-box-tab--active' ) );

		const tab = document.createElement( 'button' );
		tab.type        = 'button';
		tab.className   = 'button bfo-box-tab bfo-box-tab--active';
		tab.dataset.boxId = boxId;
		tab.textContent  = label;
		tabContainer.appendChild( tab );
	}

	// -------------------------------------------------------------------------
	// Heartbeat
	// -------------------------------------------------------------------------
	function startHeartbeat() {
		const interval = ( cfg.heartbeat || 30 ) * 1000;

		state.heartbeatId = setInterval( function () {
			const body = new URLSearchParams( {
				action:     'bfo_session_heartbeat',
				security:   cfg.nonces.heartbeat,
				session_id: cfg.sessionId,
			} );

			// Fire and forget; don't disrupt the UI on failure.
			fetch( cfg.ajaxUrl, { method: 'POST', body } ).catch( () => {} );
		}, interval );
	}

	// -------------------------------------------------------------------------
	// Alert
	// -------------------------------------------------------------------------
	/**
	 * Displays a temporary alert message.
	 *
	 * @param {string} message
	 * @param {'success'|'error'|'warning'|'info'} type
	 * @param {number} duration  Auto-dismiss delay in ms (0 = permanent).
	 */
	function showAlert( message, type, duration ) {
		if ( ! alertArea ) return;

		if ( state.alertTimer ) clearTimeout( state.alertTimer );

		const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };

		alertArea.innerHTML = '<div class="bfo-alert bfo-alert--' + type + '">' +
			'<span class="bfo-alert__icon" aria-hidden="true">' + ( icons[ type ] || '' ) + '</span>' +
			'<span>' + escHtml( message ) + '</span></div>';

		const autoDismiss = ( duration !== undefined ) ? duration : 4000;
		if ( autoDismiss > 0 ) {
			state.alertTimer = setTimeout( function () {
				alertArea.innerHTML = '';
			}, autoDismiss );
		}
	}

	// -------------------------------------------------------------------------
	// Sound Cues
	// -------------------------------------------------------------------------
	/**
	 * Plays an audio cue using the Web Audio API. Gracefully silent if unavailable.
	 *
	 * @param {'success'|'error'|'warning'|'info'} type
	 */
	function playSound( type ) {
		if ( ! cfg.soundOn ) return;
		if ( typeof AudioContext === 'undefined' && typeof webkitAudioContext === 'undefined' ) return;

		try {
			const AudioCtx = window.AudioContext || window.webkitAudioContext;
			const ctx      = new AudioCtx();
			const osc      = ctx.createOscillator();
			const gain     = ctx.createGain();

			osc.connect( gain );
			gain.connect( ctx.destination );

			const presets = {
				success: { freq: 880, dur: 0.12, gain: 0.3 },
				error:   { freq: 220, dur: 0.3,  gain: 0.4 },
				warning: { freq: 440, dur: 0.2,  gain: 0.25 },
				info:    { freq: 660, dur: 0.1,  gain: 0.2 },
			};

			const p = presets[ type ] || presets.info;
			osc.frequency.setValueAtTime( p.freq, ctx.currentTime );
			gain.gain.setValueAtTime( p.gain, ctx.currentTime );
			gain.gain.exponentialRampToValueAtTime( 0.001, ctx.currentTime + p.dur );

			osc.start( ctx.currentTime );
			osc.stop( ctx.currentTime + p.dur );
		} catch ( _e ) {
			// Silently fail — sound is non-critical.
		}
	}

	// -------------------------------------------------------------------------
	// Input Flash
	// -------------------------------------------------------------------------
	function flashInput( cls ) {
		if ( ! scanInput ) return;
		scanInput.classList.add( cls );
		setTimeout( () => scanInput.classList.remove( cls ), 600 );
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------
	function escHtml( str ) {
		const d = document.createElement( 'div' );
		d.textContent = str;
		return d.innerHTML;
	}

	// -------------------------------------------------------------------------
	// Public API (for camera-scanner integration)
	// -------------------------------------------------------------------------
	window.BFOPacking = {
		processScan: processScan,
		showAlert:   showAlert,
	};

} )();
