<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WCWOM_Admin
 *
 * Registers the admin menu and renders the tabbed settings page.
 * Tabs: General | Notifications | Messages | Abandoned Cart | Tracking
 */
class WCWOM_Admin {

    private static $instance = null;
    private $tabs = [];

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->tabs = [
            'general'       => __( '⚙️ General', 'wcwom' ),
            'notifications' => __( '🔔 Notifications', 'wcwom' ),
            'messages'      => __( '💬 Messages', 'wcwom' ),
            'abandoned'     => __( '🛒 Abandoned Cart', 'wcwom' ),
            'tracking'      => __( '📦 Tracking', 'wcwom' ),
        ];
        add_action( 'admin_menu',       [ $this, 'register_menu' ] );
        add_action( 'admin_init',       [ $this, 'save_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        /* Test message AJAX */
        add_action( 'wp_ajax_wcwom_send_test', [ $this, 'ajax_send_test' ] );
    }

    /* ─────────────────────────────────────────
       Menu
    ───────────────────────────────────────── */

    public function register_menu() {
        add_menu_page(
            __( 'WhatsApp Orders', 'wcwom' ),
            __( 'WA Orders', 'wcwom' ),
            'manage_woocommerce',
            'wcwom-settings',
            [ $this, 'render_settings_page' ],
            'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>' ),
            56
        );
    }

    /* ─────────────────────────────────────────
       Save Settings
    ───────────────────────────────────────── */

