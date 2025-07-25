<?php
/**
 * KISS Woo Shipping Settings Debugger — main plugin file.
 *
 * Provides an admin-side utility to export UI-based shipping settings and scan the theme for
 * custom, code-based shipping restriction rules.
 *
 * @package   KISSShippingDebugger
 * @author    Your Name
 * @copyright Copyright (c) 2025 KISS Plugins
 * @license   GPL-2.0+
 * @link      https://kissplugins.com
 * @since     0.1.0
 */

/**
 * Plugin Name: KISS Woo Shipping Settings Debugger
 * Description: Adds a link to the plugins page and a Tool to export settings and scan the theme for custom shipping rules.
 * Version:   0.7.0
 * Author:    KISS Plugins
 * Author URI: https://kissplugins.com
 * License:   GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-debugger
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KISS_WSE_PLUGIN_FILE', __FILE__ );

add_action( 'plugins_loaded', 'kiss_wse_initialize_debugger' );

function kiss_wse_initialize_debugger(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'The KISS Woo Shipping Settings Debugger plugin requires WooCommerce to be active.', 'kiss-woo-shipping-debugger' ));
        });
        return;
    }
    new KISS_WSE_Debugger();
}

if ( ! class_exists( 'KISS_WSE_Debugger' ) ) {

    final class KISS_WSE_Debugger {

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
                __( 'Export & Scan Settings', 'kiss-woo-shipping-debugger' )
            );
            array_unshift( $links, $settings_link );
            return $links;
        }

        public function register_menu(): void {
            add_management_page(
                __( 'KISS Woo Shipping Debugger', 'kiss-woo-shipping-debugger' ),
                __( 'KISS Shipping Debugger', 'kiss-woo-shipping-debugger' ),
                'manage_woocommerce', $this->page_slug, [ $this, 'render_page' ]
            );
        }

        /**
         * Scans theme files for custom shipping rules and renders the results.
         * @since 0.7.0 - Added support for additional file and improved cost analysis.
         */
        private function scan_and_render_custom_rules(?string $additional_file_relative = null): void {
            $files_to_scan = [ get_stylesheet_directory() . '/inc/shipping-restrictions.php' ];
        
            if ( $additional_file_relative ) {
                $clean_path = '/' . ltrim( wp_normalize_path( $additional_file_relative ), '/' );
                $full_path = get_stylesheet_directory() . $clean_path;
                if ( ! in_array( $full_path, $files_to_scan, true ) ) {
                    $files_to_scan[] = $full_path;
                }
            }
        
            foreach ( $files_to_scan as $file_path ) {
                printf( '<h3>Scanning File: <code>%s</code></h3>', esc_html( wp_make_link_relative( $file_path ) ) );
        
                if ( ! file_exists( $file_path ) ) {
                    printf( '<p><em>%s</em></p>', esc_html__( 'File not found.', 'kiss-woo-shipping-debugger' ) );
                    continue;
                }
        
                printf( '<p>%s</p>', esc_html__( 'The following programmatic rules were found. Note: This is a best-effort analysis and may not capture all custom logic.', 'kiss-woo-shipping-debugger' ) );
        
                $contents = file_get_contents( $file_path );
                $tokens = token_get_all( $contents );
                $rules = [];
        
                foreach ( $tokens as $i => $token ) {
                    if ( ! is_array( $token ) ) continue;
        
                    // --- Existing Detections ---
                    if ( $token[0] === T_IF && isset( $tokens[$i+2][1] ) && $tokens[$i+2][1] === '$state' && isset( $tokens[$i+4][1] ) ) {
                        $state_code = str_replace("'", "", $tokens[$i+4][1]);
                        $rules[] = ['type' => 'context', 'value' => "For State: <strong>" . esc_html( $state_code ) . "</strong>"];
                    }
                    if ( $token[0] === T_STRING && in_array( $token[1], ['is_binoidcbd', 'is_bloomz'] ) ) {
                        $rules[] = ['type' => 'context', 'value' => "When `{$token[1]}()` is true:"];
                    }
                    if ($token[0] === T_STRING && $token[1] === 'has_term') {
                        // Simplified 'has_term' detection
                        $rules[] = ['type' => 'rule', 'value' => "Checks for product categories using `has_term`."];
                    }
                    if ($token[0] === T_STRING && $token[1] === '__') {
                        if (isset($tokens[$i+2]) && $tokens[$i+2][0] === T_CONSTANT_ENCAPSED_STRING) {
                           $message = trim($tokens[$i+2][1], "'\"");
                           if ( !empty($message) && trim($message) !== '.' ) {
                                $rules[] = ['type' => 'message', 'value' => 'Displays message: <em>"' . esc_html($message) . '"</em>'];
                           }
                        }
                    }
                    if ($token[0] === T_VARIABLE && in_array($token[1], ['$restricted_states', '$restricted_postcodes', '$restricted_postcodes_kratom'])) {
                         $rules[] = ['type' => 'array', 'value' => "Found restriction array <code>{$token[1]}</code>."];
                    }
        
                    // --- New Detections for Shipping Costs & Rules ---
                    if ($token[0] === T_STRING && $token[1] === 'add_filter' && isset($tokens[$i+2][1]) && trim($tokens[$i+2][1], "'\"") === 'woocommerce_package_rates') {
                        $rules[] = ['type' => 'context', 'value' => "Modifies rates via <code>woocommerce_package_rates</code> hook."];
                    }
                    if ( $token[0] === T_UNSET && isset($tokens[$i+2][1]) && strpos($tokens[$i+2][1], '$rates') !== false ) {
                        $rules[] = ['type' => 'rule', 'value' => 'Conditionally removes a shipping rate (e.g., <code>unset($rates[...])</code>).'];
                    }
                    if ($token[0] === T_OBJECT_OPERATOR && isset($tokens[$i+1][1]) && $tokens[$i+1][1] === 'cost') {
                        if (isset($tokens[$i+2]) && is_string($tokens[$i+2]) && $tokens[$i+2] === '=') {
                            $rules[] = ['type' => 'rule', 'value' => 'Directly sets a shipping rate cost (e.g., <code>$rate->cost = ...</code>).'];
                        } elseif (isset($tokens[$i+2]) && is_array($tokens[$i+2]) && in_array($tokens[$i+2][0], [T_PLUS_EQUAL, T_MINUS_EQUAL])) {
                            $rules[] = ['type' => 'rule', 'value' => "Modifies a shipping rate cost (e.g., <code>cost += / -=</code>)."];
                        }
                    }
                    if ($token[0] === T_NEW && isset($tokens[$i+2][1]) && $tokens[$i+2][1] === 'WC_Shipping_Rate') {
                        $rules[] = ['type' => 'rule', 'value' => 'Programmatically adds a new shipping rate (<code>new WC_Shipping_Rate()</code>).'];
                    }
                }
                
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
                    echo '<p>' . esc_html__('Could not automatically detect any known rule patterns in this file.', 'kiss-woo-shipping-debugger' ) . '</p>';
                }
            }
        }
        

        public function render_page(): void {
            $submit_button_html = sprintf( '<p><button type="submit" class="button button-primary button-large">%s</button></p>', esc_html__( 'Download CSV of UI Settings', 'kiss-woo-shipping-debugger' ) );
            $additional_file = isset($_GET['wse_additional_file']) ? sanitize_text_field(wp_unslash($_GET['wse_additional_file'])) : '';
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'KISS Woo Shipping Settings Debugger & Scanner', 'kiss-woo-shipping-debugger' ); ?></h1>
                
                <hr/>
                <div class="rules-scanner-wrapper">
                    <h2><?php esc_html_e( 'Custom Rules Scanner', 'kiss-woo-shipping-debugger' ); ?></h2>
                    <p><?php esc_html_e( 'This tool scans your active theme for files containing programmatic shipping rules. It checks a default file and allows you to specify an additional one to scan.', 'kiss-woo-shipping-debugger' ); ?></p>
                    
                    <form method="get" style="padding: 1em; border: 1px solid #c3c4c7; background: #fff;">
                        <input type="hidden" name="page" value="<?php echo esc_attr( $this->page_slug ); ?>">
                        <p style="margin-top: 0;">
                            <label for="wse_additional_file"><strong><?php esc_html_e( 'Scan Additional Theme File (Optional)', 'kiss-woo-shipping-debugger' ); ?></strong></label><br>
                            <span style="font-family: monospace; font-size: 0.9em;"><?php echo esc_html( get_stylesheet_directory() ); ?></span><input type="text" name="wse_additional_file" id="wse_additional_file" class="regular-text" style="width: auto; max-width: 400px;" placeholder="/inc/woo-functions.php" value="<?php echo esc_attr( $additional_file ); ?>">
                        </p>
                        <p style="margin-bottom: 0;"><button type="submit" class="button"><?php esc_html_e( 'Scan for Custom Rules', 'kiss-woo-shipping-debugger' ); ?></button></p>
                    </form>
                    
                    <?php $this->scan_and_render_custom_rules( $additional_file ); ?>
                </div>
                <hr/>

                <h2><?php esc_html_e( 'UI-Based Settings Export', 'kiss-woo-shipping-debugger' ); ?></h2>
                <p><?php esc_html_e( 'This section previews and exports the settings configured in the WooCommerce UI (shipping zones, methods, etc.).', 'kiss-woo-shipping-debugger' ); ?></p>
                
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( $this->page_slug, 'wse_nonce' ); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr( $this->page_slug ); ?>" />
                    <?php echo $submit_button_html; ?>
                    
                    <?php
                        $preview_limit = 10;
                        $zone_headers = [ 'Zone Name', 'Locations', 'Method Title', 'Method Type', 'Cost', 'Class Cost' ];
                        $all_zones = WC_Shipping_Zones::get_zones();
                        $preview_zones_data = []; $total_zone_rows = 0;
                        foreach ($all_zones as $zone_array) { if (count($preview_zones_data) >= $preview_limit) break; $zone = new WC_Shipping_Zone($zone_array['zone_id']); $zone_id = $zone->get_id(); $zone_edit_url = admin_url('admin.php?page=wc-settings&tab=shipping&zone_id=' . $zone_id); $zone_name_html = sprintf('<a href="%s" title="Edit this shipping zone">%s</a>', esc_url($zone_edit_url), esc_html($zone->get_zone_name())); $locations = []; foreach ($zone->get_zone_locations() as $location) { $locations[] = $location->code . ':' . $location->type; } $location_str = empty($locations) ? 'Rest of the World' : implode(' | ', $locations); $methods = $zone->get_shipping_methods(true); if (empty($methods)) { $preview_zones_data[] = [ $zone_name_html, $location_str, '<em>No methods configured</em>', '', '', '' ]; $total_zone_rows++; } else { foreach ($methods as $method) { if (count($preview_zones_data) >= $preview_limit) break 2; $settings = $method->instance_settings; $cost = $settings['cost'] ?? ''; if (!empty($settings['class_costs']) && is_array($settings['class_costs'])) { foreach ($settings['class_costs'] as $slug => $class_cost) { if (count($preview_zones_data) >= $preview_limit) break 3; $preview_zones_data[] = [ $zone_name_html, $location_str, $method->get_title(), $method->id, $cost, $class_cost ]; $total_zone_rows++; } } else { $preview_zones_data[] = [ $zone_name_html, $location_str, $method->get_title(), $method->id, $cost, '' ]; $total_zone_rows++; } } } }
                    ?>
                    <h3><?php esc_html_e( 'Shipping Zones & Methods Preview', 'kiss-woo-shipping-debugger' ); ?></h3>
                    <table class="wp-list-table widefat striped"><thead><tr><?php foreach ( $zone_headers as $header ) echo '<th scope="col">' . esc_html( $header ) . '</th>'; ?></tr></thead><tbody><?php foreach ( $preview_zones_data as $row ) { echo '<tr>'; foreach ( $row as $cell ) echo '<td>' . wp_kses_post( $cell ) . '</td>'; echo '</tr>'; } ?></tbody></table>
                    <?php if ( $total_zone_rows > count($preview_zones_data) ) printf('<p><em>' . esc_html__('And %d more rows...', 'kiss-woo-shipping-debugger') . '</em></p>', esc_html( $total_zone_rows - count($preview_zones_data) ) ); ?>
                    
                    <hr/>
                    <?php echo $submit_button_html; ?>
                </form>
            </div>
            <?php
        }
        
        public function handle_export(): void {
            if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( __( 'Sorry, you are not allowed to export this data.', 'kiss-woo-shipping-debugger' ) ); }
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