<?php
/**
 * KISS Woo Shipping Settings Exporter — main plugin file.
 *
 * Provides an admin-side utility to export UI-based shipping settings and perform
 * advanced analysis on custom, code-based shipping restriction rules.
 *
 * @package   KISSShippingExporter
 * @author    KISS Plugins
 * @copyright Copyright (c) 2025 KISS Plugins
 * @license   GPL-2.0+
 * @link      https://kissplugins.com
 * @since     0.1.0
 */

/**
 * Plugin Name: KISS Woo Shipping Settings Exporter
 * Description: Exports UI settings and analyzes custom shipping rules from your theme's functions.php and other files.
 * Version:   1.5.4
 * Author:    KISS Plugins
 * Author URI: https://kissplugins.com
 * License:   GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-exporter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KISS_WSE_PLUGIN_FILE', __FILE__ );

// Defer plugin initialization until all plugins are loaded.
add_action( 'plugins_loaded', 'kiss_wse_initialize_exporter' );

/**
 * Initializes the exporter class and all dependencies after checking requirements.
 */
function kiss_wse_initialize_exporter(): void {
    // 1. Check for dependencies first.
    if ( ! class_exists( 'WooCommerce' ) || ! class_exists('PhpParser\ParserFactory') ) {
        add_action( 'admin_notices', function() {
            $message = !class_exists('WooCommerce')
                ? __( 'KISS Exporter requires WooCommerce to be active.', 'kiss-woo-shipping-exporter' )
                : __( 'KISS Exporter requires the "PHP-Parser Loader" plugin to be installed and active to scan custom code rules.', 'kiss-woo-shipping-exporter' );
            printf('<div class="notice notice-error"><p>%s</p></div>', wp_kses_post($message));
        });
        return;
    }

    // 2. Define the dependent visitor class.
    if (!class_exists('KISS_WSE_AST_Visitor')) {
        class KISS_WSE_AST_Visitor extends \PhpParser\NodeVisitorAbstract {
            public $rules = [];
            private $declared_arrays = [];
            private $condition_context_stack = [];
            private $source_lines;

            public function __construct(string $code) { 
                $this->source_lines = explode("\n", $code);
            }

            public function beforeTraverse(array $nodes) {
                // First pass: find all array declarations and store them.
                $traverser = new \PhpParser\NodeTraverser();
                $array_collector = new class extends \PhpParser\NodeVisitorAbstract {
                    public $arrays = [];
                    public function enterNode(\PhpParser\Node $node) {
                        if ($node instanceof \PhpParser\Node\Expr\Assign && $node->expr instanceof \PhpParser\Node\Expr\Array_) {
                            if (isset($node->var->name)) {
                                $this->arrays[$node->var->name] = $this->extract_array_values($node->expr);
                            }
                        }
                    }
                    private function extract_array_values(\PhpParser\Node\Expr\Array_ $array_node): array {
                        $values = [];
                        foreach ($array_node->items as $item) {
                            if ($item->value instanceof \PhpParser\Node\Scalar\String_) {
                                $values[] = $item->value->value;
                            }
                        }
                        return $values;
                    }
                };
                $traverser->addVisitor($array_collector);
                $traverser->traverse($nodes);
                $this->declared_arrays = $array_collector->arrays;
            }

            public function enterNode(\PhpParser\Node $node) {
                if ($node instanceof \PhpParser\Node\Stmt\If_) {
                    $this->condition_context_stack[] = $this->extract_conditions($node->cond);
                }
                
                if ($node instanceof \PhpParser\Node\Expr\MethodCall && isset($node->name->name) && $node->name->name === 'add') {
                    $current_conditions = !empty($this->condition_context_stack) ? array_merge(...$this->condition_context_stack) : [];
                    if (empty($current_conditions)) return;
                    
                    $rule = ['conditions' => $current_conditions, 'actions' => [], 'raw_code' => ''];
                    $this->extract_actions($node, $rule['actions']);

                    $code_node = $node;
                    while($code_node = $code_node->getAttribute('parent')) {
                        if ($code_node instanceof \PhpParser\Node\Stmt\If_) {
                             $startLine = $code_node->getStartLine() - 1;
                             $endLine = $code_node->getEndLine() -1;
                             $rule['raw_code'] = implode("\n", array_slice($this->source_lines, $startLine, $endLine - $startLine + 1));
                            break;
                        }
                    }
                    $this->rules[] = $rule;
                }
            }

            public function leaveNode(\PhpParser\Node $node) {
                if ($node instanceof \PhpParser\Node\Stmt\If_) {
                    array_pop($this->condition_context_stack);
                }
            }

            private function extract_conditions($cond_node, $conditions = []) {
                if ($cond_node instanceof \PhpParser\Node\Expr\BinaryOp) {
                    $variableNode = $cond_node->left;
                    $valueNode = $cond_node->right;
                    
                    if ($variableNode instanceof \PhpParser\Node\Expr\Variable && in_array($variableNode->name, ['state', 'postcode'])) {
                        $value = 'Dynamic Value'; // Default
                        if ($valueNode instanceof \PhpParser\Node\Scalar\String_ || $valueNode instanceof \PhpParser\Node\Scalar\LNumber) {
                            $value = $valueNode->value;
                        }
                        $conditions[] = [
                            'type' => 'variable_comparison',
                            'variable' => $variableNode->name,
                            'operator' => $cond_node->getOperatorSigil(),
                            'value' => $value
                        ];
                    } else {
                        $conditions = array_merge($conditions, $this->extract_conditions($cond_node->left));
                        $conditions = array_merge($conditions, $this->extract_conditions($cond_node->right));
                    }
                } else if ($cond_node instanceof \PhpParser\Node\Expr\FuncCall) {
                    $funcName = $cond_node->name->toString();
                    if ($funcName === 'has_term' && isset($cond_node->args[0]->value->items)) {
                        $cats = [];
                        foreach($cond_node->args[0]->value->items as $item) $cats[] = $item->value->value;
                        $conditions[] = ['type' => 'cart_has_category', 'value' => implode(', ', $cats)];
                    } else if ($funcName === 'in_array' && isset($cond_node->args[1]->value->name)) {
                        $array_name = $cond_node->args[1]->value->name;
                        if (isset($this->declared_arrays[$array_name])) {
                            $conditions[] = [
                                'type' => 'variable_in_array', 
                                'variable' => $cond_node->args[0]->value->name ?? 'unknown',
                                'array_name' => $array_name,
                                'value' => implode(', ', $this->declared_arrays[$array_name])
                            ];
                        }
                    } else {
                        $conditions[] = ['type' => 'function_check', 'value' => $funcName];
                    }
                }
                return $conditions;
            }

            private function extract_actions($action_node, &$actions) {
                if (isset($action_node->args[1]->value) && $action_node->args[1]->value instanceof \PhpParser\Node\Scalar\String_) {
                    $actions[] = ['type' => 'block_shipment', 'message' => $action_node->args[1]->value->value];
                }
            }
        }
    }

    // 3. Define the main parser class.
    if ( ! class_exists( 'KISS_WSE_Rule_Parser' ) ) {
        class KISS_WSE_Rule_Parser {
            private $parser;
            private $last_debug_info = ['status' => 'Not run', 'ast_dump' => ''];

            public function __construct() {
                $this->parser = (new \PhpParser\ParserFactory)->createForNewestSupportedVersion();
            }

            public function get_last_debug_info(): array { return $this->last_debug_info; }

            public function parse_file(string $file_path) {
                try {
                    $code = file_get_contents($file_path);
                    if (empty($code)) {
                        $this->last_debug_info['status'] = 'File is empty.';
                        return [];
                    }
                    $ast = $this->parser->parse($code);
                    
                    $dumper = new \PhpParser\NodeDumper;
                    $this->last_debug_info = [
                        'status' => 'File successfully parsed. Traversing AST for rules...',
                        'ast_dump' => $dumper->dump($ast)
                    ];

                    $nodeTraverser = new \PhpParser\NodeTraverser();
                    $nodeTraverser->addVisitor(new \PhpParser\NodeVisitor\ParentConnectingVisitor());
                    $visitor = new KISS_WSE_AST_Visitor($code);
                    $nodeTraverser->addVisitor($visitor);
                    $nodeTraverser->traverse($ast);
                    
                    $this->last_debug_info['status'] = 'AST traversal complete. Found ' . count($visitor->rules) . ' potential rule actions.';
                    return $visitor->rules;

                } catch (\PhpParser\Error $e) {
                    $this->last_debug_info['status'] = 'Parser failed: ' . $e->getMessage();
                    return new WP_Error('parser_error', 'Failed to parse file: ' . $e->getMessage());
                }
            }

            public function format_rule_as_html(array $rule): string {
                $html = '';
                $condition_strings = [];
                foreach ($rule['conditions'] as $condition) {
                    switch ($condition['type']) {
                        case 'variable_comparison': $condition_strings[] = "the <code>\${$condition['variable']}</code> is {$condition['operator']} <strong>'{$condition['value']}'</strong>"; break;
                        case 'cart_has_category': $condition_strings[] = "the cart contains a product from category/ies <strong>'{$condition['value']}'</strong>"; break;
                        case 'function_check': $condition_strings[] = "the condition <code>{$condition['value']}()</code> is true"; break;
                        case 'variable_in_array': $condition_strings[] = "the <code>\${$condition['variable']}</code> is in the list of <strong>{$condition['array_name']}</strong> (values: <em>{$condition['value']}</em>)"; break;
                    }
                }
                if (!empty($condition_strings)) {
                    $html .= "<strong>IF</strong> " . implode("<br><strong>AND IF</strong> ", $condition_strings);
                }
                foreach ($rule['actions'] as $action) {
                    if ($action['type'] === 'block_shipment') {
                        $html .= "<br><strong>THEN</strong> Block the shipment with the message: <em>\"" . esc_html($action['message']) . "\"</em>";
                    }
                }
                return empty($html) ? 'Could not generate human-readable analysis for this rule.' : $html;
            }
        }
    }

    // 4. Define the main plugin controller class.
    if ( ! class_exists( 'KISS_WSE_Exporter' ) ) {
        final class KISS_WSE_Exporter {
            private $page_slug = 'kiss-wse-export';

            public function __construct() {
                add_action( 'admin_menu', [ $this, 'register_menu' ] );
                add_action( 'admin_post_' . $this->page_slug, [ $this, 'handle_export' ] );
                add_filter( 'plugin_action_links_' . plugin_basename( KISS_WSE_PLUGIN_FILE ), [ $this, 'add_action_links' ] );
            }

            public function add_action_links( array $links ): array {
                $settings_link = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'tools.php?page=' . $this->page_slug ) ), __( 'Export & Scan Settings', 'kiss-woo-shipping-exporter' ) );
                array_unshift( $links, $settings_link );
                return $links;
            }

            public function register_menu(): void {
                add_management_page( 'KISS Woo Shipping Exporter', 'KISS Shipping Exporter', 'manage_woocommerce', $this->page_slug, [ $this, 'render_page' ] );
            }

            public function render_page(): void {
                ?>
                <div class="wrap">
                    <h1><?php esc_html_e( 'KISS Woo Shipping Settings Exporter & Scanner', 'kiss-woo-shipping-exporter' ); ?></h1>
                    <hr/>
                    <div id="custom-rules-scanner">
                        <h2><?php esc_html_e( 'Custom Rules Scanner', 'kiss-woo-shipping-exporter' ); ?></h2>
                        <p><?php esc_html_e( 'The scanner analyzes your active theme files for code-based shipping restrictions and attempts to translate them into a human-readable format.', 'kiss-woo-shipping-exporter' ); ?></p>
                        <?php $this->render_custom_rules_tables(); ?>
                    </div>
                    <hr/>
                    <div id="ui-settings-exporter">
                        <h2><?php esc_html_e( 'UI-Based Settings Export', 'kiss-woo-shipping-exporter' ); ?></h2>
                        <p><?php esc_html_e( 'This section previews and exports the settings configured in the WooCommerce UI (shipping zones, methods, etc.).', 'kiss-woo-shipping-exporter' ); ?></p>
                        
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( $this->page_slug, 'wse_nonce' ); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr( $this->page_slug ); ?>" />
                            <p><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Download CSV of UI Settings', 'kiss-woo-shipping-exporter' ); ?></button></p>
                            
                            <?php
                                $preview_limit = 10;
                                $zone_headers = [ 'Zone Name', 'Locations', 'Method Title', 'Method Type', 'Cost', 'Class Cost' ];
                                $all_zones = WC_Shipping_Zones::get_zones();
                                $preview_zones_data = []; $total_zone_rows = 0;
                                foreach ($all_zones as $zone_array) { if (count($preview_zones_data) >= $preview_limit) break; $zone = new WC_Shipping_Zone($zone_array['zone_id']); $locations = []; foreach ($zone->get_zone_locations() as $location) { $locations[] = $location->code . ':' . $location->type; } $location_str = empty($locations) ? 'Rest of the World' : implode(' | ', $locations); $methods = $zone->get_shipping_methods(true); if (empty($methods)) { $preview_zones_data[] = [ $zone->get_zone_name(), $location_str, '<em>No methods configured</em>', '', '', '' ]; $total_zone_rows++; } else { foreach ($methods as $method) { if (count($preview_zones_data) >= $preview_limit) break 2; $settings = $method->instance_settings; $cost = $settings['cost'] ?? ''; if (!empty($settings['class_costs']) && is_array($settings['class_costs'])) { foreach ($settings['class_costs'] as $slug => $class_cost) { if (count($preview_zones_data) >= $preview_limit) break 3; $preview_zones_data[] = [ $zone->get_zone_name(), $location_str, $method->get_title(), $method->id, $cost, $class_cost ]; $total_zone_rows++; } } else { $preview_zones_data[] = [ $zone->get_zone_name(), $location_str, $method->get_title(), $method->id, $cost, '' ]; $total_zone_rows++; } } } }
                            ?>
                            <h3><?php esc_html_e( 'Shipping Zones & Methods Preview', 'kiss-woo-shipping-exporter' ); ?></h3>
                            <table class="wp-list-table widefat striped"><thead><tr><?php foreach ( $zone_headers as $header ) echo '<th scope="col">' . esc_html( $header ) . '</th>'; ?></tr></thead><tbody><?php foreach ( $preview_zones_data as $row ) { echo '<tr>'; foreach ( $row as $cell ) echo '<td>' . wp_kses_post( $cell ) . '</td>'; echo '</tr>'; } ?></tbody></table>
                            <?php if ( $total_zone_rows > count($preview_zones_data) ) printf('<p><em>' . esc_html__('And %d more rows...', 'kiss-woo-shipping-exporter') . '</em></p>', esc_html( $total_zone_rows - count($preview_zones_data) ) ); ?>
                            
                            <hr/>
                            <p><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Download CSV of UI Settings', 'kiss-woo-shipping-exporter' ); ?></button></p>
                        </form>
                    </div>
                </div>
                <?php
            }

            private function render_custom_rules_tables(): void {
                $files_to_scan = [
                    'Theme Functions' => get_stylesheet_directory() . '/functions.php',
                    'Shipping Restrictions File' => get_stylesheet_directory() . '/inc/shipping-restrictions.php',
                ];
                $rule_parser = new KISS_WSE_Rule_Parser();
                foreach ($files_to_scan as $label => $file_path) {
                    echo '<h3>' . esc_html( $label ) . '</h3>';
                    echo '<code>' . esc_html( str_replace(ABSPATH, '/', $file_path) ) . '</code>';
                    if ( ! file_exists($file_path) ) {
                        echo '<p><em>' . esc_html__('File not found.', 'kiss-woo-shipping-exporter') . '</em></p>';
                        continue;
                    }
                    $rules = $rule_parser->parse_file($file_path);
                    echo '<table class="wp-list-table widefat striped" style="margin-top:1em;"><thead><tr><th style="width:45%;">Human-Readable Analysis (Best Guess)</th><th>Source Code Snippet</th></tr></thead><tbody>';
                    if ( is_wp_error($rules) ) {
                        echo '<tr><td colspan="2"><strong>Parser Error:</strong> ' . esc_html( $rules->get_error_message() ) . '</td></tr>';
                    } elseif ( empty($rules) ) {
                        echo '<tr><td colspan="2">' . esc_html__('No shipping restriction rules were automatically detected in this file.', 'kiss-woo-shipping-exporter') . '</td></tr>';
                    } else {
                        foreach ( $rules as $rule ) {
                            echo '<tr>';
                            echo '<td>' . wp_kses_post( $rule_parser->format_rule_as_html($rule) ) . '</td>';
                            echo '<td><pre style="white-space: pre-wrap; font-size: 12px; background:#f9f9f9; padding:10px; border:1px solid #ddd;"><code>' . esc_html( $rule['raw_code'] ) . '</code></pre></td>';
                            echo '</tr>';
                        }
                    }
                    echo '</tbody></table>';
                    echo '<details style="margin-top:1em; padding-left: 10px;"><summary style="cursor:pointer; color:#2271b1;">Toggle Debugging Information</summary><div style="padding:10px; background-color:#fafafa; border:1px solid #ddd; margin-top:5px;">';
                    $debug_info = $rule_parser->get_last_debug_info();
                    echo '<strong>Parser Status:</strong> ' . esc_html($debug_info['status']) . '<br>';
                    if (!empty($debug_info['ast_dump'])) {
                        echo '<strong>AST (Abstract Syntax Tree) Dump:</strong><pre style="white-space:pre-wrap; max-height: 400px; overflow-y: scroll; background-color: #fff; padding: 5px;"><code>' . esc_html($debug_info['ast_dump']) . '</code></pre>';
                    }
                    echo '</div></details>';
                }
            }
            
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
    
    // 5. Finally, instantiate the main class.
    new KISS_WSE_Exporter();
}