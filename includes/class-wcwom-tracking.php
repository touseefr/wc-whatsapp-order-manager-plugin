<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WCWOM_Tracking
 *
 * Provides a [wcwom_track_order] shortcode that renders a self-service
 * order tracking form. Customers enter their Order ID + billing email/phone
 * and see live status — no login required.
 *
 * Also registers a dedicated tracking page automatically on activation
 * if the setting "tracking_page" is 0.
 */
class WCWOM_Tracking {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        if ( '1' !== WCWOM_Settings::get( 'tracking_enable', '1' ) ) return;
        $this->hooks();
    }

    private function hooks() {
        add_shortcode( 'wcwom_track_order', [ $this, 'render_tracking_form' ] );
        add_action( 'init', [ $this, 'maybe_create_tracking_page' ] );

        /* AJAX handler (for logged-out users too) */
        add_action( 'wp_ajax_wcwom_track',        [ $this, 'ajax_track' ] );
        add_action( 'wp_ajax_nopriv_wcwom_track', [ $this, 'ajax_track' ] );

        /* Enqueue JS on front-end */
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ─────────────────────────────────────────
       Auto-create Tracking Page
    ───────────────────────────────────────── */

    public function maybe_create_tracking_page() {
        $page_id = intval( WCWOM_Settings::get( 'tracking_page', 0 ) );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) return;

        /* Create a new page */
        $new_id = wp_insert_post( [
            'post_title'   => __( 'Track Your Order', 'wcwom' ),
            'post_content' => '[wcwom_track_order]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );

        if ( $new_id && ! is_wp_error( $new_id ) ) {
            WCWOM_Settings::set( 'tracking_page', $new_id );
        }
    }

    /* ─────────────────────────────────────────
       Shortcode
    ───────────────────────────────────────── */

    public function render_tracking_form( $atts ) {
        $atts = shortcode_atts( [], $atts, 'wcwom_track_order' );
        ob_start();
        ?>
        <div class="wcwom-track-wrap" id="wcwom-track-wrap">
            <div class="wcwom-track-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="#25D366" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 3v4M8 3v4M3 11h18"/></svg>
                <h2><?php esc_html_e( 'Track Your Order', 'wcwom' ); ?></h2>
                <p><?php esc_html_e( 'Enter your order details below to see the latest status.', 'wcwom' ); ?></p>
            </div>

            <form id="wcwom-track-form" novalidate>
                <div class="wcwom-field">
                    <label for="wcwom_order_id"><?php esc_html_e( 'Order ID', 'wcwom' ); ?> <span>*</span></label>
                    <input type="number" id="wcwom_order_id" name="order_id" placeholder="e.g. 1042" required>
                </div>
                <div class="wcwom-field">
                    <label for="wcwom_email"><?php esc_html_e( 'Billing Email or Phone', 'wcwom' ); ?> <span>*</span></label>
                    <input type="text" id="wcwom_email" name="email" placeholder="email@example.com or phone number" required>
                </div>
                <button type="submit" id="wcwom-track-btn">
                    <span class="wcwom-btn-text"><?php esc_html_e( 'Track Order', 'wcwom' ); ?></span>
                    <span class="wcwom-btn-loader" style="display:none;">⏳</span>
                </button>
            </form>

            <div id="wcwom-track-result" style="display:none;"></div>
        </div>

        <style>
        .wcwom-track-wrap {
            max-width: 520px;
            margin: 0 auto;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .wcwom-track-header { text-align: center; margin-bottom: 24px; }
        .wcwom-track-header h2 { font-size: 22px; font-weight: 700; margin: 10px 0 6px; color: #1D293D; }
        .wcwom-track-header p  { color: #6B7280; font-size: 14px; margin: 0; }
        .wcwom-field { margin-bottom: 16px; }
        .wcwom-field label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; }
        .wcwom-field label span { color: #EF4444; }
        .wcwom-field input {
            width: 100%; box-sizing: border-box;
            border: 1.5px solid #D1D5DB; border-radius: 8px;
            padding: 10px 14px; font-size: 14px; color: #1D293D;
            transition: border-color .15s;
        }
        .wcwom-field input:focus { outline: none; border-color: #25D366; box-shadow: 0 0 0 3px rgba(37,211,102,.15); }
        #wcwom-track-btn {
            width: 100%; padding: 12px; background: #25D366; color: #fff;
            border: none; border-radius: 8px; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: background .2s;
        }
        #wcwom-track-btn:hover { background: #128C7E; }
        #wcwom-track-result { margin-top: 24px; }
        .wcwom-result-card {
            border: 1.5px solid #E5E7EB; border-radius: 12px; padding: 20px;
            background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .wcwom-result-card h3 { margin: 0 0 4px; font-size: 17px; color: #1D293D; }
        .wcwom-result-card .wcwom-status-badge {
            display: inline-block; padding: 3px 12px; border-radius: 999px;
            font-size: 12px; font-weight: 700; text-transform: uppercase; margin: 6px 0 12px;
        }
        .wcwom-result-card table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .wcwom-result-card td { padding: 6px 0; border-bottom: 1px solid #F3F4F6; color: #374151; }
        .wcwom-result-card td:first-child { font-weight: 600; color: #6B7280; width: 42%; }
        .wcwom-wa-contact-btn {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 16px; background: #25D366; color: #fff;
            border: none; border-radius: 8px; padding: 10px 18px;
            font-size: 13px; font-weight: 700; text-decoration: none;
            transition: background .2s;
        }
        .wcwom-wa-contact-btn:hover { background: #128C7E; color: #fff; }
        .wcwom-error { background: #FEE2E2; color: #991B1B; border-radius: 8px; padding: 12px 16px; font-size: 14px; }
        </style>
        <?php
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────
       AJAX Track Handler
    ───────────────────────────────────────── */

    public function ajax_track() {
        check_ajax_referer( 'wcwom_track_nonce', 'nonce' );

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $email    = sanitize_text_field( $_POST['email'] ?? '' );

        if ( ! $order_id || ! $email ) {
            wp_send_json_error( [ 'message' => __( 'Please fill in all fields.', 'wcwom' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found. Please check your Order ID.', 'wcwom' ) ] );
        }

        /* Verify: email OR phone must match */
        $billing_email = strtolower( $order->get_billing_email() );
        $billing_phone = WCWOM_WhatsApp::sanitize_phone( $order->get_billing_phone() );
        $input_clean   = strtolower( trim( $email ) );
        $input_phone   = WCWOM_WhatsApp::sanitize_phone( $email );

        if ( $input_clean !== $billing_email && $input_phone !== $billing_phone ) {
            wp_send_json_error( [ 'message' => __( 'Details do not match our records. Please try again.', 'wcwom' ) ] );
        }

        /* Build status badge colours */
        $status_colors = [
            'pending'    => [ 'bg' => '#FEF3C7', 'text' => '#92400E' ],
            'processing' => [ 'bg' => '#DBEAFE', 'text' => '#1E40AF' ],
            'on-hold'    => [ 'bg' => '#F3F4F6', 'text' => '#374151' ],
            'completed'  => [ 'bg' => '#D1FAE5', 'text' => '#065F46' ],
            'cancelled'  => [ 'bg' => '#FEE2E2', 'text' => '#991B1B' ],
            'refunded'   => [ 'bg' => '#EDE9FE', 'text' => '#5B21B6' ],
            'failed'     => [ 'bg' => '#FEE2E2', 'text' => '#991B1B' ],
        ];
        $status     = $order->get_status();
        $colors     = $status_colors[ $status ] ?? $status_colors['pending'];
        $status_lbl = wc_get_order_status_name( $status );

        /* Build items list */
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = esc_html( $item->get_name() ) . ' &times;' . $item->get_quantity();
        }

        /* WhatsApp contact button */
        $admin_phone = WCWOM_Settings::get( 'phone', '' );
        $wa_btn      = '';
        if ( $admin_phone ) {
            $wa_msg = "Hi! I'm checking on Order #{$order_id}. Can you help? 😊";
            $wa_url = WCWOM_WhatsApp::generate_link( $admin_phone, $wa_msg );
            $wa_btn = '<a href="' . esc_url( $wa_url ) . '" target="_blank" class="wcwom-wa-contact-btn">'
                . '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>'
                . __( 'Contact us on WhatsApp', 'wcwom' )
                . '</a>';
        }

        $html = '<div class="wcwom-result-card">'
            . '<h3>' . sprintf( __( 'Order #%d', 'wcwom' ), $order_id ) . '</h3>'
            . '<span class="wcwom-status-badge" style="background:' . esc_attr( $colors['bg'] ) . ';color:' . esc_attr( $colors['text'] ) . ';">' . esc_html( $status_lbl ) . '</span>'
            . '<table>'
            . '<tr><td>' . __( 'Date', 'wcwom' )     . '</td><td>' . wc_format_datetime( $order->get_date_created() ) . '</td></tr>'
            . '<tr><td>' . __( 'Total', 'wcwom' )    . '</td><td>' . $order->get_formatted_order_total() . '</td></tr>'
            . '<tr><td>' . __( 'Items', 'wcwom' )    . '</td><td>' . implode( '<br>', $items ) . '</td></tr>'
            . '<tr><td>' . __( 'Payment', 'wcwom' )  . '</td><td>' . esc_html( $order->get_payment_method_title() ) . '</td></tr>'
            . '<tr><td>' . __( 'Ship to', 'wcwom' )  . '</td><td>' . esc_html( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ) . '</td></tr>'
            . '</table>'
            . $wa_btn
            . '</div>';

        wp_send_json_success( [ 'html' => $html ] );
    }

    /* ─────────────────────────────────────────
       Assets
    ───────────────────────────────────────── */

    public function enqueue_assets() {
        $tracking_page = intval( WCWOM_Settings::get( 'tracking_page', 0 ) );
        if ( ! is_page( $tracking_page ) && ! has_shortcode( get_post()->post_content ?? '', 'wcwom_track_order' ) ) return;

        wp_enqueue_script(
            'wcwom-tracking',
            WCWOM_URL . 'assets/js/tracking.js',
            [ 'jquery' ],
            WCWOM_VERSION,
            true
        );
        wp_localize_script( 'wcwom-tracking', 'wcwomTrack', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcwom_track_nonce' ),
            'loading'  => __( 'Searching…', 'wcwom' ),
            'error'    => __( 'Something went wrong. Please try again.', 'wcwom' ),
        ] );
    }
}
