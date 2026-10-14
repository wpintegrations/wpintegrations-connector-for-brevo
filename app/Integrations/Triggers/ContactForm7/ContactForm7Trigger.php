<?php
/**
 * Contact Form 7 trigger.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\ContactForm7;

use WPIntegrations\BrevoConnector\Integrations\FlowRunner;
use WPIntegrations\BrevoConnector\Integrations\Trigger;

defined( 'ABSPATH' ) || exit;

final class ContactForm7Trigger extends Trigger {

	/** @var ContactForm7FormScanner */
	private $scanner;

	public function __construct( FlowRunner $runner, ContactForm7FormScanner $scanner ) {
		parent::__construct( $runner );
		$this->scanner = $scanner;
	}

	public function key(): string {
		return 'cf7';
	}

	public function label(): string {
		return __( 'Contact Form 7', 'wpintegrations-connector-for-brevo' );
	}

	public function available(): bool {
		return defined( 'WPCF7_VERSION' );
	}

	public function sources( bool $refresh = false ): array {
		return $this->scanner->forms( $refresh );
	}

	public function register(): void {
		// Both fire only for a valid, non-spam, non-aborted submission. Hooking
		// the failure too means a site whose mail is broken still gets its lead.
		add_action( 'wpcf7_mail_sent', array( $this, 'on_submit' ) );
		add_action( 'wpcf7_mail_failed', array( $this, 'on_submit' ) );

		// Forms changed, so rediscover them on the next request.
		add_action( 'wpcf7_after_save', array( ContactForm7FormScanner::class, 'forget' ) );
		add_action( 'save_post', array( ContactForm7FormScanner::class, 'forget' ) );
		add_action( 'deleted_post', array( ContactForm7FormScanner::class, 'forget' ) );
	}

	/**
	 * @param object $contact_form \WPCF7_ContactForm.
	 */
	public function on_submit( $contact_form ): void {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		// The post id is canonical: unlike the title it is unique and survives
		// renames, and unlike the shortcode hash it exists on every CF7 version.
		$source = (string) $contact_form->id();

		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $id => $value ) {
			$id = sanitize_text_field( (string) $id );
			// CF7's own hidden inputs are underscore-prefixed.
			if ( '' === $id || 0 === strpos( $id, '_' ) ) {
				continue;
			}
			$fields[ $id ] = $this->clean( $value );
		}

		if ( ! $fields ) {
			return;
		}

		$this->remember_shape( $source, array_keys( $fields ), (string) $contact_form->title() );
		// CF7 submits over REST, so it records the page and user itself.
		$this->dispatch(
			$source,
			$fields,
			$this->request_meta( (string) $submission->get_meta( 'url' ), (int) $submission->get_meta( 'current_user_id' ) )
		);
	}
}
