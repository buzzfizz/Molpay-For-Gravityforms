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
	protected $_title = 'Gravity Forms MolPay Standard Add-On';
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

		//--add PayPal Email Address field
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
		$transaction_type['choices'][1]['label'] = 'MolPay';

		$default_settings = $this->replace_field('transactionType', $transaction_type, $default_settings);

		//-------------------------------------------------------------------------------

		//--add Page Style, Continue Button Label, Cancel URL
		$fields = array(
			array(
				'name'     => 'continueText',
				'label'    => esc_html__( 'Continue Button Label', 'gravityformsmolpay' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => false,
				'tooltip'  => '<h6>' . esc_html__( 'Continue Button Label', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the text that should appear on the continue button once payment has been completed via molpay.', 'gravityformsmolpay' )
			),
			array(
				'name'     => 'cancelUrl',
				'label'    => esc_html__( 'Cancel URL', 'gravityformsmolpay' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => false,
				'tooltip'  => '<h6>' . esc_html__( 'Cancel URL', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enter the URL the user should be sent to should they cancel before completing their molpay payment.', 'gravityformsmolpay' )
			),
		);


		//Add post fields if form has a post
		$form = $this->get_current_form();
		if ( GFCommon::has_post_field( $form['fields'] ) ) {
			$post_settings = array(
				'name'    => 'post_checkboxes',
				'label'   => esc_html__( 'Posts', 'gravityformsmolpay' ),
				'type'    => 'checkbox',
				'tooltip' => '<h6>' . esc_html__( 'Posts', 'gravityformsmolpay' ) . '</h6>' . esc_html__( 'Enable this option if you would like to only create the post after payment has been received.', 'gravityformsmolpay' ),
				'choices' => array(
					array( 'label' => esc_html__( 'Create post only when payment is received.', 'gravityformsmolpay' ), 'name' => 'delayPost' ),
				),
			);

			$fields[] = $post_settings;
		}

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
//		$add_first_name = true;
//		$add_last_name  = true;
		foreach ( $billing_fields as $mapping ) {
			//add full name if it does not already exist in billing fields
			if ( $mapping['name'] == 'fullName' ) {
				$add_full_name = false;
			}
		}

		if ( $add_full_name ) {
			//add last name
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
//		parent::redirect_url($feed, $submission_data, $form, $entry); // TODO: Change the autogenerated stub
		echo '<pre>';
		print_r($feed);
		print_r($submission_data['fullName']);
		print_r($form);
		print_r($entry);
		echo '</pre>';

	}
}