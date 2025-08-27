<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * PayPal REST v2 API client for Express Checkout conversion.
 * Replaces NVP API calls with REST v2 equivalents.
 *
 * @see https://developer.paypal.com/docs/api/orders/v2/
 */
class WC_Gateway_PPEC_REST_Client {

	/**
	 * Client credential.
	 *
	 * @var WC_Gateway_PPEC_Client_Credential
	 */
	protected $_credential;

	/**
	 * PayPal environment. Either 'sandbox' or 'live'.
	 *
	 * @var string
	 */
	protected $_environment;

	/**
	 * OAuth 2.0 access token
	 *
	 * @var string
	 */
	protected $_access_token;

	/**
	 * Access token expiry time
	 *
	 * @var int
	 */
	protected $_token_expires_at;

	const INVALID_CREDENTIAL_ERROR  = 1;
	const INVALID_ENVIRONMENT_ERROR = 2;
	const REQUEST_ERROR             = 3;
	const API_VERSION               = 'v2';

	/**
	 * Constructor.
	 *
	 * @param mixed  $credential  Client's credential
	 * @param string $environment Client's environment
	 */
	public function __construct( $credential, $environment = 'live' ) {
		$this->_environment = $environment;

		if ( is_a( $credential, 'WC_Gateway_PPEC_Client_Credential' ) ) {
			$this->set_credential( $credential );
		}
	}

	/**
	 * Set credential for the client.
	 *
	 * @param WC_Gateway_PPEC_Client_Credential $credential Client's credential
	 */
	public function set_credential( WC_Gateway_PPEC_Client_Credential $credential ) {
		$this->_credential = $credential;
	}

	/**
	 * Set environment for the client.
	 *
	 * @param string $environment Environment. Either 'live' or 'sandbox'
	 */
	public function set_environment( $environment ) {
		if ( ! in_array( $environment, array( 'live', 'sandbox' ), true ) ) {
			$environment = 'live';
		}

		$this->_environment = $environment;
	}

	/**
	 * Get PayPal REST API endpoint.
	 *
	 * @return string
	 */
	public function get_endpoint() {
		return sprintf(
			'https://api.%spaypal.com',
			'sandbox' === $this->_environment ? 'sandbox.' : ''
		);
	}

