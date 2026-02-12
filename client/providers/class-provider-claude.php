<?php
/**
 * Claude (Anthropic) Provider Handler
 *
 * @package StifliFlexMcp
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-provider-base.php';

/**
 * Claude provider handler using the Messages API
 */
class StifliFlexMcp_Client_Claude extends StifliFlexMcp_Client_Provider_Base {

	/**
	 * API endpoint
	 */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * API version
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * Claude 4.5 models that don't support both temperature and top_p
	 */
	const CLAUDE_45_MODELS = array(
		'claude-sonnet-4-5-20250929',
		'claude-haiku-4-5-20251001',
		'claude-opus-4-5-20251101',
		'claude-haiku-4-5',
		'claude-opus-4-5',
		'claude-sonnet-4-5',
	);

	/**
	 * Send a message to Claude
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	public function send_message( $args ) {
		$api_key       = $args['api_key'];
		$model         = $args['model'] ?: 'claude-sonnet-4-5-20250929';
		$message       = $args['message'];
		$conversation  = $args['conversation'] ?? array();
		$tools         = $args['tools'] ?? array();
		$tool_result   = $args['tool_result'] ?? null;
		$system_prompt = $args['system_prompt'] ?? '';
		$temperature   = $args['temperature'] ?? 0.7;
		$max_tokens    = $args['max_tokens'] ?? 4096;
		$top_p         = $args['top_p'] ?? 1.0;

		// Build messages array
		$messages = array();

		// Add conversation history - sanitize tool_use inputs to be objects
		foreach ( $conversation as $msg ) {
			$sanitized_msg = $this->sanitize_message( $msg );
			$messages[] = $sanitized_msg;
		}

		// If we have tool results, add them as a user message
		// Claude requires ALL tool_results to be sent together in one message
		if ( $tool_result ) {
			$tool_results_content = array();

			// Check if it's an array of results (multiple tools) or single result
			if ( isset( $tool_result['tool_use_id'] ) ) {
				// Single tool result
				$tool_results_content[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $tool_result['tool_use_id'],
					'content'     => wp_json_encode( $tool_result['output'] ),
				);
			} elseif ( is_array( $tool_result ) ) {
				// Multiple tool results
				foreach ( $tool_result as $result ) {
					if ( isset( $result['tool_use_id'] ) ) {
						$tool_results_content[] = array(
							'type'        => 'tool_result',
							'tool_use_id' => $result['tool_use_id'],
							'content'     => wp_json_encode( $result['output'] ),
						);
					}
				}
			}

			if ( ! empty( $tool_results_content ) ) {
				stifli_flex_mcp_log( '[Claude] Sending ' . count( $tool_results_content ) . ' tool_result(s)' );
				$messages[] = array(
					'role'    => 'user',
					'content' => $tool_results_content,
				);
			}
		} elseif ( ! empty( $message ) ) {
			// Add new user message
			$messages[] = array(
				'role'    => 'user',
				'content' => $message,
			);
		}

		// Build request body.
		// Use structured system prompt with cache_control for Anthropic prompt caching.
		$system_text = $this->get_system_prompt( $system_prompt );
		$body = array(
			'model'       => $model,
			'max_tokens'  => intval( $max_tokens ),
			'messages'    => $messages,
			'system'      => array(
				array(
					'type'          => 'text',
					'text'          => $system_text,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
		);

		// Claude 4.5 models don't support both temperature and top_p
		// Use only temperature for these models
		if ( $this->is_claude_45_model( $model ) ) {
			$body['temperature'] = floatval( $temperature );
			// Skip top_p for Claude 4.5
		} else {
			$body['temperature'] = floatval( $temperature );
			$body['top_p']       = floatval( $top_p );
		}

		// Add tools if available
		if ( ! empty( $tools ) ) {
			$body['tools'] = $this->format_tools( $tools );
			stifli_flex_mcp_log( '[Claude] Tools: ' . count( $tools ) );
		}

		// Final pass: ensure all tool_use inputs are objects for JSON serialization
		$body = $this->ensure_tool_inputs_are_objects( $body );

		// Log a compact summary (avoid logging full prompts/tools).
		$body_json  = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$body_bytes = is_string( $body_json ) ? strlen( $body_json ) : 0;
		$est_tokens = class_exists( 'StifliFlexMcpUtils' ) ? StifliFlexMcpUtils::estimateTokensFromString( (string) $body_json ) : (int) ceil( $body_bytes / 4 );
		stifli_flex_mcp_log( sprintf( '[Claude] Request summary model=%s messages=%d tools=%d body_bytes=%d est_tokens~%d max_tokens=%d', $model, count( $messages ), count( $tools ), $body_bytes, $est_tokens, intval( $max_tokens ) ) );

		// Make request
		$meta = $this->make_request_with_meta(
			self::API_URL,
			array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
			),
			$body
		);

		if ( is_wp_error( $meta ) ) {
			// If available, log rate limit headers to diagnose 429s.
			$data = $meta->get_error_data();
			if ( is_array( $data ) && ! empty( $data['headers'] ) && is_array( $data['headers'] ) ) {
				$h = $data['headers'];
				$keys = array(
					'retry-after',
					'anthropic-ratelimit-input-tokens-remaining',
					'anthropic-ratelimit-output-tokens-remaining',
					'anthropic-ratelimit-tokens-remaining',
				);
				$parts = array();
				foreach ( $keys as $k ) {
					if ( isset( $h[ $k ] ) ) {
						$parts[] = $k . '=' . $h[ $k ];
					}
				}
				if ( ! empty( $parts ) ) {
					stifli_flex_mcp_log( '[Claude] Error rate-limit headers: ' . implode( ' ', $parts ) );
				}
			}
			return $meta;
		}

		$response = $meta['body'] ?? array();
		$headers  = $meta['headers'] ?? array();

		// Log provider-reported usage if present.
		if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$u = $response['usage'];
			stifli_flex_mcp_log( sprintf(
				'[Claude] Usage input=%s output=%s cache_create=%s cache_read=%s',
				isset( $u['input_tokens'] ) ? $u['input_tokens'] : 'n/a',
				isset( $u['output_tokens'] ) ? $u['output_tokens'] : 'n/a',
				isset( $u['cache_creation_input_tokens'] ) ? $u['cache_creation_input_tokens'] : 'n/a',
				isset( $u['cache_read_input_tokens'] ) ? $u['cache_read_input_tokens'] : 'n/a'
			) );
		}

		// Log key rate limit headers if present.
		if ( is_array( $headers ) && ! empty( $headers ) ) {
			$keys = array(
				'anthropic-ratelimit-input-tokens-remaining',
				'anthropic-ratelimit-output-tokens-remaining',
				'anthropic-ratelimit-tokens-remaining',
				'anthropic-ratelimit-input-tokens-limit',
				'anthropic-ratelimit-output-tokens-limit',
			);
			$parts = array();
			foreach ( $keys as $k ) {
				if ( isset( $headers[ $k ] ) ) {
					$parts[] = $k . '=' . $headers[ $k ];
				}
			}
			if ( ! empty( $parts ) ) {
				stifli_flex_mcp_log( '[Claude] Rate headers: ' . implode( ' ', $parts ) );
			}
		}

		return $this->parse_response( $response, $messages, $headers );
	}

	/**
	 * Check if model is Claude 4.5 family
	 *
	 * @param string $model Model name.
	 * @return bool
	 */
	private function is_claude_45_model( $model ) {
		foreach ( self::CLAUDE_45_MODELS as $claude_45 ) {
			if ( strpos( $model, $claude_45 ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize a message to ensure tool_use inputs are always objects (dictionaries)
	 * Claude API requires tool_use.input to be a valid dictionary, not an array.
	 *
	 * @param array $msg Message to sanitize.
	 * @return array Sanitized message.
	 */
	private function sanitize_message( $msg ) {
		if ( ! isset( $msg['content'] ) || ! is_array( $msg['content'] ) ) {
			return $msg;
		}

		// Check each content block for tool_use
		foreach ( $msg['content'] as $index => $block ) {
			if ( isset( $block['type'] ) && $block['type'] === 'tool_use' ) {
				// Ensure input is an object, not an array
				$input = $block['input'] ?? array();
				
				// Use ArrayObject which always serializes as {} even when empty
				if ( is_array( $input ) ) {
					$msg['content'][ $index ]['input'] = new ArrayObject( $input );
				} elseif ( is_null( $input ) ) {
					$msg['content'][ $index ]['input'] = new ArrayObject();
				}
				// If already an object, leave it
				
				stifli_flex_mcp_log( '[Claude] sanitize_message tool_use input for ' . ( $block['name'] ?? 'unknown' ) . ': ' . wp_json_encode( $msg['content'][ $index ]['input'] ) );
			}
		}

		return $msg;
	}

	/**
	 * Recursively ensure all tool_use.input fields are objects (not arrays)
	 * This is the final safety net before JSON serialization.
	 *
	 * @param array $body Request body.
	 * @return array Modified body with objects instead of arrays for tool_use.input.
	 */
	private function ensure_tool_inputs_are_objects( $body ) {
		if ( ! isset( $body['messages'] ) || ! is_array( $body['messages'] ) ) {
			return $body;
		}

		foreach ( $body['messages'] as $msg_idx => $msg ) {
			if ( ! isset( $msg['content'] ) || ! is_array( $msg['content'] ) ) {
				continue;
			}

			foreach ( $msg['content'] as $block_idx => $block ) {
				if ( isset( $block['type'] ) && $block['type'] === 'tool_use' && array_key_exists( 'input', $block ) ) {
					$input = $block['input'];
					
					stifli_flex_mcp_log( '[Claude] ensure_tool_inputs - before: ' . wp_json_encode( $input ) . ' type: ' . gettype( $input ) );
					
					// Convert to ArrayObject which always serializes as {} even when empty
					if ( is_array( $input ) ) {
						$body['messages'][ $msg_idx ]['content'][ $block_idx ]['input'] = new ArrayObject( $input );
					} elseif ( is_null( $input ) ) {
						$body['messages'][ $msg_idx ]['content'][ $block_idx ]['input'] = new ArrayObject();
					}
					// If it's already an object (stdClass, ArrayObject), leave it
					
					stifli_flex_mcp_log( '[Claude] ensure_tool_inputs - after: ' . wp_json_encode( $body['messages'][ $msg_idx ]['content'][ $block_idx ]['input'] ) );
				}
			}
		}

		return $body;
	}

	/**
	 * Format MCP tools for Claude
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array Claude tool definitions.
	 */
	protected function format_tools( $mcp_tools ) {
		$formatted = array();

		foreach ( $mcp_tools as $tool ) {
			$schema = $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() );
			
			// Ensure proper structure
			if ( ! isset( $schema['type'] ) ) {
				$schema['type'] = 'object';
			}
			if ( ! isset( $schema['properties'] ) ) {
				$schema['properties'] = new stdClass();
			}

			$formatted[] = array(
				'name'         => $tool['name'],
				'description'  => $tool['description'],
				'input_schema' => $schema, // Claude uses input_schema instead of inputSchema
			);
		}

		// Add prompt-caching breakpoint on the last tool so the entire tools array
		// is cached across requests (tools are static and ~6-8k tokens).
		if ( ! empty( $formatted ) ) {
			$last = count( $formatted ) - 1;
			$formatted[ $last ]['cache_control'] = array( 'type' => 'ephemeral' );
		}

		return $formatted;
	}

	/**
	 * Parse Claude response
	 *
	 * @param array $response API response.
	 * @param array $messages Messages sent.
	 * @return array Parsed response.
	 */
	private function parse_response( $response, $messages, $headers = array() ) {
		$result = array(
			'text'         => '',
			'tool_calls'   => array(),
			'conversation' => $messages,
			'finished'     => true,
			'usage'        => isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : null,
			'rate_limit'   => $this->extract_rate_limit_headers( $headers ),
		);

		if ( ! isset( $response['content'] ) || ! is_array( $response['content'] ) ) {
			return $result;
		}

		// Add assistant message to conversation
		$assistant_message = array(
			'role'    => 'assistant',
			'content' => $response['content'],
		);
		$result['conversation'][] = $assistant_message;

		// Parse content blocks
		foreach ( $response['content'] as $block ) {
			stifli_flex_mcp_log( '[Claude] Processing content block type: ' . ( $block['type'] ?? 'unknown' ) );

			if ( $block['type'] === 'text' ) {
				$result['text'] .= $block['text'];
			} elseif ( $block['type'] === 'tool_use' ) {
				// Tool call detected
				// Ensure input is always an object (dictionary), never an array
				// Claude API requires input to be a valid dictionary
				$input = $block['input'] ?? array();
				
				stifli_flex_mcp_log( '[Claude] Raw input from API: ' . wp_json_encode( $input ) . ' type: ' . gettype( $input ) );
				
				// FORCE conversion to object for JSON serialization
				// We use ArrayObject which always serializes as {} even when empty
				if ( is_array( $input ) ) {
					if ( empty( $input ) ) {
						// Use ArrayObject for empty - it serializes as {} not []
						$input = new ArrayObject();
					} else {
						// Check if sequential array
						$keys = array_keys( $input );
						if ( $keys === range( 0, count( $input ) - 1 ) ) {
							// Sequential array, convert to object
							$input = new ArrayObject( $input );
						}
					}
				}
				
				stifli_flex_mcp_log( '[Claude] Converted input: ' . wp_json_encode( $input ) . ' type: ' . gettype( $input ) );

				$tool_call = array(
					'id'          => $block['id'] ?? '',
					'tool_use_id' => $block['id'] ?? '',
					'name'        => $block['name'] ?? '',
					'arguments'   => $input,
				);
				stifli_flex_mcp_log( '[Claude] Tool call detected: ' . wp_json_encode( $tool_call ) );
				$result['tool_calls'][] = $tool_call;
				$result['finished'] = false;
			}
		}

		// Check stop reason
		if ( isset( $response['stop_reason'] ) && $response['stop_reason'] === 'tool_use' ) {
			$result['finished'] = false;
		}

		stifli_flex_mcp_log( '[Claude] Parsed result - text length: ' . strlen( $result['text'] ) . ', tool_calls: ' . count( $result['tool_calls'] ) );

		return $result;
	}

	/**
	 * Extract rate limit headers we care about into a compact array.
	 *
	 * @param array $headers Response headers.
	 * @return array|null
	 */
	private function extract_rate_limit_headers( $headers ) {
		if ( ! is_array( $headers ) || empty( $headers ) ) {
			return null;
		}
		$keys = array(
			'retry-after',
			'anthropic-ratelimit-requests-limit',
			'anthropic-ratelimit-requests-remaining',
			'anthropic-ratelimit-requests-reset',
			'anthropic-ratelimit-tokens-limit',
			'anthropic-ratelimit-tokens-remaining',
			'anthropic-ratelimit-tokens-reset',
			'anthropic-ratelimit-input-tokens-limit',
			'anthropic-ratelimit-input-tokens-remaining',
			'anthropic-ratelimit-input-tokens-reset',
			'anthropic-ratelimit-output-tokens-limit',
			'anthropic-ratelimit-output-tokens-remaining',
			'anthropic-ratelimit-output-tokens-reset',
		);
		$out = array();
		foreach ( $keys as $k ) {
			if ( isset( $headers[ $k ] ) ) {
				$out[ $k ] = $headers[ $k ];
			}
		}
		return empty( $out ) ? null : $out;
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
