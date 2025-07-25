<?php
/**
 * Plugin Name:       KISS WooCommerce Settings Debugger
 * Description:       A debugging tool to inspect WooCommerce shipping settings and scan for custom shipping rules.
 * Version:           1.2
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kiss-woo-debugger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include the NodeVisitor class required for AST traversal.
// Using require_once to prevent fatal errors if somehow included twice.
require_once __DIR__ . '/lib/RateAddCallVisitor.php';

/**
 * Add the debugger page to the WooCommerce admin menu.
 */
function kiss_debugger_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'Shipping Settings Debugger',
        'Shipping Debugger',
        'manage_woocommerce',
        'kiss-shipping-debugger',
        'kiss_debugger_page_html'
    );
}
add_action( 'admin_menu', 'kiss_debugger_admin_menu' );

/**
 * Renders the HTML for the debugger page, now with a dependency check.
 */
function kiss_debugger_page_html() {
    $parser_active = class_exists( 'PhpParser\\ParserFactory' );
    ?>
    <div class="wrap">
        <h1><span class="dashicons-wordpress-alt"></span> KISS WooCommerce Settings Debugger</h1>
        <p>This tool helps you inspect raw WooCommerce shipping settings and scans for custom code that might be overriding them.</p>
        
        <hr/>

        <h2><span class="dashicons-admin-plugins"></span> Dependency Status: PHP-Parser</h2>
        <?php
        if ( $parser_active ) {
            echo '<div class="notice notice-success"><p><strong>✅ Success:</strong> The <code>PHP-Parser</code> library is loaded and active.</p></div>';
            
            // Render the functionality test panel since the library is active
            ?>
            <h4><span class="dashicons dashicons-beaker"></span> Parser Functionality Test</h4>
            <div style="background-color: #f6f7f7; border: 1px solid #ccc; padding: 10px 20px; margin-top: 10px; max-width: 800px;">
                <p><em>This test confirms the library can parse a PHP string and re-format it.</em></p>
                <pre><code><?php
                    $code_to_parse = '<?php function test() { echo "Success!"; }';
                    echo "<strong>Input String:</strong>\n" . htmlspecialchars( $code_to_parse ) . "\n\n";

                    $parser = ( new \PhpParser\ParserFactory() )->createForNewestSupportedVersion();
                    try {
                        $ast = $parser->parse( $code_to_parse );
                        $prettyPrinter = new \PhpParser\PrettyPrinter\Standard();
                        $formatted_code = $prettyPrinter->prettyPrintFile( $ast );

                        echo "<strong>Formatted Output:</strong>\n" . htmlspecialchars( $formatted_code ) . "\n\n";
                        echo '<strong style="color: green;">Test Result: SUCCESS</strong>';

                    } catch ( \PhpParser\Error $e ) {
                        echo '<strong style="color: red;">❌ FAILED: An error occurred during parsing:</strong> ' . htmlspecialchars( $e->getMessage() );
                    }
                ?></code></pre>
            </div>
            <?php
        } else {
            echo '<div class="notice notice-error"><p><strong>❌ Dependency Missing:</strong> The <code>PHP-Parser Loader</code> plugin is not active. The <strong>Custom Rules Scanner</strong> will be disabled until this dependency is installed and activated.</p></div>';
        }
        ?>
        <hr style="margin-top: 2em;"/>

        <div id="debugger-content" style="display: flex; gap: 30px; flex-wrap: wrap;">
            <div id="settings-inspector" style="flex: 1; min-width: 400px;">
                <h2>Shipping Settings Inspector</h2>
                <?php echo get_shipping_methods_settings_html(); ?>
            </div>

            <div id="custom-rules-scanner" style="flex: 1; min-width: 400px;">
                <h2>Custom Rules Scanner</h2>
                <?php
                // Only run the scanner if the parser is active.
                if ( $parser_active ) {
                    echo '<p>Scanning active plugins and theme for <code>add_rate</code> calls...</p>';
                    echo scan_for_custom_rules();
                } else {
                    echo '<p><em>Scanner is disabled because the PHP-Parser dependency is missing.</em></p>';
                }
                ?>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Scans active plugins and theme for custom shipping rules.
 *
 * @return string HTML output of the scan results.
 */
function scan_for_custom_rules() {
    $results_html = '<ul>';
    $found_files = [];

    // Scan active plugins
    $active_plugins = get_option( 'active_plugins' );
    foreach ( $active_plugins as $plugin_path ) {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_path );
        // Prevent scanning the parser loader itself
        if ( strpos($plugin_dir, 'php-parser-loader') !== false ) continue;

        $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) );
        foreach ( $files as $file ) {
            if ( $file->isDir() || $file->getExtension() !== 'php' ) {
                continue;
            }
            $found_rules = scan_file_for_rules_ast( $file->getPathname() );
            if ( ! empty( $found_rules ) ) {
                $found_files[] = [
                    'path' => $file->getPathname(),
                    'rules' => $found_rules
                ];
            }
        }
    }

    // Prepare output
    if ( ! empty( $found_files ) ) {
        foreach ( $found_files as $file ) {
            $short_path = str_replace( ABSPATH, '', $file['path'] );
            $results_html .= '<li><strong>Found in: ' . esc_html( $short_path ) . '</strong>';
            $results_html .= '<ul>';
            foreach( $file['rules'] as $rule ) {
                $results_html .= '<li><span class="dashicons dashicons-yes-alt" style="color: #2271b1;"></span> Found <code>add_rate</code> call on line ' . esc_html( $rule['line'] ) . '</li>';
            }
            $results_html .= '</ul></li>';
        }
    } else {
        $results_html .= '<li>✅ No custom <code>add_rate</code> calls found in active plugins.</li>';
    }

    $results_html .= '</ul>';
    return $results_html;
}

