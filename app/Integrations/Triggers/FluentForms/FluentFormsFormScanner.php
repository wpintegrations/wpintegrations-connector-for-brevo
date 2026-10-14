<?php
/**
 * Lists Fluent Forms forms and the inputs each declares, so the admin UI can
 * offer forms and fields before any submission arrives.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\FluentForms;

defined( 'ABSPATH' ) || exit;

final class FluentFormsFormScanner {

	const CACHE_KEY = 'wpi_brevo_fluentform_forms';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Input elements that carry uploads, secrets, spam checks or payments
	 * rather than something worth sending to a contact.
	 */
	const SKIPPED_ELEMENTS = array(
		'input_file',
		'input_image',
		'input_password',
		'recaptcha',
		'hcaptcha',
		'turnstile',
		'custom_payment_component',
		'multi_payment_component',
		'subscription_payment_component',
		'payment_method',
		'item_quantity_component',
		'payment_coupon',
		'payment_summary_component',
	);

	/**
	 * All Fluent Forms forms on the site.
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
		// The model, not fluentFormApi()->forms(): that one pages and scopes
		// the list to the current user's form permissions.
		if ( ! class_exists( '\FluentForm\App\Models\Form' ) || ! function_exists( 'fluentFormApi' ) ) {
			return array();
		}

		$forms = array();
		foreach ( \FluentForm\App\Models\Form::select( array( 'id', 'title' ) )->orderBy( 'id', 'DESC' )->get() as $form ) {
			$name = sanitize_text_field( (string) $form->title );

			$forms[] = array(
				'id'     => (string) (int) $form->id,
				'name'   => '' !== $name ? $name : __( 'Untitled form', 'wpintegrations-connector-for-brevo' ),
				'fields' => $this->fields( (int) $form->id ),
			);
		}

		return $forms;
	}

	/**
	 * Normalize a form's inputs. The input name is the key the submission
	 * uses; a compound input (name, address) submits its parts under it, so
	 * each part becomes "<input>.<part>".
	 */
	private function fields( int $form_id ): array {
		$properties = fluentFormApi( 'forms' )->form( $form_id );
		if ( ! is_object( $properties ) || ! method_exists( $properties, 'inputs' ) ) {
			return array();
		}

		$fields = array();
		foreach ( (array) $properties->inputs( array( 'admin_label', 'raw' ) ) as $name => $input ) {
			$element = (string) ( $input['element'] ?? '' );
			if ( in_array( $element, self::SKIPPED_ELEMENTS, true ) ) {
				continue;
			}

			$raw   = (array) ( $input['raw'] ?? array() );
			$label = sanitize_text_field( (string) ( $input['admin_label'] ?? '' ) );
			$label = '' !== $label ? $label : sanitize_text_field( (string) $name );
			$parts = isset( $raw['fields'] ) && is_array( $raw['fields'] ) ? $raw['fields'] : array();

			if ( ! $parts ) {
				$fields[] = array(
					'id'       => sanitize_text_field( (string) $name ),
					'label'    => $label,
					'type'     => sanitize_text_field( $element ),
					'required' => ! empty( $raw['settings']['validation_rules']['required']['value'] ),
				);
				continue;
			}

			foreach ( $parts as $part_name => $part ) {
				if ( isset( $part['settings']['visible'] ) && ! $part['settings']['visible'] ) {
					continue;
				}
				$part_label = sanitize_text_field( (string) ( $part['settings']['label'] ?? $part_name ) );

				$fields[] = array(
					'id'       => sanitize_text_field( $name . '.' . $part_name ),
					'label'    => $label . ' — ' . ( '' !== $part_label ? $part_label : $part_name ),
					'type'     => 'text',
					'required' => ! empty( $part['settings']['validation_rules']['required']['value'] ),
				);
			}
		}

		return $fields;
	}
}
