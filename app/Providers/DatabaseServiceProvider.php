<?php
/**
 * Runs pending migrations on admin_init, since plugin updates do not fire
 * activation hooks.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Providers;

use WPIntegrations\BrevoConnector\Core\ServiceProvider;
use WPIntegrations\BrevoConnector\Database\Migrator;

defined( 'ABSPATH' ) || exit;

final class DatabaseServiceProvider extends ServiceProvider {

	public function boot(): void {
		add_action(
			'admin_init',
			function () {
				// Every request, not just on a version bump: swapping builds can
				// keep the version and change the tables. Costs one get_option
				// when nothing is pending.
				$this->app->make( Migrator::class )->run();

				if ( get_option( 'wpi_brevo_version' ) !== WPI_BREVO_VERSION ) {
					update_option( 'wpi_brevo_version', WPI_BREVO_VERSION, false );
				}
			}
		);
	}
}
