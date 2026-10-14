<?php
/**
 * Registers REST routes from routes/api.php.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Providers;

use WPIntegrations\BrevoConnector\Core\ServiceProvider;
use WPIntegrations\BrevoConnector\Http\Router;

defined( 'ABSPATH' ) || exit;

final class RestServiceProvider extends ServiceProvider {

	public function boot(): void {
		add_action(
			'rest_api_init',
			function () {
				$routes = require WPI_BREVO_PATH . 'routes/api.php';
				$routes( new Router( $this->app ) );
			}
		);
	}
}
