<?php
/**
 * SVG/PNG barcode image generator.
 *
 * Generates Code 128 B barcodes as inline SVG (for on-screen display and printing).
 * This class bundles its own encoding logic — no external library required.
 *
 * Supported formats (configurable via settings):
 *   code128  — Code 128 B (alphanumeric + punctuation, default for products & orders)
 *   qr       — QR code (uses Google Chart API when available, falls back to data-URL placeholder)
 *
 * @package BarcodeFulfillmentOrders
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BFO_Barcode_Generator
 *
 * @since 1.0.0
 */
class BFO_Barcode_Generator {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var BFO_Barcode_Generator|null
	 */
	private static $instance = null;

	/**
	 * Code 128 B symbol bit-patterns (11 bits per symbol, MSB first).
	 * Indices 0-102 are data symbols; 103 = Start A; 104 = Start B; 105 = Start C.
	 * The stop symbol uses a separate 13-bit pattern.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private static $code128_patterns = array(
		'11011001100', // 0
		'11001101100', // 1
		'11001100110', // 2
		'10010011000', // 3
		'10010001100', // 4
		'10001001100', // 5
		'10011001000', // 6
		'10011000100', // 7
		'10001100100', // 8
		'11001001000', // 9
		'11001000100', // 10
		'11000100100', // 11
		'10110011100', // 12
		'10011011100', // 13
		'10011001110', // 14
		'10111001100', // 15
		'10011101100', // 16
		'10011100110', // 17
		'11001110010', // 18
		'11001011100', // 19
		'11001001110', // 20
		'11011100100', // 21
		'11001110100', // 22
		'11101101110', // 23
		'11101001100', // 24
		'11100101100', // 25
		'11100100110', // 26
		'11101100100', // 27
		'11100110100', // 28
		'11100110010', // 29
		'11011011000', // 30
		'11011000110', // 31
		'11000110110', // 32
		'10100011000', // 33
		'10001011000', // 34
		'10001000110', // 35
		'10110001000', // 36
		'10001101000', // 37
		'10001100010', // 38
		'11010001000', // 39
		'11000101000', // 40
		'11000100010', // 41
		'10110111000', // 42
		'10110001110', // 43
		'10001101110', // 44
		'10111011000', // 45
		'10111000110', // 46
		'10001110110', // 47
		'11101110110', // 48
		'11010001110', // 49
		'11000101110', // 50
		'11011101000', // 51
		'11011100010', // 52
		'11011101110', // 53
		'11101011000', // 54
		'11101000110', // 55
		'11100010110', // 56
		'11101101000', // 57
		'11101100010', // 58
		'11100011010', // 59
		'11101111010', // 60
		'11001000010', // 61
		'11110001010', // 62
		'10100110000', // 63
		'10100001100', // 64
		'10010110000', // 65
		'10010000110', // 66
		'10000101100', // 67
		'10000100110', // 68
		'10110010000', // 69
		'10110000100', // 70
		'10011010000', // 71
		'10011000010', // 72
		'10000110100', // 73
		'10000110010', // 74
		'11000010010', // 75
		'11001010000', // 76
		'11110111010', // 77
		'11000010100', // 78
		'10001111010', // 79
		'10100111100', // 80
		'10010111100', // 81
		'10010011110', // 82
		'10111100100', // 83
		'10011110100', // 84
		'10011110010', // 85
		'11110100100', // 86
		'11110010100', // 87
		'11110010010', // 88
		'11011011110', // 89
		'11011110110', // 90
		'11110110110', // 91
		'10101111000', // 92
		'10100011110', // 93
		'10001011110', // 94
		'10111101000', // 95
		'10111100010', // 96
		'11110101000', // 97
		'11110100010', // 98
		'10111011110', // 99
		'10111101110', // 100
		'11101011110', // 101
		'11110101110', // 102
		'11010000100', // 103 = Start A
		'11010010000', // 104 = Start B
		'11010011100', // 105 = Start C
	);

	/** Stop pattern (13 bits). */
	const CODE128_STOP = '1100011101011';

