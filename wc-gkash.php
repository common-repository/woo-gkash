<?php
/**
 * GKash WooCommerce Shopping Cart Plugin
 * 
 * @author Gkash Sdn. Bhd.
 * @version 1.2.14
 * @example For callback : https://woocommerce.gkash.my/mywordpress/?wc-api=gk_notify_url
 * @example For notification : https://woocommerce.gkash.my/mywordpress/?wc-api=gk_return_url
 */

/**
 * Plugin Name: WooCommerce Gkash
 * Plugin URI: https://wordpress.org/plugins/woo-gkash/
 * Description: WooCommerce Gkash
 * Author: Gkash Sdn. Bhd.
 * Author URI: https://gkash.my/
 * Version: 1.2.14
 * License: MIT
 * Text Domain: wcgkash
 * WC requires at least: 5.1.0
 * WC tested up to: 6.0
 * Domain Path: /languages/
 * For callback : https://woocommerce.gkash.my/mywordpress/?wc-api=gk_notify_url
 * For notification : https://woocommerce.gkash.my/mywordpress/?wc-api=gk_return_url
 * Invalid Transaction maybe is because vkey not found / skey wrong generated
 */

//If WooCommerce plugin is not available
function wcgkash_woocommerce_fallback_notice() {
	echo ('<div class="error"><p>' . __( 'WooCommerce Gkash Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'wcgkash' ) . '</p></div>');
}

//Load the function
add_action( 'plugins_loaded', 'wcgkash_gateway_load', 0 );

//Load Gkash gateway plugin function
function wcgkash_gateway_load() {
	if ( !class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'wcgkash_woocommerce_fallback_notice' );
		return;
	}

	//Load language
	add_filter( 'woocommerce_payment_gateways', 'wcgkash_add_gateway' );

	//Add Gkash gateway to ensure WooCommerce can load it @param array $methods
	function wcgkash_add_gateway( $methods ) {
		$methods[] = 'WC_Gkash_Gateway';
		return $methods;
	}

	//Define the Gkash gateway
	class WC_Gkash_Gateway extends WC_Payment_Gateway {

		//Construct the Gkash gateway class @global mixed $woocommerce
		public function __construct() {
			global $woocommerce;

			//settings image in checkout page
			$this->setup_properties();

			//settings inside woocommerce payment settings , let user insert userid and signaturekey)
			$this->init_form_fields();

			// Load the settings. (init UserID and send to payment form)
			$this->init_settings();

			// Define user setting variables.
			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			$this->user_id = $this->settings['user_id'];
			$this->signature_key = $this->settings['signature_key'];
			$this->IsStaging = $this->get_option('IsStaging');

			//Checking if the staging enviroment is Enable
			$this->CheckStagingEnv();
			
			//Get Product details
			$this->GetProductDetails();

			// Actions.	- submit checkout page to gkash payment
			add_action( 'woocommerce_receipt_gkash', array( &$this, 'receipt_page' ) );
			
			//save setting configuration
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_gk_return_url', array( &$this, 'return_url' ) );

			// add_action( 'valid_gkash_request_returnurl', array( &$this, 'return_url' ) );
			add_action( 'woocommerce_api_gk_notify_url', array( &$this, 'notify_url' ) );  

			// Checking if user_id is not empty.
			$this->user_id == '' ? add_action( 'admin_notices', array( &$this, 'user_id_missing_message' ) ) : '';

			// Checking if signature_key is not empty.
			$this->signature_key == '' ? add_action( 'admin_notices', array( &$this, 'signature_key_missing_message' ) ) : '';	
		   
		}

		protected function CheckStagingEnv(){
			if($this->get_option('IsStaging') == 'yes'){
			   $this->pay_url = 'https://api-staging.pay.asia/api/PaymentForm.aspx';
			}else{
				$this->pay_url = 'https://api.gkash.my/api/PaymentForm.aspx';     
			}
		}
		
		protected function GetProductDetails(){
			// Make sure it's only on front end
			if (is_admin()) return false;
			
			$this->product_description="";
			if(WC()->cart){
				foreach( WC()->cart->get_cart() as $cart_item ) {
					$this->product_description = preg_replace('/[{\<](.|\s)*?[}\>]/', '', $cart_item['data']->get_name());
				}
			}
		}

		//settings image in checkout page
		protected function setup_properties() {
			$this->id = 'gkash';
			$this->icon = plugins_url( 'images/checkout.png', __FILE__ );
			$this->has_fields = false;
			$this->method_title = __( 'GKash', 'wcgkash' );
			$this->method_description = __('Official Gkash payment gateway for WooCommerce.', 'wcgkash');
			}

		//Checking if this gateway is enabled and available in the user's country.
		public function is_valid_for_use() {
			if ( !in_array( get_woocommerce_currency() , array( 'MYR' ) ) ) {
				return false;
			}
			return true;
		}
		
		//Gateway Settings Form Fields.
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'wcgkash' ),
					'type' => 'checkbox',
					'label' => __( 'Enable gkash', 'wcgkash' ),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __( 'Title', 'wcgkash' ),
					'type' => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'wcgkash' ),
					'default' => __( 'Gkash Payment', 'wcgkash' )
				),
				'description' => array(
					'title' => __( 'Description', 'wcgkash' ),
					'type' => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'wcgkash' ),
					'default' => __( 'Secured Gkash Online Payment Gateway', 'wcgkash' )
				),
				'user_id' => array(
					'title' => __( 'User ID', 'wcgkash' ),
					'type' => 'text',
					'description' => __( 'Please enter your Gkash User ID.', 'wcgkash' ), 
					'default' => ''
				),
				'signature_key' => array(
					'title' => __( 'Signature Key', 'wcgkash' ),
					'type' => 'text',
					'description' => __( 'Please enter your Gkash Signature Key.', 'wcgkash' ),
					'default' => ''
				),
				'IsStaging' => array(
					'title'=> __('Staging Enviroment','wcgkash' ),
					'type' => 'checkbox',
					'label' => __('Use staging enviroment.','wcgkash'),
					'default' => 'no'
				),
				'api_details' => array( 
				'type' => 'Title',
				/* translators: %s: URL */
				'description' => sprintf( __( '<H4>Please login to <a href="%s">e.gkash.my</a> to customize your Payment page.</H4>', 'woocommerce' ), 'https://e.gkash.my' ),
				),

			);
		}

		//Generate payment form
		public function generate_form( $order_id ) {
			$order = new WC_Order( $order_id );

			$signature = hash('sha512', 
				strtoupper($this->signature_key.";"
				.$this->user_id.";"
				.$order->id."_".date('YmdHis').";"
				.str_replace(",","",str_replace(".", "", str_replace(",","",number_format($order->order_total,2)))).";"
				.get_woocommerce_currency())
			);

			$gkash_args = array(
				'version' => "1.5.0",
				'CID' => $this->user_id,
				'v_currency' => get_woocommerce_currency(),                
				'v_amount' => str_replace(",","",number_format($order->order_total,2)),
				'v_cartid' => $order->id."_".date('YmdHis'),               
				'v_firstname' => $order->billing_first_name,
				'v_lastname' =>$order->billing_last_name,
				'v_billemail' => $order->billing_email,
				'v_billstreet' => $order->billing_address_1." ".$order->billing_address_2,
				'v_billpost' => $order->billing_postcode,
				'v_billcity' => $order->billing_city,
				'v_billstate' => $order->billing_state,
				'v_billcountry' => $order->billing_country,
				'v_billphone' => isset($order->billing_phone) ? $order->billing_phone : $order->shipping_phone,
				'v_shipstreet' => $order->shipping_address_1." ".$order->shipping_address_2,
				'v_shippost' => $order->shipping_postcode,
				'v_shipcity' => $order->shipping_city,
				'v_shipstate' => $order->shipping_state,
				'v_shipcountry' => $order->shipping_country,
				'v_shipphone' => isset($order->shipping_phone) ? $order->shipping_phone : $order->billing_phone,
				'v_productdesc' => $this->product_description,
				'returnurl' => add_query_arg( 'wc-api', 'gk_return_url', home_url( '/' ) ), 
				'callbackurl' => add_query_arg( 'wc-api', 'gk_notify_url', home_url( '/' ) ), 
				'clientip' => sanitize_text_field($_SERVER['REMOTE_ADDR']),
				'signature' => $signature                        
			);

			//if select ship to different address
			if(isset($order->shipping_address_1)){
				$gkash_args = array(
				'version' => "1.5.0",
				'CID' => $this->user_id,
				'v_currency' => get_woocommerce_currency(),                
				'v_amount' => str_replace(",","",number_format($order->order_total,2)),
				'v_cartid' => $order->id."_".date('YmdHis'),               
				'v_firstname' => $order->shipping_first_name,
				'v_lastname' =>$order->shipping_last_name,
				'v_billemail' => $order->billing_email,
				'v_billstreet' => $order->shipping_address_1." ".$order->shipping_address_2,
				'v_billpost' => $order->shipping_postcode,
				'v_billcity' => $order->shipping_city,
				'v_billstate' => $order->shipping_state,
				'v_billcountry' => $order->shipping_country,
				'v_billphone' => isset($order->shipping_phone) ? $order->shipping_phone : $order->billing_phone,
				'v_shipstreet' => $order->billing_address_1." ".$order->billing_address_2,
				'v_shippost' => $order->billing_postcode,
				'v_shipcity' => $order->billing_city,
				'v_shipstate' => $order->billing_state,
				'v_shipcountry' => $order->billing_country,
				'v_shipphone' => isset($order->billing_phone) ? $order->billing_phone : $order->shipping_phone,
				'v_productdesc' => $this->product_description,
				'returnurl' => add_query_arg( 'wc-api', 'gk_return_url', home_url( '/' ) ), 
				'callbackurl' => add_query_arg( 'wc-api', 'gk_notify_url', home_url( '/' ) ), 
				'clientip' => sanitize_text_field($_SERVER['REMOTE_ADDR']),
				'signature' => $signature                        
				);
			}
			
				 $var_input = "";
				 foreach ($gkash_args as $key => $val) {
				   $var_input .= '<input type="hidden" name="' . $key . '" value="' . $val . '" /><br/>';
				 }
				 return '<form action="' . $this->pay_url .'" method="post" id="gkash_payment_form">
						 <input type="submit" class="button-alt" id="form" value="submit" />
						 <a class="button cancel" href="'.$order->get_cancel_order_url().'">Cancel Order</a> ' . $var_input . '</form>
						 <script type="text/javascript">
						 jQuery( document ).ready(function() {
						   jQuery("#form").click();
						 });
						 </script>';
		}

		//Order error button.
		protected function gkash_order_error( $order ) {
			return ('<p>' . __( 'An error has occurred while processing your payment, please try again. Or contact us for assistance.', 'wcgkash' ) . '</p>' .
					'<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Click to try again', 'wcgkash' ) . '</a>');
		}

		//Process the payment in before go gkashPayment.
		public function process_payment( $order_id ) {
			$order =  wc_get_order( $order_id );
			$order->get_checkout_payment_url( $on_checkout = false );
			$pay_now_url = $order->get_checkout_payment_url('/checkout/order-received/{{order_number}}/?pay_for_order=true&key={{order_key}}');
			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url( true )
			);
		}

		// Output for the order received page.
		public function receipt_page( $order ) {
			echo $this->generate_form( $order );
		}

		//This part is returnurl function for gkash @global mixed $woocommerce
		function return_url() {           
			global $woocommerce;
			
			$order = new WC_Order(strstr(sanitize_text_field($_POST['cartid']), '_', true));            
			   
			if (strpos(sanitize_text_field($_POST['status']), '88') !== false) {
				wp_redirect($order->get_checkout_order_received_url());
				exit();
			}
			else if (strpos(sanitize_text_field($_POST['status']), 'Pending') !== false) { 
				wp_redirect($order->get_checkout_order_received_url());
				exit();
			}
			else if (strpos(sanitize_text_field($_POST['status']), '66') !== false) { 
				wp_redirect($order->get_cancel_order_url());      
				exit();
			} 
			else  {
				wp_redirect($order->get_cancel_order_url());
				exit();
			}	
		}
		
		 //This part is callback function for gkash @global mixed $woocommerce
		function notify_url() {
			global $woocommerce;
				
			$self_sign = hash('sha512',strtoupper($this->signature_key.";"
			.sanitize_text_field($_POST['CID']).";"
			.sanitize_text_field($_POST['POID']).";"
			.sanitize_text_field($_POST['cartid']).";"
			.str_replace(".", "", sanitize_text_field($_POST['amount'])).";"
			.sanitize_text_field($_POST['currency']).";"
			.sanitize_text_field($_POST['status'])
			));
 
			$order = new WC_Order(strstr(sanitize_text_field($_POST['cartid']), '_', true));
			if ($self_sign != sanitize_text_field($_POST['signature'])){
				$order->add_order_note('Gkash Payment Status: Invalid Signature'.'<br>Transaction ID: ' . sanitize_text_field($_POST['POID']) . "<br>Referer: CallbackURL<br>" . sanitize_text_field($_POST['cartid']));
				exit();
			} 

			//Get OrderStatus if already success dont do update
			$orderstatus = $order->get_status();
			if($orderstatus == 'processing' || $orderstatus == 'COMPLETED')
			{
				echo 'OK';
				exit();
			}

			if (strpos(sanitize_text_field($_POST['status']), '88') !== false) {				
				$order->add_order_note('Gkash Payment Status: SUCCESSFUL'.'<br>Transaction ID: ' . sanitize_text_field($_POST['POID']) . "<br>Referer: CallbackURL<br>" . sanitize_text_field($_POST['cartid']));	
				$order->payment_complete();
				echo 'OK';
				exit();          
			}
			else if (strpos(sanitize_text_field($_POST['status']), '66') !== false) { 
				$order->add_order_note('Gkash Payment Status: FAILED'.'<br>Transaction ID: ' . sanitize_text_field($_POST['POID']) . "<br>Referer: CallbackURL<br>" . sanitize_text_field($_POST['cartid']));
				$order->update_status('failed', sprintf(__('Payment %s via gkash.', 'woocommerce'), sanitize_text_field($_POST['POID']) ) );
				echo 'OK';
				exit();
			} 
			else {
				$order->add_order_note('Gkash Payment Status: Invalid Transaction'.'<br>Transaction ID: ' . sanitize_text_field($_POST['POID']) . "<br>Referer: CallbackURL<br>");
				$order->update_status('on-hold', sprintf(__('Payment %s via gkash.', 'woocommerce'), sanitize_text_field($_POST['POID']) ) );
				echo 'OK';
				exit();
			}		
		}

		//Adds error message when not configured the user id.
		public function user_id_missing_message() {
			echo ('<div class="error"><p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should input your User ID in Gkash. %sClick here to configure!%s' , 'wcgkash' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=wc_gkash_gateway">', '</a>' ) . '</p></div>' );
		}

		//Adds error message when not configured the signatureKey.
		public function signature_key_missing_message() {
			echo('<div class="error"><p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should input your Signature Key in Gkash. %sClick here to configure!%s' , 'wcgkash' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=wc_gkash_gateway">', '</a>' ) . '</p></div>' );
		}

	}
}
