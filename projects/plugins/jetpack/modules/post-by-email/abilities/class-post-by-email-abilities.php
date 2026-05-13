<?php
/**
 * Jetpack Post by Email Abilities Registration
 *
 * Registers Jetpack Post by Email abilities with the WordPress Abilities API.
 *
 * @package automattic/jetpack
 */

// @phan-file-suppress PhanUndeclaredFunction, PhanUndeclaredClassMethod @phan-suppress-current-line UnusedSuppression -- Abilities API added in WP 6.9; suppressions needed for older-WP compatibility runs.

namespace Automattic\Jetpack\Plugin\Abilities;

use Automattic\Jetpack\Connection\Manager as Connection_Manager;
use Automattic\Jetpack\WP_Abilities\Registrar;
use Jetpack;
use Jetpack_IXR_Client;

/**
 * Registers Jetpack Post by Email abilities with the WordPress Abilities API.
 *
 * Exposes a per-user read of the current user's Post by Email state and a
 * declarative rotate action that mints a fresh address (invalidating the old
 * one) so AI agents can inspect and rotate Post by Email through the standard
 * `wp-abilities/v1` REST surface.
 */
class Post_By_Email_Abilities extends Registrar {

	private const MODULE_SLUG = 'post-by-email';

	/**
	 * {@inheritDoc}
	 */
	public static function get_category_slug(): string {
		return 'jetpack-post-by-email';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_category_definition(): array {
		return array(
			// translators: "Jetpack" is a product name and should not be translated.
			'label'       => __( 'Jetpack Post by Email', 'jetpack' ),
			'description' => __( 'Abilities for inspecting and rotating the current user\'s Post by Email address.', 'jetpack' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_abilities(): array {
		return array(
			'jetpack-post-by-email/get-status'         => array(
				'label'               => __( 'Get Jetpack Post by Email status', 'jetpack' ),
				'description'         => __( 'Return the current user\'s Post by Email state as { active, address, address_active, last_used_at }. active is whether the Post by Email feature is enabled for the user (an address has been minted). address is the per-user Post by Email address as a string, or null when the user has not enabled Post by Email. address_active mirrors active and is true when a non-empty address is present. last_used_at is always null in this release — the remote service does not currently expose last-used metadata; the field is reserved so the shape can grow without breaking callers. Fails with jetpack_post_by_email_not_connected when the current user is not connected to Jetpack (connect first via the My Jetpack admin page), or jetpack_post_by_email_service_unreachable when the remote service cannot be reached. These abilities are only registered while the Post by Email module is active; if they are absent from wp_get_abilities(), activate the Post by Email module first. Related: jetpack-post-by-email/regenerate-address rotates the user\'s address.', 'jetpack' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active'         => array( 'type' => 'boolean' ),
						'address'        => array( 'type' => array( 'string', 'null' ) ),
						'address_active' => array( 'type' => 'boolean' ),
						'last_used_at'   => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'can_view_post_by_email' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			),

			'jetpack-post-by-email/regenerate-address' => array(
				'label'               => __( 'Regenerate Jetpack Post by Email address', 'jetpack' ),
				'description'         => __( 'Rotate the current user\'s Post by Email address. Mints a fresh address on the remote service and invalidates the previous one — destructive: any saved drafts pointing at the old address will no longer reach this site. Not idempotent — each call produces a new address even when the previous call succeeded. Returns { address, regenerated_at } where address is the new email string and regenerated_at is a Unix timestamp (seconds) recorded at call time. Preconditions: the Post by Email module must be active and the current user must be connected to Jetpack; call jetpack-post-by-email/get-status first to verify the connection. Fails with jetpack_post_by_email_module_inactive (defensive — the abilities are only registered while the module is active), jetpack_post_by_email_not_connected, or jetpack_post_by_email_service_unreachable when the remote regenerate call fails.', 'jetpack' ),
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'address'        => array( 'type' => 'string' ),
						'regenerated_at' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'regenerate_address' ),
				'permission_callback' => array( __CLASS__, 'can_manage_post_by_email' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			),
		);
	}

	/**
	 * Permission check: can the current user read their own Post by Email state?
	 *
	 * Post by Email is a per-user feature: each authenticated user manages their
	 * own address. `is_user_logged_in()` is the right gate — no admin capability
	 * is required for a user to inspect their own address.
	 */
	public static function can_view_post_by_email(): bool {
		return is_user_logged_in();
	}

	/**
	 * Permission check: can the current user rotate their own Post by Email address?
	 *
	 * Same gate as the read: this rotates the caller's own address only, so
	 * `is_user_logged_in()` is the right level. The remote service uses the
	 * caller's Jetpack user token; users cannot rotate anyone else's address.
	 */
	public static function can_manage_post_by_email(): bool {
		return is_user_logged_in();
	}

	/**
	 * Execute: per-user read. Returns the documented four-key shape on the happy
	 * path. Surfaces precondition and transport failures as `WP_Error` so callers
	 * (especially AI agents) get an actionable next step instead of opaque null
	 * fields:
	 *
	 * - `jetpack_post_by_email_module_inactive` — defensive guard; in practice
	 *   unreachable because the abilities are only registered while the module
	 *   is active.
	 * - `jetpack_post_by_email_not_connected` — the current user is not
	 *   connected to Jetpack; the remote read needs the user's token.
	 * - `jetpack_post_by_email_service_unreachable` — the remote service
	 *   returned an error for the `jetpack.getPostByEmailAddress` XML-RPC read.
	 *
	 * A null `address` on the happy path means the user has not yet enabled
	 * Post by Email; that is the documented "feature off for this user" signal,
	 * not a failure.
	 *
	 * @param array|null $input Ability input (no parameters accepted).
	 * @return array|\WP_Error
	 */
	public static function get_status( $input = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Abilities API contract requires execute callbacks to accept the input array even when the schema declares no parameters.
		if ( ! Jetpack::is_module_active( self::MODULE_SLUG ) ) {
			return new \WP_Error(
				'jetpack_post_by_email_module_inactive',
				__( 'The Post by Email module is not active. Activate it before reading Post by Email status.', 'jetpack' )
			);
		}

		if ( ! static::is_user_connected_to_jetpack() ) {
			return new \WP_Error(
				'jetpack_post_by_email_not_connected',
				__( 'User is not connected to Jetpack. Connect first via the My Jetpack admin page, then retry this ability.', 'jetpack' )
			);
		}

		$address = static::fetch_address();
		if ( is_wp_error( $address ) ) {
			return new \WP_Error(
				'jetpack_post_by_email_service_unreachable',
				__( 'The remote Jetpack Post by Email service is unreachable. Retry shortly; this is typically transient.', 'jetpack' ),
				array( 'underlying' => $address->get_error_code() )
			);
		}

		$has_address = is_string( $address ) && '' !== $address;

		return array(
			'active'         => $has_address,
			'address'        => $has_address ? $address : null,
			'address_active' => $has_address,
			// The remote service does not currently surface last-used metadata.
			// The field is reserved so the response shape can grow without
			// breaking callers; callers should treat null as "unknown", not
			// "never used".
			'last_used_at'   => null,
		);
	}

	/**
	 * Execute: rotate the user's address. Always mints a new address on success
	 * — destructive (invalidates the old one) and not idempotent (each call
	 * produces a new value).
	 *
	 * @param array|null $input Input matching the ability's input_schema (no params).
	 * @return array|\WP_Error
	 */
	public static function regenerate_address( $input = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Abilities API contract requires execute callbacks to accept the input array even when the schema declares no parameters.
		if ( ! Jetpack::is_module_active( self::MODULE_SLUG ) ) {
			return new \WP_Error(
				'jetpack_post_by_email_module_inactive',
				__( 'The Post by Email module is not active. Activate it before rotating the Post by Email address.', 'jetpack' )
			);
		}

		if ( ! static::is_user_connected_to_jetpack() ) {
			return new \WP_Error(
				'jetpack_post_by_email_not_connected',
				__( 'The current user is not connected to Jetpack. Connect the user to Jetpack before rotating the Post by Email address.', 'jetpack' )
			);
		}

		$new_address = static::apply_regenerate();
		if ( is_wp_error( $new_address ) ) {
			return new \WP_Error(
				'jetpack_post_by_email_service_unreachable',
				__( 'The remote Jetpack Post by Email service is unreachable. Retry shortly; this is typically transient.', 'jetpack' ),
				array( 'underlying' => $new_address->get_error_code() )
			);
		}

		return array(
			'address'        => $new_address,
			'regenerated_at' => time(),
		);
	}

	/**
	 * Whether the current user is connected to Jetpack.
	 *
	 * Extracted as a protected seam so tests can override the connection check
	 * without standing up a full Jetpack token fixture.
	 */
	protected static function is_user_connected_to_jetpack(): bool {
		return ( new Connection_Manager( 'jetpack' ) )->is_user_connected();
	}

	/**
	 * Fetch the current user's Post by Email address from the remote service.
	 *
	 * @return string|null|\WP_Error Address string when set, null when the user
	 *                               has not enabled Post by Email, or WP_Error
	 *                               on remote failure.
	 */
	protected static function fetch_address() {
		$xml = new Jetpack_IXR_Client( array( 'user_id' => get_current_user_id() ) );
		$xml->query( 'jetpack.getPostByEmailAddress' );
		if ( $xml->isError() ) {
			return new \WP_Error(
				'jetpack_post_by_email_data_unavailable',
				sprintf( '%s: %s', $xml->getErrorCode(), $xml->getErrorMessage() )
			);
		}

		$response = $xml->getResponse();
		if ( empty( $response ) || ! is_string( $response ) ) {
			return null;
		}

		return $response;
	}

	/**
	 * Send the IXR `jetpack.regeneratePostByEmailAddress` request to mint a new
	 * address on the remote service.
	 *
	 * @return string|\WP_Error New address on success, WP_Error on remote failure.
	 */
	protected static function apply_regenerate() {
		$xml = new Jetpack_IXR_Client( array( 'user_id' => get_current_user_id() ) );
		$xml->query( 'jetpack.regeneratePostByEmailAddress' );
		if ( $xml->isError() ) {
			return new \WP_Error(
				'jetpack_post_by_email_regenerate_failed',
				sprintf( '%s: %s', $xml->getErrorCode(), $xml->getErrorMessage() )
			);
		}

		$response = $xml->getResponse();
		if ( empty( $response ) || ! is_string( $response ) ) {
			return new \WP_Error(
				'jetpack_post_by_email_regenerate_failed',
				__( 'Empty response from remote Post by Email service.', 'jetpack' )
			);
		}

		// Mirror the write to the legacy `post_by_email_address{user_id}` option
		// so `Jetpack_Core_Json_Api_Endpoints::get_remote_value` (the other
		// reader of this option) stays in sync with the remote state.
		update_option( 'post_by_email_address' . get_current_user_id(), $response );

		return $response;
	}
}
