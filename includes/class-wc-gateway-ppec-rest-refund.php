<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * PayPal REST API refund handler.
 * Replaces NVP RefundTransaction with REST v2 refund API.
 */
class WC_Gateway_PPEC_REST_Refund {

	/**
	 * Refund an order using REST API.
	 *
	 * @throws \Exception
	 *
	 * @param WC_Order $order      Order to refund
	 * @param float    $amount     Amount to refund
	 * @param string   $refundType Type of refund (Partial or Full)
	 * @param string   $reason     Reason to refund
	 * @param string   $currency   Currency of refund
	 *
	 * @return null|string If exception is thrown, null is returned. Otherwise
	 *                     ID of refund transaction is returned.
	 */
	public static function refund_order( $order, $amount, $refundType, $reason, $currency ) {
		// Get the REST client
		$rest_client = self::get_rest_client();
		
		// Get transaction ID (capture ID for REST API)
		$transaction_id = $order->get_transaction_id();
		
		if ( empty( $transaction_id ) ) {
			throw new Exception( 'No transaction ID found for refund' );
		}

		// Prepare refund parameters
		$params = array(
			'AMT' => $amount,
			'CURRENCYCODE' => $currency,
			'NOTE' => $reason,
			'REFUNDTYPE' => $refundType,
		);

		try {
			// Call REST API refund
			$response = $rest_client->refund_capture( $transaction_id, $params );
			
			if ( ! empty( $response['id'] ) ) {
				return $response['id'];
			} else {
				throw new Exception( 'Invalid refund response: ' . wp_json_encode( $response ) );
			}
		} catch ( Exception $e ) {
			// Convert REST API exception to PayPal API exception for backward compatibility
			$error_response = array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'Refund Failed',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
			throw new PayPal_API_Exception( $error_response );
		}
	}

	/**
	 * Get REST client instance.
	 *
	 * @return WC_Gateway_PPEC_REST_Client
	 */
	protected static function get_rest_client() {
		// Get settings
		$settings = wc_gateway_ppec()->settings;
		
		// Create credential
		if ( 'live' === $settings->environment ) {
			$credential = new WC_Gateway_PPEC_Client_Credential_Signature(
				$settings->api_username,
				$settings->api_password,
				$settings->api_signature,
				$settings->api_subject
			);
		} else {
			$credential = new WC_Gateway_PPEC_Client_Credential_Signature(
				$settings->sandbox_api_username,
				$settings->sandbox_api_password,
				$settings->sandbox_api_signature,
				$settings->sandbox_api_subject
			);
		}
		
		// Create and return REST client
		return new WC_Gateway_PPEC_REST_Client( $credential, $settings->environment );
	}
}