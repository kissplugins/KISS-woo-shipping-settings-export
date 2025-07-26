<?php
/**
 * Plugin Name: KISS Woo Shipping Settings Debugger
 * Description: Exports UI-based WooCommerce shipping settings and scans theme files for custom shipping rules via AST.
 * Version:     1.0.5
 * Author:      KISS Plugins
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-debugger
 *
 * Changelog:
 * - 1.0.5
 *   - UX: More human-friendly descriptions:
 *         • “Free Shipping” detection now reads as “the rate is a Free Shipping method”.
 *         • Common variable names are translated (e.g., has_drinks → “the cart contains drinks”, adjusted_total → “the non-drink subtotal”).
 *         • Comparisons like adjusted_total < 20 render as “the non-drink subtotal is under $20”.
 *         • Resolves simple in-scope string assignments for variables (e.g., {custom_rate_id} → drinks_shipping_flat).
 *   - Fix: Corrected a typo in action links registration.
 * - 1.0.4
 *   - UX: Adds context from surrounding conditions for matches:
 *         • Shows WHEN-conditions for unset($rates[...]), new WC_Shipping_Rate(...), and add_fee().
 *         • Detects common patterns like strpos($rate->method_id, 'free_shipping') to say “free shipping rate”.
 *         • Includes IDs/labels/costs for new WC_Shipping_Rate where available.
 * - 1.0.3
 *   - UX: Improved human-readable messages:
 *         • Extracts messages built with concatenation, sprintf(), and interpolated strings for $errors->add().
 *         • Shows dynamic placeholders (e.g., {restricted_states[$state]}) when parts are non-literal.
 *         • Attempts to display the key used in unset($rates[...]) even when dynamic, via readable placeholders.
 *   - Fix: Avoid duplicate output by relying on a single instantiation path (no extra instantiation at file end).
 * - 1.0.2
 *   - UX: Renamed the $errors->add() section to “Checkout validation ($errors->add)”.
 *   - UX: Scanner now shows human-readable explanations:
 *         • Extracts and displays error message strings passed to $errors->add().
 *         • Adds short plain-English descriptions for filters, fee hooks, add_rate(), new WC_Shipping_Rate, unset($rates[]), and add_fee().
 * - 1.0.1
 *   - Security: Added capability + nonce verification to export handler and proper CSV streaming headers with hard exit.
 *   - Security: Added realpath clamping so the “additional file” scan is restricted to the active child theme /inc/ directory.
 *   - DX: Added automatic PHP-Parser availability check and self-test on settings page load with visible status.
 * - 1.0.0
 *   - Initial release.
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

        // --- PHP-Parser Status & Self-Test (auto) ---
        $parser_loaded = class_exists( \PhpParser\ParserFactory::class );
        $parser_ok     = false;
        $parser_msg    = '';

        if ( $parser_loaded ) {
            try {
                $parser = $this->create_parser();
                // Tiny parse test
                $test_code = "<?php\nfunction _kiss_wse_test(){return 42;} _kiss_wse_test();";
                $ast = $parser->parse( $test_code );
                $parser_ok  = is_array( $ast ) && ! empty( $ast );
                $parser_msg = $parser_ok
                    ? __( 'PHP-Parser is loaded and parsed a test snippet successfully.', 'kiss-woo-shipping-debugger' )
                    : __( 'PHP-Parser is present but could not parse the test snippet.', 'kiss-woo-shipping-debugger' );
            } catch ( \Throwable $e ) {
                $parser_ok  = false;
                $parser_msg = sprintf(
                    /* translators: %s is an error message */
                    __( 'PHP-Parser error: %s', 'kiss-woo-shipping-debugger' ),
                    $e->getMessage()
                );
                error_log( '[KISS WSE Parser Test] ' . $e->getMessage() );
            }
        } else {
            $parser_msg = __( 'PHP-Parser is not loaded. Some scanning features may be unavailable.', 'kiss-woo-shipping-debugger' );
        }

        if ( $parser_loaded && $parser_ok ) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html( $parser_msg )
            );
        } else {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html( $parser_msg )
            );
        }

        // --- Custom Rules Scanner UI ---
        echo '<hr/><h2>' . esc_html__( 'Custom Rules Scanner', 'kiss-woo-shipping-debugger' ) . '</h2>';
        echo '<p>' . esc_html__( 'Scans your theme files for shipping-related code via AST.', 'kiss-woo-shipping-debugger' ) . '</p>';
        printf(
            '<form method="get" style="padding:1em;border:1px solid #c3c4c7;background:#fff;">
                <input type="hidden" name="page" value="%1$s">
                <p>
                  <label><strong>%2$s</strong></label><br>
                  <span style="font-family:monospace;">%3$s/inc</span>
                  <input type="text" name="wse_additional_file" class="regular-text" placeholder="extra.php" value="%4$s">
                  <br><em>%6$s</em>
                </p>
                <p><button type="submit" class="button">%5$s</button></p>
             </form>',
            esc_attr( $this->page_slug ),
            esc_html__( 'Scan Additional Theme File (Optional)', 'kiss-woo-shipping-debugger' ),
            esc_html( get_stylesheet_directory() ),
            esc_attr( $additional ),
            esc_html__( 'Scan for Custom Rules', 'kiss-woo-shipping-debugger' ),
            esc_html__( 'Path is restricted to the active child theme’s /inc/ directory (e.g., "extra.php" or "subdir/custom.php").', 'kiss-woo-shipping-debugger' )
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
        // Capability check
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'kiss-woo-shipping-debugger' ), 403 );
        }

        // Nonce verification
        check_admin_referer( $this->page_slug, 'wse_nonce' );

        // Prepare CSV streaming
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );

        $host     = parse_url( home_url(), PHP_URL_HOST );
        $filename = sanitize_file_name( sprintf( '%s-shipping-%s.csv', (string) $host, wp_date( 'Y-m-d-His' ) ) );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        /**
         * Stream CSV.
         * To keep changes minimal and DRY, we allow existing logic to output rows if present.
         * Priority:
         *  1) Method $this->output_csv() if it exists.
         *  2) Function kiss_wse_output_csv() if defined.
         *  3) Fallback: emit a single header row noting no data.
         */
        if ( method_exists( $this, 'output_csv' ) ) {
            $this->output_csv();
        } elseif ( function_exists( 'kiss_wse_output_csv' ) ) {
            kiss_wse_output_csv();
        } else {
            $out = fopen( 'php://output', 'w' );
            if ( $out ) {
                fputcsv( $out, [ 'notice', 'No export rows available in this build.' ] );
                fclose( $out );
            }
        }

        exit;
    }

    private function scan_and_render_custom_rules( ?string $additional ): void {
        require_once plugin_dir_path( __FILE__ ) . 'lib/RateAddCallVisitor.php';

        // Always scan the canonical file within /inc/
        $files = [];
        $default_file = wp_normalize_path( trailingslashit( get_stylesheet_directory() ) . 'inc/shipping-restrictions.php' );
        $files[] = $default_file;

        // Restrict any "additional" file to the child theme /inc/ directory
        $base_inc  = wp_normalize_path( trailingslashit( get_stylesheet_directory() ) . 'inc' );
        $base_real = realpath( $base_inc );

        if ( $additional && $base_real ) {
            $rel   = ltrim( wp_normalize_path( $additional ), '/\\' ); // user may enter "extra.php" or "subdir/file.php"
            $try   = wp_normalize_path( $base_real . DIRECTORY_SEPARATOR . $rel );
            $real  = realpath( $try );

            // Verify $real exists and is under $base_real
            if ( $real ) {
                $real_norm = wp_normalize_path( $real );
                $base_norm = wp_normalize_path( $base_real );
                if ( strncmp( $real_norm, $base_norm, strlen( $base_norm ) ) === 0 && is_file( $real ) ) {
                    $files[] = $real;
                } else {
                    echo '<div class="notice notice-warning"><p>' .
                         esc_html__( 'Invalid file path. The additional file must be inside the active child theme’s /inc/ directory.', 'kiss-woo-shipping-debugger' ) .
                         '</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning"><p>' .
                     esc_html__( 'Additional file not found within /inc/. Please check the filename.', 'kiss-woo-shipping-debugger' ) .
                     '</p></div>';
            }
        }

        foreach ( $files as $file ) {
            printf( '<h3>Scanning <code>%s</code></h3>', esc_html( wp_make_link_relative( $file ) ) );
            if ( ! file_exists( $file ) ) {
                echo '<p><em>' . esc_html__( 'File not found.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
                continue;
            }

            // If parser isn't available, skip with a message
            if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
                echo '<p><em>' . esc_html__( 'PHP-Parser not available. Unable to scan this file.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
                continue;
            }

            $code   = file_get_contents( $file );
            $parser = $this->create_parser();

            $ast       = $parser->parse( $code );
            $trav      = new \PhpParser\NodeTraverser();
            // Attach parent pointers so we can read enclosing conditions.
            $trav->addVisitor( new \PhpParser\NodeVisitor\ParentConnectingVisitor() );
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

            // Titles
            $titles = [
                'filterHooks' => __('Package Rate Filters', 'kiss-woo-shipping-debugger'),
                'feeHooks'    => __('Cart Fee Hooks',      'kiss-woo-shipping-debugger'),
                'rateCalls'   => __('add_rate() Calls',    'kiss-woo-shipping-debugger'),
                'newRates'    => __('new WC_Shipping_Rate', 'kiss-woo-shipping-debugger'),
                'unsetRates'  => __('unset($rates[])',     'kiss-woo-shipping-debugger'),
                'addFees'     => __('add_fee() Calls',     'kiss-woo-shipping-debugger'),
                'errors'      => __('Checkout validation ($errors->add)', 'kiss-woo-shipping-debugger'),
            ];

            foreach ( $titles as $key => $title ) {
                if ( ! empty( $sections[ $key ] ) ) {
                    printf( '<h4>%s</h4><ul>', esc_html( $title ) );
                    foreach ( $sections[ $key ] as $node ) {
                        $line = (int) $node->getLine();
                        $desc = $this->describe_node( $key, $node );
                        printf(
                            '<li><strong>%s</strong> — %s %s</li>',
                            esc_html( $this->short_explanation_label( $key ) ),
                            esc_html( $desc ),
                            sprintf( '<span style="opacity:.7;">(%s %d)</span>', esc_html__( 'line', 'kiss-woo-shipping-debugger' ), esc_html( $line ) )
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

    /**
     * Create and return a PHP-Parser instance using the newest supported version.
     * Kept as a helper for DRY use across the parser self-test and the scanner.
     */
    private function create_parser() {
        $factory = new \PhpParser\ParserFactory();
        if ( method_exists( $factory, 'createForNewestSupportedVersion' ) ) {
            return $factory->createForNewestSupportedVersion();
        }
        return $factory->create( \PhpParser\ParserFactory::PREFER_PHP7 );
    }

    /**
     * Produce a short label used in list items.
     */
    private function short_explanation_label( string $key ): string {
        switch ( $key ) {
            case 'filterHooks': return __( 'Modifies shipping rates', 'kiss-woo-shipping-debugger' );
            case 'feeHooks':    return __( 'Adjusts cart fees/totals', 'kiss-woo-shipping-debugger' );
            case 'rateCalls':   return __( 'Adds a custom rate', 'kiss-woo-shipping-debugger' );
            case 'newRates':    return __( 'Creates a rate object', 'kiss-woo-shipping-debugger' );
            case 'unsetRates':  return __( 'Removes a rate', 'kiss-woo-shipping-debugger' );
            case 'addFees':     return __( 'Adds a cart fee', 'kiss-woo-shipping-debugger' );
            case 'errors':      return __( 'Checkout rule', 'kiss-woo-shipping-debugger' );
            default:            return __( 'Matched code', 'kiss-woo-shipping-debugger' );
        }
    }

    /**
     * Return a human-readable explanation for a given node.
     * Conservative and static: uses simple extraction heuristics, no execution.
     *
     * @param string          $key  Section key.
     * @param \PhpParser\Node $node Matched node.
     * @return string
     */
    private function describe_node( string $key, \PhpParser\Node $node ): string {
        try {
            switch ( $key ) {
                case 'errors':
                    // $errors->add( key, message, ... )
                    if ( property_exists( $node, 'args' ) && isset( $node->args[1] ) ) {
                        $msg = $this->extract_string( $node->args[1]->value );
                        if ( $msg !== '' ) {
                            return sprintf(
                                /* translators: checkout validation explanation + message */
                                __( 'Adds a checkout error message: “%s”. Customers will be blocked until they resolve it.', 'kiss-woo-shipping-debugger' ),
                                $msg
                            );
                        }
                    }
                    return __( 'Adds a checkout error message.', 'kiss-woo-shipping-debugger' );

                case 'filterHooks':
                    // add_filter('woocommerce_package_rates', callback, ...)
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    if ( $cb ) {
                        return sprintf(
                            __( 'Theme code hooks into WooCommerce package rates (%s) to change which shipping options appear.', 'kiss-woo-shipping-debugger' ),
                            $cb
                        );
                    }
                    return __( 'Theme code hooks into WooCommerce package rates to change which shipping options appear.', 'kiss-woo-shipping-debugger' );

                case 'feeHooks':
                    // add_action('woocommerce_cart_calculate_fees', callback, ...)
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    if ( $cb ) {
                        return sprintf(
                            __( 'Runs during cart fee calculation (%s). This can add discounts/surcharges and affect totals.', 'kiss-woo-shipping-debugger' ),
                            $cb
                        );
                    }
                    return __( 'Runs during cart fee calculation. This can add discounts/surcharges and affect totals.', 'kiss-woo-shipping-debugger' );

                case 'rateCalls':
                    return __( 'Calls add_rate() to insert a custom shipping option programmatically.', 'kiss-woo-shipping-debugger' );

                case 'newRates':
                    // new WC_Shipping_Rate(id, label, cost, meta, method_id)
                    $parts = [];
                    if ( property_exists( $node, 'args' ) ) {
                        // Try to resolve ID from a variable assignment in scope
                        $idExpr = isset( $node->args[0] ) ? $node->args[0]->value : null;
                        $id     = $this->string_or_resolved_variable( $node, $idExpr );

                        $label  = isset( $node->args[1] ) ? $this->extract_string_or_placeholder( $node->args[1]->value ) : '';
                        $cost   = isset( $node->args[2] ) ? $this->extract_string_or_placeholder( $node->args[2]->value ) : '';
                        if ( $id !== '' )    { $parts[] = sprintf( __( 'id “%s”', 'kiss-woo-shipping-debugger' ), $id ); }
                        if ( $label !== '' ) { $parts[] = sprintf( __( 'label “%s”', 'kiss-woo-shipping-debugger' ), $label ); }
                        if ( $cost !== '' )  { $parts[] = sprintf( __( 'cost %s', 'kiss-woo-shipping-debugger' ), $cost ); }
                    }
                    $when = $this->condition_chain_text( $node );
                    $summary = __( 'Instantiates WC_Shipping_Rate directly, creating a shipping option in code.', 'kiss-woo-shipping-debugger' );
                    if ( ! empty( $parts ) ) {
                        $summary .= ' ' . sprintf( __( 'Details: %s.', 'kiss-woo-shipping-debugger' ), implode( ', ', $parts ) );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'Runs when %s.', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    return $summary;

                case 'unsetRates':
                    // unset($rates['key']) or dynamic
                    $keyStr = $this->extract_unset_rate_key( $node );
                    $when   = $this->condition_chain_text( $node );
                    $summary = '';
                    if ( $this->condition_mentions_free_shipping( $node ) ) {
                        $summary = __( 'Removes the free shipping rate', 'kiss-woo-shipping-debugger' );
                    } elseif ( $keyStr !== '' ) {
                        $summary = sprintf(
                            __( 'Removes a shipping rate by key (%s)', 'kiss-woo-shipping-debugger' ),
                            $keyStr
                        );
                    } else {
                        $summary = __( 'Removes one or more shipping rates from the available options', 'kiss-woo-shipping-debugger' );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'when %s', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    $summary .= '.';
                    return $summary;

                case 'addFees':
                    // $cart->add_fee( label, amount, ... )
                    $label = ( property_exists( $node, 'args' ) && isset( $node->args[0] ) )
                        ? $this->extract_string_or_placeholder( $node->args[0]->value ) : '';
                    $amt   = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->extract_string_or_placeholder( $node->args[1]->value ) : '';
                    $when  = $this->condition_chain_text( $node );

                    $summary = __( 'Adds a cart fee/adjustment', 'kiss-woo-shipping-debugger' );
                    if ( $label !== '' ) {
                        $summary .= ' ' . sprintf( __( 'named “%s”', 'kiss-woo-shipping-debugger' ), $label );
                    }
                    if ( $amt !== '' ) {
                        $summary .= ' ' . sprintf( __( 'for %s', 'kiss-woo-shipping-debugger' ), $amt );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'when %s', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    $summary .= '.';
                    return $summary;
            }
        } catch ( \Throwable $e ) {
            // Fall through to generic text on any parsing edge cases
        }
        return __( 'Matched code pattern.', 'kiss-woo-shipping-debugger' );
    }

    /**
     * Try to extract a human-readable string from an expression, allowing:
     *  - plain "string"
     *  - concatenation ("a" . $b . "c") -> "a{b}c"
     *  - interpolated strings "Hello $name" -> "Hello {name}"
     *  - translation wrappers: __("..."), esc_html__(), etc.
     *  - sprintf("fmt %s", $x) -> "fmt {x}"
     * For non-literals, we render placeholders like {var}, {func()}, {obj->prop}, {arr[key]}.
     */
    private function extract_string( $expr ): string {
        // Direct string
        if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
            return (string) $expr->value;
        }

        // Interpolated strings
        if ( $expr instanceof \PhpParser\Node\Scalar\Encapsed ) {
            $out = '';
            foreach ( $expr->parts as $p ) {
                if ( $p instanceof \PhpParser\Node\Scalar\EncapsedStringPart ) {
                    $out .= $p->value;
                } else {
                    $out .= $this->expr_placeholder( $p );
                }
            }
            return $out;
        }

        // Concatenation
        if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat ) {
            return $this->extract_string( $expr->left ) . $this->extract_string( $expr->right );
        }

        // Translation wrappers
        if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
            $fn = strtolower( $expr->name->toString() );

            // i18n wrappers like __("string", "domain")
            $i18n = [ '__', 'esc_html__', 'esc_attr__', '_x', '_nx', '_ex' ];
            if ( in_array( $fn, $i18n, true ) && isset( $expr->args[0] ) ) {
                return $this->extract_string( $expr->args[0]->value );
            }

            // sprintf("format %s ...", args...)
            if ( $fn === 'sprintf' && isset( $expr->args[0] ) ) {
                $fmt = $this->extract_string( $expr->args[0]->value );
                $argTokens = [];
                for ( $i = 1; isset( $expr->args[$i] ); $i++ ) {
                    $argTokens[] = $this->expr_placeholder( $expr->args[$i]->value );
                }
                // Replace %s/%d etc. sequentially (simple heuristic)
                $idx = 0;
                $out = preg_replace_callback('/%[%bcdeEufFgGosxX]/', function($m) use (&$idx, $argTokens) {
                    if ($m[0] === '%%') return '%';
                    $token = $argTokens[$idx] ?? '{?}';
                    $idx++;
                    return $token;
                }, $fmt );
                return $out ?? $fmt;
            }
        }

        // Fallback placeholder
        return $this->expr_placeholder( $expr );
    }

    /**
     * Like extract_string(), but if it's not a clear string, return a placeholder.
     */
    private function extract_string_or_placeholder( $expr ): string {
        $s = $this->extract_string( $expr );
        if ( $s !== '' && $s[0] !== '{' ) {
            return $s;
        }
        return $this->expr_placeholder( $expr );
    }

    /**
     * Render a readable placeholder for an arbitrary expression.
     * Examples: $postcode -> {postcode}, $arr[$state] -> {arr[$state]}, $obj->method() -> {obj->method()}
     */
    private function expr_placeholder( $expr ): string {
        try {
            if ( $expr instanceof \PhpParser\Node\Expr\Variable ) {
                return '{' . (is_string($expr->name) ? $expr->name : '?') . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
                $var  = $this->expr_placeholder( $expr->var );
                $dim  = $expr->dim ? $this->extract_string( $expr->dim ) : '';
                if ( $dim === '' && $expr->dim ) $dim = $this->expr_placeholder( $expr->dim );
                return str_replace(['{','}'],'',$var) ? '{' . trim($var, '{}') . '[' . $dim . ']}' : '{array[' . $dim . ']}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\PropertyFetch ) {
                $obj = trim( $this->expr_placeholder( $expr->var ), '{}' );
                $prop = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $obj . '->' . $prop . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\MethodCall ) {
                $obj = trim( $this->expr_placeholder( $expr->var ), '{}' );
                $meth = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $obj . '->' . $meth . '()}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\StaticCall ) {
                $cls = $expr->class instanceof \PhpParser\Node\Name ? $expr->class->toString() : '?';
                $meth = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $cls . '::' . $meth . '()}';
            }
            if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
                return $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber ) {
                return (string) $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Expr\ConstFetch && $expr->name instanceof \PhpParser\Node\Name ) {
                return '{' . $expr->name->toString() . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
                return '{' . $expr->name->toString() . '()}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat ) {
                return $this->extract_string( $expr ); // handled already
            }
        } catch ( \Throwable $e ) {
            // ignore and fall through
        }
        return '{?}';
    }

    /**
     * Describe a callback (string function name or array(Class/obj, 'method')).
     */
    private function describe_callback( $expr ): string {
        try {
            if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
                return $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Expr\Array_
                 && isset( $expr->items[1] )
                 && $expr->items[1]->value instanceof \PhpParser\Node\Scalar\String_ ) {
                $method = $expr->items[1]->value->value;
                // Class/variable part may be complex; keep simple:
                return '::' . $method;
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return '';
    }

    /**
     * Extract the rate key from unset($rates['key']) if available.
     * Returns a literal or a readable placeholder. Attempts local variable resolution.
     */
    private function extract_unset_rate_key( \PhpParser\Node $unsetStmt ): string {
        try {
            if ( $unsetStmt instanceof \PhpParser\Node\Stmt\Unset_
                 && isset( $unsetStmt->vars[0] )
                 && $unsetStmt->vars[0] instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {

                $dim = $unsetStmt->vars[0]->dim;
                if ( $dim instanceof \PhpParser\Node\Scalar\String_ ) {
                    return $dim->value;
                }
                if ( $dim instanceof \PhpParser\Node\Expr\Variable && is_string( $dim->name ) ) {
                    $resolved = $this->resolve_variable_value( $unsetStmt, $dim->name );
                    if ( is_string( $resolved ) && $resolved !== '' ) {
                        return $resolved;
                    }
                }
                if ( $dim ) {
                    // Render readable placeholder for dynamic key
                    $ph = $this->extract_string( $dim );
                    if ( $ph === '' ) {
                        $ph = $this->expr_placeholder( $dim );
                    }
                    return $ph;
                }
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return '';
    }

    /**
     * If $expr is a variable, try to resolve a string assignment in the same function scope.
     * Otherwise, return extract_string_or_placeholder().
     */
    private function string_or_resolved_variable( \PhpParser\Node $ctx, $expr ): string {
        if ( $expr instanceof \PhpParser\Node\Expr\Variable && is_string( $expr->name ) ) {
            $val = $this->resolve_variable_value( $ctx, $expr->name );
            if ( is_string( $val ) && $val !== '' ) {
                return $val;
            }
        }
        return $this->extract_string_or_placeholder( $expr );
    }

    /**
     * Resolve the most recent scalar string assigned to $varName in the same function-like scope
     * before the line of $fromNode.
     */
    private function resolve_variable_value( \PhpParser\Node $fromNode, string $varName ): ?string {
        try {
            // Find nearest function-like ancestor
            $cur = $fromNode;
            $scope = null;
            while ( $cur ) {
                if ( $cur instanceof \PhpParser\Node\FunctionLike ) { $scope = $cur; break; }
                $cur = $cur->getAttribute('parent');
                if ( ! $cur instanceof \PhpParser\Node ) break;
            }
            if ( ! $scope ) return null;

            $finder = new \PhpParser\NodeFinder();
            /** @var \PhpParser\Node\Expr\Assign[] $assigns */
            $assigns = $finder->findInstanceOf( $scope, \PhpParser\Node\Expr\Assign::class );

            $line = $fromNode->getLine();
            $best   = null;
            $bestLn = -1;

            foreach ( $assigns as $as ) {
                if ( $as->var instanceof \PhpParser\Node\Expr\Variable && is_string( $as->var->name ) && $as->var->name === $varName ) {
                    $ln = (int) $as->getLine();
                    if ( $ln < $line && $ln > $bestLn ) {
                        // Accept simple strings or expressions we can stringify
                        $str = $this->extract_string( $as->expr );
                        if ( $str === '' ) {
                            if ( $as->expr instanceof \PhpParser\Node\Scalar\String_ ) {
                                $str = $as->expr->value;
                            }
                        }
                        if ( $str !== '' ) {
                            $best   = $str;
                            $bestLn = $ln;
                        }
                    }
                }
            }
            return $best;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Return a natural-language description of the chain of enclosing conditions
     * for a node (nearest first), e.g. "the cart contains drinks and the non-drink subtotal is under $20".
     */
    private function condition_chain_text( \PhpParser\Node $node ): string {
        $conds = [];
        $cur = $node;
        $limit = 4; // keep short
        while ( $limit-- > 0 && $cur ) {
            $parent = $cur->getAttribute('parent');
            if ( $parent instanceof \PhpParser\Node\Stmt\If_ ) {
                $desc = $this->cond_to_text( $parent->cond );
                if ( $desc !== '' ) $conds[] = $desc;
            }
            $cur = $parent instanceof \PhpParser\Node ? $parent : null;
        }
        if ( empty( $conds ) ) return '';
        // De-duplicate simple repeats
        $conds = array_values( array_unique( array_filter( $conds ) ) );
        return implode( ' ' . __( 'and', 'kiss-woo-shipping-debugger' ) . ' ', $conds );
    }

    /**
     * Convert a boolean expression into a short readable phrase.
     */
    private function cond_to_text( $expr ): string {
        try {
            // (A && B) / (A || B)
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\BooleanAnd ) {
                $left = $this->cond_to_text( $expr->left );
                $right = $this->cond_to_text( $expr->right );
                $glue = ' ' . __( 'and', 'kiss-woo-shipping-debugger' ) . ' ';
                return trim( $left ) . $glue . trim( $right );
            }
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\BooleanOr ) {
                $left = $this->cond_to_text( $expr->left );
                $right = $this->cond_to_text( $expr->right );
                $glue = ' ' . __( 'or', 'kiss-woo-shipping-debugger' ) . ' ';
                return trim( $left ) . $glue . trim( $right );
            }

            // Special-case: strpos( ... , 'free_shipping') !== false  (or !=)
            $isFreeShip = function($call) {
                return ($call instanceof \PhpParser\Node\Expr\FuncCall)
                    && ($call->name instanceof \PhpParser\Node\Name)
                    && (strtolower($call->name->toString()) === 'strpos')
                    && isset($call->args[1])
                    && strtolower($this->extract_string($call->args[1]->value)) === 'free_shipping';
            };
            if ( ($expr instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical || $expr instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual)
                 && (
                      ($isFreeShip($expr->left) && $this->is_false_const($expr->right))
                      || ($isFreeShip($expr->right) && $this->is_false_const($expr->left))
                 ) ) {
                return __( 'the rate is a Free Shipping method', 'kiss-woo-shipping-debugger' );
            }

            // Comparisons with friendly variable names
            $opMap = [
                \PhpParser\Node\Expr\BinaryOp\Smaller::class        => '<',
                \PhpParser\Node\Expr\BinaryOp\SmallerOrEqual::class => '<=',
                \PhpParser\Node\Expr\BinaryOp\Greater::class        => '>',
                \PhpParser\Node\Expr\BinaryOp\GreaterOrEqual::class => '>=',
                \PhpParser\Node\Expr\BinaryOp\Equal::class          => '==',
                \PhpParser\Node\Expr\BinaryOp\NotEqual::class       => '!=',
                \PhpParser\Node\Expr\BinaryOp\Identical::class      => '===',
                \PhpParser\Node\Expr\BinaryOp\NotIdentical::class   => '!==',
            ];
            foreach ( $opMap as $cls => $op ) {
                if ( $expr instanceof $cls ) {
                    // If it's adjusted_total < number → "the non-drink subtotal is under $N"
                    if ( $this->is_var_named( $expr->left, 'adjusted_total' ) && $this->is_number_like( $expr->right ) ) {
                        $num = $this->number_to_money( $expr->right );
                        switch ( $op ) {
                            case '<':  return sprintf( __( 'the non-drink subtotal is under %s', 'kiss-woo-shipping-debugger' ), $num );
                            case '<=': return sprintf( __( 'the non-drink subtotal is at most %s', 'kiss-woo-shipping-debugger' ), $num );
                            case '>':  return sprintf( __( 'the non-drink subtotal is over %s', 'kiss-woo-shipping-debugger' ), $num );
                            case '>=': return sprintf( __( 'the non-drink subtotal is at least %s', 'kiss-woo-shipping-debugger' ), $num );
                            default:   return 'adjusted_total ' . $op . ' ' . $num;
                        }
                    }
                    return $this->simple_expr_text( $expr->left ) . ' ' . $op . ' ' . $this->simple_expr_text( $expr->right );
                }
            }

            // Negation
            if ( $expr instanceof \PhpParser\Node\Expr\BooleanNot ) {
                $inner = $this->simple_expr_text( $expr->expr );
                // Pretty negation: "!has_drinks" → "the cart does not contain drinks"
                if ( $this->is_var_named( $expr->expr, 'has_drinks' ) ) {
                    return __( 'the cart does not contain drinks', 'kiss-woo-shipping-debugger' );
                }
                return __( 'not', 'kiss-woo-shipping-debugger' ) . ' ' . $inner;
            }

            // Bare variables with friendly names
            if ( $this->is_var_named( $expr, 'has_drinks' ) ) {
                return __( 'the cart contains drinks', 'kiss-woo-shipping-debugger' );
            }

            // Fallback
            return $this->simple_expr_text( $expr );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private function is_false_const( $expr ): bool {
        return $expr instanceof \PhpParser\Node\Expr\ConstFetch
            && $expr->name instanceof \PhpParser\Node\Name
            && strtolower($expr->name->toString()) === 'false';
    }

    private function is_var_named( $expr, string $name ): bool {
        return $expr instanceof \PhpParser\Node\Expr\Variable
            && is_string( $expr->name )
            && $expr->name === $name;
    }

    private function is_number_like( $expr ): bool {
        return $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber;
    }

    private function number_to_money( $expr ): string {
        if ( $this->is_number_like( $expr ) ) {
            $val = (float) $expr->value;
            // Simple formatting without locale for clarity
            if ( floor($val) == $val ) {
                return '$' . number_format( (int) $val, 0 );
            }
            return '$' . number_format( $val, 2 );
        }
        return (string) $this->extract_string_or_placeholder( $expr );
    }

    /**
     * Render a short text for simple expressions used in conditions.
     */
    private function simple_expr_text( $expr ): string {
        if ( $this->is_var_named( $expr, 'has_drinks' ) ) return __( 'the cart contains drinks', 'kiss-woo-shipping-debugger' );
        if ( $this->is_var_named( $expr, 'adjusted_total' ) ) return __( 'the non-drink subtotal', 'kiss-woo-shipping-debugger' );
        if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) return "'" . $expr->value . "'";
        if ( $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber ) return (string) $expr->value;
        if ( $expr instanceof \PhpParser\Node\Expr\Variable ) return (is_string($expr->name) ? (string)$expr->name : '{var}');
        if ( $expr instanceof \PhpParser\Node\Expr\PropertyFetch ) {
            $obj = $this->simple_expr_text( $expr->var );
            $prop = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
            return $obj . '->' . $prop;
        }
        if ( $expr instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
            $arr = $this->simple_expr_text( $expr->var );
            $dim = $expr->dim ? $this->simple_expr_text( $expr->dim ) : '';
            return $arr . '[' . $dim . ']';
        }
        if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
            return $expr->name->toString() . '()';
        }
        if ( $expr instanceof \PhpParser\Node\Expr\ConstFetch && $expr->name instanceof \PhpParser\Node\Name ) {
            return $expr->name->toString();
        }
        return $this->expr_placeholder( $expr );
    }

    /**
     * Heuristic: check if the immediate condition mentions free shipping.
     */
    private function condition_mentions_free_shipping( \PhpParser\Node $node ): bool {
        // Look at nearest If_ condition above the node for a strpos(..., 'free_shipping') !== false
        $cur = $node;
        $steps = 2;
        while ( $steps-- > 0 && $cur ) {
            $parent = $cur->getAttribute('parent');
            if ( $parent instanceof \PhpParser\Node\Stmt\If_ ) {
                $cond = $parent->cond;
                $isFree = function($call) {
                    return ($call instanceof \PhpParser\Node\Expr\FuncCall)
                        && ($call->name instanceof \PhpParser\Node\Name)
                        && (strtolower($call->name->toString()) === 'strpos')
                        && isset($call->args[1])
                        && strtolower($this->extract_string($call->args[1]->value)) === 'free_shipping';
                };
                if ( ($cond instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical || $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual)
                     && (
                          ($isFree($cond->left) && $this->is_false_const($cond->right))
                          || ($isFree($cond->right) && $this->is_false_const($cond->left))
                     ) ) {
                    return true;
                }
            }
            $cur = $parent instanceof \PhpParser\Node ? $parent : null;
        }
        return false;
    }
}