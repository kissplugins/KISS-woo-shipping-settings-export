<?php
/**
 * Plugin Name: KISS Woo Shipping Settings Debugger
 * Description: Exports UI-based WooCommerce shipping settings and scans theme files for custom shipping rules via AST.
 * Version:     1.0.0
 * Author:      KISS Plugins
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-debugger
 */

if ( ! defined( 'ABSPATH' ) ) exit;
define( 'KISS_WSE_PLUGIN_FILE', __FILE__ );

add_action( 'plugins_loaded', 'kiss_wse_initialize_debugger' );
function kiss_wse_initialize_debugger(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', fn() => printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'KISS Woo Shipping Settings Debugger requires WooCommerce to be active.', 'kiss-woo-shipping-debugger' )
        ));
        return;
    }
    new KISS_WSE_Debugger();
}

class KISS_WSE_Debugger {
    private string $page_slug = 'kiss-wse-export';

    public function __construct() {
        // Load PHP-Parser
        if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
            $this->maybe_require_parser_loader();
        }

        add_filter( 'plugin_action_links_' . plugin_basename( KISS_WSE_PLUGIN_FILE ), [ $this, 'add_action_links' ] );
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_' . $this->page_slug, [ $this, 'handle_export' ] );
    }

    private function maybe_require_parser_loader(): void {
        foreach ( glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
            $loader = $dir . '/php-parser-loader.php';
            if ( file_exists( $loader ) ) {
                require_once $loader;
                if ( class_exists( \PhpParser\ParserFactory::class ) ) {
                    return;
                }
            }
        }
        error_log( 'KISS WSE Debugger: php-parser-loader.php not found.' );
    }

    public function add_action_links( array $links ): array {
        $url  = esc_url( admin_url( 'tools.php?page=' . $this->page_slug ) );
        $text = esc_html__( 'Export & Scan Settings', 'kiss-woo-shipping-debugger' );
        array_unshift( $links, "<a href=\"$url\">$text</a>" );
        return $links;
    }

    public function register_menu(): void {
        add_management_page(
            __( 'KISS Woo Shipping Debugger', 'kiss-woo-shipping-debugger' ),
            __( 'KISS Shipping Debugger', 'kiss-woo-shipping-debugger' ),
            'manage_woocommerce',
            $this->page_slug,
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        $additional = $_GET['wse_additional_file'] ?? '';
        $additional = sanitize_text_field( wp_unslash( $additional ) );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'KISS Woo Shipping Settings Debugger & Scanner', 'kiss-woo-shipping-debugger' ) . '</h1>';

        // --- Custom Rules Scanner UI ---
        echo '<hr/><h2>' . esc_html__( 'Custom Rules Scanner', 'kiss-woo-shipping-debugger' ) . '</h2>';
        echo '<p>' . esc_html__( 'Scans your theme files for shipping-related code via AST.', 'kiss-woo-shipping-debugger' ) . '</p>';
        printf(
            '<form method="get" style="padding:1em;border:1px solid #c3c4c7;background:#fff;">
                <input type="hidden" name="page" value="%1$s">
                <p>
                  <label><strong>%2$s</strong></label><br>
                  <span style="font-family:monospace;">%3$s</span>
                  <input type="text" name="wse_additional_file" class="regular-text" placeholder="/inc/extra.php" value="%4$s">
                </p>
                <p><button type="submit" class="button">%5$s</button></p>
             </form>',
            esc_attr( $this->page_slug ),
            esc_html__( 'Scan Additional Theme File (Optional)', 'kiss-woo-shipping-debugger' ),
            esc_html( get_stylesheet_directory() ),
            esc_attr( $additional ),
            esc_html__( 'Scan for Custom Rules', 'kiss-woo-shipping-debugger' )
        );

        try {
            $this->scan_and_render_custom_rules( $additional );
        } catch ( \Throwable $e ) {
            echo '<div class="notice notice-error"><pre>' . esc_html( $e->getMessage() ) . '</pre></div>';
            error_log( '[KISS Scanner] ' . $e->getMessage() );
        }

        // --- UI Settings Export UI ---
        echo '<hr/><h2>' . esc_html__( 'UI-Based Settings Export', 'kiss-woo-shipping-debugger' ) . '</h2>';
        echo '<p>' . esc_html__( 'Preview and download WooCommerce shipping settings configured in the admin.', 'kiss-woo-shipping-debugger' ) . '</p>';
        printf(
            '<form method="post" action="%1$s">%2$s
               <input type="hidden" name="action" value="%3$s">
               <p><button type="submit" class="button button-primary">%4$s</button></p>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            wp_nonce_field( $this->page_slug, 'wse_nonce', true, false ),
            esc_attr( $this->page_slug ),
            esc_html__( 'Download CSV of UI Settings', 'kiss-woo-shipping-debugger' )
        );
        $this->render_preview_table();

        echo '</div>';
    }

    public function handle_export(): void {
        // ... your existing CSV export logic ...
    }

    private function scan_and_render_custom_rules( ?string $additional ): void {
        require_once plugin_dir_path( __FILE__ ) . 'lib/RateAddCallVisitor.php';

        $files = [ get_stylesheet_directory() . '/inc/shipping-restrictions.php' ];
        if ( $additional ) {
            $clean = '/' . ltrim( wp_normalize_path( $additional ), '/' );
            $files[] = get_stylesheet_directory() . $clean;
        }

        foreach ( $files as $file ) {
            printf( '<h3>Scanning <code>%s</code></h3>', esc_html( wp_make_link_relative( $file ) ) );
            if ( ! file_exists( $file ) ) {
                echo '<p><em>' . esc_html__( 'File not found.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
                continue;
            }

            $code = file_get_contents( $file );
            $factory = new \PhpParser\ParserFactory();
            $parser = method_exists( $factory, 'createForNewestSupportedVersion' )
                ? $factory->createForNewestSupportedVersion()
                : $factory->create( \PhpParser\ParserFactory::PREFER_PHP7 );

            $ast       = $parser->parse( $code );
            $trav      = new \PhpParser\NodeTraverser();
            $visitor   = new \KISSShippingDebugger\RateAddCallVisitor();
            $trav->addVisitor( $visitor );
            $trav->traverse( $ast );

            // gather
            $sections = [
                'filterHooks' => $visitor->getFilterHookNodes(),
                'feeHooks'    => $visitor->getFeeHookNodes(),
                'rateCalls'   => $visitor->getAddRateNodes(),
                'newRates'    => $visitor->getNewRateNodes(),
                'unsetRates'  => $visitor->getUnsetRateNodes(),
                'addFees'     => $visitor->getAddFeeNodes(),
                'errors'      => $visitor->getErrorAddNodes(),
            ];

            foreach ( [
                'filterHooks' => __('Package Rate Filters', 'kiss-woo-shipping-debugger'),
                'feeHooks'    => __('Cart Fee Hooks',      'kiss-woo-shipping-debugger'),
                'rateCalls'   => __('add_rate() Calls',    'kiss-woo-shipping-debugger'),
                'newRates'    => __('new WC_Shipping_Rate', 'kiss-woo-shipping-debugger'),
                'unsetRates'  => __('unset($rates[])',     'kiss-woo-shipping-debugger'),
                'addFees'     => __('add_fee() Calls',     'kiss-woo-shipping-debugger'),
                'errors'      => __('$errors->add()',      'kiss-woo-shipping-debugger'),
            ] as $key => $title ) {
                if ( ! empty( $sections[ $key ] ) ) {
                    printf( '<h4>%s</h4><ul>', esc_html( $title ) );
                    foreach ( $sections[ $key ] as $node ) {
                        printf( '<li>%s on line %d</li>',
                            esc_html( $title ),
                            esc_html( $node->getLine() )
                        );
                    }
                    echo '</ul>';
                }
            }

            if ( empty( array_filter( $sections ) ) ) {
                echo '<p><em>' . esc_html__( 'No shipping-related hooks or methods found.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
            }
        }
    }

    private function render_preview_table(): void {
        // ... your existing preview‐table code ...
    }
}

// instantiate
new KISS_WSE_Debugger();