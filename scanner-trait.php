<?php
/**
 * Trait providing AST scanning utilities used by the debugger.
 */
trait KISS_WSE_Scanner {
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
                    return $summary;

                case 'addFees':
                    return __( 'Calls add_fee() to adjust cart totals.', 'kiss-woo-shipping-debugger' );
            }

            return '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Extract a string from an expression. Handles:
     *  - direct strings
     *  - interpolated strings
     *  - concatenation
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
            if ( $expr instanceof \PhpParser\Node\Expr\Array_ && isset( $expr->items[1] ) && $expr->items[1]->value instanceof \PhpParser\Node\Scalar\String_ ) {
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
            if ( $unsetStmt instanceof \PhpParser\Node\Stmt\Unset_ && isset( $unsetStmt->vars[0] ) && $unsetStmt->vars[0] instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {

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
     * If $expr is a variable, try to resolve a string assignment in the same function-like scope.
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
                    // adjusted_total < number → "the non-drink subtotal is under $N"
                    if ( $this->is_var_named( $expr->left, 'adjusted_total' ) && $this->is_number_like( $expr->right ) ) {
                        $num = $this->price_to_text( (float) $expr->right->value );
                        switch ( $op ) {
                            case '<':  return sprintf( __( 'the non-drink subtotal is under %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            case '<=': return sprintf( __( 'the non-drink subtotal is at most %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            case '>':  return sprintf( __( 'the non-drink subtotal is over %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            case '>=': return sprintf( __( 'the non-drink subtotal is at least %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            default:   return 'adjusted_total ' . $op . ' ' . $num;
                        }
                    }
                    return $this->simple_expr_text( $expr->left ) . ' ' . $op . ' ' . $this->simple_expr_text( $expr->right );
                }
            }

            // Negation
            if ( $expr instanceof \PhpParser\Node\Expr\BooleanNot ) {
                $inner = $this->simple_expr_text( $expr->expr );
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
