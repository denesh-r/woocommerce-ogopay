<?php
/*
 * Plugin Name: WooCommerce OGOPay Gateway
 * Description: Take credit card payments on your store.
 * Author: Denesh Rajaratnam
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
    // Exit if accessed directly
}

// this custom parses does various actions related to the gateway depending on the page being loaded
add_action('parse_request', 'custom_parser');
function custom_parser() {

	global $wp;
	$url = $wp->request;

	// display the close modal page
    if ($url == 'ogopay_close_modal') {
		$file_path = plugin_dir_path(__FILE__ ) . 'close_modal.html';
		$response = file_get_contents($file_path);
		echo $response;
 	    exit();
	}

	// when redirected to the payment methods page after adding a card, 
	// display the appropriate notice based on the result
	if ($url == 'my-account/payment-methods') {
		if (isset($_GET['result'])) {
			if ($_GET['result'] == 'success') {
				wc_add_notice( 'Payment method successfully added.');
			} else {
				wc_add_notice('There was a problem adding this card. Reason: ' . $_GET['result'], 'error');
			}
		}
	}

	// when being redirected to the checkout page after a failed add card, 
	// display the error sent by the gateway
	if (strpos($url, "checkout") === 0) { // if url starts with checkout
        if (isset($_GET['result'])) {
			wc_add_notice('There was a problem with the payment. Reason: ' . $_GET['result'], 'error');
        }
	}
}

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'ogopay_add_gateway_class');
function ogopay_add_gateway_class($gateways)
{
    $gateways[] = 'WC_OGOPay_Gateway'; // your class name is here
    return $gateways;
}
 
/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'OGOPay_init_gateway_class');
function OGOPay_init_gateway_class()
{
	// Class that extends Token_CC to store the card mask
    class WC_Payment_Token_OGOToken extends WC_Payment_Token_CC
    {
        
		/** @protected string Token Type String */
		// not sure if this is the right way, but have to set as cc to make payment methods page work properly
        protected $type = 'cc';

        public function validate()
        {
            if (false === parent::validate()) {
                return false;
            }
            
            // add more validation here if needed
            return true;
        }
        
        public function get_card_mask()
        {
            return $this->get_meta('card_mask');
        }
        public function set_card_mask($card_mask)
        {
            $this->add_meta_data('card_mask', $card_mask, true);
        }
    }

	// main gateway class
    class WC_OGOPay_Gateway extends WC_Payment_Gateway
    {
 
        public function __construct()
        {
            $this->id = 'ogopay';
            $this->method_title = __('OGOPay', 'woocommerce');
            $this->method_description = __('OGOPay Payment Gateway', 'woocommerce');
            $this->has_fields = true;
            
            $this->supports = array(
                'products',
                'tokenization'
            );
            
            // Load the form fields
            $this->init_form_fields();
            
            // Load the settings.
            $this->init_settings();
            
            // Get setting values
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->secret_key = $this->get_option('secret_key');
                       
            
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            
            // We need custom JavaScript
            add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ));
            
			// when redirected back after a purchase transaction with a new card
			add_action('woocommerce_api_wc_gateway_ogopay', array( $this, 'handle_gateway_response' ));

			// when redirected back after adding a new card as a payment method from the payment methods page
			add_action('woocommerce_api_wc_gateway_ogopay_add_card', array( $this, 'handle_gateway_response_add_card' ));

			// an endpoint to return order details when an orderId is provided
			add_action('woocommerce_api_wc_gateway_ogopay_get_order_details', array( $this, 'get_order_details' ));
		}
		

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable OGOPay',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Credit card',
                    'desc_tip' => true
                ),
                
                'description' => array(
                    'title' => 'Description',
                    'type' => 'text',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay with your credit card via OGO PAY.',
                    'desc_tip' => true
                ),
                
                'merchant_id' => array(
                    'title' => 'Merchant ID',
                    'type' => 'text',
                    'description' => 'This is the Merchant ID provided by OGO PAY.',
                    'default' => ''
                ),
                
                'secret_key' => array(
                    'title' => 'Secret Key',
                    'type' => 'text',
                    'description' => 'This is the secret key provided by OGO PAY.',
                    'default' => ''
                ),
            );
        }
 
        /**
         * You will need it if you want your custom credit card form, Step 4 is about it
         */
        public function payment_fields()
        {
            $this->saved_payment_methods();
            
            echo '<div id="myModal" class="modal">';
            echo '<div class="modal-content">';
            echo '<div id="modalClose" class="close">&times;</div>';
            echo '<div id="cont"></div>';
            echo '</div>';
            echo '</div>';

            // By default we are always going to tokenize card details.
            // So no need to explicitly ask to save card or not
            // In the future if this condition changes,
            // We'll have to uncomment the below line and make changes accordingly
            // $this->save_payment_method_checkbox();
        }
 
        /*
         * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
         */
        public function payment_scripts()
        {
            // we need JavaScript to process a token only on checkout and add payment method pages
            if (! is_checkout() && ! is_add_payment_method_page()) {
                return;
            }
 
            // // if our payment gateway is disabled, we do not have to enqueue JS too
            if ('no' === $this->enabled) {
                return;
            }
 
            // // no reason to enqueue JavaScript if API keys are not set
            if (empty($this->secret_key)) {
                return;
            }
 
            // // do not work with card details without SSL unless your website is in a test mode
            // if (! $this->testmode && ! is_ssl()) {
            //     return;
            // }
          
            if (is_checkout()) {
				// in checkout page, tell ogopay.js endpoint to get order details from
                $ogopay_params['mode'] = 'checkout';
                $ogopay_params['url'] = WC()->api_request_url('WC_Gateway_OGOPAY_get_order_details');

			} elseif (is_add_payment_method_page()) {
			 
				// in add payment method page, tell ogopay.js the params to send to the iframe
				$ogopay_params = array(
					'orderId' => 'add-card-' . time(),
					'customerId' => strval(wp_get_current_user()->id),
					'merchantId' => $this->merchant_id,
					'amount' => '100',
					'time' => strval(time()),
					'returnUrl' => urlencode(WC()->api_request_url('WC_Gateway_OGOPAY_add_card'))
				);
				
				$hash = $this->generateHash($ogopay_params);
				$ogopay_params['hash'] = $hash;
				$ogopay_params['mode'] = 'add-card';
            }

			// This is our custom JS in our plugin directory
			wp_register_script('woocommerce_ogopay', plugins_url('ogopay.js', __FILE__));
            wp_localize_script('woocommerce_ogopay', 'ogopay_params', $ogopay_params);

            wp_enqueue_script('zoid', 'https://ogo-hosted-pages.s3.amazonaws.com/zoid.js');
            wp_enqueue_script('woocommerce_ogopay');
            
            wp_register_style('ogopay-style', plugins_url('ogopay.css', __FILE__));
            wp_enqueue_style('ogopay-style');
        }
 
        // public function validate_fields() {
        // }
 
        /*
         * We're processing the payments here, everything about it is in Step 5
         */
        public function process_payment($order_id)
        {

            if ($_POST['wc-ogopay-payment-token'] == 'new') {
				// user chose to pay with a new card
				// do a redirect to change the location to trigger ogopay.js to show the dialog to pay with a new card
                return array(
                    'result' => 'success',
                    'redirect' => '#' . time() . '.' . $order_id
                );
                exit();
                            
            } else {
				// paying with an existing token
                // get the token and perform tokenized purchase transaction
                $token_id = $_POST['wc-ogopay-payment-token'];
                $token = WC_Payment_Tokens::get($token_id);
                $token_string = $token->get_token();
                
                $order = wc_get_order($order_id);
                $orderTotal = str_replace(".", "", strVal($order->total));
                
                $body = array(
                    'amount' => intVal($orderTotal),
                    'token' => $token_string,
                    'orderId' => strVal($order_id),
                    'customerId' => strVal($order->customer_id),
                );

                $isoDateTime = date('c');
                $jsonString = json_encode($body);
                $strToSign = $isoDateTime."\n".$jsonString;
                $hmac = hash_hmac('sha256', $strToSign, $this->secret_key, true);
                $hash = base64_encode($hmac);

                $args = array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Date' => $isoDateTime,
                        'Authorization' => 'OGO ' . $this->merchant_id . ":" . $hash
                        ),
                    'body' => $jsonString,
                    'timeout' => 600
                );

				$url = "https://test-ipg.ogo.exchange/purchase";
				// $url = "http://localhost:3000/purchase";
                $response = wp_remote_post($url, $args);

                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                } else {
					//convert our response body to object
					$response_body = json_decode($response['body'], true);
					$response_result = $response_body['result'];
					$transactionId = $response_result['transactionId'];
					$transactionDetails = json_encode($response_result);

					if ($response_body['success'] == true){

						$order->payment_complete($transactionId);
						$order->add_order_note($transactionDetails);

						return array(
							'result'   => 'success',
							'redirect' => $this->get_return_url( $order )
						);

					} else {

						$order->update_status('failed');
						$order->add_order_note($transactionDetails);

						wc_add_notice( $response_body['message'], 'error' );
		
						return array(
							'result'   => 'fail',
							'redirect' => ''
						);
					}
                }

            }

        }

        /**
        * This method is called when the user clicks on the add new payment method option
        *
        */
        // public function add_payment_method()
        // {
			// do a redirect to change the location to trigger ogopay.js to show the dialog to pay with a new card
			// $time = time();
			// return array(
			// 	'result' => 'redirect',
			// 	'redirect' => '#' . time()
			// );
			// exit();
        // }

		// Endpoint that responds with order details from orderId passed via request
        public function get_order_details()
        {
            $order_id = $_REQUEST['orderId'];
            $order = wc_get_order($order_id);

            $amount = str_replace(".", "", $order->total);
            $customerId = $order->customer_id;
            $merchantId = $this->merchant_id;
            $time = time();

            $orderDetails = array(
                'orderId' => strval($order_id),
                'customerId' => strval($customerId),
                'merchantId' => $merchantId,
                'amount' => strval($amount),
                'time' => strval($time),
                'returnUrl' => urlencode(WC()->api_request_url('WC_Gateway_OGOPAY'))
            );

            $hash = $this->generateHash($orderDetails);
            $orderDetails['hash'] = $hash;

            wp_send_json($orderDetails);
        }

        /**
        * This hook is called when the user gets redirected back to wordpress
		* from a purchase order transaction with a new card gone through 3DS from the payment gateway
		* 
        * This happens inside the modal
        */
        public function handle_gateway_response()
        {
            $order_id = $_REQUEST['orderId'];
            $order = wc_get_order($order_id);

            // if (($_REQUEST['success'] == 'true') && ($order->get_total() == $_REQUEST['amount'])) {
            if ($_REQUEST['success'] == 'true') {
				$order->payment_complete($_REQUEST['transactionId']);
				$order->add_order_note($_REQUEST['transactionDetails']);
				$this->saveCardToken();
				$checkout_url = urlencode($order->get_checkout_order_received_url());

            } else {
				$checkout_url = urlencode( add_query_arg('result', $_REQUEST['message'], $order->get_checkout_payment_url()));
				// $checkout_url = urlencode( add_query_arg('result', $_REQUEST['message'], wc_get_checkout_url()));
                $order->update_status('failed');
                $order->add_order_note('Payment Transaction Failed');
			}
			
			// redirect to our custom page that closes the modal and redirects the page to the given url
			wp_safe_redirect(add_query_arg( array(
				'mode' => 'checkout',
				'url' => $checkout_url
			), get_site_url() . '/ogopay_close_modal'));
        }

        /**
        * This method is called when the user gets redirected back to wordpress
        * from an add new card transaction with the payment gateway
		*
		* This happens inside the modal
        */
        public function handle_gateway_response_add_card()
        {
            if ($_REQUEST['success'] == 'true') {
				$this->saveCardToken();
				// create payment method success url
				$payment_method_url = urlencode( add_query_arg('result', 'success', wc_get_account_endpoint_url('payment-methods')));

            } else {
				// create payment method failed url
				$payment_method_url = urlencode( add_query_arg('result', $_REQUEST['message'], wc_get_account_endpoint_url('payment-methods')));
			}
			
			// redirect to our custom page that closes the modal and redirects the page to the given url
			wp_safe_redirect(add_query_arg( array(
				'mode' => 'add-card',
				'url' => $payment_method_url
			), get_site_url() . '/ogopay_close_modal'));

        }
		
        private function base64url_encode($data)
        {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        }

        private function base64url_decode($data)
        {
            return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
        }

        private function generateHash($data_array)
        {
            ksort($data_array);	// alphabetically sort keys
            $params = json_encode($data_array); // convert array to json

            $hashed_params = hash_hmac('sha256', $params, $this->secret_key, true);
            $encoded_hashed_params = $this->base64url_encode($hashed_params);

            return $encoded_hashed_params;
        }

        /**
        * Validate if we actually have all the data we need.
        * Saves the given card token under the users
        */
        private function saveCardToken()
        {
            $card_token = $_REQUEST['token'];
            $cardMask = $_REQUEST['cardMask'];
            $last4 = substr($cardMask, -4);
            $expiryMonth = $_REQUEST['expiryMonth'];
            $expiryYear = $_REQUEST['expiryYear'];
            $cardType = $_REQUEST['cardType'];
            $customerId = $_REQUEST['customerId'];
            
            if ($cardType == "V") {
                $cardType = "Visa";
            }
            if ($cardType == "M") {
                $cardType = "Master Card";
            }
            if ($cardType == "U") {
                $cardType = "Union Pay";
            }


            $token = new WC_Payment_Token_OGOToken();
            $token->set_token($card_token);
            $token->set_gateway_id($this->id);
            $token->set_card_mask($cardMask);
            $token->set_last4($last4);
            $token->set_card_type(strtolower($cardType));
            $token->set_expiry_month($expiryMonth);
            $token->set_expiry_year('20' . $expiryYear);
            $token->set_user_id($customerId);
            $token->save();
        }
    }
}
