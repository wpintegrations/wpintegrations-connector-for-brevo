<?php
/**
 * Lists Contact Form 7 forms and the fields each declares, so the admin UI can
 * offer forms and fields before any submission arrives.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\ContactForm7;

defined( 'ABSPATH' ) || exit;

final class ContactForm7FormScanner {

	const CACHE_KEY = 'wpi_brevo_cf7_forms';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Form tags that never reach the posted data, or carry nothing worth sending.
	 */
	const SKIPPED_TYPES = array( 'submit', 'file', 'captchac', 'captchar', 'quiz' );

	/**
	 * All Contact Form 7 forms on the site.
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
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}

		$forms = array();
		foreach ( (array) \WPCF7_ContactForm::find() as $form ) {
			$name = sanitize_text_field( (string) $form->title() );

			$forms[] = array(
				'id'     => (string) $form->id(),
				'name'   => '' !== $name ? $name : __( 'Untitled form', 'wpintegrations-connector-for-brevo' ),
				'fields' => $this->fields( $form ),
			);
		}

		return $forms;
	}

	/**
	 * Normalize a form's tags. The tag name is the key that arrives in the posted
	 * data, so it doubles as the mapping source id; CF7 has no separate label.
	 *
	 * @param \WPCF7_ContactForm $form Contact form.
	 */
	private function fields( $form ): array {
		$fields = array();
		$seen   = array();

		foreach ( (array) $form->scan_form_tags() as $tag ) {
			$id = sanitize_text_field( (string) $tag->name );
			if ( '' === $id || isset( $seen[ $id ] ) || in_array( (string) $tag->basetype, self::SKIPPED_TYPES, true ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$fields[] = array(
				'id'       => $id,
				'label'    => $id,
				'type'     => sanitize_text_field( (string) $tag->basetype ),
				'required' => $tag->is_required(),
			);
		}

		return $fields;
	}
}
