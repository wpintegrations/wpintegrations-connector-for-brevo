<?php
/**
 * Fluent Forms trigger.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\FluentForms;

use WPIntegrations\BrevoConnector\Integrations\FlowRunner;
use WPIntegrations\BrevoConnector\Integrations\Trigger;

defined( 'ABSPATH' ) || exit;

final class FluentFormsTrigger extends Trigger {

	/** @var FluentFormsFormScanner */
	private $scanner;

	public function __construct( FlowRunner $runner, FluentFormsFormScanner $scanner ) {
		parent::__construct( $runner );
		$this->scanner = $scanner;
	}

	public function key(): string {
		return 'fluentform';
	}

	public function label(): string {
		return __( 'Fluent Forms', 'wpintegrations-connector-for-brevo' );
	}

	public function available(): bool {
		return defined( 'FLUENTFORM_VERSION' );
	}

	public function sources( bool $refresh = false ): array {
		return $this->scanner->forms( $refresh );
	}

	public function register(): void {
		// Fires after a valid submission is stored, with the posted data.
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submit' ), 20, 3 );

		// Forms changed, so rediscover them on the next request.
		add_action( 'fluentform/before_updating_form', array( FluentFormsFormScanner::class, 'forget' ) );
		add_action( 'fluentform/inserted_new_form', array( FluentFormsFormScanner::class, 'forget' ) );
		add_action( 'fluentform/form_duplicated', array( FluentFormsFormScanner::class, 'forget' ) );
		add_action( 'fluentform/after_form_delete', array( FluentFormsFormScanner::class, 'forget' ) );
	}

	/**
	 * @param int    $insert_id Entry id.
	 * @param array  $form_data Posted data keyed by input name.
	 * @param object $form      \FluentForm\App\Models\Form.
	 */
	public function on_submit( $insert_id, $form_data, $form ): void {
		if ( ! is_object( $form ) || empty( $form->id ) || ! is_array( $form_data ) ) {
			return;
		}

		$fields = array();
		foreach ( $form_data as $name => $value ) {
			$name = sanitize_text_field( (string) $name );
			// Fluent's own hidden inputs and spam-check tokens.
			if ( '' === $name || 0 === strpos( $name, '_' ) || in_array( $name, array( 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response' ), true ) ) {
				continue;
			}

			// A compound input (name, address) posts its parts as a map.
			if ( is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				foreach ( $value as $part => $part_value ) {
					if ( is_scalar( $part_value ) ) {
						$fields[ $name . '.' . sanitize_text_field( (string) $part ) ] = $this->clean( $part_value );
					}
				}
				continue;
			}

			$fields[ $name ] = $this->clean( $value );
		}

		if ( ! $fields ) {
			return;
		}

		$source = (string) (int) $form->id;
		$this->remember_shape( $source, array_keys( $fields ), (string) ( $form->title ?? '' ) );
		// Fluent posts over admin-ajax, so the referer is the form's page.
		$this->dispatch( $source, $fields, $this->request_meta() );
	}
}
