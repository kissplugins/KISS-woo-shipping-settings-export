<?php
/**
 * KISS Woo Shipping Settings Exporter — main plugin file.
 *
 * Provides an admin-side utility (Tools → KISS Woo Shipping Settings Exporter) that lets store
 * managers download a CSV containing every WooCommerce shipping zone, method, rate and shipping
 * class defined in the store. The CSV begins with the site name and a timestamp.
 *
 * @package   KISSShippingExporter
 * @author    Your Name
 * @copyright Copyright (c) 2025 Your Name
 * @license   GPL-2.0+
 * @link      https://example.com/
 * @since     0.1.0
 */

/**
 * Plugin Name: KISS Woo Shipping Settings Exporter
 * Description: Adds a link to the plugins page and an item to the Tools menu to export all WooCommerce shipping settings to a downloadable CSV.
 * Version:   0.3.0
 * Author:    Your Name
 * License:   GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-exporter
 */

// BEST PRACTICE: Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// MODIFICATION: Define a constant for the main plugin file path.
define( 'KISS_WSE_PLUGIN_FILE', __FILE__ );

// Defer plugin initialization until all plugins are loaded.
add_action( 'plugins_loaded', 'kiss_wse_initialize_exporter' );

/**
 * Initializes the exporter class after checking for WooCommerce.
 *
 * @since 0.2.0
 */
function kiss_wse_initialize_exporter(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'The KISS Woo Shipping Settings Exporter plugin requires WooCommerce to be active.', 'kiss-woo-shipping-exporter' ); ?></p>
            </div>
            <?php
        });
        return;
    }

    // All clear, instantiate the plugin.
    new KISS_WSE_Exporter();
}


