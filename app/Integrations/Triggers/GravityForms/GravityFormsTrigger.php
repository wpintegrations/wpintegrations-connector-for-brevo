<?php
/**
 * Gravity Forms trigger.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\GravityForms;

use WPIntegrations\BrevoConnector\Integrations\FlowRunner;
use WPIntegrations\BrevoConnector\Integrations\Trigger;

defined( 'ABSPATH' ) || exit;

final class GravityFormsTrigger extends Trigger {

	/** @var GravityFormsFormScanner */
	private $scanner;

	public function __construct( FlowRunner $runner, GravityFormsFormScanner $scanner ) {
		parent::__construct( $runner );
		$this->scanner = $scanner;
	}

	public function key(): string {
		return 'gravityforms';
	}

	public function label(): string {
		return __( 'Gravity Forms', 'wpintegrations-connector-for-brevo' );
	}

	public function available(): bool {
		return class_exists( 'GFAPI' );
	}

	public function sources( bool $refresh = false ): array {
		return $this->scanner->forms( $refresh );
	}

	public function register(): void {
		add_action( 'gform_after_submission', array( $this, 'on_submit' ), 10, 2 );

		// Forms changed, so rediscover them on the next request.
		add_action( 'gform_after_save_form', array( GravityFormsFormScanner::class, 'forget' ) );
		add_action( 'gform_post_form_duplicated', array( GravityFormsFormScanner::class, 'forget' ) );
		add_action( 'gform_after_delete_form', array( GravityFormsFormScanner::class, 'forget' ) );
		add_action( 'gform_post_form_activated', array( GravityFormsFormScanner::class, 'forget' ) );
		add_action( 'gform_post_form_deactivated', array( GravityFormsFormScanner::class, 'forget' ) );
	}

	/**
	 * @param array $entry The saved entry, keyed by field and input id.
	 * @param array $form  The form.
	 */
	public function on_submit( $entry, $form ): void {
		if ( ! is_array( $entry ) || ! is_array( $form ) || empty( $form['id'] ) ) {
			return;
		}
		// This hook also runs for entries the spam checks caught.
		if ( 'spam' === ( $entry['status'] ?? '' ) ) {
			return;
		}

		$fields = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->id ) ) {
				continue;
			}
			$type = (string) ( method_exists( $field, 'get_input_type' ) ? $field->get_input_type() : $field->type );
			if ( in_array( $type, GravityFormsFormScanner::SKIPPED_TYPES, true ) || in_array( (string) $field->type, GravityFormsFormScanner::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$inputs = is_array( $field->inputs ) ? $field->inputs : array();

			// Name and address parts map one by one.
			if ( in_array( $type, GravityFormsFormScanner::SPLIT_TYPES, true ) && $inputs ) {
				foreach ( $inputs as $input ) {
					$id = (string) ( $input['id'] ?? '' );
					if ( '' !== $id && '' !== (string) ( $entry[ $id ] ?? '' ) ) {
						$fields[ $id ] = $this->clean( $entry[ $id ] );
					}
				}
				continue;
			}

			// Checkboxes and consent store one input per choice; send the
			// ticked ones as a list under the field's own id.
			if ( $inputs ) {
				$picked = array();
				foreach ( $inputs as $input ) {
					$value = (string) ( $entry[ (string) ( $input['id'] ?? '' ) ] ?? '' );
					if ( '' !== $value ) {
						$picked[] = $value;
					}
				}
				$fields[ (string) $field->id ] = $this->clean( $picked );
				continue;
			}

			$value = $entry[ (string) $field->id ] ?? '';
			// A multi-select stores its picks as a JSON list.
			if ( 'multiselect' === $type && is_string( $value ) && 0 === strpos( $value, '[' ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : $value;
			}
			$fields[ (string) $field->id ] = $this->clean( $value );
		}

		if ( ! $fields ) {
			return;
		}

		$source = (string) (int) $form['id'];
		$this->remember_shape( $source, array_keys( $fields ), (string) ( $form['title'] ?? '' ) );
		// The entry records the page the form was on and who sent it.
		$this->dispatch(
			$source,
			$fields,
			$this->request_meta( (string) ( $entry['source_url'] ?? '' ), (int) ( $entry['created_by'] ?? 0 ) )
		);
	}
}
