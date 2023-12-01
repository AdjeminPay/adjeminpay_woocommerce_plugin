<?php
/**
 * Handles responses from AdjeminPay IPN.
 *
 * @package WooCommerce\AdjeminPay
 * @version 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/class-wc-gateway-adjeminpay-response.php';

/**
 * WC_Gateway_Adjeminpay_IPN_Handler class.
 */
class WC_Gateway_Adjeminpay_IPN_Handler extends WC_Gateway_Adjeminpay_Response {

    /**
     * Constructor.
     *
     */
    public function __construct() {
        add_action( 'woocommerce_api_wc_gateway_adjeminpay', array( $this, 'check_response' ) );
        add_action( 'valid-adjeminpay-standard-ipn-request', array( $this, 'valid_response' ) );
    }

    /**
     * Check for AdjeminPay IPN Response.
     */
    public function check_response() {
        if ( ! empty( $_POST )) { // WPCS: CSRF ok.
            $posted = wp_unslash( $_POST ); // WPCS: CSRF ok, input var ok.

            // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
            do_action( 'valid-adjeminpay-standard-ipn-request', $posted );
            exit;
        }

        wp_die( 'AdjeminPay IPN Request Failure', 'AdjeminPay IPN', array( 'response' => 500 ) );
    }

    /**
     * There was a valid response.
     *
     * @param  array $posted Post data after wp_unslash.
     */
    public function valid_response( $posted ) {
        $order = ! empty( $posted['items'] ) ? $this->get_adjeminpay_order( $posted['items'] ) : false;

        if ( $order ) {

            WC_Gateway_Adjeminpay::log( 'Found order #' . $order->get_id() );
            WC_Gateway_Paypal::log( 'Payment status: ' . $posted['status'] );

            $this->payment_status_completed($order, $posted);


        }else{

            $response = [
                'message' => empty($posted['items'])? "items not available in params": '',
                'params' => $posted
            ];
            wp_send_json($response,500);
            exit;
        }
    }


    /**
     * Check currency from IPN matches the order.
     *
     * @param WC_Order $order    Order object.
     * @param string   $currency Currency code.
     */
    protected function validate_currency( $order, $currency ) {
        if ( $order->get_currency() !== $currency ) {
            WC_Gateway_Paypal::log( 'Payment error: Currencies do not match (sent "' . $order->get_currency() . '" | returned "' . $currency . '")' );

            /* translators: %s: currency code. */
            $order->update_status( 'on-hold', sprintf( __( 'Validation error: AdjeminPay currencies do not match (code %s).', 'adjeminpay' ), $currency ) );
            exit;
        }
    }

    /**
     * Check payment amount from IPN matches the order.
     *
     * @param WC_Order $order  Order object.
     * @param int      $amount Amount to validate.
     */
    protected function validate_amount( $order, $amount ) {
        if ( number_format( $order->get_total(), 2, '.', '' ) !== number_format( $amount, 2, '.', '' ) ) {
            WC_Gateway_Paypal::log( 'Payment error: Amounts do not match (gross ' . $amount . ')' );

            /* translators: %s: Amount. */
            $order->update_status( 'on-hold', sprintf( __( 'Validation error: AdjeminPay amounts do not match (gross %s).', 'adjeminpay' ), $amount ) );
            exit;
        }
    }


