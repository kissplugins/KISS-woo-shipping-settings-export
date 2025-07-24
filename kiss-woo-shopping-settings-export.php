<?php
/**
 * KISS Woo Shipping Settings Debugger — main plugin file.
 *
 * Provides an admin-side utility to export UI-based shipping settings and scan the theme for
 * custom, code-based shipping restriction rules.
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
 * Description: Adds a link to the plugins page and a Tool to export settings and scan the theme for custom shipping rules.
 * Version:   0.5.0
 * Author:    Your Name
 * License:   GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-exporter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KISS_WSE_PLUGIN_FILE', __FILE__ );

add_action( 'plugins_loaded', 'kiss_wse_initialize_exporter' );

function kiss_wse_initialize_exporter(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'The KISS Woo Shipping Settings Exporter plugin requires WooCommerce to be active.', 'kiss-woo-shipping-exporter' ));
        });
        return;
    }
    new KISS_WSE_Exporter();
}

if ( ! class_exists( 'KISS_WSE_Exporter' ) ) {

    final class KISS_WSE_Exporter {

        private $page_slug = 'kiss-wse-export';

        public function __construct() {
            add_action( 'admin_menu', [ $this, 'register_menu' ] );
            add_action( 'admin_post_' . $this->page_slug, [ $this, 'handle_export' ] );
            add_filter( 'plugin_action_links_' . plugin_basename( KISS_WSE_PLUGIN_FILE ), [ $this, 'add_action_links' ] );
        }

        public function add_action_links( array $links ): array {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'tools.php?page=' . $this->page_slug ) ),
                __( 'Export & Scan Settings', 'kiss-woo-shipping-exporter' )
            );
            array_unshift( $links, $settings_link );
            return $links;
        }

        public function register_menu(): void {
            add_management_page(
                __( 'KISS Woo Shipping Exporter', 'kiss-woo-shipping-exporter' ),
                __( 'KISS Shipping Exporter', 'kiss-woo-shipping-exporter' ),
                'manage_woocommerce', $this->page_slug, [ $this, 'render_page' ]
            );
        }

        /**
         * NEW: Scans the theme for the custom shipping restrictions file and renders the results.
         * @since 0.5.0
         */
        private function scan_and_render_custom_rules(): void {
            echo '<h2>' . esc_html__( 'Custom Rules Scanner', 'kiss-woo-shipping-exporter' ) . '</h2>';

            $file_path = get_stylesheet_directory() . '/inc/shipping-restrictions.php';

            if ( ! file_exists( $file_path ) ) {
                printf( '<p>%s</p>', esc_html__( 'Custom shipping file not found at:', 'kiss-woo-shipping-exporter' ) );
                echo '<code>' . esc_html( $file_path ) . '</code>';
                return;
            }

            printf( '<p>%s</p>', esc_html__( 'The following programmatic rules were found in your theme. Note: This is a best-effort analysis and may not capture all custom logic.', 'kiss-woo-shipping-exporter' ) );
            echo '<code>' . esc_html( $file_path ) . '</code>';

            $contents = file_get_contents( $file_path );
            $tokens = token_get_all( $contents );

            $rules = [];
            $current_context = 'none';

            foreach ( $tokens as $i => $token ) {
                if ( ! is_array( $token ) ) continue;

                // Detect state-based rules
                if ( $token[0] === T_IF ) {
                    if ( isset( $tokens[$i+2][1] ) && $tokens[$i+2][1] === '$state' && isset( $tokens[$i+4][1] ) ) {
                        $state_code = str_replace("'", "", $tokens[$i+4][1]);
                        $rules[] = ['type' => 'context', 'value' => "For State: <strong>" . esc_html( $state_code ) . "</strong>"];
                    }
                }
                
                // Detect `is_binoidcbd()` or similar contexts
                if ( $token[0] === T_STRING && in_array( $token[1], ['is_binoidcbd', 'is_bloomz'] ) ) {
                     $rules[] = ['type' => 'context', 'value' => "When `{$token[1]}()` is true:"];
                }
                
                // Detect category restrictions via `has_term`
                if ($token[0] === T_STRING && $token[1] === 'has_term') {
                    if (isset($tokens[$i+2][1]) && $tokens[$i+2][0] === T_ARRAY) {
                        $array_content = '';
                        for ($j = $i + 3; $j < count($tokens); $j++) {
                            if ($tokens[$j] === ')') break;
                            $array_content .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                        }
                        $cats = str_replace(["'", ' '], '', trim($array_content, '()'));
                        $rules[] = ['type' => 'rule', 'value' => "Checks for product categories: <code>" . esc_html($cats) . "</code>"];
                    }
                }

                // Detect error messages being added
                if ($token[0] === T_STRING && $token[1] === '__') {
                     if (isset($tokens[$i+2][1])) {
                        $message = trim($tokens[$i+2][1], '"');
                        $rules[] = ['type' => 'message', 'value' => esc_html($message)];
                     }
                }

                // Detect array definitions for states/postcodes
                if ($token[0] === T_VARIABLE && in_array($token[1], ['$restricted_states', '$restricted_postcodes', '$restricted_postcodes_kratom'])) {
                    $array_content = '';
                    for ($j = $i + 2; $j < count($tokens); $j++) {
                        if (is_string($tokens[$j]) && $tokens[$j] === ';') break;
                        $array_content .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    }
                    preg_match_all("/'([^']*)'/", $array_content, $matches);
                    $count = count($matches[1]) / 2;
                    $rules[] = ['type' => 'array', 'value' => "Found restriction array <code>{$token[1]}</code> with <strong>" . (int) $count . "</strong> entries."];
                }
            }
            
            // Render the discovered rules
            if (!empty($rules)) {
                echo '<ul class="ul-disc" style="margin-left: 20px;">';
                foreach ($rules as $rule) {
                    $style = 'padding-left: 10px;';
                    if ($rule['type'] === 'context') $style = 'font-weight: bold; margin-top: 1em;';
                    if ($rule['type'] === 'message') $style .= 'padding-left: 20px; font-style: italic;';
                    
                    echo '<li style="' . esc_attr($style) . '">' . wp_kses_post($rule['value']) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p>' . esc_html__('Could not automatically detect any rules from the file.', 'kiss-woo-shipping-exporter' ) . '</p>';
            }
        }

        public function render_page(): void {
            $submit_button_html = sprintf( '<p><button type="submit" class="button button-primary button-large">%s</button></p>', esc_html__( 'Download CSV of UI Settings', 'kiss-woo-shipping-exporter' ) );
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'KISS Woo Shipping Settings Exporter & Scanner', 'kiss-woo-shipping-exporter' ); ?></h1>
                
                <hr/>
                <?php $this->scan_and_render_custom_rules(); ?>
                <hr/>

                <h2><?php esc_html_e( 'UI-Based Settings Export', 'kiss-woo-shipping-exporter' ); ?></h2>
                <p><?php esc_html_e( 'This section previews and exports the settings configured in the WooCommerce UI (shipping zones, methods, etc.).', 'kiss-woo-shipping-exporter' ); ?></p>
                
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( $this->page_slug, 'wse_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr( $this->page_slug ); ?>" />
                    <?php echo $submit_button_html; ?>
                    
                    <!-- Code for preview table from v0.4.0... -->
                    <?php
                        $preview_limit = 10;
                        $zone_headers = [ 'Zone Name', 'Locations', 'Method Title', 'Method Type', 'Cost', 'Class Cost' ];
                        $all_zones = WC_Shipping_Zones::get_zones();
                        $preview_zones_data = []; $total_zone_rows = 0;
                        foreach ($all_zones as $zone_array) { if (count($preview_zones_data) >= $preview_limit) break; $zone = new WC_Shipping_Zone($zone_array['zone_id']); $locations = []; foreach ($zone->get_zone_locations() as $location) { $locations[] = $location->code . ':' . $location->type; } $location_str = empty($locations) ? 'Rest of the World' : implode(' | ', $locations); $methods = $zone->get_shipping_methods(true); if (empty($methods)) { $preview_zones_data[] = [ $zone->get_zone_name(), $location_str, '<em>No methods configured</em>', '', '', '' ]; $total_zone_rows++; } else { foreach ($methods as $method) { if (count($preview_zones_data) >= $preview_limit) break 2; $settings = $method->instance_settings; $cost = $settings['cost'] ?? ''; if (!empty($settings['class_costs']) && is_array($settings['class_costs'])) { foreach ($settings['class_costs'] as $slug => $class_cost) { if (count($preview_zones_data) >= $preview_limit) break 3; $preview_zones_data[] = [ $zone->get_zone_name(), $location_str, $method->get_title(), $method->id, $cost, $class_cost ]; $total_zone_rows++; } } else { $preview_zones_data[] = [ $zone->get_zone_name(), $location_str, $method->get_title(), $method->id, $cost, '' ]; $total_zone_rows++; } } } }
                    ?>
                    <h3><?php esc_html_e( 'Shipping Zones & Methods Preview', 'kiss-woo-shipping-exporter' ); ?></h3>
                    <table class="wp-list-table widefat striped"><thead><tr><?php foreach ( $zone_headers as $header ) echo '<th scope="col">' . esc_html( $header ) . '</th>'; ?></tr></thead><tbody><?php foreach ( $preview_zones_data as $row ) { echo '<tr>'; foreach ( $row as $cell ) echo '<td>' . esc_html( $cell ) . '</td>'; echo '</tr>'; } ?></tbody></table>
                    <?php if ( $total_zone_rows > count($preview_zones_data) ) printf('<p><em>' . esc_html__('And %d more rows...', 'kiss-woo-shipping-exporter') . '</em></p>', esc_html( $total_zone_rows - count($preview_zones_data) ) ); ?>
                    
                    <hr/>
                    <?php echo $submit_button_html; ?>
                </form>
            </div>
            <?php
        }
        
        // Unchanged handle_export method...
        public function handle_export(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( __( 'Sorry, you are not allowed to export this data.', 'kiss-woo-shipping-exporter' ) ); }
            check_admin_referer( $this->page_slug, 'wse_nonce' );
            if ( function_exists( 'set_time_limit' ) ) { set_time_limit( 0 ); }
            $filename = sanitize_title( get_bloginfo( 'name' ) ) . '-shipping-' . wp_date( 'Y-m-d-His' ) . '.csv';
            if ( ob_get_level() > 0 ) { ob_end_clean(); }
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename=' . $filename );
            try { $output = fopen( 'php://output', 'w' ); fputcsv( $output, [ get_bloginfo( 'name' ) . ' - Shipping Settings Export' ] ); fputcsv( $output, [ 'Generated:', wp_date( 'Y-m-d H:i:s' ) ] ); fputcsv( $output, [] ); fputcsv( $output, [ 'Zone ID', 'Zone Name', 'Zone Locations (code:type)', 'Method Instance ID', 'Method Title', 'Method Type', 'Cost', 'Tax Status', 'Shipping Class ID', 'Shipping Class Cost', ] ); foreach ( WC_Shipping_Zones::get_zones() as $zone_array ) { $zone = new WC_Shipping_Zone($zone_array['zone_id']); $locations = []; foreach ( $zone->get_zone_locations() as $location ) { $locations[] = $location->code . ':' . $location->type; } $location_str = empty($locations) ? 'Rest of the World' : implode(' | ', $locations); foreach ( $zone->get_shipping_methods( true ) as $instance_id => $method ) { $settings = $method->instance_settings; $cost = $settings['cost'] ?? ''; $tax_status = $settings['tax_status'] ?? ''; if ( ! empty( $settings['class_costs'] ) && is_array( $settings['class_costs'] ) ) { foreach ( $settings['class_costs'] as $class_slug => $class_cost ) { $class_term = get_term_by( 'slug', $class_slug, 'product_shipping_class' ); fputcsv( $output, [ $zone->get_id(), $zone->get_zone_name(), $location_str, $instance_id, $method->get_title(), $method->id, $cost, $tax_status, $class_term ? $class_term->term_id : 'slug:' . $class_slug, $class_cost, ]); } } else { fputcsv( $output, [ $zone->get_id(), $zone->get_zone_name(), $location_str, $instance_id, $method->get_title(), $method->id, $cost, $tax_status, '', '', ]); } } unset($zone, $locations, $location_str); } fputcsv( $output, [] ); fputcsv( $output, [ 'All Defined Shipping Classes' ] ); fputcsv( $output, [ 'Class ID', 'Class Name', 'Slug', 'Description' ] ); $shipping_classes = WC()->shipping()->get_shipping_classes(); if ( ! empty($shipping_classes) ) { foreach ( $shipping_classes as $class ) { fputcsv( $output, [ $class->term_id, $class->name, $class->slug, $class->description ] ); } } else { fputcsv( $output, [ 'No shipping classes found.' ] ); } } catch ( Exception $e ) { error_log('Error during CSV generation: ' . $e->getMessage()); } finally { if ( isset($output) && is_resource($output) ) { fclose( $output ); } } exit;
        }
    }
}
