<?php
/**
 * Woo Shipping Exporter — main plugin file.
 *
 * Provides an admin‑side utility (Tools → Shipping CSV Export) that lets store managers download a CSV
 * containing every WooCommerce shipping zone, method, rate and shipping class defined in the store.  
 * The CSV begins with the site name and a timestamp so it can be easily identified when archived.
 *
 * @package   KISS Woo Shipping Settings Exporter
 * @author    KISS Plugins
 * @copyright Copyright (c) " . date('Y') . " Your Name"
 * @license   GPL‑2.0+
 * @link      https://example.com/
 * @since     0.1.0
 */

/**
 * Plugin Name: KISS Woo Shipping Settings Exporter
 * Description: Export WooCommerce shipping zones, methods, rates and classes to a downloadable CSV that begins with the site name and a timestamp.
 * Version: 0.1.0
 * Author: KISS Plugins
 * License: GPL‑2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: woo-shipping-exporter
 */

// BEST PRACTICE: Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MODIFICATION: Defer plugin initialization until all plugins are loaded.
 *
 * This ensures that WooCommerce classes are available and prevents fatal errors if
 * WooCommerce is not active or loads after this plugin.
 */
add_action( 'plugins_loaded', 'wse_initialize_exporter' );

/**
 * Initializes the exporter class after checking for WooCommerce.
 *
 * @since 0.2.0
 */
function wse_initialize_exporter(): void {
    // MODIFICATION: Check if WooCommerce is active before running any code.
    if ( ! class_exists( 'WooCommerce' ) ) {
        // Optional: Add an admin notice to inform the user.
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'The Woo Shipping Exporter plugin requires WooCommerce to be active.', 'woo-shipping-exporter' ); ?></p>
            </div>
            <?php
        });
        return;
    }

    // All clear, instantiate the plugin.
    new WSE_Exporter();
}


