<?php
/**
 * Plugin Name:       WooCommerce WhatsApp Order Manager
 * Plugin URI:        https://example.com/wc-whatsapp-order-manager
 * Description:       Send WooCommerce order notifications, customer tracking links, and abandoned cart reminders via WhatsApp. Ideal for small businesses in Pakistan, India, UAE, and beyond.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wcwom
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   8.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Constants ── */
define( 'WCWOM_VERSION',  '1.0.0' );
define( 'WCWOM_PATH',     plugin_dir_path( __FILE__ ) );
define( 'WCWOM_URL',      plugin_dir_url( __FILE__ ) );
define( 'WCWOM_BASENAME', plugin_basename( __FILE__ ) );

/* ── Check WooCommerce is active ── */
add_action( 'plugins_loaded', 'wcwom_init', 0 );
function wcwom_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WC WhatsApp Order Manager</strong> requires WooCommerce to be installed and active.</p></div>';
        } );
        return;
    }

    /* ── Load core files ── */
    require_once WCWOM_PATH . 'includes/class-wcwom-settings.php';
    require_once WCWOM_PATH . 'includes/class-wcwom-whatsapp.php';
    require_once WCWOM_PATH . 'includes/class-wcwom-order-notifications.php';
    require_once WCWOM_PATH . 'includes/class-wcwom-abandoned-cart.php';
    require_once WCWOM_PATH . 'includes/class-wcwom-tracking.php';
    require_once WCWOM_PATH . 'admin/class-wcwom-admin.php';

    /* ── Boot ── */
    WCWOM_Settings::get_instance();
    WCWOM_Order_Notifications::get_instance();
    WCWOM_Abandoned_Cart::get_instance();
    WCWOM_Tracking::get_instance();
    WCWOM_Admin::get_instance();
}

/* ── Activation ── */
register_activation_hook( __FILE__, 'wcwom_activate' );
function wcwom_activate() {
    global $wpdb;

    /* Abandoned-cart table */
    $table   = $wpdb->prefix . 'wcwom_abandoned_carts';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT(20)   NOT NULL AUTO_INCREMENT,
        user_id      BIGINT(20)   NOT NULL DEFAULT 0,
        session_key  VARCHAR(255) NOT NULL DEFAULT '',
        phone        VARCHAR(30)  NOT NULL DEFAULT '',
        cart_data    LONGTEXT     NOT NULL,
        total        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reminded_at  DATETIME     DEFAULT NULL,
        status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
        PRIMARY KEY  (id),
        KEY session_key (session_key),
        KEY status (status)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    /* Default options */
    $defaults = [
        'wcwom_phone'                    => '',
        'wcwom_wa_api'                   => 'link',   // link | twilio | callmebot
        'wcwom_twilio_sid'               => '',
        'wcwom_twilio_token'             => '',
        'wcwom_twilio_from'              => '',
        'wcwom_callmebot_apikey'         => '',
        'wcwom_notify_new_order'         => '1',
        'wcwom_notify_processing'        => '1',
        'wcwom_notify_completed'         => '1',
        'wcwom_notify_cancelled'         => '1',
        'wcwom_notify_refunded'          => '0',
        'wcwom_notify_customer'          => '1',
        'wcwom_notify_admin'             => '1',
        'wcwom_abandoned_cart_enable'    => '1',
        'wcwom_abandoned_cart_delay'     => '60',     // minutes
        'wcwom_abandoned_cart_msg'       => "Hi {name}! 👋 You left something in your cart at {site_name}.\n\nYour cart total: {total}\n\n🛒 Complete your order here: {cart_url}\n\nReply STOP to unsubscribe.",
        'wcwom_msg_new_order'            => "✅ *New Order #{order_id}*\nThank you, {name}!\n\nYour order has been received.\n💰 Total: {total}\n📦 Status: {status}\n\n🔗 Track your order: {tracking_url}",
        'wcwom_msg_processing'           => "🔄 *Order #{order_id} is being processed*\nHi {name}, we are preparing your order!\n\n💰 Total: {total}\n\n🔗 Track: {tracking_url}",
        'wcwom_msg_completed'            => "🎉 *Order #{order_id} Completed!*\nHi {name}, your order has been delivered!\n\nThank you for shopping with us. We hope to see you again! 😊",
        'wcwom_msg_cancelled'            => "❌ *Order #{order_id} Cancelled*\nHi {name}, your order has been cancelled.\n\nQuestions? Reply to this message.",
        'wcwom_msg_refunded'             => "💸 *Order #{order_id} Refunded*\nHi {name}, your refund has been processed.\n\nIt may take 3-5 business days to appear.",
        'wcwom_tracking_enable'          => '1',
        'wcwom_tracking_page'            => '0',
        'wcwom_button_on_thankyou'       => '1',
        'wcwom_button_on_myaccount'      => '1',
    ];
    foreach ( $defaults as $key => $val ) {
        if ( get_option( $key ) === false ) {
            update_option( $key, $val );
        }
    }

    /* Schedule abandoned-cart cron */
    if ( ! wp_next_scheduled( 'wcwom_abandoned_cart_cron' ) ) {
        wp_schedule_event( time(), 'wcwom_every_15_min', 'wcwom_abandoned_cart_cron' );
    }
}

/* ── Deactivation ── */
register_deactivation_hook( __FILE__, 'wcwom_deactivate' );
function wcwom_deactivate() {
    wp_clear_scheduled_hook( 'wcwom_abandoned_cart_cron' );
}

/* ── Uninstall ── */
register_uninstall_hook( __FILE__, 'wcwom_uninstall' );
function wcwom_uninstall() {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcwom_abandoned_carts" );
    $options = [
        'wcwom_phone','wcwom_wa_api','wcwom_twilio_sid','wcwom_twilio_token',
        'wcwom_twilio_from','wcwom_callmebot_apikey','wcwom_notify_new_order',
        'wcwom_notify_processing','wcwom_notify_completed','wcwom_notify_cancelled',
        'wcwom_notify_refunded','wcwom_notify_customer','wcwom_notify_admin',
        'wcwom_abandoned_cart_enable','wcwom_abandoned_cart_delay',
        'wcwom_abandoned_cart_msg','wcwom_msg_new_order','wcwom_msg_processing',
        'wcwom_msg_completed','wcwom_msg_cancelled','wcwom_msg_refunded',
        'wcwom_tracking_enable','wcwom_tracking_page','wcwom_button_on_thankyou',
        'wcwom_button_on_myaccount',
    ];
    foreach ( $options as $opt ) delete_option( $opt );
}

/* ── Custom cron schedule (every 15 min) ── */
add_filter( 'cron_schedules', 'wcwom_cron_schedules' );
function wcwom_cron_schedules( $schedules ) {
    $schedules['wcwom_every_15_min'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __( 'Every 15 Minutes', 'wcwom' ),
    ];
    return $schedules;
}

/* ── Plugin action links ── */
add_filter( 'plugin_action_links_' . WCWOM_BASENAME, 'wcwom_action_links' );
function wcwom_action_links( $links ) {
    $links[] = '<a href="' . admin_url( 'admin.php?page=wcwom-settings' ) . '">Settings</a>';
    return $links;
}