/**
 * Phase 1: New function using PHP-Parser to scan a file.
 *
 * @param string $file_path The full path to the PHP file to scan.
 * @return array An array of found rules with their line numbers.
 */
function scan_file_for_rules_ast( $file_path ) {
    $found_rules = [];
    if ( ! class_exists('PhpParser\\ParserFactory') || ! file_exists( $file_path ) ) {
        return $found_rules;
    }

    $code = file_get_contents( $file_path );
    if ( empty( $code ) ) {
        return $found_rules;
    }

    $parser = ( new PhpParser\ParserFactory() )->createForNewestSupportedVersion();
    $traverser = new PhpParser\NodeTraverser();
    $visitor = new RateAddCallVisitor();
    $traverser->addVisitor( $visitor );

    try {
        $ast = $parser->parse( $code );
        $traverser->traverse( $ast );
        $add_rate_nodes = $visitor->getAddRateNodes();

        foreach ( $add_rate_nodes as $node ) {
            $found_rules[] = [
                'line' => $node->getStartLine(),
                'code' => 'add_rate'
            ];
        }
    } catch ( PhpParser\Error $e ) {
        // Skip files that fail to parse.
    }

    return $found_rules;
}

/**
 * Fetches and formats the shipping methods settings for display.
 * @return string HTML table of the settings.
 */
function get_shipping_methods_settings_html() {
    global $wpdb;
    $results = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->prefix}options WHERE option_name LIKE 'woocommerce_%_settings'" );

    if ( empty( $results ) ) {
        return '<p>No WooCommerce shipping settings were found in the options table.</p>';
    }

    $output = '<table class="widefat striped"><thead><tr><th>Setting Name (Option Key)</th><th>Value</th></tr></thead><tbody>';
    foreach ( $results as $result ) {
        $output .= '<tr>';
        $output .= '<td>' . esc_html( $result->option_name ) . '</td>';
        $value = maybe_unserialize( $result->option_value );
        $output .= '<td><pre>' . esc_html( print_r( $value, true ) ) . '</pre></td>';
        $output .= '</tr>';
    }
    $output .= '</tbody></table>';

    return $output;
}