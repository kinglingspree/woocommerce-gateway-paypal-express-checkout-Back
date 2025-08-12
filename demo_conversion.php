<?php
/**
 * PayPal Express Checkout REST API v2 Conversion Demo
 * 
 * This script demonstrates how the NVP API calls are converted to REST API v2 calls
 * with complete parameter mapping and backward compatibility.
 */

// This is a demonstration script - do not run in production
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
	exit( 'Demo script - only runs in debug mode' );
}

echo "<h1>PayPal Express Checkout NVP to REST API v2 Conversion Demo</h1>\n";

// Example 1: SetExpressCheckout → Create Order
echo "<h2>1. SetExpressCheckout → Create Order</h2>\n";

$nvp_params = array(
	'METHOD' => 'SetExpressCheckout',
	'VERSION' => '120.0',
	'RETURNURL' => 'https://example.com/return',
	'CANCELURL' => 'https://example.com/cancel',
	'BRANDNAME' => 'My Store',
	'LANDINGPAGE' => 'Billing',
	'PAYMENTREQUEST_0_AMT' => '25.00',
	'PAYMENTREQUEST_0_CURRENCYCODE' => 'USD',
	'PAYMENTREQUEST_0_ITEMAMT' => '20.00',
	'PAYMENTREQUEST_0_SHIPPINGAMT' => '3.00',
	'PAYMENTREQUEST_0_TAXAMT' => '2.00',
	'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
	'L_NAME0' => 'Widget A',
	'L_DESC0' => 'Amazing widget',
	'L_QTY0' => '2',
	'L_AMT0' => '10.00',
);

echo "<h3>Original NVP Parameters:</h3>\n";
echo "<pre>" . print_r( $nvp_params, true ) . "</pre>\n";

// Convert to REST API format
$rest_order_data = array(
	'intent' => 'CAPTURE', // from PAYMENTREQUEST_0_PAYMENTACTION
	'purchase_units' => array(
		array(
			'reference_id' => 'default',
			'amount' => array(
				'currency_code' => 'USD',
				'value' => '25.00',
				'breakdown' => array(
					'item_total' => array(
						'currency_code' => 'USD',
						'value' => '20.00',
					),
					'shipping' => array(
						'currency_code' => 'USD',
						'value' => '3.00',
					),
					'tax_total' => array(
						'currency_code' => 'USD',
						'value' => '2.00',
					),
				),
			),
			'items' => array(
				array(
					'name' => 'Widget A',
					'description' => 'Amazing widget',
					'quantity' => '2',
					'unit_amount' => array(
						'currency_code' => 'USD',
						'value' => '10.00',
					),
				),
			),
		),
	),
	'payment_source' => array(
		'paypal' => array(
			'experience_context' => array(
				'return_url' => 'https://example.com/return',
				'cancel_url' => 'https://example.com/cancel',
				'brand_name' => 'My Store',
				'landing_page' => 'BILLING',
			),
		),
	),
);

echo "<h3>Converted REST API v2 Order Data:</h3>\n";
echo "<pre>" . json_encode( $rest_order_data, JSON_PRETTY_PRINT ) . "</pre>\n";

// Example REST response
$rest_response = array(
	'id' => '5O190127TN364715T',
	'status' => 'CREATED',
	'links' => array(
		array(
			'href' => 'https://api.paypal.com/v2/checkout/orders/5O190127TN364715T',
			'rel' => 'self',
			'method' => 'GET',
		),
		array(
			'href' => 'https://www.paypal.com/checkoutnow?token=5O190127TN364715T',
			'rel' => 'approve',
			'method' => 'GET',
		),
	),
	'create_time' => '2023-08-12T10:30:00Z',
);

echo "<h3>REST API Response:</h3>\n";
echo "<pre>" . json_encode( $rest_response, JSON_PRETTY_PRINT ) . "</pre>\n";

// Convert back to NVP format for backward compatibility
$mapped_nvp_response = array(
	'TOKEN' => '5O190127TN364715T',
	'ACK' => 'Success',
	'VERSION' => null,
	'BUILD' => null,
	'TIMESTAMP' => '2023-08-12T10:30:00Z',
	'CORRELATIONID' => null,
	'REDIRECT_URL' => 'https://www.paypal.com/checkoutnow?token=5O190127TN364715T',
);

echo "<h3>Mapped NVP Response (Backward Compatibility):</h3>\n";
echo "<pre>" . print_r( $mapped_nvp_response, true ) . "</pre>\n";

// Example 2: GetExpressCheckoutDetails → Get Order Details
echo "<h2>2. GetExpressCheckoutDetails → Get Order Details</h2>\n";

