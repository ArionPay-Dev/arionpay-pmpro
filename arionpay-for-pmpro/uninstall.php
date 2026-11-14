<?php
// Remove the plugin's settings. Orders and their notes stay in Paid Memberships Pro.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
foreach ( array( 'pmpro_arionpay_api_key', 'pmpro_arionpay_api_secret', 'pmpro_arionpay_webhook_secret', 'pmpro_arionpay_base_url', 'pmpro_arionpay_log', 'pmpro_arionpay_site_id' ) as $option ) {
	delete_option( $option );
}
