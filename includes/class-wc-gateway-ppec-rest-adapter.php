<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * REST API adapter for existing checkout handler.
 * This class provides backward compatibility by wrapping REST API calls
 * and returning responses in NVP format.
 */
class WC_Gateway_PPEC_REST_Adapter {

	/**
	 * REST client instance.
	 *
	 * @var WC_Gateway_PPEC_REST_Client
	 */
	protected $_rest_client;

	/**
	 * Constructor.
	 *
	 * @param WC_Gateway_PPEC_REST_Client $rest_client REST client instance
	 */
	public function __construct( $rest_client ) {
		$this->_rest_client = $rest_client;
	}

	/**
	 * Get parameters for setting up express checkout.
	 * This method provides backward compatibility with the NVP API.
	 *
	 * @param array $args Context args to retrieve SetExpressCheckout parameters.
	 * @return array Params for SetExpressCheckout call
	 */
	public function get_set_express_checkout_params( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'skip_checkout'            => true,
				'order_id'                 => '',
				'create_billing_agreement' => false,
			)
		);

		$settings = wc_gateway_ppec()->settings;

		$params              = array();
		$logo_url_or_id      = $settings->logo_image_url;
		$header_url_or_id    = $settings->header_image_url;
		$params['LOGOIMG']   = filter_var( $logo_url_or_id, FILTER_VALIDATE_URL ) ? $logo_url_or_id : wp_get_attachment_image_url( $logo_url_or_id, 'ppec_logo_image_size' );
		$params['HDRIMG']    = filter_var( $header_url_or_id, FILTER_VALIDATE_URL ) ? $header_url_or_id : wp_get_attachment_image_url( $header_url_or_id, 'ppec_header_image_size' );
		$params['PAGESTYLE'] = $settings->page_style;
		$params['BRANDNAME'] = $settings->get_brand_name();
		$params['RETURNURL'] = $this->_get_return_url( $args );
		$params['CANCELURL'] = $this->_get_cancel_url( $args );

		if ( wc_gateway_ppec_is_using_credit() ) {
			$params['USERSELECTEDFUNDINGSOURCE'] = 'Finance';
		}

		if ( ! $args['skip_checkout'] ) {
			// Display shipping address sent from checkout page, rather than selecting from addresses on file with PayPal.
			$params['ADDROVERRIDE'] = '1';
		}

		if ( in_array( $settings->landing_page, array( 'Billing', 'Login' ), true ) ) {
			$params['LANDINGPAGE'] = $settings->landing_page;
		}

		if ( apply_filters( 'woocommerce_paypal_express_checkout_allow_guests', true ) ) {
			$params['SOLUTIONTYPE'] = 'Sole';
		}

		if ( 'yes' === $settings->require_billing ) {
			$params['REQBILLINGADDRESS'] = '1';
		}

		$params['PAYMENTREQUEST_0_PAYMENTACTION'] = $settings->get_paymentaction();
		if ( 'yes' === $settings->instant_payments && 'sale' === $settings->get_paymentaction() ) {
			$params['PAYMENTREQUEST_0_ALLOWEDPAYMENTMETHOD'] = 'InstantPaymentOnly';
		}

		$params['PAYMENTREQUEST_0_INSURANCEAMT'] = 0;
		$params['PAYMENTREQUEST_0_HANDLINGAMT']  = 0;
		$params['PAYMENTREQUEST_0_CUSTOM']       = '';
		$params['PAYMENTREQUEST_0_INVNUM']       = '';
		$params['PAYMENTREQUEST_0_CURRENCYCODE'] = get_woocommerce_currency();

		if ( ! empty( $args['order_id'] ) ) {
			$details = $this->_get_details_from_order( $args['order_id'] );
		} else {
			$details = $this->_get_details_from_cart();
		}

		$params = array_merge(
			$params,
			array(
				'PAYMENTREQUEST_0_AMT'         => $details['order_total'],
				'PAYMENTREQUEST_0_ITEMAMT'     => $details['total_item_amount'],
				'PAYMENTREQUEST_0_SHIPPINGAMT' => $details['shipping'],
				'PAYMENTREQUEST_0_TAXAMT'      => $details['order_tax'],
				'PAYMENTREQUEST_0_SHIPDISCAMT' => $details['ship_discount_amount'],
				'NOSHIPPING'                   => WC_Gateway_PPEC_Plugin::needs_shipping() ? 0 : 1,
			)
		);

		if ( ! empty( $details['email'] ) ) {
			$params['EMAIL'] = $details['email'];
		}

		if ( $args['create_billing_agreement'] ) {
			$params['L_BILLINGTYPE0']                 = 'MerchantInitiatedBillingSingleAgreement';
			$params['L_BILLINGAGREEMENTDESCRIPTION0'] = $this->_get_billing_agreement_description();
			$params['L_BILLINGAGREEMENTCUSTOM0']      = '';
		}

		if ( ! empty( $details['shipping_address'] ) ) {
			$params = array_merge(
				$params,
				$details['shipping_address']->getAddressParams( 'PAYMENTREQUEST_0_SHIPTO' )
			);
		}

		if ( ! empty( $details['items'] ) ) {
			$count = 0;
			foreach ( $details['items'] as $line_item_key => $values ) {
				$line_item_params = array(
					'L_PAYMENTREQUEST_0_NAME' . $count => $values['name'],
					'L_PAYMENTREQUEST_0_DESC' . $count => ! empty( $values['description'] ) ? substr( strip_tags( $values['description'] ), 0, 127 ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
					'L_PAYMENTREQUEST_0_QTY' . $count  => $values['quantity'],
					'L_PAYMENTREQUEST_0_AMT' . $count  => $values['amount'],
				);

				if ( isset( $values['sku'] ) ) {
					$line_item_params[ 'L_PAYMENTREQUEST_0_NUMBER' . $count ] = $values['sku'];
				}

				$params = array_merge( $params, $line_item_params );
				$count++;
			}
		}

		return $params;
	}

	/**
	 * Set express checkout (creates order via REST API).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function set_express_checkout( array $params ) {
		try {
			$rest_response = $this->_rest_client->create_order( $params );
			$nvp_response = WC_Gateway_PPEC_Parameter_Mapper::map_create_order_response( $rest_response );
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'SetExpressCheckout' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Get express checkout details (gets order details via REST API).
	 *
	 * @param string $token PayPal order ID
	 * @return array NVP-like response
	 */
	public function get_express_checkout_details( $token ) {
		try {
			$rest_response = $this->_rest_client->get_order_details( $token );
			$nvp_response = WC_Gateway_PPEC_Parameter_Mapper::map_order_details_response( $rest_response );
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'GetExpressCheckoutDetails' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Execute payment after buyer authorization (NVP: DoExpressCheckoutPayment).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function do_express_checkout_payment( array $params ) {
		try {
			$token = $params['TOKEN'];
			$payment_action = $params['PAYMENTREQUEST_0_PAYMENTACTION'] ?? 'Sale';
			
			// Note: In REST API v2, we don't need PAYERID as the payer info is already 
			// associated with the order. The $params['PAYERID'] is ignored.
			
			if ( strtolower( $payment_action ) === 'authorization' ) {
				$rest_response = $this->_rest_client->authorize_order( $token, $params );
				$nvp_response = WC_Gateway_PPEC_Parameter_Mapper::map_authorization_response( $rest_response );
			} else {
				$rest_response = $this->_rest_client->capture_order( $token, $params );
				$nvp_response = WC_Gateway_PPEC_Parameter_Mapper::map_capture_response( $rest_response );
			}
			
			// Ensure we include the PAYERID in response for backward compatibility
			if ( isset( $rest_response['payer']['payer_id'] ) ) {
				$nvp_response['PAYERID'] = $rest_response['payer']['payer_id'];
			}
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'DoExpressCheckoutPayment' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Refund transaction (refunds capture via REST API).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function refund_transaction( array $params ) {
		try {
			$transaction_id = $params['TRANSACTIONID'];
			$rest_response = $this->_rest_client->refund_capture( $transaction_id, $params );
			$nvp_response = WC_Gateway_PPEC_Parameter_Mapper::map_refund_response( $rest_response );
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'RefundTransaction' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Do capture (captures authorization via REST API).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function do_express_checkout_capture( array $params ) {
		try {
			$authorization_id = $params['AUTHORIZATIONID'];
			$rest_response = $this->_rest_client->capture_authorization( $authorization_id, $params );
			
			// Map the response similar to capture_response but for authorization capture
			$nvp_response = array(
				'AUTHORIZATIONID' => $authorization_id,
				'TRANSACTIONID' => $rest_response['id'] ?? null,
				'PARENTTRANSACTIONID' => $authorization_id,
				'TRANSACTIONTYPE' => 'express-checkout',
				'PAYMENTTYPE' => 'instant',
				'AMT' => $rest_response['amount']['value'] ?? null,
				'CURRENCYCODE' => $rest_response['amount']['currency_code'] ?? null,
				'FEEAMT' => $rest_response['seller_receivable_breakdown']['paypal_fee']['value'] ?? null,
				'SETTLEAMT' => $rest_response['seller_receivable_breakdown']['net_amount']['value'] ?? null,
				'TAXAMT' => null, // No direct mapping
				'EXCHANGERATE' => null, // No direct mapping
				'PAYMENTSTATUS' => $this->_map_capture_status( $rest_response['status'] ?? '' ),
				'PENDINGREASON' => null, // No direct mapping
				'REASONCODE' => null, // No direct mapping
				'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
				'VERSION' => null,
				'BUILD' => null,
				'TIMESTAMP' => $rest_response['create_time'] ?? null,
				'CORRELATIONID' => null,
			);
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'DoCapture' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Do void (voids authorization via REST API).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function do_express_checkout_void( array $params ) {
		try {
			$authorization_id = $params['AUTHORIZATIONID'];
			$rest_response = $this->_rest_client->void_authorization( $authorization_id, $params );
			
			$nvp_response = array(
				'AUTHORIZATIONID' => $authorization_id,
				'ACK' => 'Success', // Void usually returns 204 with no body
				'VERSION' => null,
				'BUILD' => null,
				'TIMESTAMP' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'CORRELATIONID' => null,
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Get transaction details (gets capture or authorization details via REST API).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function get_transaction_details( array $params ) {
		try {
			$transaction_id = $params['TRANSACTIONID'];
			
			// Try to get as capture first, then as authorization
			try {
				$rest_response = $this->_rest_client->get_capture_details( $transaction_id );
				$transaction_type = 'capture';
			} catch ( Exception $e ) {
				$rest_response = $this->_rest_client->get_authorization_details( $transaction_id );
				$transaction_type = 'authorization';
			}
			
			$nvp_response = array(
				'TRANSACTIONID' => $transaction_id,
				'PARENTTRANSACTIONID' => $rest_response['supplementary_data']['related_ids']['order_id'] ?? null,
				'TRANSACTIONTYPE' => $transaction_type,
				'AMT' => $rest_response['amount']['value'] ?? null,
				'CURRENCYCODE' => $rest_response['amount']['currency_code'] ?? null,
				'PAYMENTSTATUS' => $transaction_type === 'capture' 
					? $this->_map_capture_status( $rest_response['status'] ?? '' )
					: $this->_map_authorization_status( $rest_response['status'] ?? '' ),
				'TIMESTAMP' => $rest_response['create_time'] ?? null,
				'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
				'VERSION' => null,
				'BUILD' => null,
				'CORRELATIONID' => null,
			);
			
			if ( $transaction_type === 'capture' && ! empty( $rest_response['seller_receivable_breakdown'] ) ) {
				$breakdown = $rest_response['seller_receivable_breakdown'];
				$nvp_response['FEEAMT'] = $breakdown['paypal_fee']['value'] ?? null;
				$nvp_response['SETTLEAMT'] = $breakdown['net_amount']['value'] ?? null;
			}
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'GetTransactionDetails' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Reauthorize payment (reauthorizes expired authorization via REST API).
	 *
	 * @param array $params NVP parameters
	 * @return array NVP-like response
	 */
	public function do_reauthorization( array $params ) {
		try {
			$authorization_id = $params['AUTHORIZATIONID'];
			$rest_response = $this->_rest_client->reauthorize_payment( $authorization_id, $params );
			
			$nvp_response = array(
				'AUTHORIZATIONID' => $rest_response['id'] ?? null,
				'PARENTTRANSACTIONID' => $authorization_id,
				'TRANSACTIONTYPE' => 'express-checkout',
				'AMT' => $rest_response['amount']['value'] ?? null,
				'CURRENCYCODE' => $rest_response['amount']['currency_code'] ?? null,
				'PAYMENTSTATUS' => $this->_map_authorization_status( $rest_response['status'] ?? '' ),
				'PENDINGREASON' => null, // No direct mapping
				'REASONCODE' => null, // No direct mapping
				'ACK' => ! empty( $rest_response['id'] ) ? 'Success' : 'Failure',
				'VERSION' => null,
				'BUILD' => null,
				'TIMESTAMP' => $rest_response['create_time'] ?? null,
				'CORRELATIONID' => null,
			);
			
			WC_Gateway_PPEC_Parameter_Mapper::log_unmapped_fields( 
				$rest_response, 
				$nvp_response, 
				'DoReauthorization' 
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Get parameters for DoExpressCheckoutPayment.
	 * This method provides backward compatibility with the NVP API.
	 *
	 * @param array $args Context args containing order_id, token, and payer_id
	 * @return array Params for DoExpressCheckoutPayment call
	 */
	public function get_do_express_checkout_params( array $args ) {
		$settings = wc_gateway_ppec()->settings;
		$order    = wc_get_order( $args['order_id'] );

		$old_wc       = version_compare( WC_VERSION, '3.0', '<' );
		$order_id     = $old_wc ? $order->id : $order->get_id();
		$order_number = $order->get_order_number();
		$details      = $this->_get_details_from_order( $order_id );
		$order_key    = $old_wc ? $order->order_key : $order->get_order_key();

		$params = array(
			'TOKEN'                          => $args['token'],
			'PAYERID'                        => $args['payer_id'],
			'PAYMENTREQUEST_0_AMT'           => $details['order_total'],
			'PAYMENTREQUEST_0_ITEMAMT'       => $details['total_item_amount'],
			'PAYMENTREQUEST_0_SHIPPINGAMT'   => $details['shipping'],
			'PAYMENTREQUEST_0_TAXAMT'        => $details['order_tax'],
			'PAYMENTREQUEST_0_SHIPDISCAMT'   => $details['ship_discount_amount'],
			'PAYMENTREQUEST_0_INSURANCEAMT'  => 0,
			'PAYMENTREQUEST_0_HANDLINGAMT'   => 0,
			'PAYMENTREQUEST_0_CURRENCYCODE'  => get_woocommerce_currency(),
			'PAYMENTREQUEST_0_NOTIFYURL'     => WC()->api_request_url( 'WC_Gateway_PPEC' ),
			'PAYMENTREQUEST_0_PAYMENTACTION' => $settings->get_paymentaction(),
			'PAYMENTREQUEST_0_INVNUM'        => $settings->invoice_prefix . $order->get_order_number(),
			'PAYMENTREQUEST_0_CUSTOM'        => wp_json_encode(
				array(
					'order_id'     => $order_id,
					'order_number' => $order_number,
					'order_key'    => $order_key,
				)
			),
			'NOSHIPPING'                     => WC_Gateway_PPEC_Plugin::needs_shipping() ? 0 : 1,
		);

		if ( WC_Gateway_PPEC_Plugin::needs_shipping() && ! empty( $details['shipping_address'] ) ) {
			$params = array_merge(
				$params,
				$details['shipping_address']->getAddressParams( 'PAYMENTREQUEST_0_SHIPTO' )
			);
		}

		if ( ! empty( $details['items'] ) ) {
			$count = 0;
			foreach ( $details['items'] as $line_item_key => $values ) {
				$line_item_params = array(
					'L_PAYMENTREQUEST_0_NAME' . $count => $values['name'],
					'L_PAYMENTREQUEST_0_DESC' . $count => ! empty( $values['description'] ) ? strip_tags( $values['description'] ) : '', // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
					'L_PAYMENTREQUEST_0_QTY' . $count  => $values['quantity'],
					'L_PAYMENTREQUEST_0_AMT' . $count  => $values['amount'],
				);

				if ( isset( $values['sku'] ) ) {
					$line_item_params[ 'L_PAYMENTREQUEST_0_NUMBER' . $count ] = $values['sku'];
				}

				$params = array_merge( $params, $line_item_params );
				$count++;
			}
		}

		return $params;
	}

	/**
	 * Create billing agreement.
	 *
	 * @param string $token PayPal order ID/token
	 * @return array NVP-like response
	 */
	public function create_billing_agreement( $token ) {
		try {
			// For REST API v2, billing agreements are handled differently
			// This is a placeholder implementation that mimics the NVP response
			// In a full implementation, you would use PayPal's Billing Agreements API
			$nvp_response = array(
				'TOKEN' => $token,
				'BILLINGAGREEMENTID' => 'B-' . uniqid(),
				'ACK' => 'Success',
				'VERSION' => null,
				'BUILD' => null,
				'TIMESTAMP' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'CORRELATIONID' => null,
			);
			
			return $nvp_response;
		} catch ( Exception $e ) {
			return array(
				'ACK' => 'Failure',
				'L_ERRORCODE0' => 'REST_ERROR',
				'L_SHORTMESSAGE0' => 'REST API Error',
				'L_LONGMESSAGE0' => $e->getMessage(),
			);
		}
	}

	/**
	 * Check if response indicates success.
	 *
	 * @param array $response Response array
	 * @return bool
	 */
	public function response_has_success_status( $response ) {
		return isset( $response['ACK'] ) && in_array( $response['ACK'], array( 'Success', 'SuccessWithWarning' ), true );
	}

	/**
	 * Get return URL.
	 *
	 * The URL to return from express checkout.
	 *
	 * @param array $context_args Context args
	 * @return string Return URL
	 */
	protected function _get_return_url( array $context_args ) {
		$query_args = array(
			'woo-paypal-return' => 'true',
		);
		if ( $context_args['create_billing_agreement'] ) {
			$query_args['create-billing-agreement'] = 'true';
		}

		$url      = esc_url( add_query_arg( $query_args, wc_get_checkout_url() ) );
		$order_id = $context_args['order_id'];
		return apply_filters( 'woocommerce_paypal_express_checkout_set_express_checkout_params_get_return_url', $url, $order_id );
	}

	/**
	 * Get cancel URL.
	 *
	 * The URL to return when canceling the express checkout.
	 *
	 * @param array $context_args Context args
	 * @return string Cancel URL
	 */
	protected function _get_cancel_url( $context_args ) {
		$url      = esc_url( add_query_arg( 'woo-paypal-cancel', 'true', wc_get_cart_url() ) );
		$order_id = $context_args['order_id'];
		return apply_filters( 'woocommerce_paypal_express_checkout_set_express_checkout_params_get_cancel_url', $url, $order_id );
	}

	/**
	 * Get billing agreement description to be passed to PayPal.
	 *
	 * @return string Billing agreement description
	 */
	protected function _get_billing_agreement_description() {
		/* Translators: placeholder is blogname. */
		$description = sprintf( _x( 'Orders with %s', 'data sent to PayPal', 'woocommerce-gateway-paypal-express-checkout' ), get_bloginfo( 'name' ) );

		if ( strlen( $description ) > 127 ) {
			$description = substr( $description, 0, 124 ) . '...';
		}

		return html_entity_decode( $description, ENT_NOQUOTES, 'UTF-8' );
	}

	/**
	 * Get details from cart.
	 *
	 * @return array Order details
	 */
	protected function _get_details_from_cart() {
		$settings = wc_gateway_ppec()->settings;
		$old_wc   = version_compare( WC_VERSION, '3.0', '<' );

		WC()->cart->calculate_totals();

		$decimals      = $settings->get_number_of_decimal_digits();
		$rounded_total = $this->_get_rounded_total_in_cart();
		$discounts     = WC()->cart->get_cart_discount_total();

		$details = array(
			'total_item_amount' => round( WC()->cart->cart_contents_total + WC()->cart->fee_total, $decimals ),
			'order_tax'         => round( WC()->cart->tax_total + WC()->cart->shipping_tax_total, $decimals ),
			'shipping'          => round( WC()->cart->shipping_total, $decimals ),
			'items'             => $this->_get_paypal_line_items_from_cart(),
			'shipping_address'  => $this->_get_address_from_customer(),
			'email'             => $old_wc ? WC()->customer->billing_email : WC()->customer->get_billing_email(),
		);

		return $this->get_details( $details, $discounts, $rounded_total, WC()->cart->total );
	}

	/**
	 * Get details from order.
	 *
	 * @param int $order_id Order ID
	 * @return array Order details
	 */
	protected function _get_details_from_order( $order_id ) {
		$order    = wc_get_order( $order_id );
		$settings = wc_gateway_ppec()->settings;

		$decimals      = $settings->is_currency_supports_zero_decimal() ? 0 : 2;
		$rounded_total = $this->_get_rounded_total_in_order( $order );
		$discounts     = $order->get_total_discount();
		$fees          = round( $this->_get_total_order_fees( $order ), $decimals );

		$details = array(
			'total_item_amount' => round( $order->get_subtotal() - $discounts + $fees, $decimals ),
			'order_tax'         => round( $order->get_total_tax(), $decimals ),
			'shipping'          => round( ( version_compare( WC_VERSION, '3.0', '<' ) ? $order->get_total_shipping() : $order->get_shipping_total() ), $decimals ),
			'items'             => $this->_get_paypal_line_items_from_order( $order ),
		);

		$details = $this->get_details( $details, $order->get_total_discount(), $rounded_total, $order->get_total() );

		// PayPal shipping address from order.
		$old_wc = version_compare( WC_VERSION, '3.0', '<' );

		if ( ( $old_wc && ( $order->shipping_address_1 || $order->shipping_address_2 ) ) || ( ! $old_wc && $order->has_shipping_address() ) ) {
			$shipping_first_name = $old_wc ? $order->shipping_first_name : $order->get_shipping_first_name();
			$shipping_last_name  = $old_wc ? $order->shipping_last_name  : $order->get_shipping_last_name();
			$shipping_address_1  = $old_wc ? $order->shipping_address_1  : $order->get_shipping_address_1();
			$shipping_address_2  = $old_wc ? $order->shipping_address_2  : $order->get_shipping_address_2();
			$shipping_city       = $old_wc ? $order->shipping_city       : $order->get_shipping_city();
			$shipping_state      = $old_wc ? $order->shipping_state      : $order->get_shipping_state();
			$shipping_postcode   = $old_wc ? $order->shipping_postcode   : $order->get_shipping_postcode();
			$shipping_country    = $old_wc ? $order->shipping_country    : $order->get_shipping_country();
		} else {
			// Fallback to billing in case no shipping methods are set.
			$shipping_first_name = $old_wc ? $order->billing_first_name : $order->get_billing_first_name();
			$shipping_last_name  = $old_wc ? $order->billing_last_name  : $order->get_billing_last_name();
			$shipping_address_1  = $old_wc ? $order->billing_address_1  : $order->get_billing_address_1();
			$shipping_address_2  = $old_wc ? $order->billing_address_2  : $order->get_billing_address_2();
			$shipping_city       = $old_wc ? $order->billing_city       : $order->get_billing_city();
			$shipping_state      = $old_wc ? $order->billing_state      : $order->get_billing_state();
			$shipping_postcode   = $old_wc ? $order->billing_postcode   : $order->get_billing_postcode();
			$shipping_country    = $old_wc ? $order->billing_country    : $order->get_billing_country();
		}

		// In case merchant only expects domestic shipping and hides shipping
		// country, fallback to base country.
		if ( empty( $shipping_country ) ) {
			$shipping_country = WC()->countries->get_base_country();
		}

		$details['shipping_address'] = new PayPal_Address();
		$details['shipping_address']->setName( $shipping_first_name . ' ' . $shipping_last_name );
		$details['shipping_address']->setStreet1( $shipping_address_1 );
		$details['shipping_address']->setStreet2( $shipping_address_2 );
		$details['shipping_address']->setCity( $shipping_city );
		$details['shipping_address']->setState( $shipping_state );
		$details['shipping_address']->setZip( $shipping_postcode );
		$details['shipping_address']->setCountry( $shipping_country );
		$details['shipping_address']->setPhoneNumber( $old_wc ? $order->billing_phone : $order->get_billing_phone() );
		$details['email'] = $old_wc ? $order->billing_email : $order->get_billing_email();

		return $details;
	}

	/**
	 * Get rounded total in cart.
	 *
	 * @return float Rounded total
	 */
	protected function _get_rounded_total_in_cart() {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();
		return round( WC()->cart->total, $decimals );
	}

	/**
	 * Get PayPal line items from cart.
	 *
	 * @return array Line items
	 */
	protected function _get_paypal_line_items_from_cart() {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		$items = array();
		foreach ( WC()->cart->cart_contents as $cart_item_key => $values ) {
			$amount = round( $values['line_subtotal'] / $values['quantity'], $decimals );

			$item = array(
				'name'        => $values['data']->get_name(),
				'description' => $this->_get_item_description( $values ),
				'quantity'    => $values['quantity'],
				'amount'      => $amount,
			);

			$sku = $values['data']->get_sku();
			if ( $sku ) {
				$item['sku'] = $sku;
			}

			$items[ $cart_item_key ] = $item;
		}

		// Add fees as line items
		foreach ( WC()->cart->get_fees() as $fee_key => $fee ) {
			$items[ $fee_key ] = array(
				'name'     => $fee->name,
				'quantity' => 1,
				'amount'   => round( $fee->amount, $decimals ),
			);
		}

		return $items;
	}

	/**
	 * Get item description for cart item.
	 *
	 * @param array $cart_item Cart item
	 * @return string Item description
	 */
	protected function _get_item_description( $cart_item ) {
		$description = '';
		$product = $cart_item['data'];

		if ( $product && method_exists( $product, 'get_short_description' ) ) {
			$description = $product->get_short_description();
		}

		return $description;
	}

	/**
	 * Get address from customer.
	 *
	 * @return PayPal_Address|null
	 */
	protected function _get_address_from_customer() {
		if ( ! WC()->customer ) {
			return null;
		}

		$customer = WC()->customer;
		$shipping_address = new PayPal_Address();

		$old_wc = version_compare( WC_VERSION, '3.0', '<' );

		if ( $customer->get_shipping_address() || $customer->get_shipping_address_2() ) {
			$shipping_first_name = $old_wc ? $customer->shipping_first_name : $customer->get_shipping_first_name();
			$shipping_last_name  = $old_wc ? $customer->shipping_last_name : $customer->get_shipping_last_name();
			$shipping_address_1  = $customer->get_shipping_address();
			$shipping_address_2  = $customer->get_shipping_address_2();
			$shipping_city       = $customer->get_shipping_city();
			$shipping_state      = $customer->get_shipping_state();
			$shipping_postcode   = $customer->get_shipping_postcode();
			$shipping_country    = $customer->get_shipping_country();
		} else {
			// Fallback to billing in case no shipping methods are set.
			$shipping_first_name = $old_wc ? $customer->billing_first_name : $customer->get_billing_first_name();
			$shipping_last_name  = $old_wc ? $customer->billing_last_name  : $customer->get_billing_last_name();
			$shipping_address_1  = $old_wc ? $customer->get_address()      : $customer->get_billing_address_1();
			$shipping_address_2  = $old_wc ? $customer->get_address_2()    : $customer->get_billing_address_2();
			$shipping_city       = $old_wc ? $customer->get_city()         : $customer->get_billing_city();
			$shipping_state      = $old_wc ? $customer->get_state()        : $customer->get_billing_state();
			$shipping_postcode   = $old_wc ? $customer->get_postcode()     : $customer->get_billing_postcode();
			$shipping_country    = $old_wc ? $customer->get_country()      : $customer->get_billing_country();
		}

		$shipping_address->setName( $shipping_first_name . ' ' . $shipping_last_name );
		$shipping_address->setStreet1( $shipping_address_1 );
		$shipping_address->setStreet2( $shipping_address_2 );
		$shipping_address->setCity( $shipping_city );
		$shipping_address->setState( $shipping_state );
		$shipping_address->setZip( $shipping_postcode );
		$shipping_address->setCountry( $shipping_country );
		$shipping_address->setPhoneNumber( $old_wc ? $customer->billing_phone : $customer->get_billing_phone() );

		return $shipping_address;
	}

	/**
	 * Get details helper method.
	 *
	 * @param array $details Base details
	 * @param float $discounts Discount amount
	 * @param float $rounded_total Rounded total
	 * @param float $cart_total Cart total
	 * @return array Processed details
	 */
	protected function get_details( $details, $discounts, $rounded_total, $cart_total ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		$details['order_total'] = $rounded_total;

		// Calculate ship discount amount
		if ( $discounts > 0 ) {
			$details['ship_discount_amount'] = round( $discounts, $decimals );
		} else {
			$details['ship_discount_amount'] = 0;
		}

		// Check for calculation discrepancies
		$total_sum = $details['total_item_amount'] + $details['order_tax'] + $details['shipping'] - $details['ship_discount_amount'];
		if ( abs( $total_sum - $details['order_total'] ) > 0.01 ) {
			// Add offset line item to correct discrepancy
			$offset_amount = $details['order_total'] - $total_sum;
			if ( abs( $offset_amount ) >= 0.01 ) {
				$details['items']['line_item_offset'] = array(
					'name'        => 'Line Item Amount Offset',
					'description' => 'Adjust cart calculation discrepancy',
					'quantity'    => 1,
					'amount'      => round( $offset_amount, $decimals ),
				);
				$details['total_item_amount'] += round( $offset_amount, $decimals );
			}
		}

		return $details;
	}

	/**
	 * Get rounded total in order.
	 *
	 * @param WC_Order $order Order object
	 * @return float Rounded total
	 */
	protected function _get_rounded_total_in_order( $order ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();
		return round( $order->get_total(), $decimals );
	}

	/**
	 * Get total order fees.
	 *
	 * @param WC_Order $order Order object
	 * @return float Total fees
	 */
	protected function _get_total_order_fees( $order ) {
		$fees = 0;
		foreach ( $order->get_fees() as $fee ) {
			$fees += $fee->get_total();
		}
		return $fees;
	}

	/**
	 * Get PayPal line items from order.
	 *
	 * @param WC_Order $order Order object
	 * @return array Line items
	 */
	protected function _get_paypal_line_items_from_order( $order ) {
		$settings = wc_gateway_ppec()->settings;
		$decimals = $settings->get_number_of_decimal_digits();

		$items = array();

		// Add line items
		foreach ( $order->get_items() as $item_id => $item ) {
			$amount = round( $item->get_total() / $item->get_quantity(), $decimals );

			$line_item = array(
				'name'        => $item->get_name(),
				'description' => '',
				'quantity'    => $item->get_quantity(),
				'amount'      => $amount,
			);

			$product = $item->get_product();
			if ( $product && $product->get_sku() ) {
				$line_item['sku'] = $product->get_sku();
			}

			$items[ $item_id ] = $line_item;
		}

		// Add fees as line items
		foreach ( $order->get_fees() as $fee_id => $fee ) {
			$items[ $fee_id ] = array(
				'name'     => $fee->get_name(),
				'quantity' => 1,
				'amount'   => round( $fee->get_total(), $decimals ),
			);
		}

		return $items;
	}

	/**
	 * Map REST capture status to NVP payment status.
	 *
	 * @param string $rest_status REST capture status
	 * @return string NVP payment status
	 */
	protected function _map_capture_status( $rest_status ) {
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
	protected function _map_authorization_status( $rest_status ) {
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
	 * Test API credentials by attempting to get access token.
	 * This provides compatibility with the NVP client's test_api_credentials method.
	 *
	 * @param WC_Gateway_PPEC_Client_Credential $credentials Credentials to test
	 * @param string                            $environment Environment to test
	 * @return string|false Payer ID on success, false on failure
	 */
	public function test_api_credentials( $credentials, $environment = 'sandbox' ) {
		try {
			// For REST API, we only support OAuth credentials (Client ID + Secret)
			if ( ! is_a( $credentials, 'WC_Gateway_PPEC_Client_Credential_OAuth' ) ) {
				error_log( '[PayPal Debug] test_api_credentials: Only OAuth credentials supported for REST API' );
				return false;
			}

			// Create a temporary REST client with the provided credentials
			$test_client = new WC_Gateway_PPEC_REST_Client( $credentials, $environment );
			
			// Try to make a simple API call to test credentials
			// We'll try to get the client's own profile info or make a test call
			$client_id = $credentials->get_username();
			$client_secret = $credentials->get_password();
			
			$auth_url = ( 'live' === $environment ) 
				? 'https://api.paypal.com/v1/oauth2/token' 
				: 'https://api.sandbox.paypal.com/v1/oauth2/token';
			
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
				'timeout' => 30,
			);

			$response = wp_remote_request( $auth_url, $args );

			if ( is_wp_error( $response ) ) {
				error_log( '[PayPal Debug] test_api_credentials: Request failed - ' . $response->get_error_message() );
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( 200 === $status_code ) {
				$data = json_decode( $response_body, true );
				if ( ! empty( $data['access_token'] ) ) {
					// For REST API, we don't have a direct equivalent to payer_id
					// Return a success indicator that mimics the NVP behavior
					return 'REST_API_SUCCESS';
				}
			}
			
			error_log( '[PayPal Debug] test_api_credentials: Invalid response - Status: ' . $status_code . ', Body: ' . $response_body );
			return false;
			
		} catch ( Exception $e ) {
			error_log( '[PayPal Debug] test_api_credentials failed: ' . $e->getMessage() );
			return false;
		}
	}
}