<?php
/*
Plugin Name: WooCommerce Paycel Gateway
Description: Paycel Payment Gateway Integration for WooCommerce.
Version: 1.0
Author: Your Name
Text Domain: woocommerce-paycel
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

add_action('plugins_loaded', 'init_paycel_gateway');

function init_paycel_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Paycel extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'paycel';
            $this->icon = ''; // URL of the icon that will be displayed on checkout page.
            $this->has_fields = true; // If you need custom credit card form, set this to true.
            $this->method_title = __('Paycel', 'woocommerce-paycel');
            $this->method_description = __('Paycel Payment Gateway Integration for WooCommerce.', 'woocommerce-paycel');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            // Add actions.
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_response'));

            // Add payment gateway.
            add_filter('woocommerce_payment_gateways', array($this, 'add_paycel_gateway'));

            // Define hooks.
            $this->init_hooks();
        }

        // Define plugin settings.
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __('Enable/Disable', 'woocommerce-paycel'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable Paycel Payment Gateway', 'woocommerce-paycel'),
                    'default'     => 'yes'
                ),
                'title' => array(
                    'title'       => __('Title', 'woocommerce-paycel'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-paycel'),
                    'default'     => __('Paycel', 'woocommerce-paycel'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'woocommerce-paycel'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-paycel'),
                    'default'     => __('Pay via Paycel; you can pay with your credit card.', 'woocommerce-paycel'),
                ),
                'api_key' => array(
                    'title'       => __('API Key', 'woocommerce-paycel'),
                    'type'        => 'text',
                    'description' => __('Enter your Paycel API Key', 'woocommerce-paycel'),
                    'desc_tip'    => true,
                    'custom_attributes' => array(
                        'pattern' => '[a-zA-Z0-9]+'
                    ),
                    'validate'    => array($this, 'validate_api_key_field'),
                ),
                'api_secret' => array(
                    'title'       => __('API Secret', 'woocommerce-paycel'),
                    'type'        => 'password',
                    'description' => __('Enter your Paycel API Secret', 'woocommerce-paycel'),
                    'desc_tip'    => true,
                    'custom_attributes' => array(
                        'pattern' => '[a-zA-Z0-9]+'
                    ),
                    'validate'    => array($this, 'validate_api_secret_field'),
                ),
            );
        }

        // Validate API key field.
        public function validate_api_key_field($key) {
            $value = $_POST[$key];
            if (!empty($value) && !preg_match('/^[a-zA-Z0-9]+$/', $value)) {
                WC_Admin_Settings::add_error(__('Invalid API key format.', 'woocommerce-paycel'));
                return '';
            }
            return $value;
        }

        // Validate API secret field.
        public function validate_api_secret_field($key) {
            $value = $_POST[$key];
            if (!empty($value) && !preg_match('/^[a-zA-Z0-9]+$/', $value)) {
                WC_Admin_Settings::add_error(__('Invalid API secret format.', 'woocommerce-paycel'));
                return '';
            }
            return $value;
        }

        // Process the payment.
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            // Call Paycel API to process the payment.
            $response = $this->paycel_payment_request($order);

            if ($response['status'] == 'success') {
                $order->payment_complete();
                $order->add_order_note(__('Paycel payment successful.', 'woocommerce-paycel'));

                // Empty the cart.
                WC()->cart->empty_cart();

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            } else {
                // Payment failed, display error message to user
                wc_add_notice(__('Payment error: ', 'woocommerce-paycel') . $response['message'], 'error');
                return;
            }
        }

        // Payment request to Paycel API.
        private function paycel_payment_request($order) {
            // Implement API request to Paycel.
            $api_key = $this->get_option('api_key');
            $            api_secret = $this->get_option('api_secret');

            // Prepare request parameters.
            $params = array(
                'amount' => $order->get_total(),
                'currency' => get_woocommerce_currency(),
                'order_id' => $order->get_id(),
                // Add other necessary parameters
            );

            // Make the API request.
            $response = wp_remote_post('https://api.paycel.com/v1/payments', array(
                'method'    => 'POST',
                'body'      => json_encode($params),
                'headers'   => array(
                    'Authorization' => 'Bearer ' . base64_encode($api_key . ':' . $api_secret),
                    'Content-Type'  => 'application/json'
                ),
            ));

            if (is_wp_error($response)) {
                return array('status' => 'error', 'message' => $response->get_error_message());
            }

            $body = json_decode($response['body'], true);

            if ($body['status'] == 'success') {
                return array('status' => 'success', 'transaction_id' => $body['transaction_id']);
            } else {
                return array('status' => 'error', 'message' => $body['message']);
            }
        }

        // Add Paycel Gateway to WooCommerce.
        public function add_paycel_gateway($methods) {
            $methods[] = 'WC_Gateway_Paycel';
            return $methods;
        }
    }

    // Initialize the gateway
    function add_paycel_gateway($methods) {
        $methods[] = 'WC_Gateway_Paycel';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_paycel_gateway');
}

