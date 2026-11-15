<?php
/**
 * ArionPay gateway for Paid Memberships Pro 3.x.
 *
 * Checkout: the order is saved as "token" (waiting for payment) with its checkout data, and the member goes to the
 * ArionPay payment page. The signed invoice.paid webhook completes the checkout with pmpro_complete_async_checkout(),
 * under a database lock, only while the order is still waiting. The admin "check payment" button on a token order
 * does the same from a signed invoice read.
 *
 * Crypto cannot charge a member again by itself, so for ArionPay checkouts a recurring level is sold as one billing
 * period that the member renews by checking out again (Paid Memberships Pro adds the time left on a renewal).
 */

defined( 'ABSPATH' ) || exit;

#[AllowDynamicProperties]
class PMProGateway_arionpay extends PMProGateway {

	const SLUG           = 'arionpay';
	const CURRENCIES     = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );
	const MAX_BODY_BYTES = 262144;
	const LOG_OPTION     = 'pmpro_arionpay_log';
	const OPEN_STATUSES  = array( 'pending', 'paid_partial', 'claim_pending' );

	public function __construct( $gateway = null ) {
		$this->gateway = $gateway;
		return $this->gateway;
	}

	public static function init() {
		add_filter( 'pmpro_gateways', array( __CLASS__, 'pmpro_gateways' ) );
		add_action( 'wp_ajax_nopriv_arionpay_pmpro_webhook', array( __CLASS__, 'webhook' ) );
		add_action( 'wp_ajax_arionpay_pmpro_webhook', array( __CLASS__, 'webhook' ) );

		if ( self::SLUG === pmpro_getGateway() ) {
			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			add_filter( 'pmpro_required_billing_fields', array( __CLASS__, 'required_billing_fields' ) );
			add_filter( 'pmpro_checkout_level', array( __CLASS__, 'checkout_level' ), 5 ); // before PMPro extends renewals (10)
			add_filter( 'pmpro_checkout_default_submit_button', array( __CLASS__, 'submit_button' ) );
			add_action( 'pmpro_checkout_after_level_cost', array( __CLASS__, 'checkout_text' ) );
		}
	}

	public static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways[ self::SLUG ] ) ) {
			$gateways[ self::SLUG ] = __( 'ArionPay (crypto)', 'arionpay-for-pmpro' );
		}
		return $gateways;
	}

	public static function supports( $feature ) {
		return 'check_token_orders' === $feature;
	}

	public static function get_description_for_gateway_settings() {
		return esc_html__( 'Bitcoin, USDT, Litecoin, Ethereum, TRON and more, paid straight to your own wallet.', 'arionpay-for-pmpro' );
	}

	// ------------------------------------------------------------------ checkout

	public static function required_billing_fields( $fields ) {
		foreach ( array( 'bfirstname', 'blastname', 'baddress1', 'bcity', 'bstate', 'bzipcode', 'bphone', 'bemail', 'bcountry', 'CardType', 'AccountNumber', 'ExpirationMonth', 'ExpirationYear', 'CVV' ) as $key ) {
			unset( $fields[ $key ] );
		}
		return $fields;
	}

	/**
	 * Recurring level -> one paid billing period. A member who already has the level pays the recurring price to renew.
	 */
	public static function checkout_level( $level ) {
		if ( empty( $level ) || empty( $level->id ) || ! pmpro_isLevelRecurring( $level ) ) {
			return $level;
		}
		if ( (float) $level->billing_amount > 0 && is_user_logged_in() && pmpro_hasMembershipLevel( $level->id ) ) {
			$level->initial_payment = $level->billing_amount;
		}
		$level->expiration_number = (int) $level->cycle_number;
		$level->expiration_period = $level->cycle_period;
		$level->billing_amount    = 0;
		$level->cycle_number      = 0;
		$level->cycle_period      = '';
		$level->billing_limit     = 0;
		$level->trial_amount      = 0;
		$level->trial_limit       = 0;
		return $level;
	}

	public static function checkout_text() {
		echo '<p class="' . esc_attr( pmpro_get_element_class( 'pmpro_arionpay_note' ) ) . '">' . esc_html__( 'You will pay with crypto on the secure ArionPay page. Your membership starts as soon as the network confirms the payment.', 'arionpay-for-pmpro' );
		if ( 'sandbox' === get_option( 'pmpro_gateway_environment' ) ) {
			echo ' <strong>' . esc_html__( 'Test mode: no real money is taken.', 'arionpay-for-pmpro' ) . '</strong>';
		}
		echo '</p>';
	}

	public static function submit_button( $show ) {
		global $pmpro_requirebilling;
		?>
		<span id="pmpro_submit_span">
			<input type="hidden" name="submit-checkout" value="1" />
			<input type="submit" id="pmpro_btn-submit" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_btn pmpro_btn-submit-checkout', 'pmpro_btn-submit-checkout' ) ); ?>" value="<?php echo $pmpro_requirebilling ? esc_attr__( 'Pay with crypto', 'arionpay-for-pmpro' ) : esc_attr__( 'Submit and Confirm', 'paid-memberships-pro' ); ?>" />
		</span>
		<?php
		return false;
	}

	/**
	 * Save the order as waiting for payment and send the member to the ArionPay page. Runs again when the member
	 * comes back to pay a waiting order (?pmpro_order=CODE): the open ArionPay invoice is reused.
	 */
	public function process( &$order ) {
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}
		$order->payment_type = 'ArionPay';
		$order->cardtype     = '';
		$currency            = strtoupper( (string) get_option( 'pmpro_currency', 'USD' ) );
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			$order->error      = sprintf( /* translators: %s: currency code */ __( 'Crypto payments are not available in %s. Please contact us.', 'arionpay-for-pmpro' ), $currency );
			$order->shorterror = $order->error;
			return false;
		}
		if ( ! self::configured() ) {
			$order->error      = __( 'Crypto payments are not set up yet. Please contact us.', 'arionpay-for-pmpro' );
			$order->shorterror = $order->error;
			return false;
		}

		$order->status = 'token';
		$order->saveOrder();
		pmpro_save_checkout_data_to_order( $order );

		try {
			$url = self::invoice_url( $order, $currency );
		} catch ( \Throwable $e ) {
			self::log( 'error', 'Could not start the ArionPay payment for order ' . $order->code . ': ' . $e->getMessage() );
			$order->error      = __( 'The crypto payment could not be started right now. Please try again in a minute.', 'arionpay-for-pmpro' );
			$order->shorterror = $order->error;
			return false;
		}
		if ( null === $url ) {
			// ArionPay already has this order as paid (and the signed read completed it, if it was still waiting).
			wp_redirect( pmpro_url( 'confirmation', '?pmpro_level=' . (int) $order->membership_id ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- pmpro_url() is filterable.
			exit;
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the ArionPay payment page (offsite by design).
		exit;
	}

	/**
	 * Open ArionPay invoice for this order (reused while it can still be paid), or a new attempt. Null when ArionPay
	 * already reports the order paid.
	 */
	private static function invoice_url( $order, $currency ) {
		$amount   = self::money( $order->total );
		$test     = self::is_test_order( $order );
		$client   = self::client();
		$existing = (string) get_pmpro_membership_order_meta( $order->id, 'arionpay_invoice_id', true );
		$attempt  = max( 1, (int) get_pmpro_membership_order_meta( $order->id, 'arionpay_attempt', true ) );

		if ( '' !== $existing ) {
			try {
				$read = $client->getInvoice( $existing );
			} catch ( \ArionPay\ApiException $e ) {
				if ( 404 !== $e->getCode() ) {
					throw $e;
				}
				$read = array( 'status' => 'missing', 'raw' => array() );
			}
			if ( 'paid' === $read['status'] ) {
				self::complete( $order->id, $read, 'signed read at checkout' );
				return null;
			}
			$url = (string) get_pmpro_membership_order_meta( $order->id, 'arionpay_invoice_url', true );
			if ( '' !== $url && self::still_open( $read['status'], isset( $read['raw']['expiresAt'] ) ? $read['raw']['expiresAt'] : null )
				&& $amount === (string) get_pmpro_membership_order_meta( $order->id, 'arionpay_amount', true ) ) {
				return $url;
			}
			$attempt++;
		}

		for ( $tries = 0; $tries < 20; $tries++, $attempt++ ) {
			$created = $client->createInvoice(
				array(
					'amount'         => $amount,
					'currency'       => $currency,
					'orderId'        => (string) $order->code,
					'description'    => self::cut( sprintf( '%s - %s', get_bloginfo( 'name' ), isset( $order->membership_level->name ) ? $order->membership_level->name : 'membership' ), 500 ),
					'successUrl'     => pmpro_url( 'confirmation', '?pmpro_level=' . (int) $order->membership_id ),
					'cancelUrl'      => pmpro_url( 'checkout', '?pmpro_level=' . (int) $order->membership_id . '&pmpro_order=' . rawurlencode( $order->code ) ),
					'callbackUrl'    => self::webhook_url(),
					'metadata'       => array(
						'pmpro_order_id'   => (int) $order->id,
						'pmpro_order_code' => (string) $order->code,
						'pmpro_amount'     => $amount,
						'pmpro_currency'   => $currency,
						'pmpro_site'       => self::site_id(),
						'platform'         => 'pmpro',
					),
					'idempotencyKey' => 'pmpro-' . self::site_id() . '-' . (int) $order->id . '-' . $order->code . '-' . $amount . $currency . ( $test ? '-test' : '' ) . '-' . $attempt,
					'test'           => $test,
				)
			);
			if ( 'paid' === $created['status'] ) {
				return null;
			}
			if ( ( 'new' === $created['status'] && self::still_open( 'new', $created['expiresAt'] ) ) || in_array( $created['status'], self::OPEN_STATUSES, true ) ) {
				update_pmpro_membership_order_meta( $order->id, 'arionpay_invoice_id', $created['id'] );
				update_pmpro_membership_order_meta( $order->id, 'arionpay_invoice_url', $created['invoiceUrl'] );
				update_pmpro_membership_order_meta( $order->id, 'arionpay_amount', $amount );
				update_pmpro_membership_order_meta( $order->id, 'arionpay_currency', $currency );
				update_pmpro_membership_order_meta( $order->id, 'arionpay_attempt', $attempt );
				self::add_invoice_to_list( $order->id, $created['id'] );
				return $created['invoiceUrl'];
			}
		}
		throw new \Exception( 'Too many finished ArionPay invoices for this order.' );
	}

	private static function still_open( $status, $expires_at ) {
		if ( in_array( $status, self::OPEN_STATUSES, true ) ) {
			return true;
		}
		return 'new' === $status && ( empty( $expires_at ) || strtotime( (string) $expires_at ) > time() + 300 );
	}

	private static function add_invoice_to_list( $order_id, $invoice_id ) {
		$list = get_pmpro_membership_order_meta( $order_id, 'arionpay_invoices', true );
		$list = is_array( $list ) ? $list : array();
		if ( ! in_array( $invoice_id, $list, true ) ) {
			$list[] = $invoice_id;
			update_pmpro_membership_order_meta( $order_id, 'arionpay_invoices', $list );
		}
	}

	// ------------------------------------------------------------------ admin "check payment" (token orders)

	public function check_token_order( $order ) {
		if ( 'token' !== $order->status ) {
			return __( 'This order is not waiting for payment.', 'arionpay-for-pmpro' );
		}
		$id = (string) get_pmpro_membership_order_meta( $order->id, 'arionpay_invoice_id', true );
		if ( '' === $id ) {
			return __( 'No ArionPay invoice was made for this order.', 'arionpay-for-pmpro' );
		}
		try {
			$read = self::client()->getInvoice( $id );
		} catch ( \Throwable $e ) {
			return __( 'Could not reach ArionPay: ', 'arionpay-for-pmpro' ) . $e->getMessage();
		}
		if ( 'paid' !== $read['status'] ) {
			/* translators: %s: ArionPay invoice status */
			return sprintf( __( 'ArionPay has not confirmed this payment yet (status: %s).', 'arionpay-for-pmpro' ), $read['status'] );
		}
		$result = self::complete( $order->id, $read, 'signed read (admin check)' );
		return 'completed' === $result ? true : self::result_text( $result );
	}

	// ------------------------------------------------------------------ webhook

	/** admin-ajax.php?action=arionpay_pmpro_webhook */
	public static function webhook() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::respond( 405, array( 'error' => 'POST only' ) );
		}
		$secret = trim( (string) get_option( 'pmpro_arionpay_webhook_secret', '' ) );
		if ( '' === $secret ) {
			self::respond( 503, array( 'error' => 'webhook secret not set' ) );
		}
		$raw = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY_BYTES + 1 );
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			self::respond( 413, array( 'error' => 'too large' ) );
		}
		$header = isset( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ? (string) wp_unslash( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		try {
			$event = \ArionPay\Webhook::verify( $raw, $header, $secret );
		} catch ( \ArionPay\SignatureException $e ) {
			self::log( 'refused', 'Webhook refused: ' . $e->getMessage() );
			self::respond( 401, array( 'error' => 'invalid signature' ) );
		}

		$invoice = $event['data']['invoice'];
		if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
			self::log( 'test', 'Verified ArionPay test webhook received (' . $event['type'] . '). Nothing changed.' );
			self::respond( 200, array( 'received' => true, 'test' => true ) );
		}
		$meta = isset( $invoice['metadata'] ) && is_array( $invoice['metadata'] ) ? $invoice['metadata'] : array();
		if ( empty( $meta['pmpro_order_id'] ) || empty( $meta['pmpro_order_code'] ) || ! isset( $meta['pmpro_site'] ) || self::site_id() !== $meta['pmpro_site'] ) {
			self::respond( 200, array( 'received' => true, 'ignored' => 'not an order of this site' ) );
		}
		$order = new MemberOrder( (int) $meta['pmpro_order_id'] );
		if ( empty( $order->id ) || (string) $order->code !== (string) $meta['pmpro_order_code'] || self::SLUG !== $order->gateway ) {
			self::log( 'ignored', 'Invoice ' . $invoice['id'] . ' names order ' . $meta['pmpro_order_code'] . ', which is not an ArionPay order here.' );
			self::respond( 200, array( 'received' => true, 'ignored' => 'unknown order' ) );
		}
		if ( (bool) $invoice['test'] !== self::is_test_order( $order ) ) {
			self::note_once( $order->id, $event['id'], __( 'ArionPay update refused: test/live mode of the invoice differs from this order.', 'arionpay-for-pmpro' ) );
			self::respond( 409, array( 'error' => 'test/live mismatch' ) );
		}

		switch ( $event['type'] ) {
			case 'invoice.paid':
				$result = self::complete( $order->id, $invoice, 'webhook' );
				// error / locked: answer 5xx so ArionPay tries again a little later.
				self::respond( 'error' === $result ? 500 : ( 'locked' === $result ? 503 : 200 ), array( 'received' => true, 'result' => $result ) );
				break;
			case 'invoice.pending':
				$text = sprintf( /* translators: %s: ArionPay invoice id */ __( 'ArionPay: payment seen for invoice %s, waiting for network confirmations.', 'arionpay-for-pmpro' ), $invoice['id'] );
				break;
			case 'invoice.partial':
				$text = sprintf( /* translators: %s: ArionPay invoice id */ __( 'ArionPay: partial payment for invoice %s. The membership starts only when it is fully paid.', 'arionpay-for-pmpro' ), $invoice['id'] );
				break;
			case 'invoice.needs_review':
				$text = sprintf( /* translators: %s: ArionPay invoice id */ __( 'ArionPay flagged invoice %s for review (Payment Radar). Decide in your ArionPay dashboard.', 'arionpay-for-pmpro' ), $invoice['id'] );
				break;
			case 'invoice.expired':
				$text = sprintf( /* translators: %s: ArionPay invoice id */ __( 'ArionPay invoice %s expired without full payment. A late payment still completes the order.', 'arionpay-for-pmpro' ), $invoice['id'] );
				break;
			default:
				self::respond( 200, array( 'received' => true, 'ignored' => 'event type' ) );
		}
		self::note_once( $order->id, $event['id'], self::test_prefix( $invoice ) . $text );
		self::respond( 200, array( 'received' => true ) );
	}

	/**
	 * Complete the checkout for a paid ArionPay invoice. Safe to call many times: only a waiting order is completed,
	 * under a lock. Returns completed | duplicate | already_done | mismatch | not_waiting | locked | error.
	 */
	private static function complete( $order_id, array $invoice, $source ) {
		global $wpdb;
		$order = new MemberOrder( (int) $order_id );
		$meta  = isset( $invoice['metadata'] ) && is_array( $invoice['metadata'] ) ? $invoice['metadata'] : array();
		$paid  = is_numeric( $invoice['amountFiat'] ) ? self::money( $invoice['amountFiat'] ) : '';

		// The paid invoice must be exactly the one this order asked for: its amount and currency.
		if ( empty( $meta['pmpro_order_code'] ) || (string) $order->code !== (string) $meta['pmpro_order_code']
			|| ! isset( $meta['pmpro_amount'], $meta['pmpro_currency'] ) || $paid !== (string) $meta['pmpro_amount'] || $paid !== self::money( $order->total )
			|| strtoupper( (string) $invoice['currency'] ) !== strtoupper( (string) $meta['pmpro_currency'] ) ) {
			self::note_once( $order->id, 'mismatch-' . $invoice['id'], sprintf( /* translators: %s: ArionPay invoice id */ __( 'ArionPay invoice %s is paid, but its amount or currency does not match this order. Membership NOT given: check it in your ArionPay dashboard.', 'arionpay-for-pmpro' ), $invoice['id'] ) );
			return 'mismatch';
		}

		$lock = 'pmpro_arionpay_' . (int) $order->id;
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 20)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '1' !== (string) $got ) {
			return 'locked'; // another request is completing it right now; ArionPay retries
		}
		try {
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->pmpro_membership_orders} WHERE id = %d", $order->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 'success' === $status ) {
				$done = new MemberOrder( (int) $order->id );
				if ( (string) $done->payment_transaction_id === (string) $invoice['id'] ) {
					return 'duplicate';
				}
				self::note_once( $order->id, 'again-' . $invoice['id'], sprintf( /* translators: %s: ArionPay invoice id */ __( 'Another ArionPay invoice (%s) for this order was paid too. Possible double payment: check your wallet and refund it.', 'arionpay-for-pmpro' ), $invoice['id'] ) );
				return 'already_done';
			}
			if ( ! in_array( $status, array( 'token', 'pending' ), true ) ) {
				self::note_once( $order->id, 'state-' . $invoice['id'], sprintf( /* translators: 1: ArionPay invoice id, 2: order status */ __( 'ArionPay invoice %1$s is paid, but this order is "%2$s". Membership NOT given: check it.', 'arionpay-for-pmpro' ), $invoice['id'], $status ) );
				return 'not_waiting';
			}

			$order = new MemberOrder( (int) $order->id );
			$order->payment_transaction_id = (string) $invoice['id'];
			self::add_note( $order, self::test_prefix( $invoice ) . self::paid_text( $invoice, $source ) );
			$order->saveOrder();

			pmpro_pull_checkout_data_from_order( $order );
			if ( pmpro_complete_async_checkout( $order ) ) {
				self::log( 'paid', 'Order ' . $order->code . ' completed from ' . $source . ' (invoice ' . $invoice['id'] . ').' );
				return 'completed';
			}
			$order->status = 'error';
			self::add_note( $order, __( 'ArionPay payment confirmed, but Paid Memberships Pro could not give the membership. Give it by hand.', 'arionpay-for-pmpro' ) );
			$order->saveOrder();
			self::log( 'error', 'Order ' . $order->code . ': membership could not be given.' );
			return 'error';
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	private static function paid_text( array $invoice, $source ) {
		$parts = array( sprintf( /* translators: 1: invoice id, 2: amount, 3: currency */ __( 'ArionPay invoice %1$s is paid (%2$s %3$s)', 'arionpay-for-pmpro' ), $invoice['id'], self::money( $invoice['amountFiat'] ), $invoice['currency'] ) );
		if ( ! empty( $invoice['asset'] ) ) {
			$parts[] = trim( ( isset( $invoice['paidCrypto'] ) ? $invoice['paidCrypto'] . ' ' : '' ) . $invoice['asset'] . ( ! empty( $invoice['network'] ) ? ' on ' . $invoice['network'] : '' ) );
		}
		// Each payment of the invoice names its blockchain transaction in `txid` (1.0.0 read `txHash`, which only exists
		// on the invoice itself as the last transaction, so the id never reached the note).
		$hashes = array();
		foreach ( isset( $invoice['transactions'] ) && is_array( $invoice['transactions'] ) ? $invoice['transactions'] : array() as $tx ) {
			if ( ! is_array( $tx ) ) {
				continue;
			}
			$id = ! empty( $tx['txid'] ) ? $tx['txid'] : ( ! empty( $tx['txHash'] ) ? $tx['txHash'] : '' );
			if ( '' !== $id && ! in_array( (string) $id, $hashes, true ) ) {
				$hashes[] = (string) $id;
			}
		}
		if ( ! $hashes && ! empty( $invoice['txHash'] ) ) {
			$hashes[] = (string) $invoice['txHash'];
		}
		if ( $hashes ) {
			$parts[] = 'tx ' . implode( ', ', array_slice( $hashes, 0, 5 ) );
		}
		if ( ! empty( $invoice['late'] ) ) {
			$parts[] = __( 'paid late', 'arionpay-for-pmpro' );
		}
		if ( ! empty( $invoice['underpaidWithinTolerance'] ) ) {
			$parts[] = __( 'slightly under, inside your payment tolerance', 'arionpay-for-pmpro' );
		}
		if ( ! empty( $invoice['overpaid'] ) ) {
			$parts[] = __( 'overpaid', 'arionpay-for-pmpro' );
		}
		if ( ! empty( $invoice['manual'] ) ) {
			$parts[] = __( 'accepted by hand in ArionPay', 'arionpay-for-pmpro' );
		}
		return implode( '; ', $parts ) . ' [' . $source . '].';
	}

	private static function result_text( $result ) {
		$texts = array(
			'duplicate'    => __( 'Already completed.', 'arionpay-for-pmpro' ),
			'already_done' => __( 'The order was already completed by another payment. See the order notes.', 'arionpay-for-pmpro' ),
			'mismatch'     => __( 'The paid amount or currency does not match this order. See the order notes.', 'arionpay-for-pmpro' ),
			'not_waiting'  => __( 'The order is no longer waiting for payment. See the order notes.', 'arionpay-for-pmpro' ),
			'locked'       => __( 'The order is being completed right now. Reload in a moment.', 'arionpay-for-pmpro' ),
			'error'        => __( 'The membership could not be given. See the order notes.', 'arionpay-for-pmpro' ),
		);
		return isset( $texts[ $result ] ) ? $texts[ $result ] : $result;
	}

	// ------------------------------------------------------------------ settings (Memberships > Settings > Payment Gateways > ArionPay)

	public static function show_settings_fields() {
		$saved_secret  = '' !== (string) get_option( 'pmpro_arionpay_api_secret', '' );
		$saved_webhook = '' !== (string) get_option( 'pmpro_arionpay_webhook_secret', '' );
		$currency      = strtoupper( (string) get_option( 'pmpro_currency', 'USD' ) );
		?>
		<div id="pmpro_arionpay" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Settings', 'paid-memberships-pro' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<?php if ( ! in_array( $currency, self::CURRENCIES, true ) ) : ?>
					<div class="notice error inline"><p>
						<?php /* translators: 1: currency code, 2: list of currencies */ echo esc_html( sprintf( __( 'Your currency (%1$s) is not one ArionPay takes: %2$s.', 'arionpay-for-pmpro' ), $currency, implode( ', ', self::CURRENCIES ) ) ); ?>
					</p></div>
				<?php endif; ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="arionpay_api_key"><?php esc_html_e( 'API key', 'arionpay-for-pmpro' ); ?></label></th>
							<td>
								<input type="text" id="arionpay_api_key" name="arionpay_api_key" value="<?php echo esc_attr( get_option( 'pmpro_arionpay_api_key', '' ) ); ?>" class="regular-text code" placeholder="pk_..." autocomplete="off" />
								<p class="description"><?php esc_html_e( 'ArionPay dashboard > your store > API key (starts with pk_).', 'arionpay-for-pmpro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="arionpay_api_secret"><?php esc_html_e( 'API secret', 'arionpay-for-pmpro' ); ?></label></th>
							<td>
								<input type="password" id="arionpay_api_secret" name="arionpay_api_secret" value="" class="regular-text code" placeholder="<?php echo $saved_secret ? esc_attr__( 'Saved. Leave empty to keep it.', 'arionpay-for-pmpro' ) : 'sk_...'; ?>" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'ArionPay dashboard > your store > API secret (starts with sk_). It is never shown again.', 'arionpay-for-pmpro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="arionpay_webhook_secret"><?php esc_html_e( 'Webhook signing secret', 'arionpay-for-pmpro' ); ?></label></th>
							<td>
								<input type="password" id="arionpay_webhook_secret" name="arionpay_webhook_secret" value="" class="regular-text code" placeholder="<?php echo $saved_webhook ? esc_attr__( 'Saved. Leave empty to keep it.', 'arionpay-for-pmpro' ) : 'whsec_...'; ?>" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'ArionPay dashboard > Webhooks > signing secret (starts with whsec_). It is never shown again.', 'arionpay-for-pmpro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="arionpay_base_url"><?php esc_html_e( 'ArionPay address', 'arionpay-for-pmpro' ); ?></label></th>
							<td>
								<input type="url" id="arionpay_base_url" name="arionpay_base_url" value="<?php echo esc_attr( get_option( 'pmpro_arionpay_base_url', '' ) ); ?>" class="regular-text code" placeholder="https://merchant.arionpay.com" />
								<p class="description"><?php esc_html_e( 'Leave empty unless ArionPay tells you otherwise.', 'arionpay-for-pmpro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Payment updates', 'arionpay-for-pmpro' ); ?></th>
							<td>
								<p><code><?php echo esc_html( self::webhook_url() ); ?></code></p>
								<p class="description"><?php esc_html_e( 'Nothing to paste: every invoice tells ArionPay to send its updates here. You can also paste it on the ArionPay Webhooks page to use "Send test webhook".', 'arionpay-for-pmpro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Test mode', 'arionpay-for-pmpro' ); ?></th>
							<td><p class="description"><?php esc_html_e( 'Uses the Gateway Environment of Paid Memberships Pro: "Sandbox/Testing" makes test invoices (no real money), "Live/Production" real ones.', 'arionpay-for-pmpro' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Recurring levels', 'arionpay-for-pmpro' ); ?></th>
							<td><p class="description"><?php esc_html_e( 'Crypto cannot charge a member automatically. A recurring level paid with ArionPay gives one billing period; members renew by checking out again (they pay the recurring price, and the time they had left is kept).', 'arionpay-for-pmpro' ); ?></p></td>
						</tr>
					</tbody>
				</table>
				<?php self::show_log(); ?>
			</div>
		</div>
		<?php
	}

	private static function show_log() {
		$log = get_option( self::LOG_OPTION, array() );
		if ( empty( $log ) || ! is_array( $log ) ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Recent ArionPay activity', 'arionpay-for-pmpro' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( array_reverse( $log ) as $row ) {
			echo '<tr><td style="width:170px">' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['t'] ) ) . '</td><td style="width:80px">' . esc_html( $row['k'] ) . '</td><td>' . esc_html( $row['m'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** PMPro checks its settings nonce before calling this. Empty secret fields keep the saved secrets. */
	public static function save_settings_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in adminpages/paymentsettings.php
		if ( isset( $_POST['arionpay_api_key'] ) ) {
			update_option( 'pmpro_arionpay_api_key', sanitize_text_field( wp_unslash( $_POST['arionpay_api_key'] ) ), false );
		}
		foreach ( array( 'arionpay_api_secret', 'arionpay_webhook_secret' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secrets are stored as typed
			if ( '' !== $value ) {
				update_option( 'pmpro_' . $key, preg_replace( '/\s+/', '', $value ), false );
			}
		}
		if ( isset( $_POST['arionpay_base_url'] ) ) {
			update_option( 'pmpro_arionpay_base_url', untrailingslashit( esc_url_raw( trim( wp_unslash( $_POST['arionpay_base_url'] ) ) ) ), false );
		}
		// phpcs:enable
	}

	// ------------------------------------------------------------------ helpers

	private static function configured() {
		return '' !== trim( (string) get_option( 'pmpro_arionpay_api_key', '' ) ) && '' !== trim( (string) get_option( 'pmpro_arionpay_api_secret', '' ) );
	}

	private static function client() {
		$base = trim( (string) get_option( 'pmpro_arionpay_base_url', '' ) );
		return new \ArionPay\Client(
			trim( (string) get_option( 'pmpro_arionpay_api_key', '' ) ),
			trim( (string) get_option( 'pmpro_arionpay_api_secret', '' ) ),
			array(
				'baseUrl' => '' !== $base ? $base : 'https://merchant.arionpay.com',
				'appInfo' => 'pmpro/' . ( defined( 'PMPRO_VERSION' ) ? PMPRO_VERSION : '?' ) . ' arionpay-pmpro/' . ARIONPAY_PMPRO_VERSION,
			)
		);
	}

	private static function is_test_order( $order ) {
		$env = (string) $order->gateway_environment;
		return 'live' !== ( '' !== $env ? $env : (string) get_option( 'pmpro_gateway_environment' ) );
	}

	public static function webhook_url() {
		return add_query_arg( 'action', 'arionpay_pmpro_webhook', admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Random id of this site, made once, so two sites on one ArionPay store never touch each other's orders. Stored
	 * (not derived from the address) so a language plugin or an address change can't break waiting payments.
	 */
	private static function site_id() {
		$id = (string) get_option( 'pmpro_arionpay_site_id', '' );
		if ( ! preg_match( '/^[a-z0-9]{12}$/', $id ) ) {
			$id = strtolower( wp_generate_password( 12, false, false ) );
			if ( ! add_option( 'pmpro_arionpay_site_id', $id, '', false ) ) {
				$id = (string) get_option( 'pmpro_arionpay_site_id', $id ); // made by a parallel request
			}
		}
		return $id;
	}

	private static function money( $value ) {
		return number_format( (float) $value, 2, '.', '' );
	}

	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}

	private static function test_prefix( array $invoice ) {
		return ! empty( $invoice['test'] ) ? '[TEST - no real money] ' : '';
	}

	/** Order note with a time stamp (PMPro 3.6.1+ has add_order_note(); older 3.x versions get the same by hand). */
	private static function add_note( $order, $text ) {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $text );
			return;
		}
		$order->notes = trim( (string) $order->notes . ( '' === trim( (string) $order->notes ) ? '' : "\n\n" ) . wp_date( 'Y-m-d H:i' ) . ': ' . wp_strip_all_tags( $text ) );
	}

	/** Add an order note once per key (event id), so repeated deliveries don't repeat notes. */
	private static function note_once( $order_id, $key, $text ) {
		$seen = get_pmpro_membership_order_meta( $order_id, 'arionpay_notes', true );
		$seen = is_array( $seen ) ? $seen : array();
		if ( in_array( $key, $seen, true ) ) {
			return;
		}
		$seen[] = $key;
		update_pmpro_membership_order_meta( $order_id, 'arionpay_notes', array_slice( $seen, -100 ) );
		$order = new MemberOrder( (int) $order_id );
		self::add_note( $order, $text );
		$order->saveOrder();
		self::log( 'note', 'Order ' . $order->code . ': ' . $text );
	}

	/** Last 30 events on the settings page. Never holds keys. */
	private static function log( $kind, $message ) {
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 't' => time(), 'k' => $kind, 'm' => self::cut( $message, 400 ) );
		update_option( self::LOG_OPTION, array_slice( $log, -30 ), false );
	}

	private static function respond( $status, array $body ) {
		status_header( (int) $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}
}
