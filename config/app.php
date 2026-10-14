<?php
/**
 * Application configuration.
 *
 * @package WPIntegrations\BrevoConnector
 */

defined( 'ABSPATH' ) || exit;

return array(
	'providers' => array(
		WPIntegrations\BrevoConnector\Providers\AppServiceProvider::class,
		WPIntegrations\BrevoConnector\Providers\DatabaseServiceProvider::class,
		WPIntegrations\BrevoConnector\Providers\IntegrationServiceProvider::class,
		WPIntegrations\BrevoConnector\Providers\RetentionServiceProvider::class,
		WPIntegrations\BrevoConnector\Providers\RestServiceProvider::class,
		WPIntegrations\BrevoConnector\Providers\AdminServiceProvider::class,
	),
);