$rest_order_details = array(
	'id' => '5O190127TN364715T',
	'status' => 'APPROVED',
	'payer' => array(
		'payer_id' => '7E7MGXCWTTKK2',
		'email_address' => 'buyer@example.com',
		'name' => array(
			'given_name' => 'John',
			'surname' => 'Doe',
		),
		'address' => array(
			'country_code' => 'US',
		),
	),
	'purchase_units' => array(
		array(
			'amount' => array(
				'currency_code' => 'USD',
				'value' => '25.00',
			),
			'shipping' => array(
				'name' => array(
					'full_name' => 'John Doe',
				),
				'address' => array(
					'address_line_1' => '123 Main St',
					'admin_area_2' => 'Anytown',
					'admin_area_1' => 'CA',
					'postal_code' => '12345',
					'country_code' => 'US',
				),
			),
			'payee' => array(
				'email_address' => 'merchant@example.com',
			),
		),
	),
	'create_time' => '2023-08-12T10:30:00Z',
);

echo "<h3>REST Order Details Response:</h3>\n";
echo "<pre>" . json_encode( $rest_order_details, JSON_PRETTY_PRINT ) . "</pre>\n";

$mapped_details_response = array(
	'TOKEN' => '5O190127TN364715T',
	'ACK' => 'Success',
	'EMAIL' => 'buyer@example.com',
	'PAYERID' => '7E7MGXCWTTKK2',
	'FIRSTNAME' => 'John',
	'LASTNAME' => 'Doe',
	'COUNTRYCODE' => 'US',
	'SHIPTONAME' => 'John Doe',
	'SHIPTOSTREET' => '123 Main St',
	'SHIPTOCITY' => 'Anytown',
	'SHIPTOSTATE' => 'CA',
	'SHIPTOZIP' => '12345',
	'SHIPTOCOUNTRYCODE' => 'US',
	'AMT' => '25.00',
	'CURRENCYCODE' => 'USD',
	'PAYMENTREQUEST_0_AMT' => '25.00',
	'PAYMENTREQUEST_0_CURRENCYCODE' => 'USD',
	'PAYMENTREQUEST_0_SHIPTONAME' => 'John Doe',
	'PAYMENTREQUEST_0_SHIPTOSTREET' => '123 Main St',
	'PAYMENTREQUEST_0_SHIPTOCITY' => 'Anytown',
	'PAYMENTREQUEST_0_SHIPTOSTATE' => 'CA',
	'PAYMENTREQUEST_0_SHIPTOZIP' => '12345',
	'PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE' => 'US',
	'PAYMENTREQUEST_0_SELLERPAYPALACCOUNTID' => 'merchant@example.com',
	'TIMESTAMP' => '2023-08-12T10:30:00Z',
);

echo "<h3>Mapped NVP Response:</h3>\n";
echo "<pre>" . print_r( $mapped_details_response, true ) . "</pre>\n";

// Example 3: DoExpressCheckoutPayment → Capture Order
echo "<h2>3. DoExpressCheckoutPayment → Capture Order</h2>\n";

$rest_capture_response = array(
	'id' => '5O190127TN364715T',
	'status' => 'COMPLETED',
	'purchase_units' => array(
		array(
			'payments' => array(
				'captures' => array(
					array(
						'id' => '0AW2184448739415Y',
						'status' => 'COMPLETED',
						'amount' => array(
							'currency_code' => 'USD',
							'value' => '25.00',
						),
						'seller_receivable_breakdown' => array(
							'gross_amount' => array(
								'currency_code' => 'USD',
								'value' => '25.00',
							),
							'paypal_fee' => array(
								'currency_code' => 'USD',
								'value' => '1.03',
							),
							'net_amount' => array(
								'currency_code' => 'USD',
								'value' => '23.97',
							),
						),
						'seller_protection' => array(
							'status' => 'ELIGIBLE',
							'dispute_categories' => array(
								'ITEM_NOT_RECEIVED',
								'UNAUTHORIZED_TRANSACTION',
							),
						),
						'create_time' => '2023-08-12T10:35:00Z',
						'update_time' => '2023-08-12T10:35:00Z',
					),
				),
			),
			'payee' => array(
				'email_address' => 'merchant@example.com',
			),
		),
	),
);

echo "<h3>REST Capture Response:</h3>\n";
echo "<pre>" . json_encode( $rest_capture_response, JSON_PRETTY_PRINT ) . "</pre>\n";

