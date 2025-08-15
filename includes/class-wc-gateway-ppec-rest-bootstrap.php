<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Bootstrap class for PayPal REST API v2 integration.
 * Ensures proper loading order and initialization.
 */
class WC_Gateway_PPEC_REST_Bootstrap {

	/**
	 * Initialize REST API v2 components.
	 * This should be called after the main plugin components are loaded.
	 */
	public static function init() {
		// Hook into plugins_loaded to ensure main plugin is ready
		add_action( 'plugins_loaded', array( __CLASS__, 'load_rest_components' ), 25 );
		
		// Add settings integration hook
		add_filter( 'woocommerce_paypal_express_checkout_settings', array( __CLASS__, 'integrate_rest_settings' ), 10 );
	}

	/**
	 * Load REST API components after main plugin is ready.
	 */
	public static function load_rest_components() {
		// Ensure main plugin and WooCommerce are available
		if ( ! function_exists( 'wc_gateway_ppec' ) || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Wait for main plugin to initialize its core classes
		add_action( 'init', array( __CLASS__, 'load_after_plugin_init' ), 30 );
	}

	/**
	 * Load REST components after main plugin initialization.
	 */
	public static function load_after_plugin_init() {
		// Check if main plugin credential classes are loaded
		if ( ! class_exists( 'WC_Gateway_PPEC_Client_Credential' ) ) {
			// Force load the credential classes
			$includes_path = plugin_dir_path( __FILE__ );
			$abstracts_path = $includes_path . 'abstracts/abstract-wc-gateway-ppec-client-credential.php';
			
			if ( file_exists( $abstracts_path ) ) {
				require_once $abstracts_path;
			}
		}

		// Now load our REST components
		self::load_rest_files();
		
		// Initialize REST API integration
		self::init_rest_integration();
	}

	/**
	 * Load REST API files in correct order.
	 */
	public static function load_rest_files() {
		$includes_path = plugin_dir_path( __FILE__ );

		// Load components in dependency order
		$files = array(
			'class-wc-gateway-ppec-parameter-mapper.php',
			'class-wc-gateway-ppec-rest-client.php',
			'class-wc-gateway-ppec-rest-adapter.php',
			'class-wc-gateway-ppec-rest-refund.php',
			'class-wc-gateway-ppec-client-factory.php',
			'class-wc-gateway-ppec-rest-settings.php', // Load after credential classes
		);

		foreach ( $files as $file ) {
			$file_path = $includes_path . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}
	}

	/**
	 * Initialize REST API integration.
	 */
	public static function init_rest_integration() {
		// Check if we should enable REST API integration
		$settings = wc_gateway_ppec()->settings;
		
		if ( ! $settings ) {
			return;
		}
		
		// Add admin notices for REST API status
		add_action( 'admin_notices', array( __CLASS__, 'rest_api_admin_notices' ) );
		
		// Add AJAX handlers for testing REST API credentials
		add_action( 'wp_ajax_ppec_test_rest_credentials', array( __CLASS__, 'ajax_test_rest_credentials' ) );
		
		// Add admin scripts for settings page
		add_action( 'admin_footer', array( __CLASS__, 'rest_api_settings_script' ) );
	}

	/**
	 * Integrate REST API settings with existing settings.
	 *
	 * @param array $settings Existing settings
	 * @return array Modified settings
	 */
	public static function integrate_rest_settings( $settings ) {
		// Only modify settings if REST settings class is available
		if ( class_exists( 'WC_Gateway_PPEC_REST_Settings' ) ) {
			return WC_Gateway_PPEC_REST_Settings::filter_settings( $settings );
		}
		return $settings;
	}

	/**
	 * Display admin notices for REST API status.
	 */
	public static function rest_api_admin_notices() {
		// Only show on PayPal settings page
		if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['section'] ) || 
			 'wc-settings' !== $_GET['page'] || 
			 'checkout' !== $_GET['tab'] || 
			 'ppec_paypal' !== $_GET['section'] ) {
			return;
		}
		
		$settings = wc_gateway_ppec()->settings;
		