if ( ! class_exists( 'WSE_Exporter' ) ) {

    /**
     * Main exporter class.
     *
     * @since 0.1.0
     */
    final class WSE_Exporter {

        /**
         * Constructor.
         *
         * @since 0.1.0
         */
        public function __construct() {
            add_action( 'admin_menu', [ $this, 'register_menu' ] );
            add_action( 'admin_post_wse_export', [ $this, 'handle_export' ] );
        }

        /**
         * Adds an item to the *Tools* menu.
         *
         * @since 0.1.0
         */
        public function register_menu(): void {
            add_management_page(
                __( 'Shipping CSV Export', 'woo-shipping-exporter' ), // Page title.
                __( 'Shipping CSV Export', 'woo-shipping-exporter' ), // Menu title.
                'manage_woocommerce',                                 // Capability.
                'wse-export',                                         // Slug.
                [ $this, 'render_page' ]                              // Callback.
            );
        }

        /**
         * Renders the admin page.
         *
         * @since 0.1.0
         */
        public function render_page(): void {
            // BEST PRACTICE: Capability check is already handled by add_management_page,
            // but an explicit check here doesn't hurt.
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                return;
            }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Export WooCommerce Shipping Data', 'woo-shipping-exporter' ); ?></h1>
                <p><?php esc_html_e( 'Click the button below to generate a CSV that contains every shipping zone, method, rate, and shipping class defined in this store.', 'woo-shipping-exporter' ); ?></p>
                <p><?php esc_html_e( 'Note: For sites with a very large number of shipping zones, this process may take some time to complete.', 'woo-shipping-exporter' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wse_export', 'wse_nonce' ); ?>
                    <input type="hidden" name="action" value="wse_export" />
                    <p><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Download CSV', 'woo-shipping-exporter' ); ?></button></p>
                </form>
            </div>
            <?php
        }

        /**
         * Handles the POST request and generates the CSV.
         *
         * @since 0.1.0
         */
        public function handle_export(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( __( 'Sorry, you are not allowed to export this data.', 'woo-shipping-exporter' ) );
            }

            check_admin_referer( 'wse_export', 'wse_nonce' );

            // MODIFICATION: Prevent script from timing out on large exports.
            // This is the most critical change to prevent server crashes.
            // set_time_limit(0) allows the script to run indefinitely.
            if ( function_exists( 'set_time_limit' ) ) {
                set_time_limit( 0 );
            }

            // MODIFICATION: Use the WordPress date/time functions for correct timezone handling.
            $filename = sanitize_title( get_bloginfo( 'name' ) ) . '-shipping-' . wp_date( 'Y-m-d-His' ) . '.csv';

            // MODIFICATION: Clear any previously buffered output to prevent "headers already sent" errors.
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );

            // BEST PRACTICE: Wrap the entire file generation in a try/catch block for graceful error handling.
            try {
                $output = fopen( 'php://output', 'w' );

                /* Preamble — site name + timestamp */
                fputcsv( $output, [ get_bloginfo( 'name' ) . ' - Shipping Export' ] );
                // MODIFICATION: Use wp_date for a localized timestamp.
                fputcsv( $output, [ 'Generated:', wp_date( 'Y-m-d H:i:s' ) ] );
                fputcsv( $output, [] );

                /* Shipping Zones, Methods & Rates */
                // BEST PRACTICE: More descriptive headers.
                fputcsv( $output, [
                    'Zone ID',
                    'Zone Name',
                    'Zone Locations (code:type)',
                    'Method Instance ID',
                    'Method Title',
                    'Method Type',
                    'Cost',
                    'Tax Status',
                    'Shipping Class ID',
                    'Shipping Class Cost',
                ] );
                
                // MODIFICATION: Use WC_Shipping_Zones::get_zones() for consistency with WC practices.
                foreach ( WC_Shipping_Zones::get_zones() as $zone_array ) {
                    // BEST PRACTICE: Use a more representative object.
                    $zone = new WC_Shipping_Zone($zone_array['zone_id']);

                    $locations = [];
                    foreach ( $zone->get_zone_locations() as $location ) {
                        $locations[] = $location->code . ':' . $location->type;
                    }
                    $location_str = implode( ' | ', $locations );

                    if (empty($location_str)) {
                        $location_str = 'All locations';
                    }

                    foreach ( $zone->get_shipping_methods( true ) as $instance_id => $method ) {
                        /** @var WC_Shipping_Method $method */
                        $settings = $method->instance_settings; // Already loaded property.

                        $cost       = $settings['cost']       ?? '';
                        $tax_status = $settings['tax_status'] ?? '';
                        
                        // Handle class costs for methods that support them (e.g., flat_rate).
                        if ( ! empty( $settings['class_costs'] ) && is_array( $settings['class_costs'] ) ) {
                            foreach ( $settings['class_costs'] as $class_slug => $class_cost ) {
                                // The key is the slug, we need the ID.
                                $class_term = get_term_by( 'slug', $class_slug, 'product_shipping_class' );
                                $class_id   = $class_term ? $class_term->term_id : 'slug:' . $class_slug;

                                fputcsv( $output, [
                                    $zone->get_id(),
                                    $zone->get_zone_name(),
                                    $location_str,
                                    $instance_id,
                                    $method->get_title(),
                                    $method->id,
                                    $cost,
                                    $tax_status,
                                    $class_id,
                                    $class_cost,
                                ] );
                            }
                        } else {
                            fputcsv( $output, [
                                $zone->get_id(),
                                $zone->get_zone_name(),
                                $location_str,
                                $instance_id,
                                $method->get_title(),
                                $method->id,
                                $cost,
                                $tax_status,
                                '', // No class ID
                                '', // No class cost
                            ] );
                        }
                    }
                    // BEST PRACTICE: Unset large variables in long loops to help with memory management.
                    unset($zone, $locations, $location_str);
                }

                /* Shipping Class Catalogue */
                fputcsv( $output, [] );
                fputcsv( $output, [ 'All Defined Shipping Classes' ] );
                fputcsv( $output, [ 'Class ID', 'Class Name', 'Slug', 'Description' ] );

                // BEST PRACTICE: Use the dedicated WC function.
                $shipping_classes = WC()->shipping()->get_shipping_classes();

                if ( ! empty($shipping_classes) ) {
                    foreach ( $shipping_classes as $class ) {
                        fputcsv( $output, [
                            $class->term_id,
                            $class->name,
                            $class->slug,
                            $class->description,
                        ] );
                    }
                } else {
                     fputcsv( $output, [ 'No shipping classes found.' ] );
                }
                
            } catch ( Exception $e ) {
                // MODIFICATION: If anything goes wrong, log the error and stop gracefully.
                // This prevents a broken file download or a white screen of death.
                $error_message = 'An error occurred during CSV generation: ' . $e->getMessage();
                error_log($error_message); // Log for the site admin.
                // We can't output the error to the CSV as headers are already sent.
                // The script will just exit here, resulting in a potentially incomplete file,
                // but the server won't crash and the error is logged.
            } finally {
                // BEST PRACTICE: Always ensure the file handle is closed.
                if ( isset($output) && is_resource($output) ) {
                    fclose( $output );
                }
            }

            exit;
        }
    }
}