    public function save_settings() {
        if ( ! isset( $_POST['wcwom_save'] ) ) return;
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        check_admin_referer( 'wcwom_save_settings' );

        $fields = [
            /* General */
            'wcwom_phone'                 => 'sanitize_text_field',
            'wcwom_wa_api'                => 'sanitize_text_field',
            'wcwom_twilio_sid'            => 'sanitize_text_field',
            'wcwom_twilio_token'          => 'sanitize_text_field',
            'wcwom_twilio_from'           => 'sanitize_text_field',
            'wcwom_callmebot_apikey'      => 'sanitize_text_field',
            /* Notifications */
            'wcwom_notify_new_order'      => null,
            'wcwom_notify_processing'     => null,
            'wcwom_notify_completed'      => null,
            'wcwom_notify_cancelled'      => null,
            'wcwom_notify_refunded'       => null,
            'wcwom_notify_customer'       => null,
            'wcwom_notify_admin'          => null,
            'wcwom_button_on_thankyou'    => null,
            'wcwom_button_on_myaccount'   => null,
            /* Messages */
            'wcwom_msg_new_order'         => 'sanitize_textarea_field',
            'wcwom_msg_processing'        => 'sanitize_textarea_field',
            'wcwom_msg_completed'         => 'sanitize_textarea_field',
            'wcwom_msg_cancelled'         => 'sanitize_textarea_field',
            'wcwom_msg_refunded'          => 'sanitize_textarea_field',
            /* Abandoned Cart */
            'wcwom_abandoned_cart_enable' => null,
            'wcwom_abandoned_cart_delay'  => 'absint',
            'wcwom_abandoned_cart_msg'    => 'sanitize_textarea_field',
            /* Tracking */
            'wcwom_tracking_enable'       => null,
            'wcwom_tracking_page'         => 'absint',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            if ( $sanitizer === null ) {
                /* Checkbox: 1 if present, 0 if not */
                update_option( $key, isset( $_POST[ $key ] ) ? '1' : '0' );
            } else {
                $value = isset( $_POST[ $key ] ) ? call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) ) : '';
                update_option( $key, $value );
            }
        }

        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Settings saved.', 'wcwom' ) . '</p></div>';
        } );
    }

    /* ─────────────────────────────────────────
       Assets
    ───────────────────────────────────────── */

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wcwom' ) === false ) return;
        wp_enqueue_style(  'wcwom-admin', WCWOM_URL . 'assets/css/admin.css', [], WCWOM_VERSION );
        wp_enqueue_script( 'wcwom-admin', WCWOM_URL . 'assets/js/admin.js', [ 'jquery' ], WCWOM_VERSION, true );
        wp_localize_script( 'wcwom-admin', 'wcwomAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcwom_test_nonce' ),
        ] );
    }

    /* ─────────────────────────────────────────
       Test Message AJAX
    ───────────────────────────────────────── */

    public function ajax_send_test() {
        check_ajax_referer( 'wcwom_test_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

        $phone   = sanitize_text_field( $_POST['phone'] ?? '' );
        $message = sanitize_textarea_field( $_POST['message'] ?? '👋 This is a test message from WC WhatsApp Order Manager!' );

        if ( ! $phone ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a phone number.', 'wcwom' ) ] );
        }

        $result = WCWOM_WhatsApp::send( $phone, $message );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        /* For 'link' method, $result is the URL */
        if ( is_string( $result ) ) {
            wp_send_json_success( [ 'link' => $result, 'message' => __( 'Open the link below in your browser to send via WhatsApp:', 'wcwom' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Test message sent successfully!', 'wcwom' ) ] );
    }

    /* ─────────────────────────────────────────
       Settings Page
    ───────────────────────────────────────── */

    public function render_settings_page() {
        $current_tab = sanitize_key( $_GET['tab'] ?? 'general' );
        if ( ! array_key_exists( $current_tab, $this->tabs ) ) $current_tab = 'general';
        ?>
        <div class="wrap wcwom-wrap">

            <!-- Header -->
            <div class="wcwom-page-header">
                <div class="wcwom-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#25D366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    <div>
                        <h1>WooCommerce WhatsApp Order Manager</h1>
                        <p>v<?php echo WCWOM_VERSION; ?> &nbsp;|&nbsp; by Your Name</p>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <nav class="wcwom-tabs">
                <?php foreach ( $this->tabs as $slug => $label ) :
                    $url = admin_url( 'admin.php?page=wcwom-settings&tab=' . $slug );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="wcwom-tab <?php echo $current_tab === $slug ? 'wcwom-tab--active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <!-- Form -->
            <form method="post" id="wcwom-settings-form">
                <?php wp_nonce_field( 'wcwom_save_settings' ); ?>

                <div class="wcwom-tab-content">
                <?php
                switch ( $current_tab ) {
                    case 'general':       $this->tab_general();       break;
                    case 'notifications': $this->tab_notifications(); break;
                    case 'messages':      $this->tab_messages();      break;
                    case 'abandoned':     $this->tab_abandoned();     break;
                    case 'tracking':      $this->tab_tracking();      break;
                }
                ?>
                </div>

                <div class="wcwom-save-bar">
                    <button type="submit" name="wcwom_save" class="wcwom-btn-save">
                        💾 <?php esc_html_e( 'Save Settings', 'wcwom' ); ?>
                    </button>
                </div>
            </form>

        </div><!-- /.wrap -->
        <?php
    }

    /* ════════════════════════════════════════
       TAB: GENERAL
    ════════════════════════════════════════ */
    private function tab_general() {
        $api = get_option( 'wcwom_wa_api', 'link' );
        ?>
        <div class="wcwom-section">
            <h2><?php esc_html_e( 'WhatsApp API Configuration', 'wcwom' ); ?></h2>
            <p class="wcwom-desc"><?php esc_html_e( 'Choose how messages are sent. "WhatsApp Link" requires no API key — it opens WhatsApp for the admin to send manually or is used for customer-facing buttons.', 'wcwom' ); ?></p>

            <table class="wcwom-table">
                <tr>
                    <th><label for="wcwom_phone"><?php esc_html_e( 'Your WhatsApp Number', 'wcwom' ); ?></label></th>
                    <td>
                        <input type="text" id="wcwom_phone" name="wcwom_phone"
                               value="<?php echo esc_attr( get_option( 'wcwom_phone', '' ) ); ?>"
                               placeholder="923001234567" class="regular-text">
                        <p class="description"><?php esc_html_e( 'Enter with country code, no + sign. E.g. 923001234567 for Pakistan.', 'wcwom' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Sending Method', 'wcwom' ); ?></label></th>
                    <td>
                        <fieldset>
                            <label class="wcwom-radio">
                                <input type="radio" name="wcwom_wa_api" value="link" <?php checked( $api, 'link' ); ?>>
                                <span><?php esc_html_e( 'WhatsApp Link (wa.me) — Free, no API key needed', 'wcwom' ); ?></span>
                            </label>
                            <label class="wcwom-radio">
                                <input type="radio" name="wcwom_wa_api" value="callmebot" <?php checked( $api, 'callmebot' ); ?>>
                                <span><?php esc_html_e( 'CallMeBot — Free WhatsApp API (requires one-time activation)', 'wcwom' ); ?></span>
                            </label>
                            <label class="wcwom-radio">
                                <input type="radio" name="wcwom_wa_api" value="twilio" <?php checked( $api, 'twilio' ); ?>>
                                <span><?php esc_html_e( 'Twilio — Paid, reliable for production', 'wcwom' ); ?></span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>
        </div>

        <!-- CallMeBot -->
        <div class="wcwom-section wcwom-api-section" id="wcwom-api-callmebot"
             style="<?php echo $api !== 'callmebot' ? 'display:none;' : ''; ?>">
            <h2><?php esc_html_e( 'CallMeBot Settings', 'wcwom' ); ?></h2>
            <div class="wcwom-notice wcwom-notice--info">
                <strong>How to activate CallMeBot:</strong><br>
                1. Save the number <strong>+34 644 52 74 97</strong> in your phone as "CallMeBot".<br>
                2. Send the message <code>I allow callmebot to send me messages</code> via WhatsApp to that number.<br>
                3. You'll receive your API key within 2 minutes.<br>
                <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank">→ Full Instructions</a>
            </div>
            <table class="wcwom-table">
                <tr>
                    <th><label for="wcwom_callmebot_apikey"><?php esc_html_e( 'API Key', 'wcwom' ); ?></label></th>
                    <td>
                        <input type="text" id="wcwom_callmebot_apikey" name="wcwom_callmebot_apikey"
                               value="<?php echo esc_attr( get_option( 'wcwom_callmebot_apikey', '' ) ); ?>"
                               class="regular-text" placeholder="123456">
                    </td>
                </tr>
            </table>
        </div>

        <!-- Twilio -->
        <div class="wcwom-section wcwom-api-section" id="wcwom-api-twilio"
             style="<?php echo $api !== 'twilio' ? 'display:none;' : ''; ?>">
            <h2><?php esc_html_e( 'Twilio Settings', 'wcwom' ); ?></h2>
            <div class="wcwom-notice wcwom-notice--info">
                <?php esc_html_e( 'Get your Account SID and Auth Token from the Twilio Console. Your "From" number must be a Twilio WhatsApp-enabled number.', 'wcwom' ); ?>
                <a href="https://console.twilio.com/" target="_blank">→ Twilio Console</a>
            </div>
            <table class="wcwom-table">
                <tr>
                    <th><label for="wcwom_twilio_sid"><?php esc_html_e( 'Account SID', 'wcwom' ); ?></label></th>
                    <td><input type="text" id="wcwom_twilio_sid" name="wcwom_twilio_sid"
                               value="<?php echo esc_attr( get_option( 'wcwom_twilio_sid', '' ) ); ?>"
                               class="regular-text" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"></td>
                </tr>
                <tr>
                    <th><label for="wcwom_twilio_token"><?php esc_html_e( 'Auth Token', 'wcwom' ); ?></label></th>
                    <td><input type="password" id="wcwom_twilio_token" name="wcwom_twilio_token"
                               value="<?php echo esc_attr( get_option( 'wcwom_twilio_token', '' ) ); ?>"
                               class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="wcwom_twilio_from"><?php esc_html_e( 'From Number', 'wcwom' ); ?></label></th>
                    <td><input type="text" id="wcwom_twilio_from" name="wcwom_twilio_from"
                               value="<?php echo esc_attr( get_option( 'wcwom_twilio_from', '' ) ); ?>"
                               class="regular-text" placeholder="14155238886"></td>
                </tr>
            </table>
        </div>

        <!-- Test Message -->
        <div class="wcwom-section">
            <h2><?php esc_html_e( 'Send Test Message', 'wcwom' ); ?></h2>
            <table class="wcwom-table">
                <tr>
                    <th><label for="wcwom_test_phone"><?php esc_html_e( 'Test Phone Number', 'wcwom' ); ?></label></th>
                    <td>
                        <input type="text" id="wcwom_test_phone" placeholder="923001234567" class="regular-text">
                        <button type="button" id="wcwom-send-test" class="button button-secondary" style="margin-left:8px;">
                            📱 <?php esc_html_e( 'Send Test', 'wcwom' ); ?>
                        </button>
                        <span id="wcwom-test-result" style="margin-left:10px;"></span>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /* ════════════════════════════════════════
       TAB: NOTIFICATIONS
    ════════════════════════════════════════ */
    private function tab_notifications() {
        $toggles = [
            'notify_new_order'    => __( 'New / Pending Order', 'wcwom' ),
            'notify_processing'   => __( 'Order Processing', 'wcwom' ),
            'notify_completed'    => __( 'Order Completed', 'wcwom' ),
            'notify_cancelled'    => __( 'Order Cancelled', 'wcwom' ),
            'notify_refunded'     => __( 'Order Refunded', 'wcwom' ),
        ];
        $recipients = [
            'notify_customer'     => __( 'Send to Customer', 'wcwom' ),
            'notify_admin'        => __( 'Send to Admin (your number)', 'wcwom' ),
        ];
        $buttons = [
            'button_on_thankyou'  => __( 'Show "Chat with us on WhatsApp" button on Thank You page', 'wcwom' ),
            'button_on_myaccount' => __( 'Show "WhatsApp Support" button in My Account → Orders', 'wcwom' ),
        ];
        ?>
        <div class="wcwom-section">
            <h2><?php esc_html_e( 'Order Status Triggers', 'wcwom' ); ?></h2>
            <p class="wcwom-desc"><?php esc_html_e( 'Choose which order status changes trigger a WhatsApp message.', 'wcwom' ); ?></p>
            <table class="wcwom-table">
                <?php foreach ( $toggles as $key => $label ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <label class="wcwom-toggle">
                            <input type="checkbox" name="wcwom_<?php echo $key; ?>" value="1"
                                   <?php checked( get_option( 'wcwom_' . $key, '1' ), '1' ); ?>>
                            <span class="wcwom-toggle-slider"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="wcwom-section">
            <h2><?php esc_html_e( 'Recipients', 'wcwom' ); ?></h2>
            <table class="wcwom-table">
                <?php foreach ( $recipients as $key => $label ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <label class="wcwom-toggle">
                            <input type="checkbox" name="wcwom_<?php echo $key; ?>" value="1"
                                   <?php checked( get_option( 'wcwom_' . $key, '1' ), '1' ); ?>>
                            <span class="wcwom-toggle-slider"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="wcwom-section">
            <h2><?php esc_html_e( 'WhatsApp Buttons', 'wcwom' ); ?></h2>
            <table class="wcwom-table">
                <?php foreach ( $buttons as $key => $label ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <label class="wcwom-toggle">
                            <input type="checkbox" name="wcwom_<?php echo $key; ?>" value="1"
                                   <?php checked( get_option( 'wcwom_' . $key, '1' ), '1' ); ?>>
                            <span class="wcwom-toggle-slider"></span>
                        </label>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
    }

    /* ════════════════════════════════════════
       TAB: MESSAGES
    ════════════════════════════════════════ */
    private function tab_messages() {
        $messages = [
            'msg_new_order'   => [ 'label' => __( 'New / Pending Order', 'wcwom' ),   'key' => 'wcwom_msg_new_order' ],
            'msg_processing'  => [ 'label' => __( 'Processing', 'wcwom' ),             'key' => 'wcwom_msg_processing' ],
            'msg_completed'   => [ 'label' => __( 'Completed', 'wcwom' ),              'key' => 'wcwom_msg_completed' ],
            'msg_cancelled'   => [ 'label' => __( 'Cancelled', 'wcwom' ),              'key' => 'wcwom_msg_cancelled' ],
            'msg_refunded'    => [ 'label' => __( 'Refunded', 'wcwom' ),               'key' => 'wcwom_msg_refunded' ],
        ];
        ?>
        <div class="wcwom-section">
            <h2><?php esc_html_e( 'Message Templates', 'wcwom' ); ?></h2>
            <div class="wcwom-notice wcwom-notice--info">
                <strong><?php esc_html_e( 'Available variables:', 'wcwom' ); ?></strong><br>
                <code>{name}</code> <?php esc_html_e( 'Customer first name', 'wcwom' ); ?> &nbsp;
                <code>{order_id}</code> <?php esc_html_e( 'Order number', 'wcwom' ); ?> &nbsp;
                <code>{total}</code> <?php esc_html_e( 'Order total', 'wcwom' ); ?> &nbsp;
                <code>{status}</code> <?php esc_html_e( 'Order status', 'wcwom' ); ?> &nbsp;
                <code>{items}</code> <?php esc_html_e( 'Item list', 'wcwom' ); ?> &nbsp;
                <code>{tracking_url}</code> <?php esc_html_e( 'Tracking link', 'wcwom' ); ?> &nbsp;
                <code>{site_name}</code> <?php esc_html_e( 'Site name', 'wcwom' ); ?>
            </div>

            <?php foreach ( $messages as $m ) : ?>
            <div class="wcwom-msg-block">
                <label class="wcwom-msg-label"><?php echo esc_html( $m['label'] ); ?></label>
                <textarea name="<?php echo esc_attr( $m['key'] ); ?>"
                          rows="5"
                          class="wcwom-msg-textarea large-text"><?php echo esc_textarea( get_option( $m['key'], '' ) ); ?></textarea>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /* ════════════════════════════════════════
       TAB: ABANDONED CART
    ════════════════════════════════════════ */
    private function tab_abandoned() {
        ?>
        <div class="wcwom-section">
            <h2><?php esc_html_e( 'Abandoned Cart Reminders', 'wcwom' ); ?></h2>
            <p class="wcwom-desc"><?php esc_html_e( 'When a customer adds items to their cart, enters their phone at checkout, but does not complete the order — send them a WhatsApp reminder.', 'wcwom' ); ?></p>

            <table class="wcwom-table">
                <tr>
                    <th><?php esc_html_e( 'Enable Abandoned Cart', 'wcwom' ); ?></th>
                    <td>
                        <label class="wcwom-toggle">
                            <input type="checkbox" name="wcwom_abandoned_cart_enable" value="1"
                                   <?php checked( get_option( 'wcwom_abandoned_cart_enable', '1' ), '1' ); ?>>
                            <span class="wcwom-toggle-slider"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="wcwom_abandoned_cart_delay"><?php esc_html_e( 'Send Reminder After', 'wcwom' ); ?></label></th>
                    <td>
                        <input type="number" id="wcwom_abandoned_cart_delay" name="wcwom_abandoned_cart_delay"
                               value="<?php echo esc_attr( get_option( 'wcwom_abandoned_cart_delay', 60 ) ); ?>"
                               min="15" max="1440" class="small-text">
                        <?php esc_html_e( 'minutes', 'wcwom' ); ?>
                        <p class="description"><?php esc_html_e( 'Minimum 15 minutes. Recommended: 60 minutes.', 'wcwom' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wcwom_abandoned_cart_msg"><?php esc_html_e( 'Reminder Message', 'wcwom' ); ?></label></th>
                    <td>
                        <textarea id="wcwom_abandoned_cart_msg" name="wcwom_abandoned_cart_msg"
                                  rows="6" class="large-text"><?php echo esc_textarea( get_option( 'wcwom_abandoned_cart_msg', '' ) ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Variables:', 'wcwom' ); ?>
                            <code>{name}</code> <code>{total}</code> <code>{cart_url}</code> <code>{items}</code> <code>{site_name}</code>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wcwom-section">
            <h2><?php esc_html_e( 'View Abandoned Carts', 'wcwom' ); ?></h2>
            <p>
                <a href="<?php echo admin_url( 'admin.php?page=wcwom-abandoned-carts' ); ?>" class="button button-secondary">
                    🛒 <?php esc_html_e( 'View All Abandoned Carts', 'wcwom' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /* ════════════════════════════════════════
       TAB: TRACKING
    ════════════════════════════════════════ */
    private function tab_tracking() {
        $pages = get_pages();
        ?>
        <div class="wcwom-section">
            <h2><?php esc_html_e( 'Order Tracking Page', 'wcwom' ); ?></h2>
            <p class="wcwom-desc"><?php esc_html_e( 'Allow customers to self-serve their order status without logging in. Useful for WhatsApp-linked tracking.', 'wcwom' ); ?></p>

            <table class="wcwom-table">
                <tr>
                    <th><?php esc_html_e( 'Enable Tracking', 'wcwom' ); ?></th>
                    <td>
                        <label class="wcwom-toggle">
                            <input type="checkbox" name="wcwom_tracking_enable" value="1"
                                   <?php checked( get_option( 'wcwom_tracking_enable', '1' ), '1' ); ?>>
                            <span class="wcwom-toggle-slider"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="wcwom_tracking_page"><?php esc_html_e( 'Tracking Page', 'wcwom' ); ?></label></th>
                    <td>
                        <select id="wcwom_tracking_page" name="wcwom_tracking_page" class="regular-text">
                            <option value="0"><?php esc_html_e( '— Auto-create a page —', 'wcwom' ); ?></option>
                            <?php
                            $saved_page = intval( get_option( 'wcwom_tracking_page', 0 ) );
                            foreach ( $pages as $page ) :
                            ?>
                            <option value="<?php echo $page->ID; ?>" <?php selected( $saved_page, $page->ID ); ?>>
                                <?php echo esc_html( $page->post_title ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ( $saved_page ) : ?>
                        <a href="<?php echo get_permalink( $saved_page ); ?>" target="_blank" class="button button-small" style="margin-left:8px;">
                            👁️ <?php esc_html_e( 'Preview', 'wcwom' ); ?>
                        </a>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e( 'Select the page that contains [wcwom_track_order] shortcode, or leave blank to auto-create.', 'wcwom' ); ?></p>
                    </td>
                </tr>
            </table>

            <div class="wcwom-notice wcwom-notice--info" style="margin-top:16px;">
                <strong><?php esc_html_e( 'Shortcode:', 'wcwom' ); ?></strong>
                <code>[wcwom_track_order]</code> — <?php esc_html_e( 'paste this on any page to show the order tracking form.', 'wcwom' ); ?>
            </div>
        </div>
        <?php
    }
}
