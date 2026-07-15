<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WCWOM_WhatsApp
 *
 * Handles sending WhatsApp messages via three methods:
 *   1. wa.me link  – opens WhatsApp web / app (no API key needed)
 *   2. Twilio      – paid, reliable, for production
 *   3. CallMeBot   – free WhatsApp API (requires one-time activation)
 */
class WCWOM_WhatsApp {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /* ─────────────────────────────────────────
       Public API
    ───────────────────────────────────────── */

    /**
     * Send a WhatsApp message.
     *
     * @param string $phone   Phone number with country code, digits only. e.g. 923001234567
     * @param string $message Plain-text message (may contain emoji).
     * @return true|WP_Error
     */
    public static function send( $phone, $message ) {
        $phone = self::sanitize_phone( $phone );
        if ( ! $phone ) {
            return new WP_Error( 'invalid_phone', __( 'Invalid or empty phone number.', 'wcwom' ) );
        }

        $method = WCWOM_Settings::get( 'wa_api', 'link' );

        switch ( $method ) {
            case 'twilio':
                return self::send_twilio( $phone, $message );
            case 'callmebot':
                return self::send_callmebot( $phone, $message );
            default:
                /* 'link' method — generate a wa.me URL and return it (used on front-end buttons) */
                return self::generate_link( $phone, $message );
        }
    }

    /**
     * Generate a wa.me link (for use in buttons / redirects).
     *
     * @param string $phone
     * @param string $message
     * @return string URL
     */
    public static function generate_link( $phone = '', $message = '' ) {
        $phone = $phone ? self::sanitize_phone( $phone ) : self::sanitize_phone( WCWOM_Settings::get( 'phone', '' ) );
        $url   = 'https://wa.me/' . $phone;
        if ( $message ) {
            $url .= '?text=' . rawurlencode( $message );
        }
        return $url;
    }

    /* ─────────────────────────────────────────
       Private senders
    ───────────────────────────────────────── */

    /**
     * Send via Twilio WhatsApp API.
     */
    private static function send_twilio( $phone, $message ) {
        $sid   = WCWOM_Settings::get( 'twilio_sid',   '' );
        $token = WCWOM_Settings::get( 'twilio_token', '' );
        $from  = WCWOM_Settings::get( 'twilio_from',  '' );

        if ( ! $sid || ! $token || ! $from ) {
            return new WP_Error( 'twilio_config', __( 'Twilio credentials are not configured.', 'wcwom' ) );
        }

        $endpoint = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "{$sid}:{$token}" ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'From' => 'whatsapp:+' . ltrim( $from, '+' ),
                'To'   => 'whatsapp:+' . ltrim( $phone, '+' ),
                'Body' => $message,
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $msg  = $body['message'] ?? 'Twilio API error.';
            return new WP_Error( 'twilio_error', $msg );
        }

        self::log( "Twilio → +{$phone}: " . substr( $message, 0, 80 ) );
        return true;
    }

    /**
     * Send via CallMeBot free API.
     * User must first activate: https://www.callmebot.com/blog/free-api-whatsapp-messages/
     */
    private static function send_callmebot( $phone, $message ) {
        $apikey = WCWOM_Settings::get( 'callmebot_apikey', '' );
        if ( ! $apikey ) {
            return new WP_Error( 'callmebot_config', __( 'CallMeBot API key is not configured.', 'wcwom' ) );
        }

        $url = add_query_arg( [
            'phone'   => $phone,
            'text'    => rawurlencode( $message ),
            'apikey'  => $apikey,
        ], 'https://api.callmebot.com/whatsapp.php' );

        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) return $response;

        $body = wp_remote_retrieve_body( $response );
        if ( stripos( $body, 'Message Queued' ) === false && stripos( $body, 'OK' ) === false ) {
            return new WP_Error( 'callmebot_error', 'CallMeBot error: ' . wp_strip_all_tags( $body ) );
        }

        self::log( "CallMeBot → +{$phone}: " . substr( $message, 0, 80 ) );
        return true;
    }

    /* ─────────────────────────────────────────
       Helpers
    ───────────────────────────────────────── */

    /**
     * Strip everything except digits from a phone number.
     */
    public static function sanitize_phone( $phone ) {
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        return $phone ?: '';
    }

    /**
     * Replace template variables in a message string.
     *
     * Supported tags: {name}, {order_id}, {total}, {status}, {tracking_url},
     *                 {site_name}, {cart_url}, {items}
     *
     * @param string   $template
     * @param array    $vars  Key-value pairs
     * @return string
     */
    public static function parse_template( $template, array $vars = [] ) {
        $defaults = [
            'site_name'    => get_bloginfo( 'name' ),
            'cart_url'     => wc_get_cart_url(),
            'tracking_url' => home_url( '/track-order/' ),
        ];
        $vars = array_merge( $defaults, $vars );

        foreach ( $vars as $key => $value ) {
            $template = str_replace( '{' . $key . '}', $value, $template );
        }
        return $template;
    }

    /**
     * Build variable map from a WC_Order.
     */
    public static function order_vars( WC_Order $order ) {
        $tracking_page = WCWOM_Settings::get( 'tracking_page', 0 );
        $tracking_url  = $tracking_page
            ? add_query_arg( 'order_id', $order->get_id(), get_permalink( $tracking_page ) )
            : $order->get_view_order_url();

        /* Build item list */
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' ×' . $item->get_quantity();
        }

        return [
            'name'         => $order->get_billing_first_name(),
            'order_id'     => $order->get_id(),
            'total'        => $order->get_formatted_order_total(),
            'status'       => wc_get_order_status_name( $order->get_status() ),
            'tracking_url' => $tracking_url,
            'items'        => implode( ', ', $items ),
        ];
    }

    /**
     * Write to WP debug log if WP_DEBUG_LOG is on.
     */
    private static function log( $message ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[WCWOM] ' . $message );
        }
    }
}
