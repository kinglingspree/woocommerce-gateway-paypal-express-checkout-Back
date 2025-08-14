<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Parameter mapping utility for converting between NVP and REST v2 API responses.
 * Handles the conversion of PayPal response data from REST format to NVP-like format
 * for backward compatibility.
 */
class WC_Gateway_PPEC_Parameter_Mapper {

	/**
	 * Map REST order creation response to NVP SetExpressCheckout format.
	 *
	 * @param array $rest_response REST API response
	 * @return array NVP-like response
	 */
	public static function map_create_order_response( array $rest_response ) {
		$nvp_response = array(
			'TOKEN' => $rest_response['id'] ?? null,
			'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
			'VERSION' => null, // No direct mapping
			'BUILD' => null,   // No direct mapping
			'TIMESTAMP' => $rest_response['create_time'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ),
			'CORRELATIONID' => null, // No direct mapping
		);

		// Extract approval URL
		if ( ! empty( $rest_response['links'] ) ) {
			foreach ( $rest_response['links'] as $link ) {
				if ( $link['rel'] === 'approve' || $link['rel'] === 'payer-action' ) {
					$nvp_response['REDIRECT_URL'] = $link['href'];
					break;
				}
			}
		}

		return $nvp_response;
	}

	/**
	 * Map REST order details response to NVP GetExpressCheckoutDetails format.
	 *
	 * @param array $rest_response REST API response
	 * @return array NVP-like response
	 */
	public static function map_order_details_response( array $rest_response ) {
		$nvp_response = array(
			'TOKEN' => $rest_response['id'] ?? null,
			'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
			'VERSION' => null,
			'BUILD' => null,
			'TIMESTAMP' => $rest_response['create_time'] ?? null,
			'CORRELATIONID' => null,
			'SUCCESSPAGEREDIRECTREQUESTED' => null,
		);

		// Payer information
		if ( ! empty( $rest_response['payer'] ) ) {
			$payer = $rest_response['payer'];
			
			$nvp_response['EMAIL'] = $payer['email_address'] ?? null;
			$nvp_response['PAYERID'] = $payer['payer_id'] ?? null;
			$nvp_response['PAYERSTATUS'] = $rest_response['payment_source']['paypal']['account_status'] ?? null;
			
			if ( ! empty( $payer['name'] ) ) {
				$nvp_response['FIRSTNAME'] = $payer['name']['given_name'] ?? null;
				$nvp_response['LASTNAME'] = $payer['name']['surname'] ?? null;
			}
			
			if ( ! empty( $payer['address'] ) ) {
				$nvp_response['COUNTRYCODE'] = $payer['address']['country_code'] ?? null;
			}
		}

		// Purchase unit information
		if ( ! empty( $rest_response['purchase_units'][0] ) ) {
			$unit = $rest_response['purchase_units'][0];
			
			// Amount details
			if ( ! empty( $unit['amount'] ) ) {
				$nvp_response['AMT'] = $unit['amount']['value'] ?? null;
				$nvp_response['CURRENCYCODE'] = $unit['amount']['currency_code'] ?? null;
				$nvp_response['PAYMENTREQUEST_0_AMT'] = $unit['amount']['value'] ?? null;
				$nvp_response['PAYMENTREQUEST_0_CURRENCYCODE'] = $unit['amount']['currency_code'] ?? null;
			}

			// Shipping information
			if ( ! empty( $unit['shipping'] ) ) {
				$shipping = $unit['shipping'];
				
				if ( ! empty( $shipping['name'] ) ) {
					$nvp_response['SHIPTONAME'] = $shipping['name']['full_name'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTONAME'] = $shipping['name']['full_name'] ?? null;
				}
				
				if ( ! empty( $shipping['address'] ) ) {
					$address = $shipping['address'];
					$nvp_response['SHIPTOSTREET'] = $address['address_line_1'] ?? null;
					$nvp_response['SHIPTOSTREET2'] = $address['address_line_2'] ?? null;
					$nvp_response['SHIPTOCITY'] = $address['admin_area_2'] ?? null;
					$nvp_response['SHIPTOSTATE'] = $address['admin_area_1'] ?? null;
					$nvp_response['SHIPTOZIP'] = $address['postal_code'] ?? null;
					$nvp_response['SHIPTOCOUNTRYCODE'] = $address['country_code'] ?? null;
					$nvp_response['SHIPTOCOUNTRYNAME'] = null; // No direct mapping
					
					// Duplicate for PAYMENTREQUEST_0_
					$nvp_response['PAYMENTREQUEST_0_SHIPTOSTREET'] = $address['address_line_1'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTOSTREET2'] = $address['address_line_2'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTOCITY'] = $address['admin_area_2'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTOSTATE'] = $address['admin_area_1'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTOZIP'] = $address['postal_code'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE'] = $address['country_code'] ?? null;
					$nvp_response['PAYMENTREQUEST_0_SHIPTOCOUNTRYNAME'] = null;
				}
			}

			// Payee information
			if ( ! empty( $unit['payee'] ) ) {
				$nvp_response['PAYMENTREQUEST_0_SELLERPAYPALACCOUNTID'] = $unit['payee']['email_address'] ?? null;
			}

			// Breakdown amounts
			if ( ! empty( $unit['amount']['breakdown'] ) ) {
				$breakdown = $unit['amount']['breakdown'];
				$nvp_response['SHIPPINGAMT'] = $breakdown['shipping']['value'] ?? null;
				$nvp_response['HANDLINGAMT'] = null; // No direct mapping
				$nvp_response['TAXAMT'] = $breakdown['tax_total']['value'] ?? null;
				$nvp_response['INSURANCEAMT'] = null; // No direct mapping
				$nvp_response['SHIPDISCAMT'] = null; // No direct mapping
				
				// Duplicate for PAYMENTREQUEST_0_
				$nvp_response['PAYMENTREQUEST_0_SHIPPINGAMT'] = $breakdown['shipping']['value'] ?? null;
				$nvp_response['PAYMENTREQUEST_0_HANDLINGAMT'] = null;
				$nvp_response['PAYMENTREQUEST_0_TAXAMT'] = $breakdown['tax_total']['value'] ?? null;
				$nvp_response['PAYMENTREQUEST_0_INSURANCEAMT'] = null;
				$nvp_response['PAYMENTREQUEST_0_SHIPDISCAMT'] = null;
			}
		}

		// Transaction ID (if captured)
		if ( ! empty( $rest_response['purchase_units'][0]['payments']['captures'][0] ) ) {
			$capture = $rest_response['purchase_units'][0]['payments']['captures'][0];
			$nvp_response['TRANSACTIONID'] = $capture['id'] ?? null;
			$nvp_response['PAYMENTREQUEST_0_TRANSACTIONID'] = $capture['id'] ?? null;
			$nvp_response['PAYMENTREQUESTINFO_0_TRANSACTIONID'] = $capture['id'] ?? null;
		}

		// Additional fields with no mapping
		$nvp_response['ADDRESSSTATUS'] = null;
		$nvp_response['INSURANCEOPTIONOFFERED'] = null;
		$nvp_response['PAYMENTREQUEST_0_INSURANCEOPTIONOFFERED'] = null;
		$nvp_response['PAYMENTREQUEST_0_ADDRESSSTATUS'] = null;
		$nvp_response['PAYMENTREQUESTINFO_0_ERRORCODE'] = null;

		return $nvp_response;
	}

	/**
	 * Map REST capture response to NVP DoExpressCheckoutPayment format.
	 *
	 * @param array $rest_response REST API response
	 * @return array NVP-like response
	 */
	public static function map_capture_response( array $rest_response ) {
		$nvp_response = array(
			'TOKEN' => $rest_response['id'] ?? null,
			'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
			'VERSION' => null,
			'BUILD' => null,
			'TIMESTAMP' => null,
			'CORRELATIONID' => null,
			'SUCCESSPAGEREDIRECTREQUESTED' => null,
			'INSURANCEOPTIONSELECTED' => null,
			'SHIPPINGOPTIONISDEFAULT' => null,
		);

		// Get capture information
		if ( ! empty( $rest_response['purchase_units'][0]['payments']['captures'][0] ) ) {
			$capture = $rest_response['purchase_units'][0]['payments']['captures'][0];
			
			$nvp_response['PAYMENTINFO_0_TRANSACTIONID'] = $capture['id'] ?? null;
			$nvp_response['PAYMENTINFO_0_TRANSACTIONTYPE'] = null; // No direct mapping
			$nvp_response['PAYMENTINFO_0_PAYMENTTYPE'] = null; // No direct mapping
			$nvp_response['PAYMENTINFO_0_ORDERTIME'] = $capture['create_time'] ?? null;
			$nvp_response['PAYMENTINFO_0_AMT'] = $capture['amount']['value'] ?? null;
			$nvp_response['PAYMENTINFO_0_CURRENCYCODE'] = $capture['amount']['currency_code'] ?? null;
			$nvp_response['PAYMENTINFO_0_PAYMENTSTATUS'] = self::map_capture_status( $capture['status'] ?? '' );
			$nvp_response['PAYMENTINFO_0_PENDINGREASON'] = null; // No direct mapping
			$nvp_response['PAYMENTINFO_0_REASONCODE'] = null; // No direct mapping
			$nvp_response['PAYMENTINFO_0_ERRORCODE'] = null; // No direct mapping
			$nvp_response['PAYMENTINFO_0_ACK'] = null; // No direct mapping
			
			// Update timestamps
			$nvp_response['TIMESTAMP'] = $capture['create_time'] ?? $capture['update_time'] ?? null;
			
			// Fee information
			if ( ! empty( $capture['seller_receivable_breakdown']['paypal_fee'] ) ) {
				$nvp_response['PAYMENTINFO_0_FEEAMT'] = $capture['seller_receivable_breakdown']['paypal_fee']['value'];
			} else {
				$nvp_response['PAYMENTINFO_0_FEEAMT'] = null;
			}
			
			$nvp_response['PAYMENTINFO_0_TAXAMT'] = null; // No direct mapping
			
			// Seller protection
			if ( ! empty( $capture['seller_protection'] ) ) {
				$protection = $capture['seller_protection'];
				$nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITY'] = $protection['status'] ?? null;
				if ( ! empty( $protection['dispute_categories'] ) && is_array( $protection['dispute_categories'] ) ) {
					$nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITYTYPE'] = implode( ',', $protection['dispute_categories'] );
				} else {
					$nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITYTYPE'] = null;
				}
			} else {
				$nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITY'] = null;
				$nvp_response['PAYMENTINFO_0_PROTECTIONELIGIBILITYTYPE'] = null;
			}
		}

		// Seller PayPal account ID
		if ( ! empty( $rest_response['purchase_units'][0]['payee'] ) ) {
			$nvp_response['PAYMENTINFO_0_SELLERPAYPALACCOUNTID'] = $rest_response['purchase_units'][0]['payee']['email_address'] ?? null;
		} else {
			$nvp_response['PAYMENTINFO_0_SELLERPAYPALACCOUNTID'] = null;
		}
		
		$nvp_response['PAYMENTINFO_0_SECUREMERCHANTACCOUNTID'] = null; // No direct mapping

		return $nvp_response;
	}

	/**
	 * Map REST refund response to NVP RefundTransaction format.
	 *
	 * @param array $rest_response REST API response
	 * @return array NVP-like response
	 */
	public static function map_refund_response( array $rest_response ) {
		return array(
			'REFUNDTRANSACTIONID' => $rest_response['id'] ?? null,
			'FEEREFUNDAMT' => $rest_response['seller_payable_breakdown']['paypal_fee']['value'] ?? null,
			'GROSSREFUNDAMT' => $rest_response['amount']['value'] ?? null,
			'NETREFUNDAMT' => $rest_response['seller_payable_breakdown']['net_amount']['value'] ?? null,
			'CURRENCYCODE' => $rest_response['amount']['currency_code'] ?? null,
			'TOTALREFUNDEDAMOUNT' => $rest_response['amount']['value'] ?? null,
			'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
			'VERSION' => null,
			'BUILD' => null,
			'TIMESTAMP' => $rest_response['create_time'] ?? null,
			'CORRELATIONID' => null,
		);
	}

	/**
	 * Map REST authorization response to NVP format.
	 *
	 * @param array $rest_response REST API response
	 * @return array NVP-like response
	 */
	public static function map_authorization_response( array $rest_response ) {
		$nvp_response = array(
			'TOKEN' => $rest_response['id'] ?? null,
			'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
			'VERSION' => null,
			'BUILD' => null,
			'TIMESTAMP' => null,
			'CORRELATIONID' => null,
		);

		// Get authorization information
		if ( ! empty( $rest_response['purchase_units'][0]['payments']['authorizations'][0] ) ) {
			$auth = $rest_response['purchase_units'][0]['payments']['authorizations'][0];
			
			$nvp_response['PAYMENTINFO_0_TRANSACTIONID'] = $auth['id'] ?? null;
			$nvp_response['PAYMENTINFO_0_AMT'] = $auth['amount']['value'] ?? null;
			$nvp_response['PAYMENTINFO_0_CURRENCYCODE'] = $auth['amount']['currency_code'] ?? null;
			$nvp_response['PAYMENTINFO_0_PAYMENTSTATUS'] = self::map_authorization_status( $auth['status'] ?? '' );
			$nvp_response['PAYMENTINFO_0_ORDERTIME'] = $auth['create_time'] ?? null;
			$nvp_response['TIMESTAMP'] = $auth['create_time'] ?? $auth['update_time'] ?? null;
		}

		return $nvp_response;
	}

	/**
	 * Map REST capture status to NVP payment status.
	 *
	 * @param string $rest_status REST capture status
	 * @return string NVP payment status
	 */
	protected static function map_capture_status( $rest_status ) {
		$status_map = array(
			'COMPLETED' => 'Completed',
			'DECLINED' => 'Declined',
			'PARTIALLY_REFUNDED' => 'Partially-Refunded',
			'PENDING' => 'Pending',
			'REFUNDED' => 'Refunded',
		);

		return $status_map[ strtoupper( $rest_status ) ] ?? $rest_status;
	}

	/**
	 * Map REST authorization status to NVP payment status.
	 *
	 * @param string $rest_status REST authorization status
	 * @return string NVP payment status
	 */
	protected static function map_authorization_status( $rest_status ) {
		$status_map = array(
			'CREATED' => 'Pending',
			'CAPTURED' => 'Completed',
			'DENIED' => 'Denied',
			'EXPIRED' => 'Expired',
			'PARTIALLY_CAPTURED' => 'Partially-Captured',
			'VOIDED' => 'Voided',
			'PENDING' => 'Pending',
		);

		return $status_map[ strtoupper( $rest_status ) ] ?? $rest_status;
	}

	/**
	 * Log unmapped fields for debugging.
	 *
	 * @param array $response Response array
	 * @param array $mapped_fields Already mapped fields
	 * @param string $context Context for logging
	 */
	public static function log_unmapped_fields( $response, $mapped_fields, $context ) {
		if ( ! wc_gateway_ppec()->settings->debug ) {
			return;
		}

		$unmapped = array();
		
		// Find fields that exist in response but not in mapped_fields
		self::find_unmapped_recursive( $response, $mapped_fields, '', $unmapped );
		
		if ( ! empty( $unmapped ) ) {
			wc_gateway_ppec_log( sprintf( 
				'Unmapped fields in %s: %s', 
				$context, 
				wp_json_encode( $unmapped ) 
			) );
		}
	}

	/**
	 * Recursively find unmapped fields.
	 *
	 * @param mixed  $response_data Response data
	 * @param mixed  $mapped_data Mapped data
	 * @param string $prefix Field prefix
	 * @param array  $unmapped Reference to unmapped fields array
	 */
	protected static function find_unmapped_recursive( $response_data, $mapped_data, $prefix, &$unmapped ) {
		if ( ! is_array( $response_data ) ) {
			return;
		}

		foreach ( $response_data as $key => $value ) {
			$full_key = $prefix ? $prefix . '.' . $key : $key;
			
			if ( is_array( $value ) ) {
				$mapped_value = is_array( $mapped_data ) && isset( $mapped_data[ $key ] ) ? $mapped_data[ $key ] : null;
				self::find_unmapped_recursive( $value, $mapped_value, $full_key, $unmapped );
			} else {
				// Check if this field was mapped
				$is_mapped = false;
				if ( is_array( $mapped_data ) ) {
					foreach ( $mapped_data as $mapped_value ) {
						if ( $mapped_value === $value ) {
							$is_mapped = true;
							break;
						}
					}
				}
				
				if ( ! $is_mapped ) {
					$unmapped[ $full_key ] = $value;
				}
			}
		}
	}
}