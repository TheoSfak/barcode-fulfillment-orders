=== Barcode Fulfillment Orders ===
Contributors: irmaiden (Theodore Sfakianakis)
Tags: woocommerce, barcode, fulfillment, warehouse, packing, scanner
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Warehouse barcode-scanning fulfillment system for WooCommerce. Scan products into orders, track packing sessions, and print labels.

== Description ==

**Barcode Fulfillment Orders** turns your WooCommerce store into a fully-featured warehouse fulfillment system:

* Every product and order automatically receives a unique barcode (Code 128 or QR).
* Warehouse workers open the Order Queue, scan the order barcode, and are presented with a list of items to pack.
* They scan each product barcode as it goes into the box — the screen updates in real time.
* Over-scans, wrong products, and missing items are all handled gracefully with sound cues and on-screen alerts.
* Multi-box support: split large orders across multiple boxes, each with weight/dimension tracking.
* Print product labels, order labels, and packing slips directly from the WordPress admin.
* Full audit trail with per-session scan logs, worker leaderboard, and CSV export.
* Analytics dashboard showing daily/weekly throughput metrics.
* Camera scanning via the device camera (html5-qrcode library, bundled).
* Sends "Order Packed" email to customer and "Missing Items" report to admin.
* Fully HPOS-compatible (WooCommerce High-Performance Order Storage).

= Custom Order Statuses =
* **Packing** — an order currently being packed by a warehouse worker.
* **Packed** — fully packed and ready to ship.

= Custom User Role =
* **Warehouse Worker** — can view the queue and pack orders; cannot access order finances or settings.

== Installation ==

1. Upload the `barcode-fulfillment-orders` folder to `wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Fulfillment → Settings** to configure barcode format, prefixes, and policies.
4. Add existing products to the barcode system via **Fulfillment → Settings → Data → Generate Missing Product Barcodes**.
5. Start packing orders from **Fulfillment → Order Queue**.

== Frequently Asked Questions ==

= Do I need a barcode scanner gun? =
No. You can use the built-in camera scanner (works on phones and tablets) or type/paste barcodes into the input field.

= Is it compatible with WooCommerce HPOS? =
Yes. All order meta is read and written through the WC CRUD layer.

= Can I override the email templates? =
Yes. Copy the templates from `templates/emails/` to your theme's `barcode-fulfillment-orders/emails/` folder.

= Can I override the print templates? =
Yes. Copy the templates from `templates/print/` to your theme's `barcode-fulfillment-orders/print/` folder.

= What happens if two workers try to pack the same order? =
The first worker to start the session gets an exclusive lock. The second worker sees a "Being packed by [name]" notice in the queue.

= Can I use QR codes instead of barcodes? =
Yes. Go to **Fulfillment → Settings → General** and change the Barcode Format to QR Code.

== Screenshots ==

1. Order Queue page — start packing with one click.
2. Fulfillment packing screen — scan products, track progress, manage boxes.
3. Missing items modal — mark items with reason and notes.
4. Packing History audit trail with per-session scan logs.
5. Fulfillment Dashboard with weekly throughput chart and worker leaderboard.
6. Product barcode field with inline SVG preview.

== Changelog ==

= 1.1.0 =
* New: Shipping integration — connect Shippo or EasyPost to fetch live carrier rates and purchase labels from the order edit screen and the packing queue.
* New: Rate-picker modal with automatic cheapest-rate pre-selection.
* New: Tracking number, carrier, and label URL stored on every order; customer email updated on shipment.
* New: Optional auto-ship on Pack — instantly purchases the cheapest rate when an order is marked Packed.
* New: "bfo-shipped" order status with blue badge and bulk-action support.
* New: Shipping settings tab (provider, API key, from-address, default parcel dimensions).

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
