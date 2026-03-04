/* global Html5Qrcode, bfoPackConfig, BFOPacking */
/**
 * bfo-camera-scanner.js
 *
 * Wraps the html5-qrcode library to provide camera barcode scanning on the
 * BFO fulfillment screen. When a code is decoded it is passed to BFOPacking.processScan().
 *
 * Requires:
 *  - html5-qrcode.min.js to be loaded before this script.
 *  - bfoPackConfig.cameraOn === true
 *  - A <div id="bfo-camera-preview"></div> in the DOM.
 *
 * @package BarcodeFullfillmentOrders
 * @since   1.0.0
 */
( function () {
	'use strict';

	// Guard: only load if camera scanning is enabled.
	if ( ! bfoPackConfig.cameraOn ) return;
	if ( typeof Html5Qrcode === 'undefined' ) {
		console.warn( '[BFO] html5-qrcode library not loaded — camera scanning unavailable.' );
		return;
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------
	let scanner     = null;
	let isRunning   = false;
	let useFrontCam = false;

	// -------------------------------------------------------------------------
	// DOM
	// -------------------------------------------------------------------------
	const previewEl  = document.getElementById( 'bfo-camera-preview' );
	const toggleBtn  = document.getElementById( 'bfo-camera-toggle-btn' );
	const flipBtn    = document.getElementById( 'bfo-camera-flip-btn' );

	if ( ! previewEl ) return;

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		scanner = new Html5Qrcode( 'bfo-camera-preview' );
		bindButtons();
	} );

	// -------------------------------------------------------------------------
	// Controls
	// -------------------------------------------------------------------------
	function bindButtons() {
		if ( toggleBtn ) {
			toggleBtn.addEventListener( 'click', function () {
				isRunning ? stopCamera() : startCamera();
			} );
		}

		if ( flipBtn ) {
			flipBtn.addEventListener( 'click', function () {
				useFrontCam = ! useFrontCam;
				if ( isRunning ) {
					stopCamera().then( startCamera );
				}
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Start / Stop
	// -------------------------------------------------------------------------
	function startCamera() {
		if ( ! scanner ) return;

		previewEl.classList.remove( 'bfo-hidden' );

		const facingMode = useFrontCam ? 'user' : 'environment';
		const config = {
			fps:            15,
			qrbox:          { width: 250, height: 180 },
			aspectRatio:    1.333,
			facingMode:     facingMode,
			formatsToSupport: [
				Html5Qrcode.SUPPORTED_FORMATS ? undefined : null,
			].filter( Boolean ),
		};

		scanner.start(
			{ facingMode: facingMode },
			config,
			onDecode,
			function ( /* errorMessage */ ) {
				// Scanning but no code detected — ignore per-frame errors.
			}
		).then( function () {
			isRunning = true;
			if ( toggleBtn ) {
				toggleBtn.textContent = bfoPackConfig.i18n.cameraStop || 'Stop Camera';
				toggleBtn.classList.add( 'button-primary' );
			}
		} ).catch( function ( err ) {
			isRunning = false;
			previewEl.classList.add( 'bfo-hidden' );
			if ( BFOPacking ) {
				BFOPacking.showAlert(
					( bfoPackConfig.i18n.cameraError || 'Camera error: ' ) + err,
					'error'
				);
			}
		} );
	}

	function stopCamera() {
		if ( ! scanner || ! isRunning ) return Promise.resolve();

		return scanner.stop().then( function () {
			isRunning = false;
			previewEl.classList.add( 'bfo-hidden' );
			if ( toggleBtn ) {
				toggleBtn.textContent = bfoPackConfig.i18n.cameraStart || 'Start Camera';
				toggleBtn.classList.remove( 'button-primary' );
			}
		} ).catch( function () {
			isRunning = false;
		} );
	}

	// -------------------------------------------------------------------------
	// Decode Callback
	// -------------------------------------------------------------------------
	/**
	 * Called by html5-qrcode each time a barcode is successfully decoded.
	 *
	 * @param {string} decodedText
	 */
	function onDecode( decodedText ) {
		if ( ! decodedText ) return;

		// Throttle: ignore duplicate scans within 1 second.
		const now = Date.now();
		if ( onDecode._lastText === decodedText && ( now - ( onDecode._lastTime || 0 ) ) < 1000 ) {
			return;
		}
		onDecode._lastText = decodedText;
		onDecode._lastTime = now;

		// Delegate to main packing controller.
		if ( window.BFOPacking && typeof BFOPacking.processScan === 'function' ) {
			BFOPacking.processScan( decodedText );
		}
	}

} )();
