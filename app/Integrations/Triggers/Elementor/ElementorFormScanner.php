<?php
/**
 * Finds Elementor form widgets in _elementor_data post meta, so the admin UI
 * can offer forms and fields before any submission arrives.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\Elementor;

defined( 'ABSPATH' ) || exit;

final class ElementorFormScanner {

	const CACHE_KEY = 'wpi_brevo_elementor_forms';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * All Elementor form widgets on the site.
	 *
	 * @param bool $refresh Bypass the cache and rescan.
	 * @return array[] Each: id, name, post_id, post_title, fields[] of {id, label, type, required}.
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
	 * Drop the cached scan when Elementor documents change.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE_KEY );
	}

	private function scan(): array {
		global $wpdb;

		// Any published post type holding Elementor data (pages, templates,
		// popups, CPTs). The LIKE narrows it to documents with a form widget.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scan of core tables, result cached in a transient.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm.meta_value
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_elementor_data'
				WHERE p.post_status = 'publish' AND pm.meta_value LIKE %s",
				'%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%'
			)
		);

		$forms = array();
		foreach ( (array) $rows as $row ) {
			$elements = json_decode( (string) $row->meta_value, true );
			if ( is_array( $elements ) ) {
				$this->collect( $elements, $row, $forms );
			}
		}

		return array_values( $forms );
	}

	/**
	 * Walk the element tree depth-first: Elementor nests arbitrarily, so a
	 * fixed-depth loop would miss container-based layouts.
	 *
	 * @param array  $elements Elementor elements at this level.
	 * @param object $post     Row with ID and post_title.
	 * @param array  $forms    Accumulator, keyed by widget element id.
	 */
	private function collect( array $elements, object $post, array &$forms ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['widgetType'], $element['id'] ) && 'form' === $element['widgetType'] ) {
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

				$name = sanitize_text_field( (string) ( $settings['form_name'] ?? '' ) );
				if ( '' === $name ) {
					$name = sanitize_text_field( (string) ( $settings['_title'] ?? '' ) );
				}

				$forms[ (string) $element['id'] ] = array(
					'id'         => sanitize_text_field( (string) $element['id'] ),
					'name'       => '' !== $name ? $name : __( 'Untitled form', 'wpintegrations-connector-for-brevo' ),
					'post_id'    => (int) $post->ID,
					'post_title' => sanitize_text_field( (string) $post->post_title ),
					'fields'     => $this->fields( $settings ),
				);
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect( $element['elements'], $post, $forms );
			}
		}
	}

	/**
	 * Normalize a widget's field list. custom_id is the key that arrives in the
	 * submission, so it doubles as the mapping source id.
	 */
	private function fields( array $settings ): array {
		$fields = array();

		foreach ( (array) ( $settings['form_fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$id = (string) ( $field['custom_id'] ?? '' );
			if ( '' === $id ) {
				$id = (string) ( $field['_id'] ?? '' );
			}
			if ( '' === $id ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $field['field_label'] ?? '' ) );

			$fields[] = array(
				'id'       => sanitize_text_field( $id ),
				'label'    => '' !== $label ? $label : sanitize_text_field( $id ),
				'type'     => sanitize_text_field( (string) ( $field['field_type'] ?? 'text' ) ),
				'required' => ! empty( $field['required'] ),
			);
		}

		return $fields;
	}
}
