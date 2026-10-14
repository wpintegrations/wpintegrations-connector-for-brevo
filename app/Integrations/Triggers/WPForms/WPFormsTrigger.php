<?php
/**
 * WPForms trigger (Lite and paid editions).
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\WPForms;

use WPIntegrations\BrevoConnector\Integrations\FlowRunner;
use WPIntegrations\BrevoConnector\Integrations\Trigger;

defined( 'ABSPATH' ) || exit;

final class WPFormsTrigger extends Trigger {

	/** @var WPFormsFormScanner */
	private $scanner;

	public function __construct( FlowRunner $runner, WPFormsFormScanner $scanner ) {
		parent::__construct( $runner );
		$this->scanner = $scanner;
	}

	public function key(): string {
		return 'wpforms';
	}

	public function label(): string {
		return __( 'WPForms', 'wpintegrations-connector-for-brevo' );
	}

	public function available(): bool {
		return function_exists( 'wpforms' );
	}

	public function sources( bool $refresh = false ): array {
		return $this->scanner->forms( $refresh );
	}

	public function register(): void {
		// Fires once a submission has passed validation and spam checks.
		add_action( 'wpforms_process_complete', array( $this, 'on_submit' ), 10, 4 );

		// Forms changed, so rediscover them on the next request.
		add_action( 'wpforms_save_form', array( WPFormsFormScanner::class, 'forget' ) );
		add_action( 'save_post_wpforms', array( WPFormsFormScanner::class, 'forget' ) );
		add_action( 'deleted_post', array( WPFormsFormScanner::class, 'forget' ) );
	}

	/**
	 * @param array $fields    Processed fields keyed by field id.
	 * @param array $entry     Raw submission.
	 * @param array $form_data Form settings and fields.
	 * @param int   $entry_id  Entry id (0 in Lite, which stores no entries).
	 */
	public function on_submit( $fields, $entry, $form_data, $entry_id ): void {
		$form_id = (int) ( $form_data['id'] ?? 0 );
		if ( ! $form_id || ! is_array( $fields ) ) {
			return;
		}

		$out = array();
		foreach ( $fields as $id => $field ) {
			if ( ! is_array( $field ) || in_array( (string) ( $field['type'] ?? '' ), WPFormsFormScanner::SKIPPED_TYPES, true ) ) {
				continue;
			}
			$id = (string) $id;

			// Name and address parts arrive beside the combined value; each is
			// offered as its own mapping source.
			foreach ( WPFormsFormScanner::PARTS[ $field['type'] ?? '' ] ?? array() as $part => $unused ) {
				if ( isset( $field[ $part ] ) && '' !== (string) $field[ $part ] ) {
					$out[ $id . '.' . $part ] = $this->clean( $field[ $part ] );
				}
			}

			// Choice fields join their picks with newlines; send them as a list.
			$value = (string) ( $field['value'] ?? '' );
			if ( in_array( (string) ( $field['type'] ?? '' ), array( 'checkbox', 'payment-checkbox' ), true ) && false !== strpos( $value, "\n" ) ) {
				$out[ $id ] = $this->clean( array_filter( array_map( 'trim', explode( "\n", $value ) ), 'strlen' ) );
			} else {
				$out[ $id ] = $this->clean( $value );
			}
		}

		if ( ! $out ) {
			return;
		}

		$source = (string) $form_id;
		$this->remember_shape( $source, array_keys( $out ), (string) ( $form_data['settings']['form_title'] ?? '' ) );
		$this->dispatch( $source, $out, $this->request_meta() );
	}
}