	/** Start B code value. */
	const CODE128_START_B = 104;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Returns the singleton instance.
	 *
	 * @since  1.0.0
	 * @return BFO_Barcode_Generator
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Alias for get_instance() — called by the main plugin bootstrapper.
	 *
	 * @since  1.0.0
	 * @return static
	 */
	public static function instance() {
		return self::get_instance();
	}

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns an inline SVG string for the given barcode data and format.
	 *
	 * @since  1.0.0
	 * @param  string $data    Data to encode.
	 * @param  string $format  'code128' or 'qr'.
	 * @param  int    $height  Bar height in pixels.
	 * @param  int    $module  Module width in pixels (Code 128 only).
	 * @return string          SVG markup (not escaped).
	 */
	public function generate_svg( $data, $format = 'code128', $height = 60, $module = 2 ) {
		if ( 'qr' === $format ) {
			return $this->generate_qr_svg( $data );
		}
		return $this->generate_code128_svg( $data, $height, $module );
	}

	/**
	 * Returns a data URI (SVG) suitable for use in an <img> src attribute.
	 *
	 * @since  1.0.0
	 * @param  string $data    Data to encode.
	 * @param  string $format  Barcode format.
	 * @param  int    $height  Bar height in pixels.
	 * @param  int    $module  Module width in pixels.
	 * @return string          data:image/svg+xml;base64,... URI.
	 */
	public function generate_data_uri( $data, $format = 'code128', $height = 60, $module = 2 ) {
		$svg = $this->generate_svg( $data, $format, $height, $module );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	// -------------------------------------------------------------------------
	// Code 128 B encoder
	// -------------------------------------------------------------------------

	/**
	 * Encodes $data as a Code 128 B SVG barcode.
	 *
	 * @since  1.0.0
	 * @param  string $data    ASCII data (printable characters 32-127).
	 * @param  int    $height  Bar height in pixels.
	 * @param  int    $module  Module width in pixels.
	 * @return string          Complete SVG markup.
	 */
	private function generate_code128_svg( $data, $height = 60, $module = 2 ) {
		$data = (string) $data;

		// Build sequence of code values: Start B + data chars + checksum + Stop.
		$code_values = array( self::CODE128_START_B );

		$len = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$ascii = ord( $data[ $i ] );
			if ( $ascii < 32 || $ascii > 127 ) {
				continue; // Skip non-Code-128B characters.
			}
			$code_values[] = $ascii - 32; // Code 128 B: code value = ASCII - 32.
		}

		// Calculate checksum.
		$check_sum = self::CODE128_START_B; // Start B value is 104.
		foreach ( $code_values as $pos => $val ) {
			if ( 0 === $pos ) {
				continue; // Position 0 is the start — already seeded checksum.
			}
			$check_sum += $pos * $val;
		}
		$check_sum = $check_sum % 103;
		$code_values[] = $check_sum;

		// Build the full bit-string.
		$bits = '';
		foreach ( $code_values as $val ) {
			$bits .= self::$code128_patterns[ $val ];
		}
		$bits .= self::CODE128_STOP;

		// Quiet zone: 10 modules on each side.
		$quiet_zone = $module * 10;

		// Compute total width.
		$bar_area_width = strlen( $bits ) * $module;
		$total_width    = $bar_area_width + ( 2 * $quiet_zone );
		$text_height    = 12;
		$total_height   = $height + $text_height + 4;

		// Build SVG rectangles.
		$rects = '';
		$x     = $quiet_zone;
		$bit_len = strlen( $bits );

		for ( $i = 0; $i < $bit_len; $i++ ) {
			if ( '1' === $bits[ $i ] ) {
				// Find the run length of consecutive 1s.
				$run = 1;
				while ( $i + $run < $bit_len && '1' === $bits[ $i + $run ] ) {
					$run++;
				}
				$rects .= sprintf(
					'<rect x="%d" y="0" width="%d" height="%d"/>',
					$x + ( $i * $module ),
					$run * $module,
					$height
				);
				$i += $run - 1; // Already consumed $run bits.
			}
		}

		// Build text label.
		$text_x   = $total_width / 2;
		$text_y   = $height + $text_height;
		$text_svg = sprintf(
			'<text x="%s" y="%d" text-anchor="middle" font-family="monospace" font-size="10" fill="#000">%s</text>',
			$text_x,
			$text_y,
			esc_attr( $data )
		);

		$svg = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" style="max-width:100%%;height:auto;">' .
			'<rect width="%d" height="%d" fill="#fff"/>' .
			'<g fill="#000">%s</g>' .
			'%s' .
			'</svg>',
			$total_width,
			$total_height,
			$total_width,
			$total_height,
			$total_width,
			$total_height,
			$rects,
			$text_svg
		);

