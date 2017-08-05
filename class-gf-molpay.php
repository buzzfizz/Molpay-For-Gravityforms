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
	protected $_title = 'MolPay For Gravity Forms';
	protected $_short_title = 'MolPay';
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
		return array(
			array(
				'title'       => '',
				'description' => 'Please update your Merchant ID below',
				'fields'      => array(
					array(
						'name'    => 'gf_molpay_merchant_id',
						'label'   => esc_html__( 'Merchant ID', 'gravityformsmolpay' ),
						'type'    => 'text'
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
		if ( ! rgar( $settings, 'gf_molpay_merchant_id' ) ) {
			return sprintf( esc_html__( 'To get started, please configure your %sMolPay Settings%s!', 'gravityformsmolpay' ), '<a href="' . admin_url( 'admin.php?page=gf_settings&subview=' . $this->_slug ) . '">', '</a>' );
		} else {
			return parent::feed_list_no_item_message();
		}
	}

	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		//--add MolPay VCode field
		$fields = array(
			array(
				'name'     => 'molpayVCode',
				'label'    => esc_html__( 'Molpay VCode ', 'gravityformsmolpay' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'MolPay Verification Code', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the molpay verification code from your merchant profile page.', 'gravityformsmolpay' )
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

		//--add Page Style, Continue Button Label, Cancel URL
		$fields = array(
			array(
				'name'     => 'cancelUrl',
				'label'    => esc_html__( 'Cancel URL', 'gravityformsmolpay' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => false,
				'tooltip'  => '<h6>' . esc_html__( 'Cancel URL', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the URL the user should be sent to should they cancel before completing their molpay payment.', 'gravityformsmolpay' )
			),
		);

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
//		$add_first_name = true;
//		$add_last_name  = true;
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

	public function redirect_url($feed, $submission_data, $form, $entry)
	{
		if ( ! rgempty( 'gf_molpay_return', $_GET ) ) {
			return false;
		}

		// update payment status to processing
		GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Processing' );
		$url = '';
		$vcode_hash = '';
		$amount = rgar( $submission_data, 'payment_amount' );
		$order_id = rgar( $entry, 'id' );
		$url .= "&amount={$amount}&orderid={$order_id}";

		$customer_details = array(
			array( 'name' => 'bill_name', 'meta_name' => 'billingInformation_fullName'	),
			array( 'name' => 'bill_mobile', 'meta_name' => 'billingInformation_phoneNum'	),
			array( 'name' => 'bill_email', 'meta_name' => 'billingInformation_email'	),
			// array( 'name' => 'address', 'meta_name' => 'billingInformation_address'	),
			// array( 'name' => 'address2', 'meta_name' => 'billingInformation_address2'	),
			// array( 'name' => 'city', 'meta_name' => 'billingInformation_city'	),
			// array( 'name' => 'state', 'meta_name' => 'billingInformation_state'	),
			// array( 'name' => 'zip', 'meta_name' => 'billingInformation_zip'	),
			array( 'name' => 'country', 'meta_name' => 'billingInformation_country'	)
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

		//generate vcode_hash

		$vcode_hash = md5($amount . $feed[''] . $order_id . $feed['molpayVCode'])


		//end generate vcode_hash
		// print_r($feed);
		// print_r($submission_data);
		// print_r($entry);
		// print_r($form);
		print_r($url);

	}

	public function init_admin() {

		parent::init_admin();

		//add actions to allow the payment status to be modified
		add_action( 'gform_payment_status', array( $this, 'admin_edit_payment_status' ), 3, 3 );
		add_action( 'gform_payment_date', array( $this, 'admin_edit_payment_date' ), 3, 3 );
		add_action( 'gform_payment_transaction_id', array( $this, 'admin_edit_payment_transaction_id' ), 3, 3 );
		add_action( 'gform_payment_amount', array( $this, 'admin_edit_payment_amount' ), 3, 3 );
		add_action( 'gform_after_update_entry', array( $this, 'admin_update_payment' ), 4, 2 );

//		add_filter( 'gform_addon_navigation', array( $this, 'maybe_create_menu' ) );
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
	 * Editing of the payment details should only be possible if the entry was processed by MolPay, if the payment status is Pending or Processing, and the transaction was not a subscription.
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

		//Ignore IPN messages from forms that are no longer configured with the MolPay add-on
		if ( ! $feed ) {
			return false;
		}

		return $feed[0]; //only one feed per form is supported (left for backwards compatibility)
	}
}
