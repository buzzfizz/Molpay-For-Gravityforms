<?php

add_action( 'wp', array( 'GFMolPay', 'maybe_thankyou_page' ), 5 );

GFForms::include_payment_addon_framework();

class GFMolPay extends GFPaymentAddOn {

	protected $_version = GF_MOLPAY_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityformsmolpay';
	protected $_path = 'gravityformsmolpay/molpay.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'MOLPay For Gravity Forms';
	protected $_short_title = 'MOLPay';
	protected $_supports_callbacks = true;
	private $production_url = 'https://www.onlinepayment.com.my/MOLPay/pay/'; //add merchant id, payment channel and query strings at the end
	private $sandbox_url = 'https://www.onlinepayment.com.my/MOLPay/pay/'; //add merchant id, payment channel and query strings at the end

	// Members plugin integration
	protected $_capabilities = array( 'gravityforms_molpay', 'gravityforms_molpay_uninstall' );

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_molpay';
	protected $_capabilities_form_settings = 'gravityforms_molpay';
	protected $_capabilities_uninstall = 'gravityforms_molpay_uninstall';

	//disable automatic upgrade
	protected $_enable_rg_autoupgrade = false;
	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFMolPay();
		}

		return self::$_instance;
	}

	private function __clone() {
	} /* do nothing */

	public function init_frontend() {
		parent::init_frontend();

		add_filter( 'gform_disable_post_creation', array( $this, 'delay_post' ), 10, 3 );
		add_filter( 'gform_disable_notification', array( $this, 'delay_notification' ), 10, 4 );
	}

	public function plugin_settings_fields() {

		$description = '
			<p style="text-align: left;">' .
			esc_html__( 'Gravity Forms requires IPN to be enabled on your MOLPay account.', 'gravityformsmolpay' ) .
			'</p>
			<ul>
				<li>' .  esc_html__( 'Navigate to your MOLPay merchant page.', 'gravityformsmolpay' ) . '</li>' .
				'<li>' . esc_html__( 'Enable IPN for Return, Callback and Notification URL.', 'gravityformsmolpay' ) . '</li>' .
				'<li>' . sprintf( esc_html__( 'Copy the following URL into Notification URL and Callback URL field: %s', 'gravityformsmolpay' ), '<strong>' . esc_url( add_query_arg( 'page', 'gf_molpay_ipn', get_bloginfo( 'url' ) . '/' ) ) . '</strong>' ) . '</li>' .
			'</ul>
			<br/>';

		return array(
			array(
				'title'       => '',
				'description' => $description,
				'fields'      => array(
					array(
						'name'    => 'gf_molpay_configured',
						'label'   => esc_html__( 'MOLPay Merchant Setting', 'gravityformsmolpay' ),
						'type'    => 'checkbox',
						'choices' => array( array( 'label' => esc_html__( 'Confirm that you have followed the steps above', 'gravityformsmolpay' ), 'name' => 'gf_molpay_configured' ) )
					),
					array(
						'type' => 'save',
						'messages' => array(
							'success' => esc_html__( 'Settings have been updated huhu.', 'gravityformsmolpay' )
						),
					),
				),
			),
		);
	}

	public function feed_list_no_item_message() {
		$settings = $this->get_plugin_settings();
		if ( ! rgar( $settings, 'gf_molpay_configured' ) ) {
			return sprintf( esc_html__( 'To get started, please configure your %sMOLPay Settings%s!', 'gravityformsmolpay' ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
		} else {
			return parent::feed_list_no_item_message();
		}
	}

	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		//--add MOLPay VCode field
		$fields = array(
			array(
				'name'     => 'molpayMerchantId',
				'label'    => esc_html__( 'MOLPay Merchant ID ', 'gravityformsmolpay' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'MOLPay Merchant ID', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the MOLPay merchant ID from your merchant profile page.', 'gravityformsmolpay' )
			),
			array(
				'name'     => 'molpayVCode',
				'label'    => esc_html__( 'MOLPay VCode ', 'gravityformsmolpay' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'MOLPay Verification Code', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the MOLPay verification code from your merchant profile page.', 'gravityformsmolpay' )
				)
			);

			$default_settings = parent::add_field_after( 'feedName', $fields, $default_settings );
			//--------------------------------------------------------------------------------------

			// Remove Subscription Option on Transaction Type


			$transaction_type = parent::get_field('transactionType', $default_settings);
			unset($transaction_type['choices'][2]);
			$transaction_type['required'] = true;

			$default_settings = $this->replace_field('transactionType', $transaction_type, $default_settings);

			//-------------------------------------------------------------------------------

			//-- Cancel URL
			$fields = array(
				array(
					'name'     => 'cancelUrl',
					'label'    => esc_html__( 'Cancel URL', 'gravityformsmolpay' ),
					'type'     => 'text',
					'class'    => 'medium',
					'required' => false,
					'tooltip'  => '<h6>' . esc_html__( 'Cancel URL', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the URL the user should be sent to should they cancel before completing their MOLPay payment.', 'gravityformsmolpay' )
				),
			);

			$form = $this->get_current_form();

			//Adding custom settings for backwards compatibility with hook 'gform_molpay_add_option_group'
			$fields[] = array(
				'name'  => 'custom_options',
				'label' => '',
				'type'  => 'custom',
			);

			$default_settings = $this->add_field_after( 'billingInformation', $fields, $default_settings );
			//-----------------------------------------------------------------------------------------

			//--get billing info section and add customer first/last name
			$billing_info   = parent::get_field( 'billingInformation', $default_settings );
			$billing_fields = $billing_info['field_map'];
			$add_full_name = true;
			$add_phone_num = true;
			foreach ( $billing_fields as $mapping ) {
				//add full name if it does not already exist in billing fields
				if ( $mapping['name'] == 'fullName' ) {
					$add_full_name = false;
				} else if ( $mapping['name'] == 'phoneNum' ) {
					$add_phone_num = false;
				}
			}
			if( $add_phone_num ){
				// add phone number because phone number is mandatory in MOLPay
				array_unshift( $billing_info['field_map'], array( 'name' => 'phoneNum', 'label' => esc_html__( 'Phone Number', 'gravityformsmolpay' ), 'required' => true ) );
			}
			if ( $add_full_name ) {
				//add full name
				array_unshift( $billing_info['field_map'], array( 'name' => 'fullName', 'label' => esc_html__( 'Full Name', 'gravityformsmolpay' ), 'required' => true ) );
			}


			$default_settings = parent::replace_field( 'billingInformation', $billing_info, $default_settings );
			//----------------------------------------------------------------------------------------------------

			//hide default display of setup fee, not used by Molpay Standard
			$default_settings = parent::remove_field( 'setupFee', $default_settings );

			/**
			* Filter through the feed settings fields for the molpay feed
			*
			* @param array $default_settings The Default feed settings
			* @param array $form The Form object to filter through
			*/
			return apply_filters( 'gform_molpay_feed_settings_fields', $default_settings, $form );
		}

		/**
		* Prevent the GFPaymentAddOn version of the options field being added to the feed settings.
		*
		* @return bool
		*/
		public function option_choices() {
			return false;
		}

		public function save_feed_settings($feed_id, $form_id, $settings) {
			//--------------------------------------------------------
			//For backwards compatibility
			$feed = $this->get_feed($feed_id);
			//Saving new fields into old field names to maintain backwards compatibility for delayed payments
			$settings['type'] = $settings['transactionType'];
			if (isset($settings['recurringAmount'])) {
				$settings['recurring_amount_field'] = $settings['recurringAmount'];
			}
			$feed['meta'] = $settings;
			$feed = apply_filters('gform_molpay_save_config', $feed);
			//call hook to validate custom settings/meta added using gform_molpay_action_fields or gform_molpay_add_option_group action hooks
			$is_validation_error = apply_filters('gform_molpay_config_validation', false, $feed);
			if ($is_validation_error) {
				//fail save
				return false;
			}
			$settings = $feed['meta'];
			//--------------------------------------------------------
			return parent::save_feed_settings($feed_id, $form_id, $settings);
		}

		//REDIRECT TO MOLPAY
		public function redirect_url($feed, $submission_data, $form, $entry) {
			if ( ! rgempty( 'gf_molpay_return', $_GET ) ) {
				return false;
			}

			// update payment status to processing

			GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
			$url = '';
			$vcode_hash = '';
			$merchant_id = $feed['meta']['molpayMerchantId']; //merchant id from plugin settings. maybe store per feed?
			$amount = rgar( $submission_data, 'payment_amount' );
			$order_id = rgar( $entry, 'id' ); //Use entry id as order id, maybe change to something else?
			$return_url = '&returnurl=' . urlencode( $this->return_url( $form['id'], $entry['id'] ) );
			$callback_url = '&callbackurl=' . urlencode ( get_bloginfo('url') . '/?page=gf_molpay_ipn' );
			
			//set cancel url
			$cancel_url = !empty($feed['meta']['cancelUrl']) ? "&cancelurl=" . urlencode($feed['meta']['cancelUrl']) : '';
			$url .= "?amount={$amount}&orderid={$order_id}";
			$customer_details = array(
				array( 'name' => 'bill_name', 'meta_name' => 'billingInformation_fullName' ),
				array( 'name' => 'bill_mobile', 'meta_name' => 'billingInformation_phoneNum' ),
				array( 'name' => 'bill_email', 'meta_name' => 'billingInformation_email' ),
				array( 'name' => 'country', 'meta_name' => 'billingInformation_country' )
			);

			foreach ($customer_details as $field) {

				$field_id = $feed['meta'][ $field['meta_name'] ];
				$value    = rgar( $entry, $field_id );

				if ( $field['name'] == 'country' ) {
					$value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_country_code( $value ) : GFCommon::get_country_code( $value );
				} elseif ( $field['name'] == 'state' ) {
					$value = class_exists( 'GF_Field_Address' ) ? GF_Fields::get( 'address' )->get_us_state_code( $value ) : GFCommon::get_us_state_code( $value );
				}

				if ( ! empty( $value ) ) {
					$url .= "&{$field['name']}=" . urlencode( $value );
				}
			}

			$bill_desc = get_bloginfo() . ' ' . $submission_data['line_items'][0]['name']; //take first product only for description purposes
			$bill_description = '&bill_desc=' . urlencode($bill_desc);
			$url .= $bill_description . $return_url . $callback_url . $cancel_url;
			//generate vcode_hash

			$vcode_hash = md5($amount . $merchant_id . $order_id . $feed['meta']['molpayVCode']);
			$url .= "&vcode={$vcode_hash}";

			//combine production url and the rest. Index maybe can use switch for other forms of payment. Index returns all payment channels

			$url = $this->production_url . $merchant_id . '/index.php' . $url;

			//end generate vcode_hash

			$this->log_debug( __METHOD__ . "(): Sending to MOLPay: {$url}" );
			return $url;
		}

		public function return_url( $form_id, $lead_id ) {
			$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

			$server_port = apply_filters( 'gform_molpay_return_url_port', $_SERVER['SERVER_PORT'] );

			if ( $server_port != '80' ) {
				$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
			} else {
				$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
			}

			$ids_query = "ids={$form_id}|{$lead_id}";
			$ids_query .= '&hash=' . wp_hash( $ids_query );

			$url = add_query_arg( 'gf_molpay_return', base64_encode( $ids_query ), $pageURL );

			$query = 'gf_molpay_return=' . base64_encode( $ids_query );
			/**
			* Filters molpay's return URL, which is the URL that users will be sent to after completing the payment on molpay's site.
			* Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
			*
			* @since 2.4.5
			*
			* @param string  $url 	The URL to be filtered.
			* @param int $form_id	The ID of the form being submitted.
			* @param int $entry_id	The ID of the entry that was just created.
			* @param string $query	The query string portion of the URL.
			*/
			return apply_filters( 'gform_molpay_return_url', $url, $form_id, $lead_id, $query  );

		}

		public static function maybe_thankyou_page() {
			$instance = self::get_instance();

			if ( ! $instance->is_gravityforms_supported() ) {
				return;
			}

			if ( $str = rgget( 'gf_molpay_return' ) ) {
				$str = base64_decode( $str );

				parse_str( $str, $query );
				if ( wp_hash( 'ids=' . $query['ids'] ) == $query['hash'] ) {
					list( $form_id, $lead_id ) = explode( '|', $query['ids'] );

					$form = GFAPI::get_form( $form_id );
					$lead = GFAPI::get_entry( $lead_id );

					if ( ! class_exists( 'GFFormDisplay' ) ) {
						require_once( GFCommon::get_base_path() . '/form_display.php' );
					}

					$confirmation = GFFormDisplay::handle_confirmation( $form, $lead, false );

					if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ) {
						header( "Location: {$confirmation['redirect']}" );
						exit;
					}

					GFFormDisplay::$submission[ $form_id ] = array( 'is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $lead );
				}
			}
		}

		//------- AJAX FUNCTIONS ------------------//
		public function init_ajax() {
			parent::init_ajax();
			add_action( 'wp_ajax_gf_dismiss_molpay_menu', array( $this, 'ajax_dismiss_menu' ) );
		}

		public function init_admin() {

			parent::init_admin();

			//add actions to allow the payment status to be modified
			add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 3, 3 );
			add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 3, 3 );
			add_action( 'gform_payment_transaction_id', array( $this, 'admin_edit_payment_transaction_id' ), 3, 3 );
			add_action( 'gform_payment_amount', array( $this, 'admin_edit_payment_amount' ), 3, 3 );
			add_action( 'gform_after_update_entry', array( $this, 'admin_update_payment' ), 4, 2 );
		}

		public function delay_post( $is_disabled, $form, $entry ) {

			$feed            = $this->get_payment_feed( $entry );
			$submission_data = $this->get_submission_data( $feed, $form, $entry );

			if ( ! $feed || empty( $submission_data['payment_amount'] ) ) {
				return $is_disabled;
			}

			return ! rgempty( 'delayPost', $feed['meta'] );
		}

		public function delay_notification( $is_disabled, $notification, $form, $entry ) {
			if ( rgar( $notification, 'event' ) != 'form_submission' ) {
				return $is_disabled;
			}

			$feed            = $this->get_payment_feed( $entry );
			$submission_data = $this->get_submission_data( $feed, $form, $entry );

			if ( ! $feed || empty( $submission_data['payment_amount'] ) ) {
				return $is_disabled;
			}

			$selected_notifications = is_array( rgar( $feed['meta'], 'selectedNotifications' ) ) ? rgar( $feed['meta'], 'selectedNotifications' ) : array();

			return isset( $feed['meta']['delayNotification'] ) && in_array( $notification['id'], $selected_notifications ) ? true : $is_disabled;
		}


		//------- PROCESSING molpay IPN (Callback) -----------//

		public function callback() {

			if ( ! $this->is_gravityforms_supported() ) {
				return false;
			}
			$this->log_debug( __METHOD__ . '(): IPN request received. Starting to process => ' . print_r( $_POST, true ) );

			//------ Getting feed related to this IPN ------------------------------------------//
			$entry = GFAPI::get_entry( rgpost('orderid') ); // use back order id return from molpay

			$this->log_debug( __METHOD__ . '(): Entry =>' . print_r($entry, true) );						

			$feed = $this->get_payment_feed( $entry );

			$this->log_debug( __METHOD__ . '(): Feed =>' . print_r($feed, true) );			
			
			$this->log_debug( __METHOD__ . "(): Form {$entry['form_id']} is properly configured." );

			//----- Processing IPN ------------------------------------------------------------//
			$this->log_debug( __METHOD__ . '(): Processing IPN...' );

			$action = $this->process_ipn( 
				$feed,
				$entry,
				rgpost( 'status' ),
				rgpost( 'domain' ),
				rgpost( 'tranID' ),
				rgpost( 'amount' ),
				rgpost( 'currency' ),
				rgpost( 'paydate' ),
				rgpost( 'orderid' ),
				rgpost( 'appcode' ),
				rgpost( 'skey' )
			);
			$this->log_debug( __METHOD__ . '(): Action =>' . print_r($action, true));

			$this->log_debug( __METHOD__ . '(): IPN processing complete.' );

			if ( rgempty( 'entry_id', $action ) ) {

				return false;

			}

			return $action;

		}

		public function get_payment_feed( $entry, $form = false ) {

			$feed = parent::get_payment_feed( $entry, $form );

			if ( empty( $feed ) && ! empty( $entry['id'] ) ) {
				//looking for feed created by legacy versions
				$feed = $this->get_molpay_feed_by_entry( $entry['id'] );
			}

			$feed = apply_filters( 'gform_molpay_get_payment_feed', $feed, $entry, $form ? $form : GFAPI::get_form( $entry['form_id'] ) );

			return $feed;
		}

		private function get_molpay_feed_by_entry( $entry_id ) {

			$feed_id = gform_get_meta( $entry_id, 'molpay_feed_id' );
			$feed    = $this->get_feed( $feed_id );

			return ! empty( $feed ) ? $feed : false;
		}

		public function post_callback( $callback_action, $callback_result ) {

			if ( is_wp_error( $callback_action ) || ! $callback_action ) {
				return false;
			}
			//run the necessary hooks
			$entry          = GFAPI::get_entry( $callback_action['entry_id'] );
			$feed           = $this->get_payment_feed( $entry );
			$transaction_id = rgar( $callback_action, 'transaction_id' );
			$amount         = rgar( $callback_action, 'amount' );
			$subscriber_id  = rgar( $callback_action, 'subscriber_id' );
			$pending_reason = rgpost( 'pending_reason' );
			$reason         = rgpost( 'reason_code' );
			$status         = rgpost( 'payment_status' );
			$txn_type       = rgpost( 'txn_type' );
			$parent_txn_id  = rgpost( 'parent_txn_id' );
			//run gform_molpay_fulfillment only in certain conditions
			if ( rgar( $callback_action, 'ready_to_fulfill' ) && ! rgar( $callback_action, 'abort_callback' ) ) {
				$this->fulfill_order( $entry, $transaction_id, $amount, $feed );
			} else {
				if ( rgar( $callback_action, 'abort_callback' ) ) {
					$this->log_debug( __METHOD__ . '(): Callback processing was aborted. Not fulfilling entry.' );
				} else {
					$this->log_debug( __METHOD__ . '(): Entry is already fulfilled or not ready to be fulfilled, not running gform_molpay_fulfillment hook.' );
				}
			}
			do_action( 'gform_post_payment_status', $feed, $entry, $status, $transaction_id, $subscriber_id, $amount, $pending_reason, $reason );
			if ( has_filter( 'gform_post_payment_status' ) ) {
				$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_post_payment_status.' );
			}
			do_action( 'gform_molpay_ipn_' . $txn_type, $entry, $feed, $status, $txn_type, $transaction_id, $parent_txn_id, $subscriber_id, $amount, $pending_reason, $reason );
			if ( has_filter( 'gform_molpay_ipn_' . $txn_type ) ) {
				$this->log_debug( __METHOD__ . "(): Executing functions hooked to gform_molpay_ipn_{$txn_type}." );
			}
			do_action( 'gform_molpay_post_ipn', $_POST, $entry, $feed, false );
			if ( has_filter( 'gform_molpay_post_ipn' ) ) {
				$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_molpay_post_ipn.' );
			}
		}

		public function modify_post($post_id, $action) {
			$result = false;
			if (!$post_id) {
				return $result;
			}
			switch ($action) {
				case 'draft':
					$post = get_post($post_id);
					$post->post_status = 'draft';
					$result = wp_update_post($post);
					$this->log_debug(__METHOD__ . "(): Set post (#{$post_id}) status to \"draft\".");
					break;
				case 'delete':
					$result = wp_delete_post($post_id);
					$this->log_debug(__METHOD__ . "(): Deleted post (#{$post_id}).");
					break;
			}
			return $result;
		}

		public function is_callback_valid() {
			if (rgget('page') != 'gf_molpay_ipn') {
					return false;
			}
			return true;
		}

		private function process_ipn( $config, $entry, $status, $merchant, $transaction_id, $amount, $currency, $paydate, $orderid, $appcode, $skey ) {

			$this->log_debug( __METHOD__ . "(): Payment status: {$status} - Transaction ID: {$transaction_id} - Amount: {$amount} - Currency: {$currency} - Pay Date: {$paydate} - Order ID: {$orderid} - App Code: {$appcode} - SKEY: {$skey}" );
			$action = array();

			//status 00 = success, 11 = failed, 22 = pending 

			if( $status == '00' ){ //success
				$action['id']               = $transaction_id . '_' . $status;
				$action['type']             = 'complete_payment';
				$action['transaction_id']   = $transaction_id;
				$action['amount']           = $amount;
				$action['entry_id']         = $entry['id'];
				$action['payment_date']     = gmdate( 'y-m-d H:i:s' );
				$action['payment_method']	= 'MOLPay';
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;
				
				$this->log_debug( __METHOD__ . "(): status 00. Result => " . print_r($action, true) );

				//generate skey and compare with MOLPays response
				if( ! $this->validate_skey( $config, $entry, $status, $merchant, $transaction_id, $amount, $currency, $paydate, $orderid, $appcode, $skey ) ){
					$this->log_debug( __METHOD__ . '(): SKEY DOESNT MATCH' );
					$action['abort_callback'] = true;							
				}

				if ( ! $this->is_valid_initial_payment_amount( $entry['id'], $amount ) ){
					//create note and transaction
					$this->log_debug( __METHOD__ . '(): Payment amount does not match product price. Entry will not be marked as Approved.' );
					GFPaymentAddOn::add_note( $entry['id'], sprintf( __( 'Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction ID: %s', 'gravityformsmolpay' ), GFCommon::to_money( $amount, $entry['currency'] ), $transaction_id ) );
					GFPaymentAddOn::insert_transaction( $entry['id'], 'payment', $transaction_id, $amount );

					$action['abort_callback'] = true;
				}

				return $action;
			}
			
			if( $status == '11' ){ //fail
				$action['id']             = $transaction_id . '_' . $status;
				$action['type']           = 'fail_payment';
				$action['transaction_id'] = $transaction_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $amount;
				$action['note']           = sprintf( __( 'Payment failed. Please contact MOLPay if anything happens. Amount: %s. Transaction ID: %s.', 'gravityformsmolpay' ), $amount_formatted, $action['transaction_id'] );
				

				return $action;
			}

			if( $status == '22') { //pending
				$action['id']             = $transaction_id . '_' . $status;
				$action['type']           = 'add_pending_payment';
				$action['transaction_id'] = $transaction_id;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $amount;
				$action['entry_id']       = $entry['id'];
				$amount_formatted         = GFCommon::to_money( $action['amount'], $entry['currency'] );
				$action['note']           = sprintf( __( 'Payment is pending. Amount: %s. Transaction ID: %s.', 'gravityformsmolpay' ), $amount_formatted, $action['transaction_id'] );

				return $action;
			}
		}

		//generate and validate skey return from molpay
		private function validate_skey($config, $entry, $status, $merchant, $transaction_id, $amount, $currency, $paydate, $orderid, $appcode, $skey) {

			$vcode = $config['meta']['molpayVCode'];
			$merchant_id = $config['meta']['molpayMerchantId'];;

			$this->log_debug(__METHOD__ . "Transaction_ID: {$transaction_id} OrderID: {$orderid} Status: {$status} Merchant: {$merchant} Amount: {$amount} Currency: {$currency}");
			$this->log_debug(__METHOD__ . "Paydate: {$paydate} Domain: {$merchant_id} Appcode: {$appcode} VCODE: {$vcode}");
			
			$key0 = md5( $transaction_id . $orderid . $status . $merchant . $amount . $currency );
			$key1 = md5( $paydate . $merchant_id . $key0 . $appcode . $vcode );

			$this->log_debug(__METHOD__ . "Skey generated:{$key1}, Skey sent by MOLPay:{$skey}");

			if($skey === $key1) {

				return true;

			} else {

				return false; 

			}

		}

		/**
		* Add supported notification events.
		*
		* @param array $form The form currently being processed.
		*
		* @return array
		*/
		public function supported_notification_events($form) {
			if (!$this->has_feed($form['id'])) {
				return false;
			}
			return array(
				'complete_payment' => esc_html__('Payment Completed', 'gravityformsmolpay'),
				'refund_payment' => esc_html__('Payment Refunded', 'gravityformsmolpay'),
				'fail_payment' => esc_html__('Payment Failed', 'gravityformsmolpay'),
				'add_pending_payment' => esc_html__('Payment Pending', 'gravityformsmolpay'),
				'void_authorization' => esc_html__('Authorization Voided', 'gravityformsmolpay')
			);
		}

		public function ajax_dismiss_menu() {
			$current_user = wp_get_current_user();
			update_metadata('user', $current_user->ID, 'dismiss_molpay_menu', '1');
		}

		public function admin_edit_payment_status( $payment_status, $form, $entry ) {
			if ( $this->payment_details_editing_disabled( $entry ) ) {
				return $payment_status;
			}

			//create drop down for payment status
			$payment_string = gform_tooltip( 'molpay_edit_payment_status', '', true );
			$payment_string .= '<select id="payment_status" name="payment_status">';
			$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
			$payment_string .= '<option value="Paid">Paid</option>';
			$payment_string .= '</select>';

			return $payment_string;
		}

		public function admin_edit_payment_date( $payment_date, $form, $entry ) {
			if ( $this->payment_details_editing_disabled( $entry ) ) {
				return $payment_date;
			}

			$payment_date = $entry['payment_date'];
			if ( empty( $payment_date ) ) {
				$payment_date = gmdate( 'y-m-d H:i:s' );
			}

			$input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';

			return $input;
		}

		public function admin_edit_payment_transaction_id( $transaction_id, $form, $entry ) {
			if ( $this->payment_details_editing_disabled( $entry ) ) {
				return $transaction_id;
			}

			$input = '<input type="text" id="molpay_transaction_id" name="molpay_transaction_id" value="' . $transaction_id . '">';

			return $input;
		}

		public function admin_edit_payment_amount( $payment_amount, $form, $entry ) {
			if ( $this->payment_details_editing_disabled( $entry ) ) {
				return $payment_amount;
			}

			if ( empty( $payment_amount ) ) {
				$payment_amount = GFCommon::get_order_total( $form, $entry );
			}

			$input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';

			return $input;
		}

		public function admin_update_payment( $form, $entry_id ) {
			check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );

			//update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
			$entry = GFFormsModel::get_lead( $entry_id );

			if ( $this->payment_details_editing_disabled( $entry, 'update' ) ) {
				return;
			}

			//get payment fields to update
			$payment_status = rgpost( 'payment_status' );
			//when updating, payment status may not be editable, if no value in post, set to lead payment status
			if ( empty( $payment_status ) ) {
				$payment_status = $entry['payment_status'];
			}

			$payment_amount      = GFCommon::to_number( rgpost( 'payment_amount' ) );
			$payment_transaction = rgpost( 'molpay_transaction_id' );
			$payment_date        = rgpost( 'payment_date' );

			$status_unchanged = $entry['payment_status'] == $payment_status;
			$amount_unchanged = $entry['payment_amount'] == $payment_amount;
			$id_unchanged     = $entry['transaction_id'] == $payment_transaction;
			$date_unchanged   = $entry['payment_date'] == $payment_date;

			if ( $status_unchanged && $amount_unchanged && $id_unchanged && $date_unchanged ) {
				return;
			}

			if ( empty( $payment_date ) ) {
				$payment_date = gmdate( 'y-m-d H:i:s' );
			} else {
				//format date entered by user
				$payment_date = date( 'Y-m-d H:i:s', strtotime( $payment_date ) );
			}

			global $current_user;
			$user_id   = 0;
			$user_name = 'System';
			if ( $current_user && $user_data = get_userdata( $current_user->ID ) ) {
				$user_id   = $current_user->ID;
				$user_name = $user_data->display_name;
			}

			$entry['payment_status'] = $payment_status;
			$entry['payment_amount'] = $payment_amount;
			$entry['payment_date']   = $payment_date;
			$entry['transaction_id'] = $payment_transaction;

			// if payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
			if ( ( $payment_status == 'Approved' || $payment_status == 'Paid' ) && ! $entry['is_fulfilled'] ) {
				$action['id']             = $payment_transaction;
				$action['type']           = 'complete_payment';
				$action['transaction_id'] = $payment_transaction;
				$action['amount']         = $payment_amount;
				$action['entry_id']       = $entry['id'];

				$this->complete_payment( $entry, $action );
				$this->fulfill_order( $entry, $payment_transaction, $payment_amount );
			}
			//update lead, add a note
			GFAPI::update_entry( $entry );
			GFFormsModel::add_note( $entry['id'], $user_id, $user_name, sprintf( esc_html__( 'Payment information was manually updated. Status: %s. Amount: %s. Transaction ID: %s. Date: %s', 'gravityformsmolpay' ), $entry['payment_status'], GFCommon::to_money( $entry['payment_amount'], $entry['currency'] ), $payment_transaction, $entry['payment_date'] ) );
		}

		public function fulfill_order( &$entry, $transaction_id, $amount, $feed = null ) {

			if ( ! $feed ) {
				$feed = $this->get_payment_feed( $entry );
			}

			$form = GFFormsModel::get_form_meta( $entry['form_id'] );
			if ( rgars( $feed, 'meta/delayPost' ) ) {
				$this->log_debug( __METHOD__ . '(): Creating post.' );
				$entry['post_id'] = GFFormsModel::create_post( $form, $entry );
				$this->log_debug( __METHOD__ . '(): Post created.' );
			}

			if ( rgars( $feed, 'meta/delayNotification' ) ) {
				//sending delayed notifications
				$notifications = $this->get_notifications_to_send( $form, $feed );
				GFCommon::send_notifications( $notifications, $form, $entry, true, 'form_submission' );
			}

			do_action( 'gform_molpay_fulfillment', $entry, $feed, $transaction_id, $amount );
			if ( has_filter( 'gform_molpay_fulfillment' ) ) {
				$this->log_debug( __METHOD__ . '(): Executing functions hooked to gform_molpay_fulfillment.' );
			}

		}

		/**
		* Retrieve the IDs of the notifications to be sent.
		*
		* @param array $form The form which created the entry being processed.
		* @param array $feed The feed which processed the entry.
		*
		* @return array
		*/
		public function get_notifications_to_send( $form, $feed ) {
			$notifications_to_send  = array();
			$selected_notifications = rgars( $feed, 'meta/selectedNotifications' );

			if ( is_array( $selected_notifications ) ) {
				// Make sure that the notifications being sent belong to the form submission event, just in case the notification event was changed after the feed was configured.
				foreach ( $form['notifications'] as $notification ) {
					if ( rgar( $notification, 'event' ) != 'form_submission' || ! in_array( $notification['id'], $selected_notifications ) ) {
						continue;
					}

					$notifications_to_send[] = $notification['id'];
				}
			}

			return $notifications_to_send;
		}

		private function is_valid_initial_payment_amount( $entry_id, $amount_paid ) {

			//get amount initially sent to molpayl
			$amount_sent = gform_get_meta( $entry_id, 'payment_amount' );
			if ( empty( $amount_sent ) ) {
				return true;
			}

			$epsilon    = 0.00001;
			$is_equal   = abs( floatval( $amount_paid ) - floatval( $amount_sent ) ) < $epsilon;
			$is_greater = floatval( $amount_paid ) > floatval( $amount_sent );

			//initial payment is valid if it is equal to or greater than product/subscription amount
			if ( $is_equal || $is_greater ) {
				return true;
			}

			return false;

		}

		public function molpay_fulfillment( $entry, $molpay_config, $transaction_id, $amount ) {
			//no need to do anything for molpay when it runs this function, ignore
			return false;
		}

		/**
		* Editing of the payment details should only be possible if the entry was processed by MOLPay, if the payment status is Pending or Processing, and the transaction was not a subscription.
		*
		* @param array $entry The current entry
		* @param string $action The entry detail page action, edit or update.
		*
		* @return bool
		*/
		public function payment_details_editing_disabled( $entry, $action = 'edit' ) {
			if ( ! $this->is_payment_gateway( $entry['id'] ) ) {
				// Entry was not processed by this add-on, don't allow editing.
				return true;
			}

			$payment_status = rgar( $entry, 'payment_status' );
			if ( $payment_status == 'Approved' || $payment_status == 'Paid' || rgar( $entry, 'transaction_type' ) == 2 ) {
				// Editing not allowed for this entries transaction type or payment status.
				return true;
			}

			if ( $action == 'edit' && rgpost( 'screen_mode' ) == 'edit' ) {
				// Editing is allowed for this entry.
				return false;
			}

			if ( $action == 'update' && rgpost( 'screen_mode' ) == 'view' && rgpost( 'action' ) == 'update' ) {
				// Updating the payment details for this entry is allowed.
				return false;
			}

			// In all other cases editing is not allowed.

			return true;
		}

		public function uninstall()
		{
			parent::uninstall();
			global $wpdb;
			$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'molpay_gf_%'");
		}

		//------ FOR BACKWARDS COMPATIBILITY ----------------------//

		public function update_feed_id($old_feed_id, $new_feed_id)
		{
			global $wpdb;
			$sql = $wpdb->prepare("UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='molpay_feed_id' AND meta_value=%s", $new_feed_id, $old_feed_id);
			$wpdb->query($sql);
		}

		public function add_legacy_meta($new_meta, $old_feed)
		{

			$known_meta_keys = array(
				'email', 'mode', 'type', 'style', 'continue_text', 'cancel_url', 'disable_note', 'disable_shipping', 'recurring_amount_field', 'recurring_times',
				'recurring_retry', 'billing_cycle_number', 'billing_cycle_type', 'trial_period_enabled', 'trial_amount', 'trial_period_number', 'trial_period_type', 'delay_post',
				'update_post_action', 'delay_notifications', 'selected_notifications', 'molpay_conditional_enabled', 'molpay_conditional_field_id',
				'molpay_conditional_operator', 'molpay_conditional_value', 'customer_fields',
			);

			foreach ($old_feed['meta'] as $key => $value) {
				if (!in_array($key, $known_meta_keys)) {
					$new_meta[$key] = $value;
				}
			}

			return $new_meta;
		}

		//This function kept static for backwards compatibility
		public static function get_config_by_entry( $entry ) {

			$molpay = GFMolPay::get_instance();

			$feed = $molpay->get_payment_feed( $entry );

			if ( empty( $feed ) ) {
				return false;
			}

			return $feed['addon_slug'] == $molpay->_slug ? $feed : false;
		}

		//This function kept static for backwards compatibility
		//This needs to be here until all add-ons are on the framework, otherwise they look for this function
		public static function get_config( $form_id ) {

			$molpay = GFMolPay::get_instance();
			$feed   = $molpay->get_feeds( $form_id );

			//Ignore IPN messages from forms that are no longer configured with the MOLPay add-on
			if ( ! $feed ) {
				return false;
			}

			return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
		}
	}