	/**
	 * Get OAuth 2.0 access token with automatic refresh.
	 *
	 * @return string
	 * @throws Exception
	 */
	protected function _get_access_token() {
		// Check if token exists and is not expired
		if ( $this->_access_token && $this->_token_expires_at && time() < $this->_token_expires_at - 60 ) {
			return $this->_access_token;
		}

		// Request new token
		$client_id     = $this->_credential->get_username();
		$client_secret = $this->_credential->get_password();
		
		$auth_url = $this->get_endpoint() . '/v1/oauth2/token';
		
		$headers = array(
			'Accept'        => 'application/json',
			'Accept-Language' => 'en_US',
			'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);

		$body = 'grant_type=client_credentials';

		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => $body,
			'timeout' => 70,
		);

		$this->_log_request( __FUNCTION__, 'POST', $auth_url, $headers, $body );

		$response = wp_remote_request( $auth_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->_log_response( __FUNCTION__, 'ERROR', 0, array(), $response->get_error_message() );
			throw new Exception( 'OAuth request failed: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_headers = wp_remote_retrieve_headers( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$this->_log_response( __FUNCTION__, 'POST', $status_code, $response_headers, $response_body );

		if ( 200 !== $status_code ) {
			throw new Exception( 'OAuth failed with status: ' . $status_code . ', Response: ' . $response_body );
		}

		$auth_response = json_decode( $response_body, true );

		if ( ! isset( $auth_response['access_token'] ) ) {
			throw new Exception( 'Invalid OAuth response: ' . $response_body );
		}

		$this->_access_token = $auth_response['access_token'];
		$this->_token_expires_at = time() + $auth_response['expires_in'];

		return $this->_access_token;
	}

	/**
	 * Make REST API request.
	 *
	 * @param string $method HTTP method
	 * @param string $endpoint API endpoint
	 * @param array  $data Request data
	 * @return array
	 * @throws Exception
	 */
	protected function _request( $method, $endpoint, $data = array() ) {
		$access_token = $this->_get_access_token();
		$url = $this->get_endpoint() . $endpoint;

		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $access_token,
			'PayPal-Request-Id' => wp_generate_uuid4(),
		);

		$body = empty( $data ) ? '' : wp_json_encode( $data );

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => 70,
		);

		$this->_log_request( __FUNCTION__, $method, $url, $headers, $body );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->_log_response( __FUNCTION__, $method, 0, array(), $response->get_error_message() );
			throw new Exception( 'REST API request failed: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_headers = wp_remote_retrieve_headers( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$this->_log_response( __FUNCTION__, $method, $status_code, $response_headers, $response_body );

		// Both 200 and 201 indicate success
		if ( ! in_array( $status_code, array( 200, 201 ), true ) ) {
			throw new Exception( 'REST API failed with status: ' . $status_code . ', Response: ' . $response_body );
		}

		return json_decode( $response_body, true );
	}

	/**
	 * Log API request for debugging.
	 *
	 * @param string $function_name Function name
	 * @param string $method HTTP method
	 * @param string $url Request URL
	 * @param array  $headers Request headers
	 * @param string $body Request body
	 */
	protected function _log_request( $function_name, $method, $url, $headers, $body ) {
		if ( wc_gateway_ppec()->settings->debug ) {
			$log_message = sprintf(
				'%s + Request - Method: %s, URL: %s, Headers: %s, Body: %s',
				$function_name,
				$method,
				$url,
				wp_json_encode( $headers ),
				$body
			);
			
			// Log to WooCommerce logs
			wc_gateway_ppec_log( $log_message );
			
			// Also log to php_error.log as requested
			error_log( '[PayPal REST API] ' . $log_message );
		}
	}

	/**
	 * Log API response for debugging.
	 *
	 * @param string $function_name Function name
	 * @param string $method HTTP method
	 * @param int    $status_code Response status code
	 * @param array  $headers Response headers
	 * @param string $body Response body
	 */
	protected function _log_response( $function_name, $method, $status_code, $headers, $body ) {
		if ( wc_gateway_ppec()->settings->debug ) {
			$log_message = sprintf(
				'%s + Response - Status Code: %d, Headers: %s, Body: %s',
				$function_name,
				$status_code,
				wp_json_encode( $headers ),
				$body
			);
			
			// Log to WooCommerce logs
			wc_gateway_ppec_log( $log_message );
			
			// Also log to php_error.log as requested
			error_log( '[PayPal REST API] ' . $log_message );
		}
	}

	/**
	 * Create order (replaces SetExpressCheckout).
	 *
	 * @param array $params Order parameters
	 * @return array REST response
	 */
	public function create_order( array $params ) {
		$order_data = $this->_convert_nvp_to_rest_order( $params );
		return $this->_request( 'POST', '/v2/checkout/orders', $order_data );
	}

	/**
	 * Get order details (replaces GetExpressCheckoutDetails).
	 *
	 * @param string $order_id PayPal order ID
	 * @return array REST response
	 */
	public function get_order_details( $order_id ) {
		return $this->_request( 'GET', '/v2/checkout/orders/' . $order_id );
	}

	/**
	 * Capture order (replaces DoExpressCheckoutPayment for sale).
	 *
	 * @param string $order_id PayPal order ID
	 * @param array  $params Capture parameters
	 * @return array REST response
	 */
	public function capture_order( $order_id, array $params = array() ) {
		$capture_data = array();
		if ( ! empty( $params['note_to_payer'] ) ) {
			$capture_data['note_to_payer'] = $params['note_to_payer'];
		}
		return $this->_request( 'POST', '/v2/checkout/orders/' . $order_id . '/capture', $capture_data );
	}

	/**
	 * Authorize order (replaces DoExpressCheckoutPayment for authorization).
	 *
	 * @param string $order_id PayPal order ID
	 * @param array  $params Authorization parameters
	 * @return array REST response
	 */
	public function authorize_order( $order_id, array $params = array() ) {
		$auth_data = array();
		if ( ! empty( $params['note_to_payer'] ) ) {
			$auth_data['note_to_payer'] = $params['note_to_payer'];
		}
		return $this->_request( 'POST', '/v2/checkout/orders/' . $order_id . '/authorize', $auth_data );
	}

	/**
	 * Capture authorized payment (replaces DoCapture).
	 *
	 * @param string $authorization_id Authorization ID
	 * @param array  $params Capture parameters
	 * @return array REST response
	 */
	public function capture_authorization( $authorization_id, array $params ) {
		$capture_data = array(
			'amount' => array(
				'currency_code' => $params['CURRENCYCODE'],
				'value' => $params['AMT'],
			),
		);

		if ( ! empty( $params['NOTE'] ) ) {
			$capture_data['note_to_payer'] = $params['NOTE'];
		}

		if ( ! empty( $params['INVNUM'] ) ) {
			$capture_data['invoice_id'] = $params['INVNUM'];
		}

		return $this->_request( 'POST', '/v2/payments/authorizations/' . $authorization_id . '/capture', $capture_data );
	}

	/**
	 * Void authorization (replaces DoVoid).
	 *
	 * @param string $authorization_id Authorization ID
	 * @param array  $params Void parameters
	 * @return array REST response
	 */
	public function void_authorization( $authorization_id, array $params = array() ) {
		$void_data = array();
		if ( ! empty( $params['NOTE'] ) ) {
			$void_data['note_to_payer'] = $params['NOTE'];
		}
		return $this->_request( 'POST', '/v2/payments/authorizations/' . $authorization_id . '/void', $void_data );
	}

	/**
	 * Reauthorize payment (replaces DoReauthorization).
	 *
	 * @param string $authorization_id Authorization ID
	 * @param array  $params Reauthorization parameters
	 * @return array REST response
	 */
	public function reauthorize_payment( $authorization_id, array $params ) {
		$reauth_data = array(
			'amount' => array(
				'currency_code' => $params['CURRENCYCODE'],
				'value' => $params['AMT'],
			),
		);
		return $this->_request( 'POST', '/v2/payments/authorizations/' . $authorization_id . '/reauthorize', $reauth_data );
	}

	/**
	 * Refund captured payment (replaces RefundTransaction).
	 *
	 * @param string $capture_id Capture ID
	 * @param array  $params Refund parameters
	 * @return array REST response
	 */
	public function refund_capture( $capture_id, array $params ) {
		$refund_data = array();

		if ( ! empty( $params['AMT'] ) ) {
			$refund_data['amount'] = array(
				'currency_code' => $params['CURRENCYCODE'],
				'value' => $params['AMT'],
			);
		}

		if ( ! empty( $params['NOTE'] ) ) {
			$refund_data['note_to_payer'] = $params['NOTE'];
		}

		if ( ! empty( $params['INVNUM'] ) ) {
			$refund_data['invoice_id'] = $params['INVNUM'];
		}

		return $this->_request( 'POST', '/v2/payments/captures/' . $capture_id . '/refund', $refund_data );
	}

	/**
	 * Get capture details (replaces GetTransactionDetails for captures).
	 *
	 * @param string $capture_id Capture ID
	 * @return array REST response
	 */
	public function get_capture_details( $capture_id ) {
		return $this->_request( 'GET', '/v2/payments/captures/' . $capture_id );
	}

	/**
	 * Get authorization details (replaces GetTransactionDetails for authorizations).
	 *
	 * @param string $authorization_id Authorization ID
	 * @return array REST response
	 */
	public function get_authorization_details( $authorization_id ) {
		return $this->_request( 'GET', '/v2/payments/authorizations/' . $authorization_id );
	}


	/**
		* Check if shipping address meets PayPal REST API v2 requirements.
		* Based on official PayPal documentation: Country and region address requirements
		*
		* @param array $nvp_params NVP parameters
		* @return bool True if shipping address is complete enough
	*/
	protected function _is_shipping_address_complete( array $nvp_params ) {
	$country_code = strtoupper( $nvp_params['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] ?? '' );
	$city = $nvp_params['PAYMENTREQUEST_0_SHIPTOCITY'] ?? '';
	$postal_code = $nvp_params['PAYMENTREQUEST_0_SHIPTOZIP'] ?? '';

	// Countries that require postal code according to PayPal Orders v2 API documentation
	$countries_requiring_postal_code = array(
	'AR', 'AU', 'AT', 'BT', 'BR', 'CA', 'C2', 'CN', 'CC', 'KM', 'DK', 'FK', 'FO', 'TF', 'FR', 'GM', 'DE', 
	'GL', 'IT', 'JP', 'KI', 'KG', 'MR', 'YT', 'MX', 'NL', 'NR', 'NE', 'NU', 'NF', 'NO', 'PN', 'PL', 
	'SM', 'SG', 'ES', 'SH', 'PM', 'SR', 'SJ', 'SE', 'CH', 'TH', 'TK', 'TV', 'GB', 'US', 'UM', 'VA', 
	'WF', 'EH'
	);

	// Countries that do NOT require city (most require city, but these are exceptions)
	$countries_not_requiring_city = array( 'HK', 'SG', 'JP' );

	// Check basic requirements
	$has_country = ! empty( $country_code );
	$has_city = ! empty( $city ) || in_array( $country_code, $countries_not_requiring_city, true );
	$has_postal_code_if_required = ! in_array( $country_code, $countries_requiring_postal_code, true ) || ! empty( $postal_code );

	return $has_country && $has_city && $has_postal_code_if_required;
	}  

	/**
	 * Convert NVP parameters to REST order structure.
	 *
	 * @param array $nvp_params NVP parameters
	 * @return array REST order data
	 */
	protected function _convert_nvp_to_rest_order( array $nvp_params ) {
		$order_data = array(
			'intent' => $this->_get_intent_from_paymentaction( $nvp_params ),
			'purchase_units' => array(),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'return_url' => $nvp_params['RETURNURL'],
						'cancel_url' => $nvp_params['CANCELURL'],
					),
				),
			),
		);

		// Set brand name if provided
		if ( ! empty( $nvp_params['BRANDNAME'] ) ) {
			$order_data['payment_source']['paypal']['experience_context']['brand_name'] = $nvp_params['BRANDNAME'];
		}

		// Set landing page
		if ( ! empty( $nvp_params['LANDINGPAGE'] ) ) {
			$landing_page = strtolower( $nvp_params['LANDINGPAGE'] );
			$order_data['payment_source']['paypal']['experience_context']['landing_page'] = $landing_page === 'billing' ? 'BILLING' : 'LOGIN';
		}

		// Set shipping preference based on NOSHIPPING parameter and available shipping address
		$has_shipping_address = ! empty( $nvp_params['SHIPTONAME'] ) || ! empty( $nvp_params['SHIPTOSTREET'] ) || 
		                        ! empty( $nvp_params['PAYMENTREQUEST_0_SHIPTONAME'] ) || ! empty( $nvp_params['PAYMENTREQUEST_0_SHIPTOSTREET'] );
		
		if ( isset( $nvp_params['NOSHIPPING'] ) ) {
			if ( $nvp_params['NOSHIPPING'] == 1 ) {
				// No shipping needed
				$order_data['payment_source']['paypal']['experience_context']['shipping_preference'] = 'NO_SHIPPING';
			} elseif ( $has_shipping_address && $this->_is_shipping_address_complete( $nvp_params ) ) {
				// Shipping needed and we have address - use provided address
				$order_data['payment_source']['paypal']['experience_context']['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
			} else {
				// Shipping needed but no address provided - get from PayPal file
				$order_data['payment_source']['paypal']['experience_context']['shipping_preference'] = 'GET_FROM_FILE';
			}
		} else {
            // Default behavior: if we have shipping address, force use it
			if ( $has_shipping_address && $this->_is_shipping_address_complete( $nvp_params ) ) {
			    $order_data['payment_source']['paypal']['experience_context']['shipping_preference'] = 'SET_PROVIDED_ADDRESS';
			} else {
		    	$order_data['payment_source']['paypal']['experience_context']['shipping_preference'] = 'GET_FROM_FILE';
			}
	    }


		// Build purchase unit
		$purchase_unit = array(
			'reference_id' => 'default',
		);

		// Add amount details
		if ( ! empty( $nvp_params['PAYMENTREQUEST_0_AMT'] ) ) {
			$purchase_unit['amount'] = array(
				'currency_code' => $nvp_params['PAYMENTREQUEST_0_CURRENCYCODE'] ?? 'USD',
				'value' => $nvp_params['PAYMENTREQUEST_0_AMT'],
			);

			// Add breakdown if item amount is provided
			if ( ! empty( $nvp_params['PAYMENTREQUEST_0_ITEMAMT'] ) ) {
				$breakdown = array(
					'item_total' => array(
						'currency_code' => $nvp_params['PAYMENTREQUEST_0_CURRENCYCODE'] ?? 'USD',
						'value' => $nvp_params['PAYMENTREQUEST_0_ITEMAMT'],
					),
				);

				if ( ! empty( $nvp_params['PAYMENTREQUEST_0_SHIPPINGAMT'] ) ) {
					$breakdown['shipping'] = array(
						'currency_code' => $nvp_params['PAYMENTREQUEST_0_CURRENCYCODE'] ?? 'USD',
						'value' => $nvp_params['PAYMENTREQUEST_0_SHIPPINGAMT'],
					);
				}

				if ( ! empty( $nvp_params['PAYMENTREQUEST_0_TAXAMT'] ) ) {
					$breakdown['tax_total'] = array(
						'currency_code' => $nvp_params['PAYMENTREQUEST_0_CURRENCYCODE'] ?? 'USD',
						'value' => $nvp_params['PAYMENTREQUEST_0_TAXAMT'],
					);
				}

				$purchase_unit['amount']['breakdown'] = $breakdown;
			}
		}

		// Add invoice ID
		if ( ! empty( $nvp_params['PAYMENTREQUEST_0_INVNUM'] ) ) {
			$purchase_unit['invoice_id'] = $nvp_params['PAYMENTREQUEST_0_INVNUM'];
		}

		// Add items if present
		$items = $this->_extract_line_items_from_nvp( $nvp_params );
		if ( ! empty( $items ) ) {
			$purchase_unit['items'] = $items;
		}

		// Add shipping address if provided (check both SHIPTO and PAYMENTREQUEST_0_SHIPTO formats)
		$shipto_name = $nvp_params['SHIPTONAME'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTONAME'] ?? '';
		$shipto_street = $nvp_params['SHIPTOSTREET'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTOSTREET'] ?? '';
		
		if ( ! empty( $shipto_name ) || ! empty( $shipto_street ) ) {
			$shipping = array();
			
			if ( ! empty( $shipto_name ) ) {
				$shipping['name'] = array(
					'full_name' => $shipto_name,
				);
			}

			$address = array();
			if ( ! empty( $shipto_street ) ) {
				$address['address_line_1'] = $shipto_street;
			}
			
			$shipto_street2 = $nvp_params['SHIPTOSTREET2'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTOSTREET2'] ?? '';
			if ( ! empty( $shipto_street2 ) ) {
				$address['address_line_2'] = $shipto_street2;
			}
			
			$shipto_city = $nvp_params['SHIPTOCITY'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTOCITY'] ?? '';
			if ( ! empty( $shipto_city ) ) {
				$address['admin_area_2'] = $shipto_city;
			}
			
			$shipto_state = $nvp_params['SHIPTOSTATE'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTOSTATE'] ?? '';
			if ( ! empty( $shipto_state ) ) {
				$address['admin_area_1'] = $shipto_state;
			}
			
			$shipto_zip = $nvp_params['SHIPTOZIP'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTOZIP'] ?? '';
			if ( ! empty( $shipto_zip ) ) {
				$address['postal_code'] = $shipto_zip;
			}
			
			$shipto_country = $nvp_params['SHIPTOCOUNTRYCODE'] ?? $nvp_params['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] ?? '';
			if ( ! empty( $shipto_country ) ) {
				$address['country_code'] = $shipto_country;
			}

			if ( ! empty( $address ) ) {
				$shipping['address'] = $address;
			}

			if ( ! empty( $shipping ) ) {
				$purchase_unit['shipping'] = $shipping;
			}
		}

		// If PayPal will provide the shipping address from the buyer's file,
		// do not include any shipping information in the purchase unit.
		$shipping_pref = $order_data['payment_source']['paypal']['experience_context']['shipping_preference'] ?? '';
		if ( 'GET_FROM_FILE' === $shipping_pref && isset( $purchase_unit['shipping'] ) ) {
			unset( $purchase_unit['shipping'] );
		}

		$order_data['purchase_units'][] = $purchase_unit;

		return $order_data;
	}

	/**
	 * Extract line items from NVP parameters.
	 *
	 * @param array $nvp_params NVP parameters
	 * @return array Line items
	 */
	protected function _extract_line_items_from_nvp( array $nvp_params ) {
		$items = array();
		$i = 0;

		while ( isset( $nvp_params["L_NAME{$i}"] ) ) {
			$item = array(
				'name' => $nvp_params["L_NAME{$i}"],
				'quantity' => $nvp_params["L_QTY{$i}"] ?? '1',
				'unit_amount' => array(
					'currency_code' => $nvp_params['PAYMENTREQUEST_0_CURRENCYCODE'] ?? 'USD',
					'value' => $nvp_params["L_AMT{$i}"] ?? '0.00',
				),
			);

			if ( ! empty( $nvp_params["L_DESC{$i}"] ) ) {
				$item['description'] = $nvp_params["L_DESC{$i}"];
			}

			if ( ! empty( $nvp_params["L_NUMBER{$i}"] ) ) {
				$item['sku'] = $nvp_params["L_NUMBER{$i}"];
			}

			$items[] = $item;
			$i++;
		}

		return $items;
	}

	/**
	 * Get intent from payment action.
	 *
	 * @param array $nvp_params NVP parameters
	 * @return string Intent
	 */
	protected function _get_intent_from_paymentaction( array $nvp_params ) {
		$payment_action = $nvp_params['PAYMENTREQUEST_0_PAYMENTACTION'] ?? 'sale';
		return strtolower( $payment_action ) === 'authorization' ? 'AUTHORIZE' : 'CAPTURE';
	}

	/**
	 * Check if response indicates success.
	 *
	 * @param array $response REST response
	 * @return bool
	 */
	public function response_has_success_status( $response ) {
		return ! empty( $response['id'] ) && ! empty( $response['status'] );
	}
}