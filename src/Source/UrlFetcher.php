<?php

namespace Importright\Source;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a source file to disk. Every hop is checked: the host must
 * resolve only to public addresses, redirects are followed by hand so each
 * new location is checked too, and the cURL connection is pinned to the
 * checked address with CURLOPT_RESOLVE so DNS can't change between the check
 * and the request. Without cURL pinning, URL sources are refused, and a hop
 * whose pin didn't take effect is discarded.
 */
final class UrlFetcher {

	public const DEFAULT_MAX_BYTES = 268435456;

	private const MAX_REDIRECTS = 5;

	/**
	 * @param array<string, mixed> $source    url, username, password.
	 * @param int|null             $max_bytes Size cap; defaults to the importright_max_download_bytes filter.
	 * @return array{url: string, name: string, bytes: int, type: string}|WP_Error
	 */
	public static function download( array $source, string $target, ?int $max_bytes = null ) {
		if ( ! self::can_pin() ) {
			return new WP_Error( 'importright_no_pinning', __( 'Downloading from a URL needs the PHP cURL extension (with CURLOPT_RESOLVE), so each connection can be held to the address that was checked. Ask your host to enable cURL, or upload the file instead.', 'total-import' ) );
		}

		$url      = self::normalize( (string) ( $source['url'] ?? '' ) );
		$max      = $max_bytes ?? (int) apply_filters( 'importright_max_download_bytes', self::DEFAULT_MAX_BYTES );
		$origin   = self::endpoint( $url );
		$username = (string) ( $source['username'] ?? '' );

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$checked = self::check( $url );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$headers = array();
			// Credentials go back only to the same scheme, host and port: never to another port, and never downgraded from https to http.
			if ( '' !== $username && self::endpoint( $url ) === $origin ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . (string) ( $source['password'] ?? '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
			}

			$pinned = false;
			$pin    = static function ( $handle ) use ( $checked, &$pinned ): void {
				$pinned = curl_setopt( $handle, CURLOPT_RESOLVE, array( $checked['host'] . ':' . $checked['port'] . ':' . $checked['ip'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the checked address on WordPress's own handle.
			};
			$allow  = static function (): bool {
				return true;
			};

			add_action( 'http_api_curl', $pin );
			if ( $checked['private_allowed'] ) {
				add_filter( 'http_request_host_is_external', $allow );
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => (int) apply_filters( 'importright_download_timeout', 120 ),
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $target,
					'limit_response_size' => $max > 0 ? $max + 1 : null,
					'headers'             => $headers,
					'user-agent'          => 'Importright/' . IMPORTRIGHT_VERSION,
				)
			);
			remove_action( 'http_api_curl', $pin );
			remove_filter( 'http_request_host_is_external', $allow );

			if ( ! $pinned ) {
				if ( is_file( $target ) ) {
					wp_delete_file( $target );
				}
				return new WP_Error( 'importright_no_pinning', __( 'The download was stopped because the connection could not be held to the checked address. Another plugin may have replaced the cURL transport.', 'total-import' ) );
			}

			if ( is_wp_error( $response ) ) {
				/* translators: %s: error message from the HTTP request */
				return new WP_Error( 'importright_download', sprintf( __( 'The download failed: %s', 'total-import' ), $response->get_error_message() ) );
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $location ) {
				$url = \WP_Http::make_absolute_url( $location, $url );
				continue;
			}
			if ( $code < 200 || $code >= 300 ) {
				/* translators: %d: HTTP status code */
				return new WP_Error( 'importright_download', sprintf( __( 'The server answered with HTTP status %d.', 'total-import' ), $code ) );
			}

			clearstatcache( true, $target );
			$bytes = is_file( $target ) ? (int) filesize( $target ) : 0;
			if ( $max > 0 && $bytes > $max ) {
				/* translators: %s: size limit, e.g. "20 MB" */
				return new WP_Error( 'importright_download', sprintf( __( 'The file is larger than the %s download limit.', 'total-import' ), size_format( $max ) ) );
			}

			return array(
				'url'   => $url,
				'name'  => self::file_name( (string) wp_remote_retrieve_header( $response, 'content-disposition' ), $url ),
				'bytes' => $bytes,
				'type'  => strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) ),
			);
		}

		return new WP_Error( 'importright_download', __( 'The URL redirected too many times.', 'total-import' ) );
	}

	/**
	 * Whether connections can be pinned to a checked address. The filter can only switch pinning off.
	 */
	public static function can_pin(): bool {
		$available = function_exists( 'curl_init' ) && function_exists( 'curl_setopt' ) && defined( 'CURLOPT_RESOLVE' );
		return $available && (bool) apply_filters( 'importright_can_pin_connections', true );
	}

	/**
	 * Turns a Google Sheets edit or share link into its CSV export link. Published (pub?output=csv) links pass through.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '#^https://docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)/(?:edit|view)?(?:[?\#](.*))?$#', $url, $m ) && 'e' !== $m[1] ) {
			$gid = preg_match( '/(?:^|[&#?])gid=(\d+)/', $m[2] ?? '', $g ) ? $g[1] : '0';
			return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
		}
		return $url;
	}

	/**
	 * @return array{host: string, port: int, ip: string, private_allowed: bool}|WP_Error
	 */
	public static function check( string $url ) {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) ) {
			return new WP_Error( 'importright_url', __( 'Enter an http or https URL.', 'total-import' ) );
		}
		$port = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );

		$allow_private = (bool) apply_filters( 'importright_allow_private_urls', false, $url );
		$ips           = self::resolve( trim( $host, '[]' ) );
		if ( array() === $ips ) {
			/* translators: %s: host name */
			return new WP_Error( 'importright_url', sprintf( __( 'The host %s could not be found.', 'total-import' ), $host ) );
		}
		if ( ! $allow_private ) {
			foreach ( $ips as $ip ) {
				if ( ! IpGuard::is_public( $ip ) ) {
					return new WP_Error( 'importright_url', __( 'That URL points to a private or reserved network address, which imports can\'t download from.', 'total-import' ) );
				}
			}
		}

		return array(
			'host'            => trim( $host, '[]' ),
			'port'            => $port,
			'ip'              => false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0],
			'private_allowed' => $allow_private,
		);
	}

	/**
	 * Scheme, host and port of a URL, the port filled in from the scheme.
	 */
	private static function endpoint( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$port   = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		return $scheme . '://' . strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) ) . ':' . $port;
	}

	/**
	 * @return string[]
	 */
	private static function resolve( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup just means no IPv6 addresses.
			foreach ( is_array( $v6 ) ? $v6 : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	private static function file_name( string $disposition, string $url ): string {
		if ( preg_match( '/filename\*=(?:UTF-8\'\')?([^;]+)/i', $disposition, $m ) || preg_match( '/filename="?([^";]+)"?/i', $disposition, $m ) ) {
			return sanitize_file_name( rawurldecode( trim( $m[1], " \"'" ) ) );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( rawurldecode( basename( $path ) ) );
		if ( false !== strpos( $url, 'output=csv' ) || false !== strpos( $url, 'format=csv' ) ) {
			return $name . '.csv';
		}
		return $name;
	}
}
