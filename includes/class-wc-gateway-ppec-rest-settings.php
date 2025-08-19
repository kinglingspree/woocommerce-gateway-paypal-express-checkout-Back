<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Settings field configurations for PayPal Express Checkout REST API migration.
 * This adds REST API configuration options to the existing settings.
 */

// Add REST API settings to the existing form fields
add_filter( 'woocommerce_gateway_ppec_settings_form_fields', 'wc_gateway_ppec_add_rest_api_settings' );

/**
 * Add REST API settings to form fields.
 *
 * @param array $form_fields Existing form fields
 * @return array Modified form fields
 */
function wc_gateway_ppec_add_rest_api_settings( $form_fields ) {
	// REST API is now enabled by default, no need to show toggle options
	// Add Client ID/Secret fields only
	$rest_api_fields = array(
		'rest_api_section' => array(
			'title' => __( 'PayPal REST API v2', 'woocommerce-gateway-paypal-express-checkout' ),
			'type'  => 'title',
			'desc'  => __( 'This plugin now uses PayPal REST API v2 by default for better performance and modern authentication.', 'woocommerce-gateway-paypal-express-checkout' ),
		),
		
		'client_id_live' => array(
			'title'   => __( 'Live Client ID', 'woocommerce-gateway-paypal-express-checkout' ),
			'type'    => 'text',
			'desc'    => __( 'Get your Client ID from PayPal Developer Console for live environment.', 'woocommerce-gateway-paypal-express-checkout' ),
			'default' => '',
		),
		
		'client_secret_live' => array(
			'title'   => __( 'Live Client Secret', 'woocommerce-gateway-paypal-express-checkout' ),
			'type'    => 'password',
			'desc'    => __( 'Get your Client Secret from PayPal Developer Console for live environment.', 'woocommerce-gateway-paypal-express-checkout' ),
			'default' => '',
		),
		
		'client_id_sandbox' => array(
			'title'   => __( 'Sandbox Client ID', 'woocommerce-gateway-paypal-express-checkout' ),
			'type'    => 'text',
			'desc'    => __( 'Get your Client ID from PayPal Developer Console for sandbox environment.', 'woocommerce-gateway-paypal-express-checkout' ),
			'default' => '',
		),
		
		'client_secret_sandbox' => array(
			'title'   => __( 'Sandbox Client Secret', 'woocommerce-gateway-paypal-express-checkout' ),
			'type'    => 'password',
			'desc'    => __( 'Get your Client Secret from PayPal Developer Console for sandbox environment.', 'woocommerce-gateway-paypal-express-checkout' ),
			'default' => '',
		),
	);
	
	// Insert REST API fields after the debug field
	$position = array_search( 'debug', array_keys( $form_fields ), true );
	if ( false !== $position ) {
		$form_fields = array_slice( $form_fields, 0, $position + 1, true ) +
					   $rest_api_fields +
					   array_slice( $form_fields, $position + 1, null, true );
	} else {
		// If debug field not found, append to end
		$form_fields = array_merge( $form_fields, $rest_api_fields );
	}
	
	return $form_fields;
}

/**
 * Client credential class for REST API (uses Client ID/Secret instead of API credentials).
 */
class WC_Gateway_PPEC_Client_Credential_OAuth extends WC_Gateway_PPEC_Client_Credential {
	
	/**
	 * Client ID.
	 *
	 * @var string
	 */
	protected $_client_id;
	
	/**
	 * Client Secret.
	 *
	 * @var string
	 */
	protected $_client_secret;
	
	/**
	 * Constructor.
	 *
	 * @param string $client_id Client ID
	 * @param string $client_secret Client Secret
	 */
	public function __construct( $client_id, $client_secret ) {
		$this->_client_id = $client_id;
		$this->_client_secret = $client_secret;
	}
	
	/**
	 * Get client ID (used as username for REST API).
	 *
	 * @return string
	 */
	public function get_username() {
		return $this->_client_id;
	}
	
