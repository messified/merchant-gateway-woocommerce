<?php
// require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter( 'woocommerce_payment_gateways', 'misha_add_gateway_class' );
function misha_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Merchant_Gateway'; // your class name is here
	return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action( 'plugins_loaded', 'misha_init_gateway_class' );
function misha_init_gateway_class() {

	class WC_Merchant_Gateway extends WC_Payment_Gateway {

 		/**
 		 * Class constructor, more about it in Step 3
 		 */
 		public function __construct() {
            $this->id = 'paypro_merchant_gateway'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->supports[] = 'default_credit_card_form';
            $this->method_title = 'Merchant Gateway';
            $this->method_description = 'Process credit card transactions via the Secure Merchant Gateway.'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->test_mode = 'yes' === $this->get_option( 'test_mode' );
            // $this->private_key = $this->testmode ? $this->get_option( 'test_private_key' ) : $this->get_option( 'private_key' );
            $this->merchant_token = $this->get_option( 'merchant_token' );

            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            // We need custom JavaScript to obtain a token
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

            add_action( 'admin_notices', array( $this,  'do_ssl_check' ) );

            // You can also register a webhook here
            // add_action( 'woocommerce_api_{webhook name}', array( $this, 'webhook' ) );
 		}

		/**
 		 * Plugin options, we deal with it in Step 3 too
 		 */
 		public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Merchant Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'test_mode' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'yes'
                ),
                'title' => array(
                    'title'       => 'Secure Merchant Gateway',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Secure Gateway',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'CC Processor',
                ),
                'merchant_token' => array(
                    'title'       => 'Merchant Token',
                    'type'        => 'text'
                )
            );
	 	}

		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
            // ok, let's display some description before the payment form
            if ( $this->description ) {
                // you can instructions for test mode, I mean test card numbers etc.
                // if ( $this->testmode ) {
                //     $this->description .= " TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href='#' target='_blank' rel='noopener noreferrer'>documentation</a>.";
                //     $this->description  = trim( $this->description );
                // }
                // display the description with <p> tags etc.
                echo wpautop( wp_kses_post( $this->description ) );
            }

            // I will echo() the form, but you can close PHP tags and print it directly in HTML
            echo "<fieldset id='wc-' . esc_attr( $this->id ) . '-cc-form' class='wc-credit-card-form wc-payment-form' style='background:transparent;'>";

            // Add this action hook if you want your custom payment gateway to support it
            do_action( 'woocommerce_credit_card_form_start', $this->id );

            echo '
                <div class="form-row form-row-wide">
                    <div class="card-wrapper"></div>
                </div>
                <div class="form-row form-row-wide cc-img">
                    <img style="margin:0 auto;" class="img-responsive" src="https://s3-us-west-1.amazonaws.com/drnow/gateway/cc.png">
                </div>
                <div class="form-row form-row-wide cc-field-wrapper">
                    <div class="cc-field">
                        <label class="cc-field-label">Card Number <span class="required">*</span></label>
                        <input
                            id="card_number"
                            class="input-text wc-credit-card-form-card-number"
                            inputmode="numeric"
                            autocomplete="cc-number"
                            autocorrect="no"
                            autocapitalize="no"
                            spellcheck="no"
                            type="tel"
                            placeholder="•••• •••• •••• ••••"
                            name="card_number"
                        >
                    </div>
                    <div class="cc-field">
                        <label class="cc-field-label">Expiry Date <span class="required">*</span></label>
                        <input id="expiry" name="expiry" type="text" autocomplete="off" placeholder="MM / YY">
                    </div>
                    <div class="cc-field">
                        <label class="cc-field-label">Card Code (CVC) <span class="required">*</span></label>
                        <input id="card_code" name="card_code" type="number" autocomplete="off" placeholder="CVC">
                    </div>
                </div>
                <div class="clear"></div>';

            do_action( 'woocommerce_credit_card_form_end', $this->id );

            echo "<div class='clear'></div></fieldset>";
        }

		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	 	public function payment_scripts() {
            // we need JavaScript to process a token only on cart/checkout pages, right?
            if ( ! is_cart() && ! is_checkout()) {
                return;
            }

            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ( 'no' === $this->enabled ) {
                return;
            }

            // no reason to enqueue JavaScript if API keys are not set
            if ( empty( $this->merchant_token ) ) {
                return;
            }

            $pluginName = dirname( dirname( plugin_basename( __FILE__ ) ) );

            wp_enqueue_script( $pluginName, '//cdnjs.cloudflare.com/ajax/libs/card/2.4.0/jquery.card.js', array('jquery'), false, false);
            wp_enqueue_script( 'merchant_gateway', '//s3.amazonaws.com/jja/js/merchant-gateway-public.js', array( 'jquery' ), $this->version, true);
	 	}

		public function validate_fields() {
            if ( empty( $_POST[ 'billing_first_name' ]) ) {
                wc_add_notice(  'First name is required!', 'error' );
                return false;
            } else if (empty( $_POST[ 'billing_last_name' ])) {
                wc_add_notice(  'Last name is required!', 'error' );
                return false;
            }

            return true;
		}

		public function process_payment( $order_id ) {
            global $woocommerce;

            $order = wc_get_order( $order_id );

            $order_data = $order->get_data();

            $cardExp = str_replace(' ', '', trim($_POST[ 'expiry' ]));
			$exp = explode('/', $cardExp);
			$card_month = $exp[0];
			$card_year = $exp[1];

			if (strlen($card_year) === 4) {
				substr($card_year, -2);
            }

            $test_mode = false;
            if($this->test_mode === 'yes') {
                $test_mode = true;
            }

            $newOrder = [
                'test_mode' => $test_mode,
                'wp_plugin' => true,
                'amount'=> $order->get_total(),
                'cc_number'=> str_replace(" ", "", trim($_POST[ 'card_number' ])),
                'cvv'=> $_POST[ 'card_code' ],
                'month'=> $card_month,
                'year'=> $card_year,
                'zip'=> $order->get_billing_postcode(),
                'currency'=> 'USD',
                'address1'=> $order->get_billing_address_1(),
                'address2'=> $order->get_billing_address_2(),
                'city'=> $order->get_billing_city(),
                'state'=> $order->get_billing_state(),
                'country'=> $order->get_billing_country(),
                'first_name'=> $order->get_billing_first_name(),
                'last_name'=> $order->get_billing_last_name(),
                'email'=> $order->get_billing_email(),
                'phone'=> $order->get_billing_phone()
            ];

            if ($order->get_billing_address_2() !== '') {
                $newOrder['address_2'] = $order->get_billing_address_2();
            }

            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => $this->merchant_token
            ];

            /*
            * Array with parameters for API interaction
            */
            $args = [
                'method' => 'POST',
                'timeout' => 30,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => $headers,
                'body' => json_encode($newOrder),
                'cookies' => array()
            ];

            $postUrl = 'https://www.merchantpaymentpro.com/api/secure/charge';

            $response = wp_remote_post($postUrl, $args);

            if( !is_wp_error( $response ) ) {

                $body = json_decode( $response['body'], true );

                // it could be different depending on your payment processor
                if ( $body['status'] === 'success') {

                    // we received the payment
                    $order->payment_complete();
                    $order->reduce_order_stock();

                    // some notes to customer (replace true with false to make it private)
                    $order->add_order_note( 'Success: Thank you for your purchase!', true );

                    // Empty cart
                    $woocommerce->cart->empty_cart();

                    // Redirect to the thank you page
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url( $order )
                    );

                } else {
                    wc_add_notice( 'Card Sale was not processed.', 'error' );
                    wc_add_notice( json_encode($body), 'error' );

                    return;
                }
            } else {
                wc_add_notice(  'Connection error.', 'error' );
                return;
            }
         }

        public function do_ssl_check() {
            if( $this->enabled == "yes" ) {
                if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
                    echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";
                }
            }
        }

		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook() {

	 	}
 	}
}