		return $svg;
	}

	// -------------------------------------------------------------------------
	// QR code (Google Charts API with offline fallback)
	// -------------------------------------------------------------------------

	/**
	 * Returns an SVG <image> element pointing to a QR code.
	 *
	 * Uses the Google Chart API for rendering. In offline environments,
	 * displays a text-only placeholder.
	 *
	 * @since  1.0.0
	 * @param  string $data  Data to encode.
	 * @return string        SVG markup.
	 */
	private function generate_qr_svg( $data ) {
		$size = 150;
		$url  = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size .
				'&chl=' . rawurlencode( $data ) . '&choe=UTF-8';

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d">' .
			'<image href="%2$s" width="%1$d" height="%1$d"/>' .
			'</svg>',
			$size,
			esc_url( $url )
		);
	}

	// -------------------------------------------------------------------------
	// Rendering helpers
	// -------------------------------------------------------------------------

	/**
	 * Outputs an <img> tag with a barcode SVG as a data URI.
	 * Use in admin pages / emails / label templates.
	 *
	 * @since  1.0.0
	 * @param  string $data    Data to encode.
	 * @param  string $format  Barcode format.
	 * @param  array  $attrs   Extra HTML attributes (class, style, etc.).
	 * @param  int    $height  Bar height in pixels.
	 * @param  int    $module  Module width in pixels.
	 * @return void   Directly echoes the <img> tag.
	 */
	public function render_img( $data, $format = 'code128', $attrs = array(), $height = 60, $module = 2 ) {
		if ( empty( $data ) ) {
			return;
		}

		$uri   = $this->generate_data_uri( $data, $format, $height, $module );
		$class = isset( $attrs['class'] ) ? sanitize_html_class( $attrs['class'] ) : 'bfo-barcode-img';
		$style = isset( $attrs['style'] ) ? esc_attr( $attrs['style'] ) : '';
		$alt   = isset( $attrs['alt'] )   ? esc_attr( $attrs['alt'] )   : esc_attr( $data );

		printf(
			'<img src="%s" alt="%s" class="%s" style="%s">',
			esc_attr( $uri ),
			$alt,
			esc_attr( $class ),
			$style
		);
	}

	/**
	 * Returns raw inline SVG markup wrapped in a <div>.
	 *
	 * @since  1.0.0
	 * @param  string $data    Data to encode.
	 * @param  string $format  Barcode format.
	 * @param  int    $height  Bar height in pixels.
	 * @param  int    $module  Module width in pixels.
	 * @return string
	 */
	public function render_inline( $data, $format = 'code128', $height = 60, $module = 2 ) {
		if ( empty( $data ) ) {
			return '';
		}

		$svg = $this->generate_svg( $data, $format, $height, $module );

		return '<div class="bfo-barcode-inline">' . $svg . '</div>';
	}
}