    /**
     * Handle a completed payment.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_completed( $order, $posted ) {
        if ( $order->has_status( wc_get_is_paid_statuses() ) ) {
            WC_Gateway_Paypal::log( 'Aborting, Order #' . $order->get_id() . ' is already complete.' );
            exit;
        }

        include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-adjeminpay-request.php';
        $adjeminpay_request = new WC_Gateway_Adjeminpay_Request( $this );

        $status = $adjeminpay_request->get_payment_status($posted['merchant_trans_id']);

        if($status != $posted['status']){
            WC_Gateway_Paypal::log( 'Fraud found Order #' . $order->get_id()  );
            exit;
        }

        //$this->validate_currency( $order, $posted['mc_currency'] );
        //$this->validate_amount( $order, $posted['mc_gross'] );
        $this->save_adjeminpay_meta_data( $order, $posted );


        $response = [];

        if ( 'SUCCESSFUL' === $posted['status'] ) {
            if ( $order->has_status( 'cancelled' ) ) {
                $this->payment_status_paid_cancelled_order( $order, $posted );
            }

            $this->payment_complete( $order, ( ! empty( $posted['merchant_trans_id'] ) ? wc_clean( $posted['merchant_trans_id'] ) : '' ), __( 'IPN payment completed', 'adjeminpay' ) );
            do_action( 'woocommerce_cart_emptied', true);
            $response = array(
                'code' => "OK",
                'merchant_trans_id' => $posted['merchant_trans_id'],
                'status' => "SUCCESSFUL",
                'message' => ">>> SUCCESSFUL"
            );
        }else if('EXPIRED' === $posted['status']){
            $this->payment_status_expired( $order, $posted );
            $response = array(
                'code' => "OK",
                'merchant_trans_id' => $posted['merchant_trans_id'],
                'status' => "EXPIRED",
                'message' => ">>> EXPIRED"
            );
        }else if('CANCELLED' === $posted['status']){
            $this->payment_status_cancelled( $order, $posted );
            $response = array(
                'code' => "OK",
                'merchant_trans_id' => $posted['merchant_trans_id'],
                'status' => "CANCELLED",
                'message' => ">>> CANCELLED"
            );

        }else if('FAILED' === $posted['status']){
            $this->payment_status_failed( $order, $posted );
            $response = array(
                'code' => "OK",
                'merchant_trans_id' => $posted['merchant_trans_id'],
                'status' => "FAILED",
                'message' => ">>> FAILED"
            );
        }else{

            $response = array(
                'code' => "OK",
                'merchant_trans_id' => $posted['merchant_trans_id'],
                'status' => $posted['status'],
                'message' => ">>> ".$posted['status']
            );

        }

        wp_send_json($response);
        exit;
    }

    /**
     * Handle a cancelled payment.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_cancelled( $order, $posted ) {
        $this->payment_status_failed( $order, $posted );
    }

    /**
     * Handle a failed payment.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_failed( $order, $posted ) {
        /* translators: %s: payment status. */
        $order->update_status( 'failed', sprintf( __( 'Payment %s via IPN.', 'adjeminpay' ), wc_clean( $posted['status'] ) ) );
    }

    /**
     * Handle a denied payment.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_denied( $order, $posted ) {
        $this->payment_status_failed( $order, $posted );
    }

    /**
     * Handle an expired payment.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_expired( $order, $posted ) {
        $this->payment_status_failed( $order, $posted );
    }


    /**
     * When a user cancelled order is marked paid.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_paid_cancelled_order( $order, $posted ) {
        // $this->send_ipn_email_notification(
        /* translators: %s: order link. */
        //   sprintf( __( 'Payment for cancelled order %s received', 'adjeminpay' ), '<a class="link" href="' . esc_url( $order->get_edit_order_url() ) . '">' . $order->get_order_number() . '</a>' ),
        /* translators: %s: order ID. */
        // sprintf( __( 'Order #%s has been marked paid by AdjeminPay IPN, but was previously cancelled. Admin handling required.', 'adjeminpay' ), $order->get_order_number() )
        //);
    }


    /**
     * Save important data from the IPN to the order.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function save_adjeminpay_meta_data( $order, $posted ) {

        if ( ! empty( $posted['merchant_trans_id'] ) ) {
            update_post_meta( $order->get_id(), '_merchant_trans_id', wc_clean( $posted['merchant_trans_id'] ) );
        }
        if ( ! empty( $posted['status'] ) ) {
            update_post_meta( $order->get_id(), '_adjeminpay_status', wc_clean( $posted['status'] ) );
        }
    }

    /**
     * Send a notification to the user handling orders.
     *
     * @param string $subject Email subject.
     * @param string $message Email message.
     */
    /*protected function send_ipn_email_notification( $subject, $message ) {
        $new_order_settings = get_option( 'woocommerce_new_order_settings', array() );
        $mailer             = WC()->mailer();
        $message            = $mailer->wrap_message( $subject, $message );

        $adjeminpay_adjeminpay_settings = get_option( 'woocommerce_adjeminpay_settings' );
        if ( ! empty( $woocommerce_adjeminpay_settings['ipn_notification'] ) && 'no' === $woocommerce_adjeminpay_settings['ipn_notification'] ) {
            return;
        }

        $mailer->send( ! empty( $new_order_settings['recipient'] ) ? $new_order_settings['recipient'] : get_option( 'admin_email' ), strip_tags( $subject ), $message );
    }*/
}