	/**
	 * Get client secret (used as password for REST API).
	 *
	 * @return string
	 */
	public function get_password() {
		return $this->_client_secret;
	}
	
	/**
	 * Get client ID.
	 *
	 * @return string
	 */
	public function get_client_id() {
		return $this->_client_id;
	}
	
	/**
	 * Get client secret.
	 *
	 * @return string
	 */
	public function get_client_secret() {
		return $this->_client_secret;
	}
	
	/**
	 * Get endpoint subdomain for REST API.
	 *
	 * @return string
	 */
	public function get_endpoint_subdomain() {
		return 'api-m'; // REST API uses api-m subdomain
	}
	
	/**
	 * Configure cURL for OAuth (matches parent signature).
	 *
	 * @param resource $handle The cURL handle
	 * @param array    $r      The HTTP request arguments
	 * @param string   $url    The request URL
	 */
	public function configure_curl( $handle, $r, $url ) {
		// For REST API, OAuth credentials are sent in Authorization header, not cURL options
		// Still need to call parent for SSL configuration
		parent::configure_curl( $handle, $r, $url );
	}
	
	/**
	 * Override get_request_params for OAuth (not used in REST API).
	 *
	 * @return array
	 */
	public function get_request_params() {
		// REST API doesn't use NVP-style parameters
		return array();
	}
}

/**
 * Enhanced settings class with REST API support.
 */
class WC_Gateway_PPEC_Settings_REST extends WC_Gateway_PPEC_Settings {
	
	/**
	 * Whether to use REST API.
	 *
	 * @var bool
	 */
	public $use_rest_api;
	
	/**
	 * Live Client ID.
	 *
	 * @var string
	 */
	public $client_id_live;
	
	/**
	 * Live Client Secret.
	 *
	 * @var string
	 */
	public $client_secret_live;
	
	/**
	 * Sandbox Client ID.
	 *
	 * @var string
	 */
	public $client_id_sandbox;
	
	/**
	 * Sandbox Client Secret.
	 *
	 * @var string
	 */
	public $client_secret_sandbox;
	
	
	/**
	 * Load settings from database.
	 *
	 * @param bool $force_reload Whether to force reload settings
	 */
	public function load( $force_reload = false ) {
		parent::load( $force_reload );
		
		// Load REST API specific settings using property access
		$this->use_rest_api = 'yes' === ( $this->use_rest_api ?? 'yes' );
		$this->client_id_live = $this->client_id ?? '';
		$this->client_secret_live = $this->client_secret ?? '';
		$this->client_id_sandbox = $this->sandbox_client_id ?? '';
		$this->client_secret_sandbox = $this->sandbox_client_secret ?? '';
	}
	
	/**
	 * Get setting value with default fallback.
	 *
	 * @param string $key Setting key
	 * @param mixed $default Default value
	 * @return mixed Setting value
	 */
	public function get( $key, $default = null ) {
		return $this->__get( $key ) ?? $default;
	}
	
	/**
	 * Get current client ID based on environment.
	 *
	 * @return string
	 */
	public function get_current_client_id() {
		return 'live' === $this->environment ? $this->client_id_live : $this->client_id_sandbox;
	}
	
	/**
	 * Get current client secret based on environment.
	 *
	 * @return string
	 */
	public function get_current_client_secret() {
		return 'live' === $this->environment ? $this->client_secret_live : $this->client_secret_sandbox;
	}
	
	/**
	 * Check if REST API should be used based on migration mode.
	 *
	 * @param string $context Operation context (new_order, existing_order, refund, etc.)
	 * @return bool
	 */
	public function should_use_rest_api( $context = 'default' ) {
		if ( ! $this->use_rest_api ) {
			return false;
		}
		return true;
	}
	
	/**
	 * Check if REST API credentials are configured.
	 *
	 * @return bool
	 */
	public function has_rest_api_credentials() {
		$client_id = $this->get_current_client_id();
		$client_secret = $this->get_current_client_secret();
		
		return ! empty( $client_id ) && ! empty( $client_secret );
	}
}