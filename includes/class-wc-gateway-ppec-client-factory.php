<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Client factory for PayPal API clients.
 * Provides unified interface for both NVP and REST API clients based on settings.
 */
class WC_Gateway_PPEC_Client_Factory {

	/**
	 * Cached REST client instance.
	 *
	 * @var WC_Gateway_PPEC_REST_Client
	 */
	protected static $_rest_client;

	/**
	 * Cached REST adapter instance.
	 *
	 * @var WC_Gateway_PPEC_REST_Adapter
	 */
	protected static $_rest_adapter;

	/**
	 * Get the appropriate client based on settings and context.
	 *
	 * @param string $context Operation context
	 * @return WC_Gateway_PPEC_Client|WC_Gateway_PPEC_REST_Adapter
	 */
	public static function get_client( $context = 'default' ) {
		$settings = wc_gateway_ppec()->settings;

		// Force REST API usage - no fallback to NVP
		if ( method_exists( $settings, 'should_use_rest_api' ) && $settings->should_use_rest_api( $context ) ) {
			return self::get_rest_adapter( $context );
		}

		// This should never happen now, but log if it does
		error_log( '[PayPal Debug] WARNING: should_use_rest_api() returned false or method missing!' );
		throw new Exception( 'PayPal REST API v2 is required but not properly configured' );
	}


	/**
	 * Get REST client instance.
	 *
	 * @return WC_Gateway_PPEC_REST_Client
	 */
	public static function get_rest_client() {
		if ( null === self::$_rest_client ) {
			$settings = wc_gateway_ppec()->settings;
			
			try {
				$credential = self::create_rest_credential( $settings );
				self::$_rest_client = new WC_Gateway_PPEC_REST_Client( $credential, $settings->environment );
			} catch ( Exception $e ) {
				error_log( '[PayPal Debug] REST client creation failed: ' . $e->getMessage() );
				throw $e; // Re-throw to prevent fallback to NVP
			}
		}

		return self::$_rest_client;
	}

	/**
	 * Get REST adapter instance (provides NVP-compatible interface).
	 *
	 * @param string $context Operation context
	 * @return WC_Gateway_PPEC_REST_Adapter
	 */
	public static function get_rest_adapter( $context = 'default' ) {
		if ( null === self::$_rest_adapter ) {
			$rest_client = self::get_rest_client();
			self::$_rest_adapter = new WC_Gateway_PPEC_REST_Adapter( $rest_client );
		}

		return self::$_rest_adapter;
	}


	/**
	 * Create REST credential from settings.
	 *
	 * @param object $settings Settings object
	 * @return WC_Gateway_PPEC_Client_Credential_OAuth
	 */
	protected static function create_rest_credential( $settings ) {
		$environment = $settings->environment;
		
		if ( method_exists( $settings, 'get_current_client_id' ) ) {
			$client_id = $settings->get_current_client_id();
			$client_secret = $settings->get_current_client_secret();
		} else {
			// Use direct property access for settings
			if ( 'live' === $environment ) {
				$client_id = $settings->client_id ?? '';
				$client_secret = $settings->client_secret ?? '';
			} else {
				$client_id = $settings->sandbox_client_id ?? '';
				$client_secret = $settings->sandbox_client_secret ?? '';
			}
		}

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			$error_msg = "No valid PayPal REST API credentials found for {$environment} environment. Client ID: " . ( empty( $client_id ) ? 'EMPTY' : 'SET' ) . ', Client Secret: ' . ( empty( $client_secret ) ? 'EMPTY' : 'SET' );
			error_log( '[PayPal Debug] ' . $error_msg );
			throw new Exception( $error_msg );
		}

		return new WC_Gateway_PPEC_Client_Credential_OAuth( $client_id, $client_secret );
	}


	/**
	 * Clear cached client instances.
	 */
	public static function clear_cache() {
		self::$_rest_client = null;
		self::$_rest_adapter = null;
	}

	/**
	 * Get the appropriate refund handler based on settings.
	 *
	 * @return string Class name for refund handler
	 */
	public static function get_refund_handler_class() {
		$settings = wc_gateway_ppec()->settings;

		if ( method_exists( $settings, 'should_use_rest_api' ) && $settings->should_use_rest_api( 'refund' ) ) {
			return 'WC_Gateway_PPEC_REST_Refund';
		}

		return 'WC_Gateway_PPEC_Refund';
	}

	/**
	 * Process refund using appropriate handler.
	 *
	 * @param WC_Order $order Order to refund
	 * @param float    $amount Amount to refund
	 * @param string   $refund_type Type of refund
	 * @param string   $reason Refund reason
	 * @param string   $currency Currency
	 * @return string Refund transaction ID
	 */
	public static function process_refund( $order, $amount, $refund_type, $reason, $currency ) {
		$handler_class = self::get_refund_handler_class();
		
		if ( ! class_exists( $handler_class ) ) {
			throw new Exception( "Refund handler class {$handler_class} not found" );
		}

		return call_user_func( 
			array( $handler_class, 'refund_order' ), 
			$order, 
			$amount, 
			$refund_type, 
			$reason, 
			$currency 
		);
	}
}

/**
 * Enhanced plugin class that uses the client factory.
 */
class WC_Gateway_PPEC_Plugin_Enhanced extends WC_Gateway_PPEC_Plugin {

	/**
	 * Client instance.
	 *
	 * @var WC_Gateway_PPEC_Client|WC_Gateway_PPEC_REST_Adapter
	 */
	public $client;

	/**
	 * Initialize the plugin with appropriate client.
	 */
	public function init() {
		parent::init();

		// Override the client with factory-created client
		$this->client = WC_Gateway_PPEC_Client_Factory::get_client();
	}

	/**
	 * Get client for specific context.
	 *
	 * @param string $context Operation context
	 * @return WC_Gateway_PPEC_Client|WC_Gateway_PPEC_REST_Adapter
	 */
	public function get_client( $context = 'default' ) {
		return WC_Gateway_PPEC_Client_Factory::get_client( $context );
	}
}