<?php
/**
 * Sends form submissions to Brevo as contacts, optionally adding them to lists.
 *
 * One site-wide API key covers delivery, the list picker and the double
 * opt-in template picker. Brevo authenticates with the api-key header; there
 * is no OAuth.
 *
 * The email is sent as the contact's identifier; every other mapped target is
 * sent as a Brevo attribute of that name.
 *
 * @package WPIntegrations\BrevoConnector
 */

namespace WPIntegrations\BrevoConnector\Integrations\Actions\Brevo;

use WPIntegrations\BrevoConnector\Delivery\Result;
use WPIntegrations\BrevoConnector\Integrations\Action;
use WPIntegrations\BrevoConnector\Services\Http;

defined( 'ABSPATH' ) || exit;

final class BrevoAction extends Action {

	const BASE_URL = 'https://api.brevo.com/v3';

	/** Brevo's page-size ceiling for GET /contacts/lists. */
	const LISTS_PAGE_SIZE = 50;

	/** Stop paging lists after this many pages (2,500 lists). */
	const LISTS_MAX_PAGES = 50;

	/** Page size for GET /smtp/templates, and the page cap (1,000 templates). */
	const TEMPLATES_PAGE_SIZE = 100;
	const TEMPLATES_MAX_PAGES = 10;

	/** @var Http */
	private $http;

	public function __construct( Http $http ) {
		$this->http = $http;
	}

	public function key(): string {
		return 'brevo';
	}

	public function label(): string {
		return __( 'Brevo', 'wpintegrations-connector-for-brevo' );
	}

	public function global_fields(): array {
		return array(
			array(
				'key'    => 'api_key',
				'label'  => __( 'Brevo API key', 'wpintegrations-connector-for-brevo' ),
				'secret' => true,
			),
		);
	}

	public function target_fields(): array {
		return array(
			array(
				'key'      => 'email',
				'label'    => __( 'Email', 'wpintegrations-connector-for-brevo' ),
				'required' => true,
			),
			array(
				'key'   => 'FIRSTNAME',
				'label' => __( 'First name', 'wpintegrations-connector-for-brevo' ),
			),
			array(
				'key'   => 'LASTNAME',
				'label' => __( 'Last name', 'wpintegrations-connector-for-brevo' ),
			),
		);
	}

	/**
	 * The email identifies the contact, so it must be mapped.
	 */
	public function validate_flow( array $field_map, array $settings ): ?string {
		$targets = array();
		foreach ( $field_map as $pair ) {
			$targets[ strtoupper( (string) $pair['target'] ) ] = true;
		}

		if ( ! isset( $targets['EMAIL'] ) ) {
			return __( 'Map a form field to Email — Brevo identifies the contact by it.', 'wpintegrations-connector-for-brevo' );
		}
		return null;
	}

	/**
	 * Per-integration settings. Nothing is declared that execute() does not send.
	 */
	public function flow_fields(): array {
		return array(
			array(
				'key'            => 'list_ids',
				'label'          => __( 'Add to lists', 'wpintegrations-connector-for-brevo' ),
				'help'           => __( 'Every contact from this form joins these lists.', 'wpintegrations-connector-for-brevo' ),
				'type'           => 'select',
				'multiple'       => true,
				'options_source' => 'lists',
			),
			array(
				'key'     => 'update_existing',
				'label'   => __( 'Update contacts that already exist in Brevo', 'wpintegrations-connector-for-brevo' ),
				'type'    => 'boolean',
				'default' => '1',
			),
			array(
				'key'     => 'double_opt_in',
				'label'   => __( 'Ask for confirmation by email (double opt-in)', 'wpintegrations-connector-for-brevo' ),
				'help'    => __( 'Contacts join your lists only after confirming by email.', 'wpintegrations-connector-for-brevo' ),
				'type'    => 'boolean',
				'default' => '',
				'below'   => true,
			),
			array(
				'key'            => 'doi_template_id',
				'label'          => __( 'Confirmation email template', 'wpintegrations-connector-for-brevo' ),
				'help'           => __( 'A Brevo template whose button links to “Double opt-in link”.', 'wpintegrations-connector-for-brevo' ),
				'type'           => 'select',
				'options_source' => 'doi_templates',
				'required'       => true,
				'depends_on'     => array( 'double_opt_in' => true ),
			),
			array(
				'key'        => 'doi_redirect_url',
				'label'      => __( 'Page after confirming', 'wpintegrations-connector-for-brevo' ),
				'help'       => __( 'Leave empty for your home page.', 'wpintegrations-connector-for-brevo' ),
				'depends_on' => array( 'double_opt_in' => true ),
			),
			array(
				'key'        => 'doi_skip_existing',
				'label'      => __( 'Skip the confirmation for people already in Brevo', 'wpintegrations-connector-for-brevo' ),
				'help'       => __( 'Known emails are added without confirming again.', 'wpintegrations-connector-for-brevo' ),
				'type'       => 'boolean',
				'default'    => '',
				'below'      => true,
				'depends_on' => array( 'double_opt_in' => true ),
			),
		);
	}

