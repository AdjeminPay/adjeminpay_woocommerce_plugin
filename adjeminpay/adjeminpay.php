<?php
/**
* AdjeminPay.
*
* Plugin Name: AdjeminPay
* Plugin URI: http://adjeminpay.com/
* Description: AdjeminPay vous permet de recevoir des paiements mobile money et carte bancaire dans votre site woocommerce.
* Author: Adjemin
* Author URI: http://adjemin.com
* Version: 3.0.0
*
*/


if (!defined('ABSPATH')) {
    exit;
}

define( 'ADJEMINPAY_VERSION', '3.0.0' );
define( 'ADJEMINPAY_PLUGIN_DIR', dirname(__FILE__).'/' );
define( 'ADJEMINPAY_PLUGIN_URL', trailingslashit(plugin_dir_url( __FILE__ ).'/' ));

register_activation_hook(__FILE__, 'activate_adjeminpay');
function activate_adjeminpay(){
    // generate a CPT
    // $this->custom_post_type();
    // flush rewrite rules
    flush_rewrite_rules();

}

register_deactivation_hook(__FILE__, 'deactivate_adjeminpay');
function deactivate_adjeminpay(){
    // generate a CPT
    // $this->custom_post_type();
    // flush rewrite rules
    //delete_option('client_id');
    //delete_option('client_secret');
    flush_rewrite_rules();
}

function adp_unistall(){
    require_once ADJEMINPAY_PLUGIN_DIR.'uninstall.php';
}
register_uninstall_hook(__FILE__, 'adp_unistall');



// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}
/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 */
function adjeminpay_add_to_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_Adjeminpay';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'adjeminpay_add_to_gateways' );

/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function adjeminpay_plugin_links( $links ) {

    $plugin_links = array(
        // Woocommerce Config
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=adjeminpay' ) . '">' . __( 'Activer dans Woocommerce', 'adjeminpay' ) . '</a>',
        // Dashboard
        //'<a href="admin.php?page=adjeminpay">'. __('Dashboard').'</a>',
        // Settings
        //'<a href="admin.php?page=adp_menu_settings">'. __('Settings').'</a>',
    );

    return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'adjeminpay_plugin_links' );


/**
 * Offline Payment Gateway
 *
 * Provides an Offline Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		AdjeminPay
 * @extends		WC_Payment_Gateway
 * @version		3.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Adjemin
 */
add_action( 'plugins_loaded', 'adjeminpay_init', 11 );