		// Check if REST API is enabled but credentials are missing
		if ( method_exists( $settings, 'get_option' ) ) {
			$use_rest = $settings->get_option( 'use_rest_api', 'no' );
			$client_id = $settings->get_option( 'client_id', '' );
			$sandbox_client_id = $settings->get_option( 'sandbox_client_id', '' );
			$environment = $settings->get_option( 'environment', 'live' );
			
			if ( 'yes' === $use_rest ) {
				$missing_credentials = false;
				if ( 'live' === $environment && empty( $client_id ) ) {
					$missing_credentials = true;
				} elseif ( 'sandbox' === $environment && empty( $sandbox_client_id ) ) {
					$missing_credentials = true;
				}
				
				if ( $missing_credentials ) {
					?>
					<div class="notice notice-warning is-dismissible">
						<p>
							<strong><?php esc_html_e( 'PayPal REST API Warning:', 'woocommerce-gateway-paypal-express-checkout' ); ?></strong>
							<?php esc_html_e( 'REST API is enabled but Client ID and Client Secret are not configured. Please add your REST API credentials.', 'woocommerce-gateway-paypal-express-checkout' ); ?>
						</p>
					</div>
					<?php
				}
			}
		}
	}

	/**
	 * AJAX handler for testing REST API credentials.
	 */
	public static function ajax_test_rest_credentials() {
		// Check nonce and permissions
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ppec_rest_test' ) ) {
			wp_die( 'Invalid nonce' );
		}
		
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}
		
		$environment = sanitize_text_field( $_POST['environment'] ?? 'sandbox' );
		$client_id = sanitize_text_field( $_POST['client_id'] ?? '' );
		$client_secret = sanitize_text_field( $_POST['client_secret'] ?? '' );
		
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Client ID and Client Secret are required.', 'woocommerce-gateway-paypal-express-checkout' ) ) );
		}
		
		try {
			// Create credential and test
			if ( class_exists( 'WC_Gateway_PPEC_Client_Credential_OAuth' ) ) {
				$credential = new WC_Gateway_PPEC_Client_Credential_OAuth( $client_id, $client_secret );
				$client = new WC_Gateway_PPEC_REST_Client( $credential, $environment );
				
				// Test by getting access token
				$reflection = new ReflectionClass( $client );
				$method = $reflection->getMethod( '_get_access_token' );
				$method->setAccessible( true );
				$token = $method->invoke( $client );
				
				if ( ! empty( $token ) ) {
					wp_send_json_success( array( 
						'message' => __( 'REST API credentials are valid and working.', 'woocommerce-gateway-paypal-express-checkout' ) 
					) );
				} else {
					wp_send_json_error( array( 
						'message' => __( 'Failed to obtain access token.', 'woocommerce-gateway-paypal-express-checkout' ) 
					) );
				}
			} else {
				wp_send_json_error( array( 
					'message' => __( 'OAuth credential class not available.', 'woocommerce-gateway-paypal-express-checkout' ) 
				) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 
				'message' => sprintf( 
					__( 'REST API test failed: %s', 'woocommerce-gateway-paypal-express-checkout' ), 
					$e->getMessage() 
				) 
			) );
		}
	}

	/**
	 * Add JavaScript for REST API settings page.
	 */
	public static function rest_api_settings_script() {
		// Only enqueue on PayPal settings page
		if ( ! isset( $_GET['page'], $_GET['tab'], $_GET['section'] ) || 
			 'wc-settings' !== $_GET['page'] || 
			 'checkout' !== $_GET['tab'] || 
			 'ppec_paypal' !== $_GET['section'] ) {
			return;
		}
		
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Show/hide REST API settings based on checkbox
			$('#woocommerce_ppec_paypal_use_rest_api').on('change', function() {
				var restFields = $(this).closest('table').find('tr').filter(function() {
					var id = $(this).find('input, select').attr('id') || '';
					return id.includes('client_id') || id.includes('client_secret');
				});
				
				if ($(this).is(':checked')) {
					restFields.show();
				} else {
					restFields.hide();
				}
			}).trigger('change');
		});
		</script>
		<?php
	}
}

// Initialize bootstrap
WC_Gateway_PPEC_REST_Bootstrap::init();