	/**
	 * Check the stored key against GET /account, which reads without writing.
	 */
	public function test_connection( array $credentials ): Result {
		$key = $this->credential( 'api_key' );
		if ( '' === $key ) {
			return Result::failure( __( 'Save your Brevo API key first.', 'wpintegrations-connector-for-brevo' ) );
		}

		$response = $this->http->get( self::BASE_URL . '/account', $this->headers( $key ) );

		if ( 0 === $response->code ) {
			/* translators: %s: transport error message. */
			return Result::failure( sprintf( __( 'Could not reach Brevo: %s', 'wpintegrations-connector-for-brevo' ), $response->error ) );
		}
		if ( 401 === $response->code ) {
			return Result::failure( $this->unauthorized_message( $response ) );
		}
		if ( ! $response->ok ) {
			return Result::failure( $this->error_message( $response ) );
		}

		$body    = is_array( $response->body ) ? $response->body : array();
		$account = (string) ( $body['companyName'] ?? ( $body['email'] ?? '' ) );

		return Result::success(
			'' !== $account
				/* translators: %s: Brevo account (company name or email). */
				? sprintf( __( 'Connected to Brevo account “%s”.', 'wpintegrations-connector-for-brevo' ), sanitize_text_field( $account ) )
				: __( 'Connected to Brevo.', 'wpintegrations-connector-for-brevo' )
		);
	}

	public function discover_options( string $key, array $settings ): array {
		if ( 'lists' === $key ) {
			$options = $this->lists();
		} elseif ( 'doi_templates' === $key ) {
			$options = $this->doi_templates();
		} else {
			$options = null;
		}
		return null === $options ? array() : $options;
	}

	public function execute( object $flow, array $payload ): Result {
		$key = $this->credential( 'api_key' );
		if ( '' === $key ) {
			return Result::failure( __( 'Brevo is not connected — add your API key under Settings.', 'wpintegrations-connector-for-brevo' ) );
		}

		// The email is a top-level field; every other mapped target is an
		// attribute of that name.
		$email      = '';
		$attributes = array();
		foreach ( $this->map_fields( $flow, $payload ) as $target => $value ) {
			$upper = strtoupper( trim( (string) $target ) );
			$value = trim( (string) $value );
			if ( 'EMAIL' === $upper ) {
				$email = $value;
			} elseif ( '' !== $upper && '' !== $value ) {
				$attributes[ $upper ] = sanitize_text_field( $value );
			}
		}

		// The mapping has the email, but the visitor may have left it empty.
		if ( '' === $email ) {
			return Result::failure( __( 'This submission has no email, so Brevo cannot identify the contact. Nothing was sent.', 'wpintegrations-connector-for-brevo' ) );
		}
		if ( ! is_email( $email ) ) {
			/* translators: %s: the submitted email address. */
			return Result::failure( sprintf( __( '“%s” is not a valid email address, so the contact was not sent.', 'wpintegrations-connector-for-brevo' ), sanitize_text_field( $email ) ) );
		}
		$email = sanitize_email( $email );

		$settings = (array) ( $flow->settings ?? array() );
		$update   = ! empty( $settings['update_existing'] );
		$list_ids = array_values( array_filter( array_map( 'absint', (array) ( $settings['list_ids'] ?? array() ) ) ) );

		if ( ! empty( $settings['double_opt_in'] ) ) {
			$template_id = absint( $settings['doi_template_id'] ?? 0 );
			if ( ! $template_id ) {
				return Result::failure( __( 'Double opt-in is on but no confirmation template is chosen. Pick one in the integration.', 'wpintegrations-connector-for-brevo' ) );
			}
			if ( ! $list_ids ) {
				return Result::failure( __( 'Double opt-in needs at least one list to add confirmed contacts to. Choose one in the integration.', 'wpintegrations-connector-for-brevo' ) );
			}

			$known = false;
			if ( ! empty( $settings['doi_skip_existing'] ) ) {
				$known = $this->contact_exists( $email, $key );
				if ( $known instanceof Result ) {
					return $known;
				}
			}

			if ( ! $known ) {
				$redirect = esc_url_raw( trim( (string) ( $settings['doi_redirect_url'] ?? '' ) ) );
				$body     = array(
					'email'          => $email,
					// Brevo wants an object here even when nothing is mapped.
					'attributes'     => (object) $attributes,
					'includeListIds' => $list_ids,
					'templateId'     => $template_id,
					'redirectionUrl' => '' !== $redirect ? $redirect : home_url( '/' ),
				);

				return $this->send(
					'/contacts/doubleOptinConfirmation',
					$body,
					$key,
					__( 'Confirmation email sent by Brevo. The contact joins the lists once they confirm.', 'wpintegrations-connector-for-brevo' ),
					__( 'Confirmation email sent by Brevo. The contact joins the lists once they confirm.', 'wpintegrations-connector-for-brevo' ),
					$update
				);
			}
		}

		$body = array(
			'email'         => $email,
			'updateEnabled' => $update,
		);
		if ( $attributes ) {
			$body['attributes'] = $attributes;
		}
		if ( $list_ids ) {
			$body['listIds'] = $list_ids;
		}

		return $this->send(
			'/contacts',
			$body,
			$key,
			__( 'Contact created in Brevo.', 'wpintegrations-connector-for-brevo' ),
			__( 'Contact updated in Brevo.', 'wpintegrations-connector-for-brevo' ),
			$update
		);
	}

