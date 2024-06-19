<?php
/**
* Plugin Name: WooCommerce Sale Notifications for Discord
* Plugin URI: https://github.com/Cral-Cactus/woocommerce-discord-sale-notifications
* Description: Sends a notification to a Discord channel when a sale is made on Woocommerce.
* Version: 1.1
* Author: Cral_Cactus
* Author URI: https://github.com/Cral-Cactus
* Requires Plugins: woocommerce
* Requires at least: 6.2
* WC requires at least: 8.5
* WC tested up to: 8.9
*/

if (!defined('ABSPATH')) {
    exit;
}

class WC_Discord_Sale_Notifications {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('woocommerce_thankyou', array($this, 'send_discord_notification'));
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Discord Notifications', 'wc-discord-notifications'),
            __('Discord Notifications', 'wc-discord-notifications'),
            'manage_options',
            'wc-discord-notifications',
            array($this, 'notification_settings_page')
        );
    }

    public function register_settings() {
        register_setting('wc_discord_notifications', 'discord_webhook_url');
        register_setting('wc_discord_notifications', 'discord_order_statuses');
        register_setting('wc_discord_notifications', 'discord_status_webhooks');
        register_setting('wc_discord_notifications', 'discord_status_colors');

        add_settings_section(
            'wc_discord_notifications_section',
            __('Discord Webhook Settings', 'wc-discord-notifications'),
            null,
            'wc_discord_notifications'
        );

        add_settings_field(
            'discord_webhook_url',
            __('Discord Webhook URL', 'wc-discord-notifications'),
            array($this, 'discord_webhook_url_callback'),
            'wc_discord_notifications',
            'wc_discord_notifications_section'
        );

        add_settings_field(
            'discord_order_statuses',
            __('Order Status Notifications', 'wc-discord-notifications'),
            array($this, 'discord_order_statuses_callback'),
            'wc_discord_notifications',
            'wc_discord_notifications_section'
        );
    }

    public function discord_webhook_url_callback() {
        $webhook_url = get_option('discord_webhook_url');
        echo '<input type="text" name="discord_webhook_url" value="' . esc_attr($webhook_url) . '" size="50" />';
    }

    public function discord_order_statuses_callback() {
        $order_statuses = wc_get_order_statuses();
        $selected_statuses = get_option('discord_order_statuses', []);
        $status_webhooks = get_option('discord_status_webhooks', []);
        $status_colors = get_option('discord_status_colors', []);

        foreach ($order_statuses as $status => $label) {
            $checked = in_array($status, $selected_statuses) ? 'checked' : '';
            $webhook = isset($status_webhooks[$status]) ? esc_attr($status_webhooks[$status]) : '';
            $color = isset($status_colors[$status]) ? esc_attr($status_colors[$status]) : '#ffffff';

            echo '<p style="margin-bottom: 10px;">';
            echo '<label style="margin-right: 10px;">';
            echo '<input type="checkbox" name="discord_order_statuses[]" value="' . esc_attr($status) . '" ' . $checked . '>';
            echo ' ' . esc_html($label);
            echo '</label>';
            echo '<input type="text" name="discord_status_webhooks[' . esc_attr($status) . ']" value="' . $webhook . '" placeholder="Webhook URL (optional)" size="50">';
            echo '<input type="color" name="discord_status_colors[' . esc_attr($status) . ']" value="' . $color . '" style="margin-left: 10px;">';
            echo '</p>';
        }
        echo '<style>
                @media (max-width: 767px) {
                    input[name^="discord_status_webhooks"], input[name^="discord_status_colors"] {
                        margin-top: 5px !important;
                    }
                }
              </style>';
    }

    public function notification_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Discord Sale Notifications', 'wc-discord-notifications'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_discord_notifications');
                do_settings_sections('wc_discord_notifications');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function send_discord_notification($order_id) {
        $selected_statuses = get_option('discord_order_statuses', []);
        $status_webhooks = get_option('discord_status_webhooks', []);
        $status_colors = get_option('discord_status_colors', []);
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $order_status = 'wc-' . $order->get_status();

        if (!in_array($order_status, $selected_statuses)) {
            return;
        }

        $webhook_url = !empty($status_webhooks[$order_status]) ? $status_webhooks[$order_status] : get_option('discord_webhook_url');
        $embed_color = !empty($status_colors[$order_status]) ? hexdec(substr($status_colors[$order_status], 1)) : hexdec(substr('#ffffff', 1));

        if (!$webhook_url) {
            return;
        }

        $order_data = $order->get_data();
        $order_total = $order_data['total'];
        $order_currency = $order_data['currency'];
        $order_date = $order_data['date_created']->date('Y-m-d H:i:s');
        $order_number = $order_data['id'];
        $order_email = $order_data['billing']['email'];
        $payment_method = $order_data['payment_method_title'];
        $order_items = $order->get_items();
        $items_list = '';

        foreach ($order_items as $item) {
            $items_list .= $item->get_name() . ', ';
        }
        $items_list = rtrim($items_list, ', ');

        $embed = [
            'title' => '🎉 New Sale!',
            'fields' => [
                ['name' => 'Order Number', 'value' => $order_number, 'inline' => true],
                ['name' => 'Order Date', 'value' => $order_date, 'inline' => true],
                ['name' => 'Order Total', 'value' => $order_total . ' ' . $order_currency, 'inline' => true],
                ['name' => 'Items', 'value' => $items_list],
                ['name' => 'Email', 'value' => $order_email, 'inline' => true],
                ['name' => 'Payment Method', 'value' => $payment_method, 'inline' => true]
            ],
            'color' => $embed_color
        ];

        $this->send_to_discord($webhook_url, $embed);
    }

    private function send_to_discord($webhook_url, $embed) {
        $data = json_encode(['embeds' => [$embed]]);

        $args = [
            'body'        => $data,
            'headers'     => ['Content-Type' => 'application/json'],
            'timeout'     => 60,
        ];

        wp_remote_post($webhook_url, $args);
    }
}

new WC_Discord_Sale_Notifications();