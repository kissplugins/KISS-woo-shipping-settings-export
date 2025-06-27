<?php
/**
 * Woo Shipping Exporter — main plugin file.
 *
 * Provides an admin‑side utility (Tools → Shipping CSV Export) that lets store managers download a CSV
 * containing every WooCommerce shipping zone, method, rate and shipping class defined in the store.  
 * The CSV begins with the site name and a timestamp so it can be easily identified when archived.
 *
 * @package   WooShippingExporter
 * @author    Your Name
 * @copyright Copyright (c) " . date('Y') . " Your Name"
 * @license   GPL‑2.0+
 * @link      https://example.com/
 * @since     0.1.0
 */

/**
 * Plugin Name: Woo Shipping Exporter
 * Description: Export WooCommerce shipping zones, methods, rates and classes to a downloadable CSV that begins with the site name and a timestamp.
 * Version: 0.1.0
 * Author: Your Name
 * License: GPL‑2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: woo-shipping-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Prevent direct access to the file.
    exit;
}

if ( ! class_exists( 'WSE_Exporter' ) ) {

    /**
     * Main exporter class.
     *
     * Hooks into the WordPress admin to render a page under Tools that lets an authorised user export
     * WooCommerce shipping data. The heavy lifting is done in `handle_export()`, which streams the CSV
     * directly to the browser so that no temporary files are left on the server.
     *
     * @since 0.1.0
     */
    final class WSE_Exporter {

        /**
         * Constructor.
         *
         * Registers all the actions required to expose the export UI and handle the CSV generation
         * request.
         *
         * @since 0.1.0
         */
        public function __construct() {
            add_action( 'admin_menu', [ $this, 'register_menu' ] );
            add_action( 'admin_post_wse_export', [ $this, 'handle_export' ] );
        }

        /**
         * Adds an item to the *Tools* menu (Tools → Shipping CSV Export).
         *
         * @since 0.1.0
         *
         * @return void
         */
        public function register_menu(): void {
            add_management_page(
                __( 'Shipping CSV Export', 'woo-shipping-exporter' ), // Page title.
                __( 'Shipping CSV Export', 'woo-shipping-exporter' ), // Menu title.
                'manage_woocommerce',                                  // Capability.
                'wse-export',                                          // Slug.
                [ $this, 'render_page' ]                               // Callback.
            );
        }

        /**
         * Renders the admin page containing a single **Download CSV** button.
         *
         * @since 0.1.0
         *
         * @return void Outputs HTML directly.
         */
        public function render_page(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Export WooCommerce Shipping Data', 'woo-shipping-exporter' ); ?></h1>
                <p><?php esc_html_e( 'Click the button below to generate a CSV that contains every shipping zone, method, rate, and shipping class defined in this store.', 'woo-shipping-exporter' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wse_export', 'wse_nonce' ); ?>
                    <input type="hidden" name="action" value="wse_export" />
                    <p><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Download CSV', 'woo-shipping-exporter' ); ?></button></p>
                </form>
            </div>
            <?php
        }

        /**
         * Handles the POST request triggered by the **Download CSV** button.
         *
         * Runs capability and nonce checks, sets the correct headers for a streamed CSV download, and
         * then writes shipping‑related data line‑by‑line to the output buffer. The CSV is structured
         * as follows:
         *
         * 1. Site name row.
         * 2. Timestamp row (in the site’s timezone).
         * 3. Blank row.
         * 4. Table of shipping zones → methods → (optional) per‑class costs.
         * 5. Blank row.
         * 6. Table of shipping classes.
         *
         * @since 0.1.0
         *
         * @return void Exits the request after streaming the CSV.
         */
        public function handle_export(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( __( 'Sorry, you are not allowed to export this data.', 'woo-shipping-exporter' ) );
            }

            check_admin_referer( 'wse_export', 'wse_nonce' );

            // Filename example: my-store-shipping-2025-06-26-103218.csv
            $filename = sanitize_title( get_bloginfo( 'name' ) ) . '-shipping-' . gmdate( 'Y-m-d-His' ) . '.csv';

            // Tell the browser we are sending CSV and force file download.
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );

            $output = fopen( 'php://output', 'w' );

            /* -------------------------------------------------------------- */
            /* Preamble — site name + timestamp                               */
            /* -------------------------------------------------------------- */
            fputcsv( $output, [ get_bloginfo( 'name' ) ] );
            fputcsv( $output, [ 'Generated:', current_time( 'mysql' ) ] );
            fputcsv( $output, [] ); // Blank separator line.

            /* -------------------------------------------------------------- */
            /* Shipping Zones, Methods & Rates                               */
            /* -------------------------------------------------------------- */
            fputcsv( $output, [
                'Zone ID',
                'Zone Name',
                'Locations',
                'Method ID',
                'Method Title',
                'Method Type',
                'Cost',
                'Tax Status',
                'Class ID',
                'Class Cost',
            ] );

            foreach ( wc_get_shipping_zones() as $zone_array ) {
                $zone_id   = (int) $zone_array['id'];
                $zone_name = $zone_array['zone_name'];

                // Build a pipe‑delimited list of location codes (e.g. country:US|state:CA).
                $locations      = [];
                $zone_locations = $zone_array['zone_locations'];
                foreach ( $zone_locations as $loc ) {
                    $locations[] = $loc->type . ':' . $loc->code;
                }
                $location_str = implode( '|', $locations );

                // Iterate through each shipping method in the zone.
                foreach ( $zone_array['shipping_methods'] as $method ) {
                    /** @var WC_Shipping_Method $method */
                    $settings = $method->get_instance_settings();

                    $cost       = $settings['cost']       ?? '';
                    $tax_status = $settings['tax_status'] ?? '';

                    // If flat‑rate per‑class costs are defined, output one row per class.
                    if ( isset( $settings['class_costs'] ) && is_array( $settings['class_costs'] ) && $settings['class_costs'] ) {
                        foreach ( $settings['class_costs'] as $class_id => $class_cost ) {
                            fputcsv( $output, [
                                $zone_id,
                                $zone_name,
                                $location_str,
                                $method->get_id(),
                                $method->get_method_title(),
                                $method->id,
                                $cost,
                                $tax_status,
                                $class_id,
                                $class_cost,
                            ] );
                        }
                    } else {
                        // No per‑class overrides — output a single row.
                        fputcsv( $output, [
                            $zone_id,
                            $zone_name,
                            $location_str,
                            $method->get_id(),
                            $method->get_method_title(),
                            $method->id,
                            $cost,
                            $tax_status,
                            '',
                            '',
                        ] );
                    }
                }
            }

            /* -------------------------------------------------------------- */
            /* Shipping Class Catalogue                                       */
            /* -------------------------------------------------------------- */
            fputcsv( $output, [] );
            fputcsv( $output, [ 'Shipping Classes' ] );
            fputcsv( $output, [ 'Class ID', 'Class Name', 'Slug', 'Description' ] );

            $classes = get_terms( [
                'taxonomy'   => 'product_shipping_class',
                'hide_empty' => false,
            ] );

            foreach ( $classes as $class ) {
                fputcsv( $output, [
                    $class->term_id,
                    $class->name,
                    $class->slug,
                    $class->description,
                ] );
            }

            fclose( $output );
            exit;
        }
    }

    // Instantiate the exporter so that its hooks register.
    new WSE_Exporter();
}
?>
