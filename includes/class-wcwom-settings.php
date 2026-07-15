<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WCWOM_Settings
 * Central store for all plugin options with a single get() helper.
 */
class WCWOM_Settings {

    private static $instance = null;
    private $cache = [];

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Get one option value.
     *
     * @param string $key     Option key (without wcwom_ prefix).
     * @param mixed  $default Default value.
     * @return mixed
     */
    public static function get( $key, $default = '' ) {
        $full_key = 'wcwom_' . $key;
        $inst = self::get_instance();
        if ( ! isset( $inst->cache[ $full_key ] ) ) {
            $inst->cache[ $full_key ] = get_option( $full_key, $default );
        }
        return $inst->cache[ $full_key ];
    }

    /**
     * Save one option value and bust cache.
     */
    public static function set( $key, $value ) {
        $full_key = 'wcwom_' . $key;
        update_option( $full_key, $value );
        $inst = self::get_instance();
        $inst->cache[ $full_key ] = $value;
    }

    /**
     * Check if a specific order status notification is enabled.
     */
    public static function is_status_enabled( $status ) {
        $map = [
            'pending'    => 'notify_new_order',
            'processing' => 'notify_processing',
            'completed'  => 'notify_completed',
            'cancelled'  => 'notify_cancelled',
            'refunded'   => 'notify_refunded',
        ];
        if ( ! isset( $map[ $status ] ) ) return false;
        return '1' === self::get( $map[ $status ], '0' );
    }

    /**
     * Get the message template for a given order status.
     */
    public static function get_message_template( $status ) {
        $map = [
            'pending'    => 'msg_new_order',
            'processing' => 'msg_processing',
            'completed'  => 'msg_completed',
            'cancelled'  => 'msg_cancelled',
            'refunded'   => 'msg_refunded',
        ];
        if ( ! isset( $map[ $status ] ) ) return '';
        return self::get( $map[ $status ], '' );
    }
}
