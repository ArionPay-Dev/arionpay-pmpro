<?php
/**
 * Plugin Name:       ArionPay for Paid Memberships Pro
 * Plugin URI:        https://arionpay.com
 * Description:       Accept Bitcoin, USDT, Litecoin, Ethereum, TRON and more for memberships in Paid Memberships Pro. The money goes straight to your own wallet.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ArionPay
 * License:           MIT
 * Text Domain:       arionpay-for-pmpro
 *
 * Flow: checkout makes a "token" order (waiting for payment) and sends the member to the ArionPay payment page.
 * Only a signed ArionPay webhook (invoice.paid), or a signed invoice read, completes the checkout and gives the
 * membership. Whether an invoice is paid is decided by ArionPay alone; this plugin never compares crypto amounts.
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_PMPRO_VERSION', '1.0.1' );
define( 'ARIONPAY_PMPRO_FILE', __FILE__ );

add_action( 'plugins_loaded', 'arionpay_pmpro_load', 20 );

function arionpay_pmpro_load() {
	if ( ! class_exists( 'PMProGateway' ) ) {
		add_action( 'admin_notices', 'arionpay_pmpro_missing_notice' );
		return;
	}
	if ( ! class_exists( '\ArionPay\Client', true ) ) {
		require_once __DIR__ . '/lib/arionpay-php/autoload.php';
	}
	require_once __DIR__ . '/classes/class-pmprogateway-arionpay.php';
	add_action( 'init', array( 'PMProGateway_arionpay', 'init' ) );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'arionpay_pmpro_settings_link' );
}

function arionpay_pmpro_missing_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'ArionPay for Paid Memberships Pro needs the Paid Memberships Pro plugin. Install and activate it first.', 'arionpay-for-pmpro' ) . '</p></div>';
	}
}

function arionpay_pmpro_settings_link( $links ) {
	$url = add_query_arg( array( 'page' => 'pmpro-paymentsettings', 'edit_gateway' => 'arionpay' ), admin_url( 'admin.php' ) );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'arionpay-for-pmpro' ) . '</a>' );
	return $links;
}
