<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WC_WooMercadoPago_Notification_IPN
 */
class WC_WooMercadoPago_Notification_IPN extends WC_WooMercadoPago_Notification_Abstract
{
    /**
     * WC_WooMercadoPago_Notification_IPN constructor.
     * @param $payment
     */
    public function __construct($payment)
    {
        parent::__construct($payment);
    }

    /**
     *  IPN
     */
    public function check_ipn_response()
    {
        parent::check_ipn_response();
        $data = $_GET;
        //IPN coupon mode
        if (isset($data['coupon_id']) && !empty($data['coupon_id'])) {
            if (isset($data['payer']) && !empty($data['payer'])) {
                $response = $this->mp->check_discount_campaigns($data['amount'], $data['payer'], $data['coupon_id']);
                header('HTTP/1.1 200 OK');
                header('Content-Type: application/json');
                echo json_encode($response);
            } else {
                $obj = new stdClass();
                $obj->status = 404;
                $obj->response = array(
                    'message' => __('Please enter your email address at the billing address to use this service', 'woocommerce-mercadopago'),
                    'error' => 'payer_not_found',
                    'status' => 404,
                    'cause' => array()
                );
                header('HTTP/1.1 200 OK');
                header('Content-Type: application/json');
                echo json_encode($obj);
            }
            exit(0);
        //IPN payment mode 
        } else if (isset($data['data_id']) && isset($data['type'])) {
            header('HTTP/1.1 200 OK');
            $access_token = array('access_token' => $this->mp->get_access_token());
            if ($data['type'] == 'payment') {
                $payment_info = $this->mp->get('/v1/payments/' . $data['data_id'], $access_token, false);
                if (!is_wp_error($payment_info) && ($payment_info['status'] == 200 || $payment_info['status'] == 201)) {
                    if ($payment_info['response']) {
                        header('HTTP/1.1 200 OK');
                        do_action('valid_mercadopago_ipn_request', $payment_info['response']);
                    }
                } else {
                    $this->log->write_log(__FUNCTION__, 'error when processing received data: ' . json_encode($payment_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        //IPN merchant order mode
        } else if (isset($data['id']) && isset($data['topic'])) {
             if ($data['topic'] == 'payment' || $data['topic'] != 'merchant_order') {
                $this->log->write_log(__FUNCTION__, 'request failure, invalid topic.');
                wp_die(__('The Mercado Pago request has failed', 'woocommerce-mercadopago'),'', array( 'response' => 422 ));
            }
            $access_token = array('access_token' => $this->mp->get_access_token());
            if ($data['topic'] == 'merchant_order') {
                $ipn_info = $this->mp->get('/merchant_orders/' . $data['id'], $access_token, false);

                if (is_wp_error($ipn_info) || ($ipn_info['status'] != 200 && $ipn_info['status'] != 201)) {
                    $this->log->write_log(__FUNCTION__, 'got status not equal 200: ' . json_encode($ipn_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }

                $payments = $ipn_info['response']['payments'];
                if (sizeof($payments) >= 1) {
                    $ipn_info['response']['ipn_type'] = 'merchant_order';
                    do_action('valid_mercadopago_ipn_request', $ipn_info['response']);
                } else {
                    $this->log->write_log(__FUNCTION__, 'order received but has no payment.');
                }
                header('HTTP/1.1 200 OK');
            }
        } else {
            $this->log->write_log(__FUNCTION__, 'request failure, received ipn call with no data.');
            wp_die(__('The Mercado Pago request has failed', 'woocommerce-mercadopago'),'', array( 'response' => 422 ));
        }
    }

    /**
     * @param $data
     * @return bool|void|WC_Order|WC_Order_Refund
     * @throws WC_Data_Exception
     */
	public function successful_request($data)
	{
		try {
			$order = parent::successful_request($data);
			$processed_status = $this->process_status_mp_business($data, $order);
			$this->log->write_log(__FUNCTION__, 'Changing order status to: ' . parent::get_wc_status_for_mp_status(str_replace('_', '', $processed_status)));
			$this->proccessStatus($processed_status, $data, $order);
		} catch (Exception $e) {
			$this->log->write_log(__FUNCTION__, $e->getMessage());
		}
	}

    /**
     * @param $data
     * @param $order
     * @return string
     */
    public function process_status_mp_business($data, $order) 
    {
        if (isset($data['payments'])){
            return $this->process_status_mp_business_merchant_order($data,$order);
        } else {
            return $this->process_status_mp_business_payment($data,$order);
        }
    }

    /**
     * @param $data
     * @param $order
     * @return string
     */
    public function process_status_mp_business_merchant_order($data, $order)
    {
        $status = 'pending';
        $payments = $data['payments'];
        if (sizeof($payments) == 1) {
            // If we have only one payment, just set status as its status
            $status = $payments[0]['status'];
        } elseif (sizeof($payments) > 1) {
            // However, if we have multiple payments, the overall payment have some rules...
            $total_paid = 0.00;
            $total_refund = 0.00;
            $total = $data['shipping_cost'] + $data['total_amount'];
            // Grab some information...
            foreach ($data['payments'] as $payment) {
                if ($payment['status'] === 'approved') {
                    // Get the total paid amount, considering only approved incomings.
                    $total_paid += (float)$payment['total_paid_amount'];
                } elseif ($payment['status'] === 'refunded') {
                    // Get the total refounded amount.
                    $total_refund += (float)$payment['amount_refunded'];
                }
            }
            if ($total_paid >= $total) {
                $status = 'approved';
            } elseif ($total_refund >= $total) {
                $status = 'refunded';
            } else {
                $status = 'pending';
            }
        }
        // WooCommerce 3.0 or later.
        if (method_exists($order, 'update_meta_data')) {
            // Updates the type of gateway.
            $order->update_meta_data('_used_gateway', 'WC_WooMercadoPago_BasicGateway');
            if (!empty($data['payer']['email'])) {
                $order->update_meta_data(__('Buyer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                $order->update_meta_data(__('Payment method', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            if (!empty($data['payments'])) {
                $payment_ids = array();
                foreach ($data['payments'] as $payment) {
                    $payment_ids[] = $payment['id'];
                    $order->update_meta_data('Mercado Pago - Payment ' . $payment['id'],
                        '[Date ' . date('Y-m-d H:i:s', strtotime($payment['date_created'])) .
                        ']/[Amount ' . $payment['transaction_amount'] .
                        ']/[Paid ' . $payment['total_paid_amount'] .
                        ']/[Refund ' . $payment['amount_refunded'] . ']'
                    );
                }
                if (sizeof($payment_ids) > 0) {
                    $order->update_meta_data('_Mercado_Pago_Payment_IDs', implode(', ', $payment_ids));
                }
            }
            $order->save();
        } else {
            // Updates the type of gateway.
            update_post_meta($order->id, '_used_gateway', 'WC_WooMercadoPago_BasicGateway');
            if (!empty($data['payer']['email'])) {
                update_post_meta($order->id, __('Buyer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                update_post_meta($order->id, __('Payment method', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            if (!empty($data['payments'])) {
                $payment_ids = array();
                foreach ($data['payments'] as $payment) {
                    $payment_ids[] = $payment['id'];
                    update_post_meta(
                        $order->id,
                        'Mercado Pago - Payment ' . $payment['id'],
                        '[Date ' . date('Y-m-d H:i:s', strtotime($payment['date_created'])) .
                        ']/[Amount ' . $payment['transaction_amount'] .
                        ']/[Paid ' . $payment['total_paid_amount'] .
                        ']/[Refund ' . $payment['amount_refunded'] . ']'
                    );
                }
                if (sizeof($payment_ids) > 0) {
                    update_post_meta($order->id, '_Mercado_Pago_Payment_IDs', implode(', ', $payment_ids));
                }
            }
        }
        return $status;
    }

    /**
     * @param $data
     * @param $order
     * @return string
     */
    public function process_status_mp_business_payment($data, $order)
    {
        $status = isset($data['status']) ? $data['status'] : 'pending';
        $total_paid = isset($data['transaction_details']['total_paid_amount']) ? $data['transaction_details']['total_paid_amount'] : 0.00;
        $total_refund = isset($data['transaction_amount_refunded']) ? $data['transaction_amount_refunded'] : 0.00;
        // WooCommerce 3.0 or later.
        if (method_exists($order, 'update_meta_data')) {
            // Updates the type of gateway.
            $order->update_meta_data('_used_gateway', get_class($this));
            if (!empty($data['payer']['email'])) {
                $order->update_meta_data(__('Buyer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                $order->update_meta_data(__('Payment method', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            $order->update_meta_data(
                'Mercado Pago - Payment ' . $data['id'],
                '[Date ' . date('Y-m-d H:i:s', strtotime($data['date_created'])) .
                ']/[Amount ' . $data['transaction_amount'] .
                ']/[Paid ' . $total_paid .
                ']/[Refund ' . $total_refund . ']'
            );
            $order->update_meta_data('_Mercado_Pago_Payment_IDs', $data['id']);
            $order->save();
        } else {
            // Updates the type of gateway.
            update_post_meta($order->id, '_used_gateway', get_class($this));
            if (!empty($data['payer']['email'])) {
                update_post_meta($order->id, __('Buyer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                update_post_meta($order->id, __('Payment method', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            update_post_meta(
                $order->id,
                'Mercado Pago - Payment ' . $data['id'],
                '[Date ' . date('Y-m-d H:i:s', strtotime($data['date_created'])) .
                ']/[Amount ' . $data['transaction_amount'] .
                ']/[Paid ' . $total_paid .
                ']/[Refund ' . $total_refund . ']'
            );
            update_post_meta($order->id, '_Mercado_Pago_Payment_IDs', $data['id']);
        }
        return $status;
    }
}