if ( ! class_exists( 'KISS_WSE_Exporter' ) ) {

    /**
     * Main exporter class.
     *
     * @since 0.1.0
     */
    final class KISS_WSE_Exporter {

        // BEST PRACTICE: Store the page slug as a class property for easy reference.
        private $page_slug = 'kiss-wse-export';

        /**
         * Constructor.
         *
         * @since 0.1.0
         */
        public function __construct() {
            add_action( 'admin_menu', [ $this, 'register_menu' ] );
            add_action( 'admin_post_' . $this->page_slug, [ $this, 'handle_export' ] );

            // MODIFICATION: Add hook for the new action link on the plugins page.
            add_filter( 'plugin_action_links_' . plugin_basename( KISS_WSE_PLUGIN_FILE ), [ $this, 'add_action_links' ] );
        }

        /**
         * MODIFICATION: Adds a "Settings" link to the plugin's action links on the plugins page.
         *
         * @since 0.3.0
         * @param array $links An array of plugin action links.
         * @return array An array of plugin action links.
         */
        public function add_action_links( array $links ): array {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'tools.php?page=' . $this->page_slug ) ),
                __( 'Export Settings', 'kiss-woo-shipping-exporter' )
            );

            // Add the 'Export Settings' link to the beginning of the links array.
            array_unshift( $links, $settings_link );

            return $links;
        }

        /**
         * Adds an item to the *Tools* menu.
         *
         * @since 0.1.0
         */
        public function register_menu(): void {
            // MODIFICATION: Renamed menu and page titles.
            add_management_page(
                __( 'KISS Woo Shipping Settings Exporter', 'kiss-woo-shipping-exporter' ), // Page title.
                __( 'KISS Shipping Exporter', 'kiss-woo-shipping-exporter' ),              // Menu title.
                'manage_woocommerce',                                                      // Capability.
                $this->page_slug,                                                          // Slug.
                [ $this, 'render_page' ]                                                   // Callback.
            );
        }

        /**
         * Renders the admin page.
         *
         * @since 0.1.0
         */
        public function render_page(): void {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'KISS Woo Shipping Settings Exporter', 'kiss-woo-shipping-exporter' ); ?></h1>
                <p><?php esc_html_e( 'Click the button below to generate a CSV that contains every shipping zone, method, rate, and shipping class defined in this store.', 'kiss-woo-shipping-exporter' ); ?></p>
                <p><?php esc_html_e( 'Note: For sites with a very large number of shipping zones, this process may take some time to complete.', 'kiss-woo-shipping-exporter' ); ?></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( $this->page_slug, 'wse_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr( $this->page_slug ); ?>" />
                    <p><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Download CSV', 'kiss-woo-shipping-exporter' ); ?></button></p>
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
                wp_die( __( 'Sorry, you are not allowed to export this data.', 'kiss-woo-shipping-exporter' ) );
            }

            check_admin_referer( $this->page_slug, 'wse_nonce' );

            if ( function_exists( 'set_time_limit' ) ) {
                set_time_limit( 0 );
            }

            $filename = sanitize_title( get_bloginfo( 'name' ) ) . '-shipping-' . wp_date( 'Y-m-d-His' ) . '.csv';

            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }

            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );

            try {
                $output = fopen( 'php://output', 'w' );

                fputcsv( $output, [ get_bloginfo( 'name' ) . ' - Shipping Settings Export' ] );
                fputcsv( $output, [ 'Generated:', wp_date( 'Y-m-d H:i:s' ) ] );
                fputcsv( $output, [] );

                fputcsv( $output, [
                    'Zone ID', 'Zone Name', 'Zone Locations (code:type)', 'Method Instance ID', 'Method Title',
                    'Method Type', 'Cost', 'Tax Status', 'Shipping Class ID', 'Shipping Class Cost',
                ] );
                
                foreach ( WC_Shipping_Zones::get_zones() as $zone_array ) {
                    $zone = new WC_Shipping_Zone($zone_array['zone_id']);
                    $locations = [];
                    foreach ( $zone->get_zone_locations() as $location ) {
                        $locations[] = $location->code . ':' . $location->type;
                    }
                    $location_str = implode( ' | ', $locations );
                    if (empty($location_str)) $location_str = 'Rest of the World';

                    foreach ( $zone->get_shipping_methods( true ) as $instance_id => $method ) {
                        $settings = $method->instance_settings;
                        $cost = $settings['cost'] ?? '';
                        $tax_status = $settings['tax_status'] ?? '';
                        
                        if ( ! empty( $settings['class_costs'] ) && is_array( $settings['class_costs'] ) ) {
                            foreach ( $settings['class_costs'] as $class_slug => $class_cost ) {
                                $class_term = get_term_by( 'slug', $class_slug, 'product_shipping_class' );
                                fputcsv( $output, [
                                    $zone->get_id(), $zone->get_zone_name(), $location_str, $instance_id,
                                    $method->get_title(), $method->id, $cost, $tax_status,
                                    $class_term ? $class_term->term_id : 'slug:' . $class_slug, $class_cost,
                                ]);
                            }
                        } else {
                            fputcsv( $output, [
                                $zone->get_id(), $zone->get_zone_name(), $location_str, $instance_id,
                                $method->get_title(), $method->id, $cost, $tax_status, '', '',
                            ]);
                        }
                    }
                    unset($zone, $locations, $location_str);
                }

                fputcsv( $output, [] );
                fputcsv( $output, [ 'All Defined Shipping Classes' ] );
                fputcsv( $output, [ 'Class ID', 'Class Name', 'Slug', 'Description' ] );
                $shipping_classes = WC()->shipping()->get_shipping_classes();
                if ( ! empty($shipping_classes) ) {
                    foreach ( $shipping_classes as $class ) {
                        fputcsv( $output, [ $class->term_id, $class->name, $class->slug, $class->description ] );
                    }
                } else {
                     fputcsv( $output, [ 'No shipping classes found.' ] );
                }
            } catch ( Exception $e ) {
                error_log('Error during CSV generation: ' . $e->getMessage());
            } finally {
                if ( isset($output) && is_resource($output) ) {
                    fclose( $output );
                }
            }
            exit;
        }
    }
}