	/**
	 * POST to Brevo and turn the answer into a logged Result.
	 *
	 * @param string $path            Path under BASE_URL.
	 * @param array  $body            Request body.
	 * @param string $key             API key.
	 * @param string $created_message Log line for 201.
	 * @param string $updated_message Log line for any other 2xx.
	 * @param bool   $update          Whether existing contacts may be updated.
	 */
	private function send( string $path, array $body, string $key, string $created_message, string $updated_message, bool $update ): Result {
		$endpoint        = self::BASE_URL . $path;
		$request_excerpt = array(
			'endpoint' => $endpoint,
			'payload'  => $body,
		);

		$response = $this->http->post_json( $endpoint, $body, $this->headers( $key ) );
		$logged   = array(
			'code' => $response->code,
			'body' => $response->body,
		);

		if ( 201 === $response->code ) {
			return Result::success( $created_message, $request_excerpt, $logged );
		}
		if ( $response->ok ) {
			return Result::success( $updated_message, $request_excerpt, $logged );
		}

		// With updating off, Brevo refuses a known email. That is the choice
		// the integration asked for, not a failed delivery.
		$code = is_array( $response->body ) ? (string) ( $response->body['code'] ?? '' ) : '';
		if ( ! $update && 400 === $response->code && 'duplicate_parameter' === $code ) {
			return Result::success( __( 'Contact already in Brevo — left unchanged, since this integration does not update existing contacts.', 'wpintegrations-connector-for-brevo' ), $request_excerpt, $logged );
		}

		return $this->failure( $response, $request_excerpt, $logged );
	}

	/**
	 * Whether Brevo already has a contact with this email.
	 *
	 * @return bool|Result A Result when Brevo could not answer, so delivery
	 *                     stops rather than guessing.
	 */
	private function contact_exists( string $email, string $key ) {
		$response = $this->http->get( self::BASE_URL . '/contacts/' . rawurlencode( $email ), $this->headers( $key ) );
		if ( $response->ok ) {
			return true;
		}
		if ( 404 === $response->code ) {
			return false;
		}
		return $this->failure( $response, array( 'endpoint' => self::BASE_URL . '/contacts/{email}' ), array( 'code' => $response->code, 'body' => $response->body ) );
	}

	/**
	 * A failed call as a Result, worded from Brevo's own reason.
	 *
	 * @param object     $response        Normalized response.
	 * @param array|null $request_excerpt Logged request.
	 * @param array|null $logged          Logged response.
	 */
	private function failure( object $response, $request_excerpt, $logged ): Result {
		if ( 401 === $response->code ) {
			$message = $this->unauthorized_message( $response );
		} elseif ( 0 === $response->code ) {
			/* translators: %s: transport error message. */
			$message = sprintf( __( 'Could not reach Brevo: %s', 'wpintegrations-connector-for-brevo' ), $response->error );
		} else {
			$message = $this->error_message( $response );
		}

		return Result::failure( $message, $request_excerpt, $logged );
	}

	/**
	 * Why Brevo refused the key. Brevo says why — a deleted key, or an IP
	 * address missing from the account's authorised IPs — so its reason is
	 * passed on; the generic hint covers a 401 that gives none.
	 *
	 * @param object $response Normalized response.
	 */
	private function unauthorized_message( object $response ): string {
		$reason = is_array( $response->body ) ? trim( (string) ( $response->body['message'] ?? '' ) ) : '';
		if ( '' === $reason ) {
			return __( 'Brevo rejected the API key. Check that you copied the whole key, and that it has not been deleted in Brevo.', 'wpintegrations-connector-for-brevo' );
		}
		/* translators: %s: Brevo's reason, e.g. an unrecognised IP address. */
		return sprintf( __( 'Brevo refused the request: %s', 'wpintegrations-connector-for-brevo' ), mb_substr( sanitize_text_field( $reason ), 0, 400 ) );
	}

