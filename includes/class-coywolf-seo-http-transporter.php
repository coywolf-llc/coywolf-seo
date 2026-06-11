<?php
/**
 * WordPress HTTP transporter for the PHP AI Client SDK.
 *
 * The SDK's default transporter discovers a PSR-18 HTTP client, but none is
 * vendored (and none is guaranteed on a WordPress host). This adapter
 * implements the SDK's transporter contract on top of the WordPress HTTP
 * API instead, so requests honor the host's proxy/SSL configuration and the
 * standard WordPress HTTP filters — and so tests can mock the entire AI
 * pipeline through pre_http_request.
 *
 * IMPORTANT: this file references SDK interfaces, so it is loaded lazily by
 * Coywolf_SEO_AI after the Composer autoloader — never from the main plugin
 * file.
 *
 * @package CoywolfSEO
 */

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SDK transporter backed by wp_remote_request().
 */
final class Coywolf_SEO_Http_Transporter implements HttpTransporterInterface {

	/**
	 * Send an SDK request through the WordPress HTTP API.
	 *
	 * @param Request             $request The SDK request.
	 * @param RequestOptions|null $options Optional transport options.
	 * @return Response The SDK response.
	 * @throws NetworkException When the request cannot be completed.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = implode( ', ', (array) $values );
		}

		$timeout = null;
		if ( $options && null !== $options->getTimeout() ) {
			$timeout = (float) $options->getTimeout();
		} elseif ( $request->getOptions() && null !== $request->getOptions()->getTimeout() ) {
			$timeout = (float) $request->getOptions()->getTimeout();
		}

		$args = array(
			'method'  => (string) $request->getMethod(),
			'headers' => $headers,
			// Generation calls on large models are slow; never go below the
			// SDK's own ask, and give a generous floor.
			'timeout' => max( 90, (float) $timeout ),
		);
		$body = $request->getBody();
		if ( null !== $body && '' !== $body ) {
			$args['body'] = $body;
		}

		$result = wp_remote_request( $request->getUri(), $args );

		if ( is_wp_error( $result ) ) {
			throw new NetworkException(
				sprintf(
					'WordPress HTTP request to %s failed: %s',
					esc_html( $request->getUri() ),
					esc_html( $result->get_error_message() )
				)
			);
		}

		$response_headers = wp_remote_retrieve_headers( $result );
		if ( is_object( $response_headers ) && method_exists( $response_headers, 'getAll' ) ) {
			$response_headers = $response_headers->getAll();
		}

		return new Response(
			(int) wp_remote_retrieve_response_code( $result ),
			is_array( $response_headers ) ? $response_headers : array(),
			(string) wp_remote_retrieve_body( $result )
		);
	}
}
