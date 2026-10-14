<?php
/**
 * Registers built-in triggers/actions and boots available triggers.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Providers;

use WPIntegrations\BrevoConnector\Core\ServiceProvider;
use WPIntegrations\BrevoConnector\Integrations\Actions\Brevo\BrevoAction;
use WPIntegrations\BrevoConnector\Integrations\Registry;
use WPIntegrations\BrevoConnector\Integrations\Triggers\ContactForm7\ContactForm7Trigger;
use WPIntegrations\BrevoConnector\Integrations\Triggers\Elementor\ElementorTrigger;
use WPIntegrations\BrevoConnector\Integrations\Triggers\FluentForms\FluentFormsTrigger;
use WPIntegrations\BrevoConnector\Integrations\Triggers\GravityForms\GravityFormsTrigger;
use WPIntegrations\BrevoConnector\Integrations\Triggers\WPForms\WPFormsTrigger;

defined( 'ABSPATH' ) || exit;

final class IntegrationServiceProvider extends ServiceProvider {

	public function boot(): void {
		$registry = $this->app->make( Registry::class );

		// Every supported form plugin is registered; only the active ones hook
		// in, and the editor offers only those.
		$registry->add_trigger( $this->app->make( ContactForm7Trigger::class ) );
		$registry->add_trigger( $this->app->make( ElementorTrigger::class ) );
		$registry->add_trigger( $this->app->make( WPFormsTrigger::class ) );
		$registry->add_trigger( $this->app->make( FluentFormsTrigger::class ) );
		$registry->add_trigger( $this->app->make( GravityFormsTrigger::class ) );
		$registry->add_action( $this->app->make( BrevoAction::class ) );

		/**
		 * Add-ons register additional triggers and actions here.
		 *
		 * @param Registry $registry The integration registry.
		 */
		do_action( 'wpi_brevo_register_integrations', $registry );

		add_action(
			'init',
			static function () use ( $registry ) {
				foreach ( $registry->triggers() as $trigger ) {
					if ( $trigger->available() ) {
						$trigger->register();
					}
				}
			}
		);
	}
}
