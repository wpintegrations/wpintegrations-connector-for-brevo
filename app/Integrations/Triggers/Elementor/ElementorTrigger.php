<?php
/**
 * Elementor Pro Forms trigger.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Triggers\Elementor;

use WPIntegrations\BrevoConnector\Integrations\FlowRunner;
use WPIntegrations\BrevoConnector\Integrations\Trigger;

defined( 'ABSPATH' ) || exit;

final class ElementorTrigger extends Trigger {

	/** @var ElementorFormScanner */
	private $scanner;

	public function __construct( FlowRunner $runner, ElementorFormScanner $scanner ) {
		parent::__construct( $runner );
		$this->scanner = $scanner;
	}

	public function key(): string {
		return 'elementor';
	}

	public function label(): string {
		return __( 'Elementor Pro Forms', 'wpintegrations-connector-for-brevo' );
	}

	public function available(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	public function sources( bool $refresh = false ): array {
		return $this->scanner->forms( $refresh );
	}

	public function register(): void {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_submit' ), 10, 2 );

		// Documents changed, so rediscover forms on the next request.
		add_action( 'elementor/editor/after_save', array( ElementorFormScanner::class, 'forget' ) );
		add_action( 'save_post', array( ElementorFormScanner::class, 'forget' ) );
	}

	/**
	 * @param object $record  \ElementorPro\Modules\Forms\Classes\Form_Record.
	 * @param object $handler \ElementorPro\Modules\Forms\Classes\Ajax_Handler.
	 */
	public function on_submit( $record, $handler ): void {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get_form_settings' ) ) {
			return;
		}

		// The widget element id is canonical: unlike form_name it is unique and
		// survives renames. The name stays an alias so older flows keep firing.
		$element_id = sanitize_text_field( (string) $record->get_form_settings( 'id' ) );
		$form_name  = sanitize_text_field( (string) $record->get_form_settings( 'form_name' ) );
		$global_id  = $this->global_widget_id( $record );
		$source     = '' !== $global_id ? $global_id : ( '' !== $element_id ? $element_id : $form_name );

		$raw    = (array) $record->get( 'fields' );
		$fields = array();
		foreach ( $raw as $id => $field ) {
			$fields[ sanitize_text_field( (string) $id ) ] = $this->clean( isset( $field['value'] ) ? $field['value'] : '' );
		}

		if ( ! $fields ) {
			return;
		}

		$this->remember_shape( $source, array_keys( $fields ), $form_name );
		$aliases = array( $source, $element_id, $form_name );
		$this->dispatch( array_values( array_unique( array_filter( $aliases ) ) ), $fields, $this->request_meta() );
	}

	/**
	 * A form inside a Global Widget submits the id of the widget's instance on
	 * the page, while the form list offers the one inside its template. Elementor
	 * reports the template as form_post_id, so the template's id is recovered
	 * from there.
	 *
	 * @param object $record Form record.
	 * @return string The template's form element id, or '' for an ordinary form.
	 */
	private function global_widget_id( $record ): string {
		$template_id = (int) $record->get_form_settings( 'form_post_id' );
		$post_id     = (int) $record->get_form_settings( 'edit_post_id' );
		if ( ! $template_id || $template_id === $post_id || 'elementor_library' !== get_post_type( $template_id ) ) {
			return '';
		}

		$elements = json_decode( (string) get_post_meta( $template_id, '_elementor_data', true ), true );
		$widget   = is_array( $elements ) ? reset( $elements ) : null;
		if ( ! is_array( $widget ) || 'form' !== ( $widget['widgetType'] ?? '' ) ) {
			return '';
		}
		return sanitize_text_field( (string) ( $widget['id'] ?? '' ) );
	}
}
