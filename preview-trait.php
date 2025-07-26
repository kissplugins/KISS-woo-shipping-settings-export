<?php
/**
 * Trait containing admin UI helper methods for the shipping settings preview.
 */
trait KISS_WSE_Preview {

    private function render_preview_table(): void {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            echo '<p><em>' . esc_html__( 'WooCommerce shipping is not available.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
            return;
        }

        // Quick filters (GET, non-persistent)
        $issues_only          = isset( $_GET['wse_issues_only'] ) ? (bool) $_GET['wse_issues_only'] : false;
        $methods_enabled_only = isset( $_GET['wse_methods_enabled_only'] ) ? (bool) $_GET['wse_methods_enabled_only'] : false;

        // Filter UI
        $filters_url = add_query_arg( [
            'page' => $this->page_slug,
        ], admin_url( 'tools.php' ) );

        echo '<h3>' . esc_html__( 'Shipping Zones & Methods Preview', 'kiss-woo-shipping-debugger' ) . '</h3>';
        echo '<form method="get" style="margin:0 0 12px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr( $this->page_slug ) . '"/>';
        echo '<label style="margin-right:12px;"><input type="checkbox" name="wse_issues_only" value="1" ' . checked( $issues_only, true, false ) . '/> ' . esc_html__( 'Only show zones with issues', 'kiss-woo-shipping-debugger' ) . '</label>';
        echo '<label style="margin-right:12px;"><input type="checkbox" name="wse_methods_enabled_only" value="1" ' . checked( $methods_enabled_only, true, false ) . '/> ' . esc_html__( 'Show only enabled methods', 'kiss-woo-shipping-debugger' ) . '</label>';
        echo ' <button class="button" type="submit">' . esc_html__( 'Apply Filters', 'kiss-woo-shipping-debugger' ) . '</button>';
        echo ' <a class="button button-link-delete" href="' . esc_url( $filters_url ) . '">' . esc_html__( 'Reset', 'kiss-woo-shipping-debugger' ) . '</a>';
        echo '</form>';

        // Collect rows (cap 100)
        $cap = 100;
        $rows = [];
        $warnings_html = '';

        list( $rows, $total_rows, $warnings_html ) = $this->collect_zone_rows( $issues_only, $methods_enabled_only, $cap );

        // Warnings (aggregate)
        if ( ! empty( $warnings_html ) ) {
            echo '<div class="notice notice-warning"><p style="margin:8px 0 0 0;">' . wp_kses_post( $warnings_html ) . '</p></div>';
        }

        // Headers
        $zone_headers = [
            __( 'Zone', 'kiss-woo-shipping-debugger' ),
            __( 'Locations', 'kiss-woo-shipping-debugger' ),
            __( 'Methods', 'kiss-woo-shipping-debugger' ),
            __( 'Links', 'kiss-woo-shipping-debugger' ),
        ];

        // Table (exact rendering style requested)
        echo '<table class="wp-list-table widefat striped"><thead><tr>';
        foreach ( $zone_headers as $header ) {
            echo '<th scope="col">' . esc_html( $header ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            foreach ( $row as $cell ) {
                echo '<td>' . wp_kses_post( $cell ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ( $total_rows > count( $rows ) ) {
            printf(
                '<p><em>%s</em></p>',
                sprintf(
                    /* translators: %d is the number of additional rows */
                    esc_html__( 'And %d more rows...', 'kiss-woo-shipping-debugger' ),
                    (int) ( $total_rows - count( $rows ) )
                )
            );
        }
    }

    /**
     * Assemble zone rows for preview table and aggregate warnings.
     *
     * @return array{0: array<int,array{string,string,string,string}>, 1:int, 2:string} rows, total_rows, warnings_html
     */
    private function collect_zone_rows( bool $issues_only, bool $methods_enabled_only, int $cap ): array {
        $rows = [];
        $warnings = [];

        // Build a list of zone IDs (add 0 for Rest of the world)
        $zone_rows = \WC_Shipping_Zones::get_zones(); // array of arrays with 'zone_id'
        $zone_ids  = [];
        foreach ( $zone_rows as $zr ) {
            if ( isset( $zr['zone_id'] ) ) {
                $zone_ids[] = (int) $zr['zone_id'];
            }
        }
        $zone_ids[] = 0; // Rest of the world

        $total_rows = 0;

        foreach ( $zone_ids as $zone_id ) {
            $zone = new \WC_Shipping_Zone( (int) $zone_id );

            $zone_name      = (string) $zone->get_zone_name();
            $locations_html = $this->format_zone_locations( $zone, 6 ); // cap display to 6 items
            $methods        = $zone->get_shipping_methods();

            // Build methods cell + counts + badges
            $enabled = 0; $disabled = 0;
            $method_lines = [];
            $method_links = [];

            foreach ( $methods as $m ) {
                $is_enabled = ( 'yes' === $m->enabled );
                if ( $is_enabled ) $enabled++; else $disabled++;

                if ( $methods_enabled_only && ! $is_enabled ) {
                    continue;
                }

                $badge = $is_enabled
                    ? '<span style="display:inline-block;padding:2px 6px;border-radius:12px;background:#e7f7ed;color:#0a732e;font-size:11px;margin-right:6px;">' . esc_html__( 'Enabled', 'kiss-woo-shipping-debugger' ) . '</span>'
                    : '<span style="display:inline-block;padding:2px 6px;border-radius:12px;background:#f7e7e7;color:#8a0b0b;font-size:11px;margin-right:6px;">' . esc_html__( 'Disabled', 'kiss-woo-shipping-debugger' ) . '</span>';

                $summary = $this->summarize_method( $m );
                $method_lines[] = $badge . $summary;

                $method_links[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url( $this->method_edit_link( (int) $zone_id, (int) $m->instance_id ) ),
                    esc_html__( 'Edit method', 'kiss-woo-shipping-debugger' )
                );
            }

            // Per-zone warnings
            $zone_issues = [];
            if ( $enabled === 0 ) {
                $zone_issues[] = __( 'Zone has no enabled shipping methods.', 'kiss-woo-shipping-debugger' );
            }
            foreach ( $methods as $m ) {
                if ( $m->id === 'free_shipping' && 'yes' === $m->enabled ) {
                    $requires = (string) $m->get_option( 'requires', '' );
                    if ( $requires === '' || $requires === 'no' ) {
                        $zone_issues[] = __( 'Free Shipping has no requirement (no minimum and no coupon).', 'kiss-woo-shipping-debugger' );
                        break;
                    }
                }
            }

            // Apply zone-level "issues only" filter
            if ( $issues_only && empty( $zone_issues ) ) {
                continue;
            }

            // Aggregate warnings list
            if ( ! empty( $zone_issues ) ) {
                $warnings[] = sprintf(
                    '<strong>%s</strong>: %s',
                    esc_html( $zone_name ),
                    esc_html( implode( '; ', $zone_issues ) )
                );
            }

            // Zone cell with counts
            $counts_label = sprintf(
                /* translators: 1: enabled count, 2: disabled count */
                __( '%1$d enabled / %2$d disabled', 'kiss-woo-shipping-debugger' ),
                (int) $enabled,
                (int) $disabled
            );

            $zone_cell = sprintf(
                '<strong>%s</strong><br><span style="opacity:.75;">%s</span>',
                esc_html( $zone_name ),
                esc_html( $counts_label )
            );

            // Methods cell
            $methods_cell = empty( $method_lines )
                ? '<em>' . esc_html__( '—', 'kiss-woo-shipping-debugger' ) . '</em>'
                : implode( '<br>', array_map( 'wp_kses_post', $method_lines ) );

            // Links cell
            $links_parts = [];
            $links_parts[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $this->zone_edit_link( (int) $zone_id ) ),
                esc_html__( 'Edit zone', 'kiss-woo-shipping-debugger' )
            );
            if ( ! empty( $method_links ) ) {
                $links_parts[] = implode( ' | ', $method_links );
            }
            $links_cell = implode( '<br>', $links_parts );

            $rows[] = [
                $zone_cell,
                $locations_html,
                $methods_cell,
                $links_cell,
            ];

            $total_rows++;

            // Cap the preview
            if ( $total_rows >= $cap ) {
                break;
            }
        }

        $warnings_html = '';
        if ( ! empty( $warnings ) ) {
            $warnings_html = '⚠️ ' . implode( '<br>⚠️ ', $warnings );
        }

        return [ $rows, $total_rows, $warnings_html ];
    }

    /**
     * Format zone locations concisely, with a display cap.
     */
    private function format_zone_locations( \WC_Shipping_Zone $zone, int $display_cap = 6 ): string {
        $locations = $zone->get_zone_locations();

        if ( empty( $locations ) ) {
            // For the "0" zone (rest of world)
            return '<em>' . esc_html__( 'Rest of the world', 'kiss-woo-shipping-debugger' ) . '</em>';
        }

        $parts = [];
        foreach ( $locations as $loc ) {
            $type = isset( $loc->type ) ? $loc->type : ( $loc['type'] ?? '' );
            $code = isset( $loc->code ) ? $loc->code : ( $loc['code'] ?? '' );

            switch ( $type ) {
                case 'country':
                    $parts[] = esc_html( $code );
                    break;
                case 'state':
                    $parts[] = esc_html( $code ); // e.g., US:CA
                    break;
                case 'continent':
                    $parts[] = esc_html( $code ); // e.g., EU
                    break;
                case 'postcode':
                    $parts[] = esc_html( $code );
                    break;
                default:
                    $parts[] = esc_html( (string) $code );
                    break;
            }
        }

        $display = array_slice( $parts, 0, $display_cap );
        $extra    = count( $parts ) - count( $display );
        $out      = implode( ', ', $display );
        if ( $extra > 0 ) {
            $out .= ' +' . $extra . '…';
        }
        return $out;
    }

    private function summarize_method( $method ): string {
        $title = (string) $method->get_method_title();
        $detail = '';

        $id = $method->id;
        if ( $id === 'flat_rate' ) {
            $cost = $method->get_option( 'cost', '' );
            if ( $cost !== '' ) {
                if ( is_numeric( $cost ) ) {
                    $detail = sprintf( __( 'cost %s', 'kiss-woo-shipping-debugger' ), esc_html( $this->price_to_text( (float) $cost ) ) );
                } else {
                    // Expression/formula configured
                    $detail = sprintf( __( 'cost expression: %s', 'kiss-woo-shipping-debugger' ), esc_html( $cost ) );
                }
            }
        } elseif ( $id === 'free_shipping' ) {
            $requires = $method->get_option( 'requires', '' ); // '', 'min_amount', 'coupon', 'either'
            if ( $requires === 'min_amount' ) {
                $min_amount = $method->get_option( 'min_amount', '' );
                if ( $min_amount !== '' && is_numeric( $min_amount ) ) {
                    $detail = sprintf( __( 'minimum order amount: %s', 'kiss-woo-shipping-debugger' ), esc_html( $this->price_to_text( (float) $min_amount ) ) );
                } else {
                    $detail = __( 'minimum order amount', 'kiss-woo-shipping-debugger' );
                }
            } elseif ( $requires === 'coupon' ) {
                $detail = __( 'requires a valid free-shipping coupon', 'kiss-woo-shipping-debugger' );
            } elseif ( $requires === 'either' ) {
                $min_amount = $method->get_option( 'min_amount', '' );
                if ( $min_amount !== '' && is_numeric( $min_amount ) ) {
                    $detail = sprintf( __( 'coupon or minimum: %s', 'kiss-woo-shipping-debugger' ), esc_html( $this->price_to_text( (float) $min_amount ) ) );
                } else {
                    $detail = __( 'coupon or minimum amount', 'kiss-woo-shipping-debugger' );
                }
            } else {
                $detail = __( 'no requirement', 'kiss-woo-shipping-debugger' );
            }
        } elseif ( $id === 'local_pickup' ) {
            $detail = __( 'local pickup', 'kiss-woo-shipping-debugger' );
        }

        $line = esc_html( $title );
        if ( $detail ) {
            $line .= ' — <span style="opacity:.85;">' . $detail . '</span>';
        }
        return $line;
    }

    /**
     * Convert a numeric amount to a clean text price (no HTML), preferring wc_price formatting.
     */
    private function price_to_text( float $amount ): string {
        if ( function_exists( 'wc_price' ) ) {
            // wc_price returns HTML; strip tags to plain text for table cells
            return trim( wp_strip_all_tags( wc_price( $amount ) ) );
        }
        // Fallback basic formatting
        if ( floor( $amount ) == $amount ) {
            return '$' . number_format( (int) $amount, 0 );
        }
        return '$' . number_format( $amount, 2 );
    }

    private function zone_edit_link( int $zone_id ): string {
        return add_query_arg( [
            'page'    => 'wc-settings',
            'tab'     => 'shipping',
            'section' => 'shipping_zones',
            'zone_id' => $zone_id,
        ], admin_url( 'admin.php' ) );
    }

    private function method_edit_link( int $zone_id, int $instance_id ): string {
        return add_query_arg( [
            'page'        => 'wc-settings',
            'tab'         => 'shipping',
            'section'     => 'shipping_zones',
            'zone_id'     => $zone_id,
            'instance_id' => $instance_id,
        ], admin_url( 'admin.php' ) );
    }
}
