<?php
/**
 * Lists WPForms forms and the fields each declares, so the admin UI can offer
 * forms and fields before any submission arrives.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\WPForms;

defined( 'ABSPATH' ) || exit;

final class WPFormsFormScanner {

	const CACHE_KEY = 'wpi_brevo_wpforms_forms';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Field types that carry layout, uploads, spam checks or payments rather
	 * than something worth sending to a contact.
	 */
	const SKIPPED_TYPES = array(
		'divider',
		'html',
		'content',
		'pagebreak',
		'layout',
		'repeater',
		'captcha',
		'file-upload',
		'entry-preview',
		'payment-single',
		'payment-multiple',
		'payment-checkbox',
		'payment-select',
		'payment-total',
		'payment-coupon',
		'credit-card',
		'stripe-credit-card',
		'square',
		'authorize_net',
		'paypal-commerce',
	);

	/**
	 * Parts a compound field submits beside its combined value, with labels.
	 * The mapping source id is "<field id>.<part>".
	 */
	const PARTS = array(
		'name'    => array(
			'first'  => 'First',
			'middle' => 'Middle',
			'last'   => 'Last',
		),
		'address' => array(
			'address1' => 'Address line 1',
			'address2' => 'Address line 2',
			'city'     => 'City',
			'state'    => 'State',
			'postal'   => 'Postal code',
			'country'  => 'Country',
		),
	);

	/**
	 * All WPForms forms on the site.
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
		$handler = $this->form_handler();
		if ( ! $handler ) {
			return array();
		}

		$forms = array();
		foreach ( (array) $handler->get( '', array( 'posts_per_page' => -1 ) ) as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$data = json_decode( (string) $post->post_content, true );
			$name = sanitize_text_field( (string) $post->post_title );

			$forms[] = array(
				'id'     => (string) $post->ID,
				'name'   => '' !== $name ? $name : __( 'Untitled form', 'wpintegrations-connector-for-brevo' ),
				'fields' => is_array( $data ) ? $this->fields( (array) ( $data['fields'] ?? array() ) ) : array(),
			);
		}

		return $forms;
	}

	/**
	 * WPForms' form handler: obj() on current versions, the property before.
	 *
	 * @return object|null
	 */
	private function form_handler() {
		if ( ! function_exists( 'wpforms' ) ) {
			return null;
		}
		$wpforms = wpforms();
		$handler = method_exists( $wpforms, 'obj' ) ? $wpforms->obj( 'form' ) : ( $wpforms->form ?? null );
		return is_object( $handler ) && method_exists( $handler, 'get' ) ? $handler : null;
	}

	/**
	 * Normalize a form's fields. The field id is the key the submission uses.
	 *
	 * @param array $declared Field settings keyed by field id.
	 */
	private function fields( array $declared ): array {
		$fields = array();

		foreach ( $declared as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? 'text' );
			if ( in_array( $type, self::SKIPPED_TYPES, true ) ) {
				continue;
			}

			$id    = (string) $field['id'];
			$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
			$label = '' !== $label ? $label : sprintf( /* translators: %s: field id. */ __( 'Field %s', 'wpintegrations-connector-for-brevo' ), $id );

			$fields[] = array(
				'id'       => $id,
				'label'    => $label,
				'type'     => sanitize_text_field( $type ),
				'required' => ! empty( $field['required'] ),
			);

			foreach ( $this->parts( $type, (string) ( $field['format'] ?? '' ) ) as $part => $part_label ) {
				$fields[] = array(
					'id'       => $id . '.' . $part,
					'label'    => $label . ' — ' . $part_label,
					'type'     => 'text',
					'required' => false,
				);
			}
		}

		return $fields;
	}

	/**
	 * The parts a compound field submits, given its format.
	 *
	 * @return array<string, string>
	 */
	private function parts( string $type, string $format ): array {
		if ( 'name' === $type ) {
			if ( 'simple' === $format ) {
				return array();
			}
			$parts = self::PARTS['name'];
			if ( 'first-last' === $format ) {
				unset( $parts['middle'] );
			}
			return $parts;
		}
		return self::PARTS[ $type ] ?? array();
	}
}