$mapped_capture_response = array(
	'TOKEN' => '5O190127TN364715T',
	'ACK' => 'Success',
	'TIMESTAMP' => '2023-08-12T10:35:00Z',
	'PAYMENTINFO_0_TRANSACTIONID' => '0AW2184448739415Y',
	'PAYMENTINFO_0_ORDERTIME' => '2023-08-12T10:35:00Z',
	'PAYMENTINFO_0_AMT' => '25.00',
	'PAYMENTINFO_0_CURRENCYCODE' => 'USD',
	'PAYMENTINFO_0_PAYMENTSTATUS' => 'Completed',
	'PAYMENTINFO_0_FEEAMT' => '1.03',
	'PAYMENTINFO_0_PROTECTIONELIGIBILITY' => 'ELIGIBLE',
	'PAYMENTINFO_0_PROTECTIONELIGIBILITYTYPE' => 'ITEM_NOT_RECEIVED,UNAUTHORIZED_TRANSACTION',
	'PAYMENTINFO_0_SELLERPAYPALACCOUNTID' => 'merchant@example.com',
);

echo "<h3>Mapped NVP Capture Response:</h3>\n";
echo "<pre>" . print_r( $mapped_capture_response, true ) . "</pre>\n";

// Example 4: RefundTransaction → Refund Capture
echo "<h2>4. RefundTransaction → Refund Capture</h2>\n";

$nvp_refund_params = array(
	'METHOD' => 'RefundTransaction',
	'VERSION' => '120.0',
	'TRANSACTIONID' => '0AW2184448739415Y',
	'REFUNDTYPE' => 'Partial',
	'AMT' => '10.00',
	'CURRENCYCODE' => 'USD',
	'NOTE' => 'Customer requested refund',
);

echo "<h3>Original NVP Refund Parameters:</h3>\n";
echo "<pre>" . print_r( $nvp_refund_params, true ) . "</pre>\n";

$rest_refund_data = array(
	'amount' => array(
		'currency_code' => 'USD',
		'value' => '10.00',
	),
	'note_to_payer' => 'Customer requested refund',
);

echo "<h3>REST Refund Request Data:</h3>\n";
echo "<pre>" . json_encode( $rest_refund_data, JSON_PRETTY_PRINT ) . "</pre>\n";

$rest_refund_response = array(
	'id' => '1JU08902781691411',
	'status' => 'COMPLETED',
	'amount' => array(
		'currency_code' => 'USD',
		'value' => '10.00',
	),
	'seller_payable_breakdown' => array(
		'gross_amount' => array(
			'currency_code' => 'USD',
			'value' => '10.00',
		),
		'paypal_fee' => array(
			'currency_code' => 'USD',
			'value' => '0.41',
		),
		'net_amount' => array(
			'currency_code' => 'USD',
			'value' => '9.59',
		),
	),
	'create_time' => '2023-08-12T11:00:00Z',
);

echo "<h3>REST Refund Response:</h3>\n";
echo "<pre>" . json_encode( $rest_refund_response, JSON_PRETTY_PRINT ) . "</pre>\n";

$mapped_refund_response = array(
	'REFUNDTRANSACTIONID' => '1JU08902781691411',
	'FEEREFUNDAMT' => '0.41',
	'GROSSREFUNDAMT' => '10.00',
	'NETREFUNDAMT' => '9.59',
	'CURRENCYCODE' => 'USD',
	'TOTALREFUNDEDAMOUNT' => '10.00',
	'ACK' => 'Success',
	'TIMESTAMP' => '2023-08-12T11:00:00Z',
);

echo "<h3>Mapped NVP Refund Response:</h3>\n";
echo "<pre>" . print_r( $mapped_refund_response, true ) . "</pre>\n";

echo "<h2>Summary</h2>\n";
echo "<p>This demonstration shows how the PayPal Express Checkout plugin seamlessly converts between NVP and REST API v2 formats:</p>\n";
echo "<ul>\n";
echo "<li><strong>Complete Parameter Mapping:</strong> All essential NVP parameters are mapped to REST equivalents</li>\n";
echo "<li><strong>Backward Compatibility:</strong> REST responses are converted back to NVP format for existing code</li>\n";
echo "<li><strong>Enhanced Features:</strong> REST API provides better structure, OAuth 2.0, and modern authentication</li>\n";
echo "<li><strong>Seamless Migration:</strong> Existing integrations continue working without code changes</li>\n";
echo "</ul>\n";

echo "<h2>Key Benefits</h2>\n";
echo "<ul>\n";
echo "<li><strong>OAuth 2.0:</strong> Modern, secure authentication with automatic token refresh</li>\n";
echo "<li><strong>Better Performance:</strong> Structured JSON responses and improved API design</li>\n";
echo "<li><strong>Enhanced Logging:</strong> Detailed request/response tracking for debugging</li>\n";
echo "<li><strong>Future-Proof:</strong> REST API v2 is PayPal's current API standard</li>\n";
echo "<li><strong>Flexible Migration:</strong> Multiple migration modes for gradual transition</li>\n";
echo "</ul>\n";
?>
