<?php

namespace MediaWiki\Extension\ApiGateway\Rest;

use Config;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\StringStream;
use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\IPUtils;
use Wikimedia\Message\MessageValue;

/**
 * REST handler that transparently proxies requests to configured external API endpoints.
 *
 * Incoming HTTP method is forwarded as-is to the external API. Auth tokens
 * defined in $wgApiGatewayEndpoints are injected server-side so they are
 * never exposed to the client.
 *
 * Route: /apigateway/v1/{endpoint}
 *
 * Query parameters:
 *   path    - sub-path appended to the endpoint base URL
 *   query   - additional query params (JSON object or encoded string)
 *   headers - extra request headers (JSON object)
 *   token   - CSRF token (required for POST/PUT/PATCH/DELETE)
 */
class GatewayHandler extends Handler {

	/** @var string[] HTTP methods that require a CSRF token */
	private const WRITE_METHODS = [ 'POST', 'PUT', 'PATCH', 'DELETE' ];

	/** @var string[] All supported HTTP methods */
	private const ALLOWED_METHODS = [ 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ];

	/** @var string[] Private/reserved IP CIDR ranges blocked for SSRF protection */
	private const BLOCKED_IP_RANGES = [
		'127.0.0.0/8',
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'169.254.0.0/16',
		'0.0.0.0/8',
		'::1/128',
		'fc00::/7',
		'fe80::/10',
	];

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/** @var Config */
	private $config;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		Config $config
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->config = $config;
	}

	/**
	 * @return Response
	 * @throws LocalizedHttpException
	 */
	public function execute() {
		// 1. Check base permission
		if ( !$this->getAuthority()->isAllowed( 'apigateway' ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-permissiondenied' ),
				403
			);
		}

		// 2. Resolve endpoint
		$endpointKey = $this->getRequest()->getPathParam( 'endpoint' );
		$endpoints = $this->config->get( 'ApiGatewayEndpoints' );

		if ( !isset( $endpoints[$endpointKey] ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-unknownendpoint', [ $endpointKey ] ),
				404
			);
		}
		$endpointConfig = $endpoints[$endpointKey];

		if ( !isset( $endpointConfig['url'] ) || $endpointConfig['url'] === '' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-unknownendpoint', [ $endpointKey ] ),
				404
			);
		}

		// 3. Check per-endpoint permission
		if ( isset( $endpointConfig['permission'] ) ) {
			if ( !$this->getAuthority()->isAllowed( $endpointConfig['permission'] ) ) {
				throw new LocalizedHttpException(
					new MessageValue( 'apigateway-error-permissiondenied' ),
					403
				);
			}
		}

		// 4. Determine outbound HTTP method (mirrors the incoming request method)
		$method = strtoupper( $this->getRequest()->getMethod() );

		// 5. Validate method against endpoint's allowed methods
		$allowedMethods = $endpointConfig['allowedMethods'] ?? self::ALLOWED_METHODS;
		$allowedMethods = array_map( 'strtoupper', $allowedMethods );
		if ( !in_array( $method, $allowedMethods ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-methodnotallowed', [ $method, $endpointKey ] ),
				405
			);
		}

		// 6. For write methods, validate CSRF token
		if ( in_array( $method, self::WRITE_METHODS ) ) {
			$queryParams = $this->getRequest()->getQueryParams();
			$token = $queryParams['token'] ?? '';
			$expectedToken = $this->getSession()->getToken();
			if ( $token === '' || !$expectedToken->match( $token ) ) {
				throw new LocalizedHttpException(
					new MessageValue( 'apigateway-error-badtoken' ),
					403
				);
			}
		}

		// 7. Construct target URL
		$queryParams = $this->getRequest()->getQueryParams();
		$path = $queryParams['path'] ?? '';
		$query = $queryParams['query'] ?? '';

		$baseUrl = rtrim( $endpointConfig['url'], '/' );
		$targetUrl = $baseUrl;

		if ( $path !== '' ) {
			$path = $this->sanitizePath( $path );
			$targetUrl .= '/' . ltrim( $path, '/' );
		}

		if ( $query !== '' ) {
			$decoded = json_decode( $query, true );
			if ( is_array( $decoded ) ) {
				$queryString = http_build_query( $decoded );
			} else {
				$queryString = $query;
			}
			$separator = ( strpos( $targetUrl, '?' ) === false ) ? '?' : '&';
			$targetUrl .= $separator . $queryString;
		}

		// 8. SSRF protection
		$this->validateTargetUrl( $targetUrl );

		// 9. Build outbound request headers
		$requestHeaders = [];

		// Inject token from config
		$tokenHeader = $endpointConfig['tokenHeader'] ?? 'Authorization';
		if ( isset( $endpointConfig['token'] ) && $endpointConfig['token'] !== '' ) {
			$requestHeaders[$tokenHeader] = $endpointConfig['token'];
		}

		// Merge caller-supplied headers
		$extraHeaders = $queryParams['headers'] ?? '';
		if ( $extraHeaders !== '' ) {
			$parsedHeaders = json_decode( $extraHeaders, true );
			if ( is_array( $parsedHeaders ) ) {
				$blockedHeaders = [ strtolower( $tokenHeader ), 'host' ];
				foreach ( $parsedHeaders as $name => $value ) {
					if ( in_array( strtolower( $name ), $blockedHeaders ) ) {
						continue;
					}
					$requestHeaders[$name] = $value;
				}
			}
		}

		// Forward Content-Type from incoming request for write methods
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ] ) ) {
			$incomingContentType = $this->getRequest()->getHeaderLine( 'Content-Type' );
			if ( $incomingContentType !== '' && !isset( $requestHeaders['Content-Type'] ) ) {
				$requestHeaders['Content-Type'] = $incomingContentType;
			}
		}

		// 10. Build outbound request body
		$requestBody = null;
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ] ) ) {
			$body = $this->getRequest()->getBody()->getContents();
			if ( $body !== '' ) {
				$maxBodySize = $this->config->get( 'ApiGatewayMaxBodySize' );
				if ( strlen( $body ) > $maxBodySize ) {
					throw new LocalizedHttpException(
						new MessageValue( 'apigateway-error-bodytoolarge', [ $maxBodySize ] ),
						413
					);
				}
				$requestBody = $body;
			}
		}

		// 11. Execute the outbound HTTP request
		$timeout = $endpointConfig['timeout']
			?? $this->config->get( 'ApiGatewayDefaultTimeout' );

		$client = $this->httpRequestFactory->createGuzzleClient( [
			'timeout' => $timeout,
			'http_errors' => false,
			'allow_redirects' => false,
		] );

		$guzzleOptions = [
			'headers' => $requestHeaders,
		];
		if ( $requestBody !== null ) {
			$guzzleOptions['body'] = $requestBody;
		}

		try {
			$upstreamResponse = $client->request( $method, $targetUrl, $guzzleOptions );
		} catch ( \GuzzleHttp\Exception\GuzzleException $e ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-requestfailed', [ $e->getMessage() ] ),
				502
			);
		}

		// 12. Build transparent response
		$response = $this->getResponseFactory()->create();
		$response->setStatus( $upstreamResponse->getStatusCode() );

		// Forward whitelisted response headers
		$allowedResponseHeaders = $this->config->get( 'ApiGatewayAllowedResponseHeaders' );
		foreach ( $allowedResponseHeaders as $headerName ) {
			$values = $upstreamResponse->getHeader( $headerName );
			if ( !empty( $values ) ) {
				$response->setHeader( $headerName, implode( ', ', $values ) );
			}
		}

		// Set the response body as raw passthrough
		$responseBody = $upstreamResponse->getBody()->getContents();
		$response->setBody( new StringStream( $responseBody ) );

		return $response;
	}

	/**
	 * Sanitize a URL sub-path to prevent path traversal attacks.
	 *
	 * @param string $path
	 * @return string
	 */
	private function sanitizePath( string $path ): string {
		$path = str_replace( "\0", '', $path );
		$path = preg_replace( '#/+#', '/', $path );

		$parts = explode( '/', $path );
		$sanitized = [];
		foreach ( $parts as $part ) {
			if ( $part === '..' || $part === '.' ) {
				continue;
			}
			if ( $part !== '' ) {
				$sanitized[] = $part;
			}
		}

		return implode( '/', $sanitized );
	}

	/**
	 * Validate that a URL does not target internal/private networks (SSRF protection).
	 *
	 * @param string $url
	 * @throws LocalizedHttpException
	 */
	private function validateTargetUrl( string $url ): void {
		$parsed = parse_url( $url );

		if ( !$parsed || !isset( $parsed['scheme'] ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-invalidurl' ),
				400
			);
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( $scheme !== 'http' && $scheme !== 'https' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-invalidscheme', [ $scheme ] ),
				400
			);
		}

		$host = $parsed['host'] ?? '';
		if ( $host === '' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-invalidurl' ),
				400
			);
		}

		$lowerHost = strtolower( $host );
		if ( $lowerHost === 'localhost' || $lowerHost === 'localhost.localdomain' ) {
			throw new LocalizedHttpException(
				new MessageValue( 'apigateway-error-ssrf' ),
				403
			);
		}

		$ips = gethostbynamel( $host );
		if ( $ips === false ) {
			$ips = [ $host ];
		}

		foreach ( $ips as $ip ) {
			if ( IPUtils::isInRanges( $ip, self::BLOCKED_IP_RANGES ) ) {
				throw new LocalizedHttpException(
					new MessageValue( 'apigateway-error-ssrf' ),
					403
				);
			}
		}
	}

	/**
	 * This handler does not write to the wiki database.
	 *
	 * @return bool
	 */
	public function needsWriteAccess(): bool {
		return false;
	}
}
