<?php
/**
 * Gemini (Google) Provider Handler
 *
 * @package StifliFlexMcp
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-provider-base.php';

/**
 * Gemini provider handler using the Generate Content API
 */
class StifliFlexMcp_Client_Gemini extends StifliFlexMcp_Client_Provider_Base {

	/**
	 * API endpoint template
	 */
	const API_URL_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Cached content API URL
	 */
	const CACHE_API_URL = 'https://generativelanguage.googleapis.com/v1beta/cachedContents';

	/**
	 * Transient key prefix for cached content name
	 */
	const CACHE_TRANSIENT_PREFIX = 'sflmcp_gemini_cache_';

	/**
	 * Send a message to Gemini
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	public function send_message( $args ) {
		$api_key           = $args['api_key'];
		$model             = $args['model'] ?: 'gemini-3-flash-preview';
		$message           = $args['message'];
		$conversation      = $args['conversation'] ?? array();
		$tools             = $args['tools'] ?? array();
		$tool_result       = $args['tool_result'] ?? null;
		$system_prompt     = $args['system_prompt'] ?? '';
		$temperature       = $args['temperature'] ?? 0.7;
		$max_tokens        = $args['max_tokens'] ?? 4096;
		$top_p             = $args['top_p'] ?? 1.0;
		$explicit_caching  = $args['explicit_caching'] ?? false;

		// Build contents array
		$contents = array();

		// Add conversation history - convert from OpenAI/Claude format to Gemini format
		foreach ( $conversation as $msg ) {
			// Skip empty messages
			if ( empty( $msg ) ) {
				continue;
			}

			// If already in Gemini format (has 'parts'), use as-is
			if ( isset( $msg['parts'] ) ) {
				// Sanitize functionCall.args after JS→PHP roundtrip:
				// json_decode($json, true) converts {} to [], which Gemini rejects.
				if ( is_array( $msg['parts'] ) ) {
					foreach ( $msg['parts'] as &$part ) {
						if ( isset( $part['functionCall']['args'] ) && is_array( $part['functionCall']['args'] ) && empty( $part['functionCall']['args'] ) ) {
							$part['functionCall']['args'] = new stdClass();
						}
					}
					unset( $part );
				}
				$contents[] = $msg;
				continue;
			}

			// Convert from OpenAI/Claude format { role, content } to Gemini format { role, parts }
			$role = $msg['role'] ?? 'user';
			// Gemini uses 'model' instead of 'assistant'
			if ( $role === 'assistant' ) {
				$role = 'model';
			}

			$content = $msg['content'] ?? '';
			if ( ! empty( $content ) ) {
				$contents[] = array(
					'role'  => $role,
					'parts' => array(
						array( 'text' => $content ),
					),
				);
			}
		}

		// If we have a tool result, add it as a function response
		if ( $tool_result ) {
			$parts = array();
			// Support both single result and array of results.
			if ( isset( $tool_result['name'] ) ) {
				$parts[] = array(
					'functionResponse' => array(
						'name'     => $tool_result['name'],
						'response' => array( 'result' => $tool_result['output'] ),
					),
				);
			} elseif ( is_array( $tool_result ) ) {
				foreach ( $tool_result as $tr ) {
					if ( isset( $tr['name'] ) ) {
						$parts[] = array(
							'functionResponse' => array(
								'name'     => $tr['name'],
								'response' => array( 'result' => $tr['output'] ),
							),
						);
					}
				}
			}
			if ( ! empty( $parts ) ) {
				$contents[] = array( 'role' => 'user', 'parts' => $parts );
			}
		} elseif ( ! empty( $message ) ) {
			// Add new user message
			$contents[] = array(
				'role'  => 'user',
				'parts' => array(
					array( 'text' => $message ),
				),
			);
		}

		// Build request body
		$body = array(
			'contents'          => $contents,
			'generationConfig'  => array(
				'temperature'     => floatval( $temperature ),
				'topP'            => floatval( $top_p ),
				'maxOutputTokens' => intval( $max_tokens ),
			),
		);

		// Explicit caching: cache system prompt + tools server-side
		$cache_name = null;
		if ( $explicit_caching ) {
			$cache_name = $this->get_or_create_cache( $api_key, $model, $system_prompt, $tools );
		}

		if ( $cache_name ) {
			// Reference cached content — system instruction and tools are already in the cache
			$body['cachedContent'] = $cache_name;
			stifli_flex_mcp_log( '[Gemini] Using explicit cache: ' . $cache_name );
		} else {
			// No cache — send system instruction and tools inline as usual
			$body['systemInstruction'] = array(
				'parts' => array(
					array( 'text' => $this->get_system_prompt( $system_prompt ) ),
				),
			);

			if ( ! empty( $tools ) ) {
				$body['tools'] = array(
					array(
						'functionDeclarations' => $this->format_tools( $tools ),
					),
				);
			}
		}

		// Build URL with API key
		$url = sprintf( self::API_URL_TEMPLATE, $model ) . '?key=' . $api_key;

		stifli_flex_mcp_log( sprintf(
			'[Gemini] Request summary model=%s contents=%d tools=%d max_tokens=%d',
			$model,
			count( $contents ),
			count( $tools ),
			intval( $max_tokens )
		) );

		// Make request
		$meta = $this->make_request_with_meta(
			$url,
			array(
				'Content-Type' => 'application/json',
			),
			$body
		);

		if ( is_wp_error( $meta ) ) {
			stifli_flex_mcp_log( '[Gemini] Request error: ' . $meta->get_error_message() );
			return $meta;
		}

		$response = $meta['body'] ?? array();
		$headers  = $meta['headers'] ?? array();

		// Log provider-reported usage if present.
		$usage_data = null;
		if ( isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ) {
			$u = $response['usageMetadata'];
			$prompt = isset( $u['promptTokenCount'] ) ? $u['promptTokenCount'] : 0;
			$output = isset( $u['candidatesTokenCount'] ) ? $u['candidatesTokenCount'] : 0;
			$total  = isset( $u['totalTokenCount'] ) ? $u['totalTokenCount'] : 0;
			$cached = isset( $u['cachedContentTokenCount'] ) ? $u['cachedContentTokenCount'] : 0;
			stifli_flex_mcp_log( sprintf(
				'[Gemini] Usage input=%s output=%s total=%s cached=%s',
				$prompt, $output, $total, $cached
			) );
			$usage_data = array(
				'input_tokens'  => $prompt,
				'output_tokens' => $output,
				'cached_tokens' => $cached,
			);
		}

		$parsed = $this->parse_response( $response, $contents );

		// Include usage data for token tracking — always provide even if API omits it
		if ( $usage_data ) {
			$parsed['usage'] = $usage_data;
		} else {
			// Estimate from content when API doesn't report usage
			$est_input  = 0;
			$est_output = 0;
			foreach ( $contents as $c ) {
				if ( isset( $c['parts'] ) ) {
					foreach ( $c['parts'] as $p ) {
						if ( isset( $p['text'] ) ) {
							$est_input += (int) ceil( strlen( $p['text'] ) / 4 );
						}
					}
				}
			}
			if ( ! empty( $parsed['text'] ) ) {
				$est_output = (int) ceil( strlen( $parsed['text'] ) / 4 );
			}
			$parsed['usage'] = array(
				'input_tokens'  => $est_input,
				'output_tokens' => $est_output,
				'cached_tokens' => 0,
			);
			stifli_flex_mcp_log( '[Gemini] Usage not reported by API, estimated input=' . $est_input . ' output=' . $est_output );
		}

		return $parsed;
	}

	/**
	 * Get or create a cached content resource for system prompt + tools.
	 *
	 * Uses a WordPress transient keyed by model + hash of payload to avoid
	 * recreating the cache on every request.  The cache lives for 30 minutes
	 * on Google's side and the transient for 25 minutes (safety margin).
	 *
	 * @param string $api_key       Gemini API key.
	 * @param string $model         Model name (e.g. gemini-3.1-pro-preview).
	 * @param string $system_prompt System prompt text.
	 * @param array  $tools         MCP tool definitions.
	 * @return string|null          cachedContents resource name, or null on failure.
	 */
	private function get_or_create_cache( $api_key, $model, $system_prompt, $tools ) {
		// Build a deterministic hash of the content that goes into the cache
		$payload_hash = md5( $model . '|' . $system_prompt . '|' . wp_json_encode( $tools ) );
		$transient_key = self::CACHE_TRANSIENT_PREFIX . $payload_hash;

		// Check if we already have a valid cache
		$cache_name = get_transient( $transient_key );
		if ( $cache_name ) {
			stifli_flex_mcp_log( '[Gemini] Cache hit (transient): ' . $cache_name );
			return $cache_name;
		}

		// Create a new cached content resource
		$cache_name = $this->create_cache( $api_key, $model, $system_prompt, $tools );
		if ( $cache_name ) {
			// Store for 25 min (cache TTL is 30 min, keeping a 5 min safety margin)
			set_transient( $transient_key, $cache_name, 25 * MINUTE_IN_SECONDS );
		}

		return $cache_name;
	}

