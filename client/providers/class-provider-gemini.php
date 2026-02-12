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
	 * Send a message to Gemini
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	public function send_message( $args ) {
		$api_key       = $args['api_key'];
		$model         = $args['model'] ?: 'gemini-2.5-flash';
		$message       = $args['message'];
		$conversation  = $args['conversation'] ?? array();
		$tools         = $args['tools'] ?? array();
		$tool_result   = $args['tool_result'] ?? null;
		$system_prompt = $args['system_prompt'] ?? '';
		$temperature   = $args['temperature'] ?? 0.7;
		$max_tokens    = $args['max_tokens'] ?? 4096;
		$top_p         = $args['top_p'] ?? 1.0;

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
			$contents[] = array(
				'role'  => 'user',
				'parts' => array(
					array(
						'functionResponse' => array(
							'name'     => $tool_result['name'],
							'response' => array(
								'result' => $tool_result['output'],
							),
						),
					),
				),
			);
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
			'systemInstruction' => array(
				'parts' => array(
					array( 'text' => $this->get_system_prompt( $system_prompt ) ),
				),
			),
			'generationConfig'  => array(
				'temperature'     => floatval( $temperature ),
				'topP'            => floatval( $top_p ),
				'maxOutputTokens' => intval( $max_tokens ),
			),
		);

		// Add tools if available
		if ( ! empty( $tools ) ) {
			$body['tools'] = array(
				array(
					'functionDeclarations' => $this->format_tools( $tools ),
				),
			);
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
		if ( isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ) {
			$u = $response['usageMetadata'];
			$prompt = isset( $u['promptTokenCount'] ) ? $u['promptTokenCount'] : 'n/a';
			$output = isset( $u['candidatesTokenCount'] ) ? $u['candidatesTokenCount'] : 'n/a';
			$total  = isset( $u['totalTokenCount'] ) ? $u['totalTokenCount'] : 'n/a';
			$cached = isset( $u['cachedContentTokenCount'] ) ? $u['cachedContentTokenCount'] : 0;
			stifli_flex_mcp_log( sprintf(
				'[Gemini] Usage input=%s output=%s total=%s cached=%s',
				$prompt, $output, $total, $cached
			) );
		}

		return $this->parse_response( $response, $contents );
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
			$params = $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() );
			
			// Ensure proper structure for Gemini
			if ( ! isset( $params['type'] ) ) {
				$params['type'] = 'object';
			}
			if ( ! isset( $params['properties'] ) ) {
				$params['properties'] = new stdClass();
			}

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

		// Add assistant message to conversation
		$result['conversation'][] = array(
			'role'  => 'model',
			'parts' => $content['parts'] ?? array(),
		);

		// Parse parts
		if ( isset( $content['parts'] ) && is_array( $content['parts'] ) ) {
			stifli_flex_mcp_log( '[Gemini] Parsing ' . count( $content['parts'] ) . ' parts' );

			foreach ( $content['parts'] as $part ) {
				stifli_flex_mcp_log( '[Gemini] Part keys: ' . implode( ', ', array_keys( $part ) ) );

				if ( isset( $part['text'] ) ) {
					$result['text'] .= $part['text'];
				} elseif ( isset( $part['functionCall'] ) ) {
					// Tool call detected
					$fc = $part['functionCall'];
					stifli_flex_mcp_log( '[Gemini] Function call detected: ' . wp_json_encode( $fc ) );
					$result['tool_calls'][] = array(
						'id'        => uniqid( 'fc_' ), // Gemini doesn't provide IDs
						'name'      => $fc['name'] ?? '',
						'arguments' => $fc['args'] ?? array(),
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
