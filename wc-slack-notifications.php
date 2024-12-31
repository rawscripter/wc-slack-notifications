<?php

/**
 * Plugin Name: WC Slack Notifications
 * Description: Send notifications to Slack whenever a WooCommerce order action occurs. Includes admin settings to configure the Slack Webhook URL, plus more detailed order info.
 * Version:     1.0.0
 * Author:      Shuvo Goswami
 * License:     GPL2
 * Text Domain: wc-slack-notifications
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active.
if (in_array(
    'woocommerce/woocommerce.php',
    apply_filters('active_plugins', get_option('active_plugins'))
)) {

    /**
     * Main Plugin Class
     */
    class WC_Slack_Notifications
    {

        /**
         * Constructor: Initialize hooks
         */
        public function __construct()
        {
            // Load the admin settings hooks only in the WordPress admin area.
            if (is_admin()) {
                add_action('admin_init', [$this, 'settings_init']);
                add_action('admin_menu', [$this, 'add_settings_page']);
            }

            // Hook into order status changes (option 1).
            add_action('woocommerce_order_status_changed', [$this, 'order_status_changed'], 10, 4);

            // Hook into new order creation (option 2, if you prefer).
            // add_action( 'woocommerce_thankyou', [ $this, 'order_created' ], 10, 1 );
        }

        /**
         * Send Slack notification when an order’s status changes
         *
         * @param int    $order_id
         * @param string $old_status
         * @param string $new_status
         * @param object $order
         */
        public function order_status_changed($order_id, $old_status, $new_status, $order)
        {
            $message = $this->build_order_message($order_id, $old_status, $new_status);
            $this->send_slack_notification($message);
        }

        /**
         * Send Slack notification when a new order is created
         *
         * @param int $order_id
         */
        public function order_created($order_id)
        {
            $order   = wc_get_order($order_id);
            // You can adjust the message below if you want less or more detail for new orders.
            $message = $this->build_order_message($order_id, 'N/A', 'new');
            $this->send_slack_notification($message);
        }

        /**
         * Build a detailed, plain-text message with order info
         *
         * @param int    $order_id
         * @param string $old_status
         * @param string $new_status
         * @return string
         */
        private function build_order_message($order_id, $old_status, $new_status)
        {
            $order = wc_get_order($order_id);

            // Basic info
            $order_total   = $order->get_total();
            $billing_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $shipping_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            $billing_email = $order->get_billing_email();
            $billing_phone = $order->get_billing_phone();

            // Billing address lines
            $billing_address_1 = $order->get_billing_address_1();
            $billing_address_2 = $order->get_billing_address_2();
            $billing_city      = $order->get_billing_city();
            $billing_state     = $order->get_billing_state();
            $billing_postcode  = $order->get_billing_postcode();

            // Shipping address lines
            $shipping_address_1 = $order->get_shipping_address_1();
            $shipping_address_2 = $order->get_shipping_address_2();
            $shipping_city      = $order->get_shipping_city();
            $shipping_state     = $order->get_shipping_state();
            $shipping_postcode  = $order->get_shipping_postcode();

            // Build the message (no HTML tags, just text and new lines)
            $message  = "Order #{$order_id} status changed from {$old_status} to {$new_status}\n";
            $message .= "Order Total: {$order_total}\n";
            $message .= "Billing Name: {$billing_name}\n";
            $message .= "Billing Email: {$billing_email}\n";
            $message .= "Billing Phone: {$billing_phone}\n";
            $message .= "\n";
            $message .= "Billing Address:\n";
            $message .= "{$billing_address_1}\n";
            if (! empty($billing_address_2)) {
                $message .= "{$billing_address_2}\n";
            }
            $message .= "{$billing_city}, {$billing_state} {$billing_postcode}\n";
            $message .= "\n";
            $message .= "Shipping Name: {$shipping_name}\n";
            $message .= "Shipping Address:\n";
            $message .= "{$shipping_address_1}\n";
            if (! empty($shipping_address_2)) {
                $message .= "{$shipping_address_2}\n";
            }
            $message .= "{$shipping_city}, {$shipping_state} {$shipping_postcode}\n";

            return $message;
        }

        /**
         * Helper function to send a message to Slack
         *
         * @param string $message
         */
        private function send_slack_notification($message)
        {
            // Retrieve webhook URL from settings.
            $webhook_url = get_option('wc_slack_notif_webhook_url', '');

            if (empty($webhook_url)) {
                // If no webhook URL is set, do nothing.
                return;
            }

            $payload = [
                'text' => $message,
            ];

            $args = [
                'body'    => wp_json_encode($payload),
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'method'  => 'POST',
            ];

            // Send request to Slack
            $response = wp_remote_post($webhook_url, $args);

            // Optional: Log or handle errors
            if (is_wp_error($response)) {
                error_log('Slack notification failed: ' . $response->get_error_message());
            }
        }

        /**
         * Register settings, sections, and fields
         */
        public function settings_init()
        {
            // Register the setting to store the Slack webhook URL.
            register_setting('wc_slack_notif_settings', 'wc_slack_notif_webhook_url');

            // Add a settings section.
            add_settings_section(
                'wc_slack_notif_settings_section',
                'Slack Notification Settings',
                [$this, 'settings_section_callback'],
                'wc_slack_notif_settings'
            );

            // Add a field for the Slack Webhook URL.
            add_settings_field(
                'wc_slack_notif_webhook_url_field',
                'Slack Webhook URL',
                [$this, 'webhook_url_field_render'],
                'wc_slack_notif_settings',
                'wc_slack_notif_settings_section'
            );
        }

        /**
         * Render the Slack Webhook URL text field
         */
        public function webhook_url_field_render()
        {
            $option = get_option('wc_slack_notif_webhook_url', '');
?>
            <input
                type="text"
                name="wc_slack_notif_webhook_url"
                value="<?php echo esc_attr($option); ?>"
                size="50"
                placeholder="https://hooks.slack.com/services/XXXXXXX/YYYYYYY/ZZZZZZZ" />
        <?php
        }

        /**
         * Callback for the settings section
         */
        public function settings_section_callback()
        {
            echo 'Enter your Slack Webhook URL to receive detailed WooCommerce order notifications.';
        }

        /**
         * Add the settings page to the WordPress admin menu
         */
        public function add_settings_page()
        {
            add_options_page(
                'WC Slack Notifications',
                'WC Slack Notifications',
                'manage_options',
                'wc_slack_notif_settings',
                [$this, 'render_settings_page']
            );
        }

        /**
         * Render the settings page content
         */
        public function render_settings_page()
        {
        ?>
            <div class="wrap">
                <h1>WC Slack Notifications</h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('wc_slack_notif_settings');
                    do_settings_sections('wc_slack_notif_settings');
                    submit_button();
                    ?>
                </form>
            </div>
<?php
        }
    }

    // Initialize the plugin.
    new WC_Slack_Notifications();
}
