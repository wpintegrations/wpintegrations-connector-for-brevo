<?php
/**
 * Removes tables, options and cron events when the plugin is deleted.
 *
 * @package WPIntegrations\BrevoConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Both editions share this data. Bail on an installed Pro copy, not just an
 * active one: a bulk delete can remove this plugin while Pro is momentarily
 * deactivated, taking Pro's tokens and integrations with it.
 */
$wpi_brevo_pro = 'wpintegrations-connector-for-brevo-pro/wpintegrations-connector-for-brevo-pro.php';

$wpi_brevo_active = (array) get_option( 'active_plugins', array() );
$wpi_brevo_shared = in_array( $wpi_brevo_pro, $wpi_brevo_active, true )
	|| ( is_multisite() && array_key_exists( $wpi_brevo_pro, (array) get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| file_exists( WP_PLUGIN_DIR . '/' . $wpi_brevo_pro );

if ( $wpi_brevo_shared ) {
	return;
}

global $wpdb;

// Plugin tables. Names are constants, not user input.
foreach ( array( 'wpi_brevo_flows', 'wpi_brevo_logs', 'wpi_brevo_jobs' ) as $wpi_brevo_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of plugin-owned tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $wpi_brevo_table ) );
}

// Options.
delete_option( 'wpi_brevo_settings' );
delete_option( 'wpi_brevo_version' );
delete_option( 'wpi_brevo_migrations' );
delete_option( 'wpi_brevo_recent_shapes' );

// Transients: each form plugin's scan cache, plus Brevo attribute and list
// caches swept by prefix (they are keyed by a hash of the API key).
foreach ( array( 'elementor', 'cf7', 'wpforms', 'fluentform', 'gravityforms' ) as $wpi_brevo_trigger ) {
	delete_transient( 'wpi_brevo_' . $wpi_brevo_trigger . '_forms' );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_wpi\_brevo\_api\_%'
	    OR option_name LIKE '\_transient\_timeout\_wpi\_brevo\_api\_%'"
);

// Cron events.
wp_clear_scheduled_hook( 'wpi_brevo_queue_tick' );
wp_clear_scheduled_hook( 'wpi_brevo_daily_purge' );