	/**
	 * Create a cached content resource via the Gemini API.
	 *
	 * @param string $api_key       Gemini API key.
	 * @param string $model         Model name.
	 * @param string $system_prompt System prompt.
	 * @param array  $tools         MCP tool definitions.
	 * @return string|null          Resource name (cachedContents/{id}) or null on failure.
	 */
	private function create_cache( $api_key, $model, $system_prompt, $tools ) {
		$cache_body = array(
			'model'             => 'models/' . $model,
			'displayName'       => 'sflmcp-' . substr( md5( $model ), 0, 8 ),
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $this->get_system_prompt( $system_prompt ) ),
				),
			),
			'ttl'               => '1800s', // 30 minutes
		);

		// Include tools in the cache
		if ( ! empty( $tools ) ) {
			$cache_body['tools'] = array(
				array(
					'functionDeclarations' => $this->format_tools( $tools ),
				),
			);
		}

		$url = self::CACHE_API_URL . '?key=' . $api_key;

		stifli_flex_mcp_log( '[Gemini] Creating explicit cache for model=' . $model . ' tools=' . count( $tools ) );

		$response = wp_remote_post( $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $cache_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			stifli_flex_mcp_log( '[Gemini] Cache creation failed (WP error): ' . $response->get_error_message() );
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status !== 200 || empty( $body['name'] ) ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_json_encode( $body );
			stifli_flex_mcp_log( '[Gemini] Cache creation failed (HTTP ' . $status . '): ' . $error_msg );
			return null;
		}

		stifli_flex_mcp_log( '[Gemini] Cache created: ' . $body['name'] . ' tokens=' . ( $body['usageMetadata']['totalTokenCount'] ?? 'n/a' ) );
		return $body['name'];
	}

	/**
	 * Format MCP tools for Gemini
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array Gemini function declarations.
	 */
	protected function format_tools( $mcp_tools ) {
		$formatted = array();

		foreach ( $mcp_tools as $tool ) {
			$params = StifliFlexMcpUtils::normalizeToolInputSchema( $tool['inputSchema'] ?? array() );

			// Gemini doesn't support additionalProperties in all cases
			if ( isset( $params['additionalProperties'] ) ) {
				unset( $params['additionalProperties'] );
			}

			$formatted[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $params,
			);
		}

		return $formatted;
	}

	/**
	 * Parse Gemini response
	 *
	 * @param array $response API response.
	 * @param array $contents Contents sent.
	 * @return array Parsed response.
	 */
	private function parse_response( $response, $contents ) {
		$result = array(
			'text'         => '',
			'tool_calls'   => array(),
			'conversation' => $contents,
			'finished'     => true,
		);

		if ( ! isset( $response['candidates'][0]['content'] ) ) {
			// Check for error
			if ( isset( $response['error'] ) ) {
				$result['text'] = __( 'API Error: ', 'stifli-flex-mcp' ) . ( $response['error']['message'] ?? 'Unknown error' );
			}
			return $result;
		}

		$content = $response['candidates'][0]['content'];

		// Sanitize parts before storing in conversation history.
		// Gemini's proto requires functionCall.args to be a Struct (JSON object),
		// but the API may return an empty array [] which wp_json_encode serializes
		// as a JSON array — causing HTTP 400 on the next round-trip.
		$sanitized_parts = array();
		if ( isset( $content['parts'] ) && is_array( $content['parts'] ) ) {
			foreach ( $content['parts'] as $part ) {
				if ( isset( $part['functionCall']['args'] ) && is_array( $part['functionCall']['args'] ) && empty( $part['functionCall']['args'] ) ) {
					$part['functionCall']['args'] = new stdClass();
				}
				$sanitized_parts[] = $part;
			}
		}

		// Add assistant message to conversation
		$result['conversation'][] = array(
			'role'  => 'model',
			'parts' => $sanitized_parts,
		);

		// Parse parts
		if ( ! empty( $sanitized_parts ) ) {
			stifli_flex_mcp_log( '[Gemini] Parsing ' . count( $sanitized_parts ) . ' parts' );

			foreach ( $sanitized_parts as $part ) {
				stifli_flex_mcp_log( '[Gemini] Part keys: ' . implode( ', ', array_keys( $part ) ) );

				if ( isset( $part['text'] ) ) {
					$result['text'] .= $part['text'];
				} elseif ( isset( $part['functionCall'] ) ) {
					// Tool call detected
					$fc = $part['functionCall'];
					stifli_flex_mcp_log( '[Gemini] Function call detected: ' . wp_json_encode( $fc ) );
					$fc_args = $fc['args'] ?? array();
					// Ensure args is always an associative array (object), never []
					if ( is_array( $fc_args ) && empty( $fc_args ) ) {
						$fc_args = array();
					}
					$result['tool_calls'][] = array(
						'id'        => $fc['id'] ?? uniqid( 'fc_' ),
						'name'      => $fc['name'] ?? '',
						'arguments' => $fc_args,
					);
					$result['finished'] = false;
				}
			}
		}

		stifli_flex_mcp_log( '[Gemini] Final result - text length: ' . strlen( $result['text'] ) . ', tool_calls: ' . count( $result['tool_calls'] ) );

		return $result;
	}

	/**
	 * Execute a tool and get the result
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_tool_call( $tool_name, $arguments ) {
		return $this->execute_tool( $tool_name, $arguments );
	}
}