function adjeminpay_init() {
    /**
     * WC_Gateway_Adjeminpay Class.
     */
    class WC_Gateway_Adjeminpay extends WC_Payment_Gateway
    {


        /**
         * Whether or not logging is enabled
         *
         * @var bool
         */
        public static $log_enabled = true;

        /**
         * Logger instance
         *
         * @var WC_Logger
         */
        public static $log = true;


        /**
         * Constructor for the gateway.
         */
        public function __construct() {
            // Setup general properties.
            $this->setup_properties();

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Get settings.
            $this->title              = $this->get_option( 'title' );
            $this->description        = $this->get_option( 'description' );
            $this->instructions       = $this->get_option( 'instructions' );

            $this->debug          = 'yes' === $this->get_option( 'debug', 'no' );

            $this->client_id       = $this->get_option( 'client_id' );
            $this->client_secret       = $this->get_option( 'client_secret' );

            self::$log_enabled    = $this->debug;

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
            add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );


            include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-adjeminpay-ipn-handler.php';
            new WC_Gateway_Adjeminpay_IPN_Handler();


            if ( 'yes' === $this->enabled ) {
                add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );
            }
        }

        /**
         * Setup general properties for the gateway.
         */
        protected function setup_properties() {
            $this->id                 = 'adjeminpay';
            $this->icon               = apply_filters('woocommerce_adjeminpay_icon', plugins_url('/assets/icon.png', __FILE__));
            $this->method_title       = __( 'AdjeminPay', 'adjeminpay' );
            $this->method_description = __( 'AdjeminPay vous permet de recevoir des paiements mobile money dans votre site woocommerce.', 'adjeminpay' );
            $this->has_fields         = false;
            $this->supports           = array(
                'products'
            );
        }

        /**
         * Initialise Gateway Settings Form Fields.
         */
        public function init_form_fields() {
            $this->form_fields = apply_filters('wc_offline_form_fields',array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'adjeminpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable AdjeminPay', 'adjeminpay' ),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title'       => __( 'Title', 'adjeminpay' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'adjeminpay' ),
                    'default'     => __( 'AdjeminPay', 'adjeminpay' ),
                    'desc_tip'    => true,
                ),

                'description' => array(
                    'title'       => __( 'Description', 'adjeminpay' ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', 'adjeminpay' ),
                    'default'     => __( 'Paiement avec votre Mobile Money ou Carte bancaire.', 'adjeminpay' ),
                    'desc_tip'    => true,
                ),

                'instructions' => array(
                    'title'       => __( 'Instructions', 'adjeminpay' ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', 'adjeminpay' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'client_id' => array(
                    'title'       => __( 'Client Id', 'adjeminpay' ),
                    'type'        => 'text',
                    'description' => __( 'Votre CLIENT ID', 'adjeminpay' )
                ),
                'client_secret' => array(
                    'title'       => __( 'Client Secret', 'adjeminpay' ),
                    'type'        => 'text',
                    'description' => __( 'Votre CLIENT SECRET', 'adjeminpay' )
                ),
                'debug' => array(
                    'title'   => __( 'Debug', 'adjeminpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Activé', 'adjeminpay' ),
                    'default' => 'no',
                    'desc_tip'    => false,
                ),
            ));
        }

        /**
         * Process the payment and return the result.
         *
         * @param  int $order_id Order ID.
         * @return array
         */
        public function process_payment( $order_id ) {

            include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-adjeminpay-request.php';

            $order  = wc_get_order( $order_id );

            try {

                $adjeminpay_request = new WC_Gateway_Adjeminpay_Request( $this );

                $approval_url = $adjeminpay_request->get_request_url( $order);

                return array(
                    'result'   => 'success',
                    'redirect' => $approval_url
                );
            }catch (Exception $ex) {

                wc_add_notice(  $ex->getMessage(), 'error' );

            }

            return array(
                'result' => 'failure',
                'redirect' => ''
            );

        }

        /**
         * Capture payment when the order is changed from on-hold to complete or processing
         *
         * @param  int $order_id Order ID.
         */
        public function capture_payment( $order_id ) {
            $order = wc_get_order( $order_id );

        }

        /**
         * Load admin scripts.
         *
         * @since 3.3.0
         */
        public function admin_scripts() {

        }

        /**
         * Custom AdjeminPay order received text.
         *
         * @since 3.9.0
         * @param string   $text Default text.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function order_received_text( $text, $order ) {
            if ( $order && $this->id === $order->get_payment_method() ) {
                return esc_html__( 'Thank you for your payment. Your transaction has been completed, and a receipt for your purchase.', 'adjeminpay' );
            }

            return $text;
        }

        /**
         * Check if this gateway is available in the user's country based on currency.
         *
         * @return bool
         */
        public function is_valid_for_use() {
            return in_array(
                get_woocommerce_currency(),
                apply_filters(
                    'woocommerce_adjeminpay_supported_currencies',
                    array('CFA' )
                ),
                true
            );
        }

        /**
         * Logging method.
         *
         * @param string $message Log message.
         * @param string $level Optional. Default 'info'. Possible values:
         *                      emergency|alert|critical|error|warning|notice|info|debug.
         */
        public static function log( $message, $level = 'info' ) {
            if ( self::$log_enabled ) {
                if ( empty( self::$log ) ) {
                    self::$log = wc_get_logger();
                }
                self::$log->log( $level, $message, array( 'source' => 'paypal' ) );
            }
        }

        /**
         * Processes and saves options.
         * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
         *
         * @return bool was anything saved?
         */
        public function process_admin_options() {
            $saved = parent::process_admin_options();

            // Maybe clear logs.
            if ( 'yes' !== $this->get_option( 'debug', 'no' ) ) {
                if ( empty( self::$log ) ) {
                    self::$log = wc_get_logger();
                }
                self::$log->clear( 'adjeminpay' );
            }

            return $saved;
        }



    }


}