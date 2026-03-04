# Barcode Fulfillment Orders — Copilot Instructions

This file provides project-specific coding rules for GitHub Copilot when working
on the `barcode-fulfillment-orders` WooCommerce plugin.

---

## Project identity

| Key            | Value                              |
|----------------|------------------------------------|
| Plugin slug    | `barcode-fulfillment-orders`       |
| Text domain    | `barcode-fulfillment-orders`       |
| Function prefix | `bfo_`                            |
| Class prefix   | `BFO_`                             |
| Constant prefix | `BFO_`                            |
| Min PHP        | 8.0                                |
| Min WP         | 6.4                                |
| Min WooCommerce | 8.0                               |

---

## Architecture rules

1. **Singleton pattern** — every class uses:
   ```php
   private static ?ClassName $instance = null;
   public static function instance(): ClassName { ... }
   private function __construct() { /* hooks here */ }
   ```

2. **No Composer, no build tools** — plain PHP, no autoloaders, no npm.
   Require files manually in the main plugin file ordered by dependency.

3. **Constants over magic strings** — use `BFO_META_*`, `BFO_OPTION_*`,
   `BFO_CAPABILITY_*`, `BFO_STATUS_*`, `BFO_SESSION_STATUS_*`, `BFO_SCAN_ACTION_*`
   everywhere. Never write `'_bfo_barcode'` directly.

4. **HPOS-safe order meta** — always use `$order->get_meta()` /
   `$order->update_meta_data()` + `$order->save_meta_data()`.
   Never use `update_post_meta()` or `get_post_meta()` for orders.

5. **AJAX security** — every AJAX handler must:
   - Call `check_ajax_referer()` or `check_admin_referer()` first.
   - Then call `current_user_can()` with the correct `BFO_CAPABILITY_*` constant.
   - Return via `wp_send_json_success()` / `wp_send_json_error()`.

---

## Naming conventions

| Type               | Convention                                  | Example                          |
|--------------------|---------------------------------------------|----------------------------------|
| Class file         | `class-bfo-{name}.php`                      | `class-bfo-scanner.php`          |
| Class name         | `BFO_{Name}` (PascalCase after prefix)      | `BFO_Scanner`                    |
| Hook callback      | `array( $this, '{verb}_{noun}' )`           | `array( $this, 'process_scan' )` |
| AJAX action        | `bfo_{verb}_{noun}`                         | `bfo_process_scan`               |
| Option key         | `bfo_{snake_case}` or `BFO_OPTION_*` const  | `BFO_OPTION_BARCODE_FORMAT`      |
| Post/order meta    | `_bfo_{snake_case}` or `BFO_META_*` const   | `BFO_META_PRODUCT_BARCODE`       |
| JS config object   | `bfo{PageName}Config`                       | `bfoPackConfig`                  |
| CSS class          | `bfo-{block}__element--modifier`            | `bfo-alert bfo-alert--success`   |

---

## Database access

- Use `$wpdb->prepare()` for every query with variables, even integers.
- Reference custom table names as `$wpdb->prefix . 'bfo_...'`.
- Inline the table name into SQL strings — never use a variable outside `prepare()`.
- Use `// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL`
  on lines that use `$wpdb->get_*` / `$wpdb->query` directly.
- Schema changes: always route through `BFO_Database::install()` /
  `BFO_Database::maybe_upgrade()` using `dbDelta()`.

---

## Security checklist for new features

- [ ] Nonce verification (`wp_nonce_field` + `check_admin_referer` or `check_ajax_referer`)
- [ ] Capability check (`current_user_can( BFO_CAPABILITY_* )`)
- [ ] Sanitize all inputs (`absint`, `sanitize_text_field`, `sanitize_key`, `wp_kses_post`)
- [ ] Escape all outputs (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)
- [ ] Direct DB queries use `$wpdb->prepare()`

---

## Adding a new settings option

1. Define a constant in `barcode-fulfillment-orders.php`:
   `define( 'BFO_OPTION_MY_SETTING', 'bfo_my_setting' );`
2. Add the default value in `bfo_activation()` activation hook.
3. Add the field in `BFO_Settings::render_*_tab()`.
4. Save it in `BFO_Settings::save_*()`.
5. Clean up in `uninstall.php`.

---

## Adding a new AJAX endpoint

1. Register in the class constructor:
   ```php
   add_action( 'wp_ajax_bfo_my_action', array( $this, 'ajax_my_action' ) );
   ```
2. Add a nonce to the localized JS config object in the relevant enqueue method.
3. Implement the handler — always `check_ajax_referer` + `current_user_can` first.
4. Return `wp_send_json_success( $data )` or `wp_send_json_error( $data )`.

---

## File load order (main plugin file)

Classes must be `require_once`'d after their dependencies. The order is:

1. `class-bfo-database.php`
2. `class-bfo-roles.php`
3. `bfo-functions.php`
4. `class-bfo-barcode-generator.php`
5. `class-bfo-order-status.php`
6. `class-bfo-product-barcode.php`
7. `class-bfo-order-barcode.php`
8. `class-bfo-packing-session.php`
9. `class-bfo-scanner.php`
10. `class-bfo-missing-products.php`
11. `class-bfo-multi-box.php`
12. `class-bfo-order-queue.php`
13. `class-bfo-fulfillment-screen.php`
14. `class-bfo-emails.php`
15. `class-bfo-settings.php`
16. `class-bfo-audit-trail.php`
17. `class-bfo-dashboard.php`
18. `class-bfo-labels.php`

---

## Testing checklist (manual)

- [ ] Activate plugin on fresh WC install → no PHP errors.
- [ ] Product barcode is generated on new product save.
- [ ] Order barcode is assigned on checkout.
- [ ] Order queue shows processing + packing orders.
- [ ] Starting a packing session locks the order for other workers.
- [ ] Scanning correct barcode increments scanned count and plays success sound.
- [ ] Scanning wrong product shows error alert.
- [ ] Over-scanning shows warning alert.
- [ ] Marking item missing transitions row to "missing" state.
- [ ] Completing order with all items transitions to `wc-bfo-packed`.
- [ ] Completing order with missing items shows unaccounted modal.
- [ ] Packing history shows the completed session.
- [ ] CSV export downloads a valid file.
- [ ] Print label opens print dialog in a new tab.
- [ ] Uninstall removes all tables, meta, options, and the warehouse_worker role.
