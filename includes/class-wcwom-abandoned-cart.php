<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WCWOM_Abandoned_Cart
 *
 * Tracks WooCommerce sessions with items in cart, captures the
 * customer's phone number from checkout fields, and sends a WhatsApp
 * reminder after a configurable delay via WP-Cron.
 */
class WCWOM_Abandoned_Cart {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        if ( '1' !== WCWOM_Settings::get( 'abandoned_cart_enable', '1' ) ) return;
        $this->hooks();
    }

    /* ─────────────────────────────────────────
       Hooks
    ───────────────────────────────────────── */
    private function hooks() {
        /* Capture phone when customer updates checkout fields */
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture_cart' ] );

        /* Also capture on add-to-cart for logged-in users */
        add_action( 'woocommerce_add_to_cart', [ $this, 'capture_logged_in_cart' ], 20 );

        /* Remove record when order is placed */
        add_action( 'woocommerce_checkout_order_created', [ $this, 'clear_cart_record' ] );

        /* Cron job */
        add_action( 'wcwom_abandoned_cart_cron', [ $this, 'process_abandoned_carts' ] );

        /* Admin: view & manage abandoned carts */
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
    }

    /* ─────────────────────────────────────────
       Capture cart on checkout update
    ───────────────────────────────────────── */

    public function capture_cart( $posted_data ) {
        parse_str( $posted_data, $data );
        $phone = sanitize_text_field( $data['billing_phone'] ?? '' );
        if ( ! $phone ) return;

        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) return;

        $session_key = $this->get_session_key();
        $user_id     = get_current_user_id();
        $cart_data   = $this->get_cart_contents();
        $total       = (float) $cart->get_cart_total();

        $this->upsert_cart( $session_key, $user_id, $phone, $cart_data, $total );
    }

    public function capture_logged_in_cart() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return;

        $user  = get_user_by( 'id', $user_id );
        $phone = get_user_meta( $user_id, 'billing_phone', true );
        if ( ! $phone ) return;

        $cart        = WC()->cart;
        $session_key = $this->get_session_key();
        $cart_data   = $this->get_cart_contents();
        $total       = (float) $cart->get_cart_total();

        $this->upsert_cart( $session_key, $user_id, $phone, $cart_data, $total );
    }

    /* ─────────────────────────────────────────
       Clear on order placed
    ───────────────────────────────────────── */

    public function clear_cart_record( $order ) {
        global $wpdb;
        $session_key = $this->get_session_key();
        $table       = $wpdb->prefix . 'wcwom_abandoned_carts';

        $wpdb->update(
            $table,
            [ 'status' => 'recovered' ],
            [ 'session_key' => $session_key ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    /* ─────────────────────────────────────────
       Cron: send reminders
    ───────────────────────────────────────── */

    public function process_abandoned_carts() {
        global $wpdb;

        $delay_minutes = intval( WCWOM_Settings::get( 'abandoned_cart_delay', 60 ) );
        $table         = $wpdb->prefix . 'wcwom_abandoned_carts';
        $cutoff        = date( 'Y-m-d H:i:s', strtotime( "-{$delay_minutes} minutes" ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
               AND created_at <= %s
               AND reminded_at IS NULL",
            $cutoff
        ) );

        if ( ! $rows ) return;

        $template = WCWOM_Settings::get( 'abandoned_cart_msg', '' );

        foreach ( $rows as $row ) {
            $cart_data = json_decode( $row->cart_data, true );
            $items     = [];
            if ( is_array( $cart_data ) ) {
                foreach ( $cart_data as $item ) {
                    $items[] = ( $item['name'] ?? 'Item' ) . ' ×' . ( $item['qty'] ?? 1 );
                }
            }

            /* Get customer name */
            $name = 'there';
            if ( $row->user_id ) {
                $user = get_user_by( 'id', $row->user_id );
                if ( $user ) $name = $user->first_name ?: $user->display_name;
            }

            $vars = [
                'name'     => $name,
                'total'    => wc_price( $row->total ),
                'items'    => implode( ', ', $items ),
                'cart_url' => wc_get_cart_url(),
            ];

            $message = WCWOM_WhatsApp::parse_template( $template, $vars );
            $result  = WCWOM_WhatsApp::send( $row->phone, $message );

            $new_status = is_wp_error( $result ) ? 'failed' : 'reminded';
            $wpdb->update(
                $table,
                [
                    'status'      => $new_status,
                    'reminded_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $row->id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /* ─────────────────────────────────────────
       Admin Page: Abandoned Carts List
    ───────────────────────────────────────── */

    public function register_admin_page() {
        add_submenu_page(
            'wcwom-settings',
            __( 'Abandoned Carts', 'wcwom' ),
            __( 'Abandoned Carts', 'wcwom' ),
            'manage_woocommerce',
            'wcwom-abandoned-carts',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcwom_abandoned_carts';
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Abandoned Carts', 'wcwom' ); ?></h1>
            <p><?php esc_html_e( 'Carts captured where the customer entered their phone number but did not complete checkout.', 'wcwom' ); ?></p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Phone', 'wcwom' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'wcwom' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'wcwom' ); ?></th>
                        <th><?php esc_html_e( 'Reminded', 'wcwom' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wcwom' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wcwom' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $rows ) : foreach ( $rows as $row ) :
                    $status_badge = [
                        'pending'   => '<span style="color:#b45309;background:#fef3c7;padding:2px 8px;border-radius:4px;">Pending</span>',
                        'reminded'  => '<span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:4px;">Reminded</span>',
                        'recovered' => '<span style="color:#1e40af;background:#dbeafe;padding:2px 8px;border-radius:4px;">Recovered</span>',
                        'failed'    => '<span style="color:#991b1b;background:#fee2e2;padding:2px 8px;border-radius:4px;">Failed</span>',
                    ];
                    $badge = $status_badge[ $row->status ] ?? esc_html( $row->status );
                    $wa_link = WCWOM_WhatsApp::generate_link( $row->phone, "Hi! Completing your cart? 🛒 " . wc_get_cart_url() );
                ?>
                <tr>
                    <td><?php echo intval( $row->id ); ?></td>
                    <td>+<?php echo esc_html( $row->phone ); ?></td>
                    <td><?php echo wc_price( $row->total ); ?></td>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                    <td><?php echo $row->reminded_at ? esc_html( $row->reminded_at ) : '—'; ?></td>
                    <td><?php echo $badge; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" class="button button-small"
                           style="background:#25D366;border-color:#25D366;color:#fff;">
                            📱 WhatsApp
                        </a>
                    </td>
                </tr>
                <?php endforeach; else : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No abandoned carts yet.', 'wcwom' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────
       Helpers
    ───────────────────────────────────────── */

    private function get_session_key() {
        $session = WC()->session;
        if ( ! $session ) return uniqid( 'wcwom_', true );
        return $session->get_customer_id() ?: uniqid( 'wcwom_', true );
    }

    private function get_cart_contents() {
        $contents = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            $product    = $item['data'];
            $contents[] = [
                'name'  => $product ? $product->get_name() : 'Product',
                'qty'   => $item['quantity'],
                'price' => $item['line_total'],
            ];
        }
        return json_encode( $contents );
    }

    private function upsert_cart( $session_key, $user_id, $phone, $cart_data, $total ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcwom_abandoned_carts';
        $phone = WCWOM_WhatsApp::sanitize_phone( $phone );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_key = %s AND status = 'pending'",
            $session_key
        ) );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [ 'cart_data' => $cart_data, 'total' => $total, 'phone' => $phone ],
                [ 'id'        => $existing ],
                [ '%s', '%f', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert( $table, [
                'user_id'     => $user_id,
                'session_key' => $session_key,
                'phone'       => $phone,
                'cart_data'   => $cart_data,
                'total'       => $total,
                'created_at'  => current_time( 'mysql' ),
                'status'      => 'pending',
            ], [ '%d', '%s', '%s', '%s', '%f', '%s', '%s' ] );
        }
    }
}
