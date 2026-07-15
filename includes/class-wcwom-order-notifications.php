<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WCWOM_Order_Notifications
 *
 * Hooks into WooCommerce order status transitions and sends
 * WhatsApp messages to both the admin and the customer.
 */
class WCWOM_Order_Notifications {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->hooks();
    }

    /* ─────────────────────────────────────────
       Hooks
    ───────────────────────────────────────── */
    private function hooks() {
        $statuses = [ 'pending', 'processing', 'completed', 'cancelled', 'refunded' ];
        foreach ( $statuses as $status ) {
            add_action( 'woocommerce_order_status_' . $status, [ $this, 'handle_status_change' ], 10, 2 );
        }

        /* WhatsApp button on Thank You page */
        if ( '1' === WCWOM_Settings::get( 'button_on_thankyou', '1' ) ) {
            add_action( 'woocommerce_thankyou', [ $this, 'thankyou_whatsapp_button' ], 20 );
        }

        /* WhatsApp button on My Account → Orders */
        if ( '1' === WCWOM_Settings::get( 'button_on_myaccount', '1' ) ) {
            add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'myaccount_whatsapp_button' ], 10, 2 );
        }
    }

    /* ─────────────────────────────────────────
       Status Change Handler
    ───────────────────────────────────────── */

    /**
     * Fired on any watched status change.
     */
    public function handle_status_change( $order_id, $order = null ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) return;

        $status = $order->get_status();

        if ( ! WCWOM_Settings::is_status_enabled( $status ) ) return;

        $template = WCWOM_Settings::get_message_template( $status );
        if ( ! $template ) return;

        $vars    = WCWOM_WhatsApp::order_vars( $order );
        $message = WCWOM_WhatsApp::parse_template( $template, $vars );

        /* Notify customer */
        if ( '1' === WCWOM_Settings::get( 'notify_customer', '1' ) ) {
            $customer_phone = $this->get_customer_phone( $order );
            if ( $customer_phone ) {
                $result = WCWOM_WhatsApp::send( $customer_phone, $message );
                if ( is_wp_error( $result ) ) {
                    $order->add_order_note( '[WCWOM] Customer WhatsApp failed: ' . $result->get_error_message() );
                } else {
                    $order->add_order_note( '[WCWOM] Customer WhatsApp sent to +' . WCWOM_WhatsApp::sanitize_phone( $customer_phone ) );
                }
            }
        }

        /* Notify admin */
        if ( '1' === WCWOM_Settings::get( 'notify_admin', '1' ) ) {
            $admin_phone = WCWOM_Settings::get( 'phone', '' );
            if ( $admin_phone ) {
                $admin_msg = $this->build_admin_message( $order, $status, $vars );
                WCWOM_WhatsApp::send( $admin_phone, $admin_msg );
            }
        }
    }

    /* ─────────────────────────────────────────
       Admin Message
    ───────────────────────────────────────── */

    private function build_admin_message( WC_Order $order, $status, array $vars ) {
        $emoji_map = [
            'pending'    => '🆕',
            'processing' => '🔄',
            'completed'  => '✅',
            'cancelled'  => '❌',
            'refunded'   => '💸',
        ];
        $emoji   = $emoji_map[ $status ] ?? '📦';
        $items   = $vars['items'];
        $address = trim( implode( ', ', array_filter( [
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_country(),
        ] ) ) );

        return "{$emoji} *New Order Alert*\n"
            . "*Order:* #{$vars['order_id']}\n"
            . "*Customer:* {$vars['name']} ({$order->get_billing_phone()})\n"
            . "*Status:* {$vars['status']}\n"
            . "*Items:* {$items}\n"
            . "*Total:* {$vars['total']}\n"
            . "*Ship to:* {$address}\n"
            . "*View:* " . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
    }

    /* ─────────────────────────────────────────
       Thank You Page Button
    ───────────────────────────────────────── */

    public function thankyou_whatsapp_button( $order_id ) {
        $order       = wc_get_order( $order_id );
        $admin_phone = WCWOM_Settings::get( 'phone', '' );
        if ( ! $admin_phone || ! $order ) return;

        $message = "Hi! I just placed Order #{$order_id} and want to confirm details. 🛒";
        $url     = WCWOM_WhatsApp::generate_link( $admin_phone, $message );
        ?>
        <div class="wcwom-thankyou-btn-wrap" style="margin:24px 0;text-align:center;">
            <a href="<?php echo esc_url( $url ); ?>"
               target="_blank"
               rel="noopener noreferrer"
               class="wcwom-wa-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                <?php esc_html_e( 'Chat with us on WhatsApp', 'wcwom' ); ?>
            </a>
        </div>
        <style>
        .wcwom-wa-btn {
            display: inline-flex;
            align-items: center;
            background: #25D366;
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            padding: 12px 24px;
            border-radius: 50px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(37,211,102,.35);
            transition: background .2s, transform .15s;
        }
        .wcwom-wa-btn:hover { background: #128C7E; color: #fff; transform: translateY(-1px); }
        </style>
        <?php
    }

    /* ─────────────────────────────────────────
       My Account Order Actions Button
    ───────────────────────────────────────── */

    public function myaccount_whatsapp_button( $actions, $order ) {
        $admin_phone = WCWOM_Settings::get( 'phone', '' );
        if ( ! $admin_phone ) return $actions;

        $message              = "Hi! I have a question about Order #{$order->get_id()}. 📦";
        $actions['whatsapp']  = [
            'url'  => WCWOM_WhatsApp::generate_link( $admin_phone, $message ),
            'name' => __( 'WhatsApp Support', 'wcwom' ),
        ];
        return $actions;
    }

    /* ─────────────────────────────────────────
       Helpers
    ───────────────────────────────────────── */

    private function get_customer_phone( WC_Order $order ) {
        /* Try billing phone first */
        $phone = $order->get_billing_phone();
        if ( ! $phone ) return '';

        /* Prepend country code if not already present */
        $phone = preg_replace( '/[^0-9+]/', '', $phone );
        if ( strpos( $phone, '+' ) !== 0 && strpos( $phone, '00' ) !== 0 ) {
            /* Try to detect country from billing_country */
            $country   = $order->get_billing_country();
            $prefix    = self::country_to_dial_code( $country );
            $phone     = $prefix . ltrim( $phone, '0' );
        }
        return WCWOM_WhatsApp::sanitize_phone( $phone );
    }

    /**
     * Basic country → dial-code map. Add more as needed.
     */
    private static function country_to_dial_code( $code ) {
        $map = [
            'PK' => '92', 'IN' => '91', 'AE' => '971', 'SA' => '966',
            'BD' => '880', 'GB' => '44', 'US' => '1',  'AU' => '61',
            'CA' => '1',  'NG' => '234','EG' => '20',  'ZA' => '27',
            'MY' => '60', 'ID' => '62', 'PH' => '63',  'TR' => '90',
        ];
        return $map[ strtoupper( $code ) ] ?? '';
    }
}
