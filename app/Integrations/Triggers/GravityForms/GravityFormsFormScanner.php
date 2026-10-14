<?php
/**
 * Lists Gravity Forms forms and the fields each declares, so the admin UI can
 * offer forms and fields before any submission arrives.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\GravityForms;

defined( 'ABSPATH' ) || exit;

final class GravityFormsFormScanner {

	const CACHE_KEY = 'wpi_brevo_gravityforms_forms';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Field types that carry layout, uploads, secrets, spam checks or payments
	 * rather than something worth sending to a contact.
	 */
	const SKIPPED_TYPES = array(
		'html',
		'section',
		'page',
		'captcha',
		'fileupload',
		'password',
		'product',
		'singleproduct',
		'option',
		'quantity',
		'shipping',
		'total',
		'creditcard',
		'donation',
		'stripe_creditcard',
		'square_creditcard',
		'paypal',
		'submit',
	);

	/**
	 * Types whose inputs are separate values worth mapping one by one. Other
	 * multi-input types (checkboxes, consent) are sent as one combined value.
	 */
	const SPLIT_TYPES = array( 'name', 'address' );

	/**
	 * All active Gravity Forms forms on the site.
	 *
	 * @param bool $refresh Bypass the cache and rescan.
	 * @return array[] Each: id, name, fields[] of {id, label, type, required}.
	 */
	public function forms( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$forms = $this->scan();
		set_transient( self::CACHE_KEY, $forms, self::CACHE_TTL );

		return $forms;
	}

	/**
	 * Drop the cached scan when a form changes.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE_KEY );
	}

	private function scan(): array {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$forms = array();
		foreach ( (array) \GFAPI::get_forms( true, false ) as $form ) {
			$name = sanitize_text_field( (string) ( $form['title'] ?? '' ) );

			$forms[] = array(
				'id'     => (string) (int) ( $form['id'] ?? 0 ),
				'name'   => '' !== $name ? $name : __( 'Untitled form', 'wpintegrations-connector-for-brevo' ),
				'fields' => $this->fields( (array) ( $form['fields'] ?? array() ) ),
			);
		}

		return $forms;
	}

	/**
	 * Normalize a form's fields. Ids match the entry's keys: "3" for a single
	 * input, "1.3" for a part of a name or address.
	 *
	 * @param array $declared GF_Field objects.
	 */
	public function fields( array $declared ): array {
		$fields = array();

		foreach ( $declared as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->id ) ) {
				continue;
			}
			$type = (string) ( method_exists( $field, 'get_input_type' ) ? $field->get_input_type() : $field->type );
			if ( in_array( $type, self::SKIPPED_TYPES, true ) || in_array( (string) $field->type, self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( '' !== (string) $field->adminLabel ? $field->adminLabel : $field->label ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms property.
			$label = '' !== $label ? $label : sprintf( /* translators: %s: field id. */ __( 'Field %s', 'wpintegrations-connector-for-brevo' ), $field->id );

			if ( in_array( $type, self::SPLIT_TYPES, true ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					if ( ! empty( $input['isHidden'] ) || empty( $input['id'] ) ) {
						continue;
					}
					$fields[] = array(
						'id'       => (string) $input['id'],
						'label'    => $label . ' — ' . sanitize_text_field( (string) ( $input['customLabel'] ?? ( $input['label'] ?? $input['id'] ) ) ),
						'type'     => 'text',
						'required' => ! empty( $field->isRequired ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					);
				}
				continue;
			}

			$fields[] = array(
				'id'       => (string) $field->id,
				'label'    => $label,
				'type'     => sanitize_text_field( $type ),
				'required' => ! empty( $field->isRequired ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			);
		}

		return $fields;
	}
}