	/**
	 * The account's contact lists as select options, all pages.
	 *
	 * @return array<int, array{value:string, label:string}>|null
	 */
	private function lists(): ?array {
		$key = $this->credential( 'api_key' );
		if ( '' === $key ) {
			return null;
		}

		$cache_key = $this->cache_key( 'lists', $key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		$options = array();
		for ( $page = 0; $page < self::LISTS_MAX_PAGES; $page++ ) {
			$url = add_query_arg(
				array(
					'limit'  => self::LISTS_PAGE_SIZE,
					'offset' => $page * self::LISTS_PAGE_SIZE,
					'sort'   => 'desc',
				),
				self::BASE_URL . '/contacts/lists'
			);

			$response = $this->http->get( $url, $this->headers( $key ) );
			if ( ! $response->ok || ! is_array( $response->body ) ) {
				return $options ? $options : null;
			}

			$batch = (array) ( $response->body['lists'] ?? array() );
			foreach ( $batch as $list ) {
				$options[] = array(
					// Select values are strings in the admin app; execute() casts back.
					'value' => (string) (int) ( $list['id'] ?? 0 ),
					'label' => sanitize_text_field( (string) ( $list['name'] ?? '' ) ),
				);
			}

			if ( count( $batch ) < self::LISTS_PAGE_SIZE ) {
				break;
			}
		}

		// Short-lived: a list made in Brevo a minute ago should show up soon.
		set_transient( $cache_key, $options, 5 * MINUTE_IN_SECONDS );
		return $options;
	}

	/**
	 * The account's double opt-in templates — the ones carrying Brevo's
	 * {{ doubleoptin }} confirmation link — as select options.
	 *
	 * @return array<int, array{value:string, label:string}>|null
	 */
	private function doi_templates(): ?array {
		$key = $this->credential( 'api_key' );
		if ( '' === $key ) {
			return null;
		}

		$cache_key = $this->cache_key( 'doi_templates', $key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		$options = array();
		for ( $page = 0; $page < self::TEMPLATES_MAX_PAGES; $page++ ) {
			$url = add_query_arg(
				array(
					'templateStatus' => 'true',
					'limit'          => self::TEMPLATES_PAGE_SIZE,
					'offset'         => $page * self::TEMPLATES_PAGE_SIZE,
				),
				self::BASE_URL . '/smtp/templates'
			);

			$response = $this->http->get( $url, $this->headers( $key ) );
			if ( ! $response->ok || ! is_array( $response->body ) ) {
				return $options ? $options : null;
			}

			$batch = (array) ( $response->body['templates'] ?? array() );
			foreach ( $batch as $template ) {
				$html = (string) ( $template['htmlContent'] ?? '' );
				if ( false === stripos( $html, 'doubleoptin' ) && false === stripos( (string) ( $template['tag'] ?? '' ), 'optin' ) ) {
					continue;
				}
				$id        = (int) ( $template['id'] ?? 0 );
				$options[] = array(
					'value' => (string) $id,
					/* translators: 1: template name, 2: template id. */
					'label' => sprintf( __( '%1$s (#%2$d)', 'wpintegrations-connector-for-brevo' ), sanitize_text_field( (string) ( $template['name'] ?? '' ) ), $id ),
				);
			}

			if ( count( $batch ) < self::TEMPLATES_PAGE_SIZE ) {
				break;
			}
		}

		set_transient( $cache_key, $options, 5 * MINUTE_IN_SECONDS );
		return $options;
	}

	/**
	 * Cache keys carry a hash of the API key, so switching accounts never
	 * serves the old account's lists or templates.
	 */
	private function cache_key( string $kind, string $api_key ): string {
		return 'wpi_brevo_api_' . $kind . '_' . substr( md5( $api_key ), 0, 12 );
	}

	/**
	 * @return array<string, string>
	 */
	private function headers( string $api_key ): array {
		return array(
			'api-key' => $api_key,
			'Accept'  => 'application/json',
		);
	}

	/**
	 * Brevo's own error message, which names the offending field.
	 *
	 * @param object $response Normalized response.
	 */
	private function error_message( object $response ): string {
		$detail = '';
		if ( is_array( $response->body ) ) {
			$detail = (string) ( $response->body['message'] ?? wp_json_encode( $response->body ) );
		} else {
			$detail = (string) $response->body;
		}
		/* translators: 1: HTTP status code, 2: error detail from Brevo. */
		return sprintf( __( 'Brevo error (HTTP %1$d): %2$s', 'wpintegrations-connector-for-brevo' ), $response->code, mb_substr( $detail, 0, 300 ) );
	}
}
