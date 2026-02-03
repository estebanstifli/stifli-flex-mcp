<?php
/**
 * OpenAI Provider Handler (Responses API)
 *
 * @package StifliFlexMcp
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-provider-base.php';

/**
 * OpenAI provider handler using the Responses API
 */
class StifliFlexMcp_Client_OpenAI extends StifliFlexMcp_Client_Provider_Base {

	/**
	 * API endpoint
	 */
	const API_URL = 'https://api.openai.com/v1/responses';

	/**
	 * Models that don't support sampling parameters (temperature, top_p, penalties)
	 * These are "reasoning" models that use adaptive reasoning instead.
	 */
	const REASONING_MODELS = array(
		'gpt-5.2',
		'gpt-5.2-chat-latest',
		'gpt-5',
		'gpt-5-mini',
		'gpt-5-nano',
		'o1',
		'o1-mini',
		'o1-preview',
		'o3',
		'o3-mini',
	);

	/**
	 * Check if model is a reasoning model (no sampling params)
	 *
	 * @param string $model Model name.
	 * @return bool
	 */
	private function is_reasoning_model( $model ) {
		foreach ( self::REASONING_MODELS as $reasoning_model ) {
			if ( strpos( $model, $reasoning_model ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Send a message to OpenAI
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	public function send_message( $args ) {
		$api_key       = $args['api_key'];
		$model         = $args['model'] ?: 'gpt-5.2-chat-latest';
		$message       = $args['message'];
		$conversation  = $args['conversation'] ?? array();
		$tools         = $args['tools'] ?? array();
		$tool_result   = $args['tool_result'] ?? null;
		$system_prompt = $args['system_prompt'] ?? '';
		$temperature   = $args['temperature'] ?? 0.7;
		$max_tokens    = $args['max_tokens'] ?? 4096;
		$top_p         = $args['top_p'] ?? 1.0;
		$frequency_penalty = $args['frequency_penalty'] ?? 0;
		$presence_penalty  = $args['presence_penalty'] ?? 0;

		// Build input array
		$input = array();

		// Add conversation history
		foreach ( $conversation as $msg ) {
			$input[] = $msg;
		}

		// If we have a tool result, add it
		if ( $tool_result ) {
			$input[] = array(
				'type'    => 'function_call_output',
				'call_id' => $tool_result['call_id'],
				'output'  => wp_json_encode( $tool_result['output'] ),
			);
		} elseif ( ! empty( $message ) ) {
			// Add new user message
			$input[] = array(
				'role'    => 'user',
				'content' => $message,
			);
		}

		// Build request body - base parameters
		$body = array(
			'model'             => $model,
			'input'             => $input,
			'instructions'      => $this->get_system_prompt( $system_prompt ),
			'max_output_tokens' => intval( $max_tokens ),
		);

		// Add sampling parameters only for non-reasoning models
		// GPT-5 family and o1/o3 models use adaptive reasoning and don't support these
		if ( ! $this->is_reasoning_model( $model ) ) {
			$body['temperature']       = floatval( $temperature );
			$body['top_p']             = floatval( $top_p );
			$body['frequency_penalty'] = floatval( $frequency_penalty );
			$body['presence_penalty']  = floatval( $presence_penalty );
		}

		// Add tools if available
		if ( ! empty( $tools ) ) {
			$body['tools'] = $this->format_tools( $tools );
		}

		// Make request
		$response = $this->make_request(
			self::API_URL,
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			$body
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( $response, $input );
	}

	/**
	 * Format MCP tools for OpenAI
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array OpenAI function tools.
	 */
	protected function format_tools( $mcp_tools ) {
		$formatted = array();

		foreach ( $mcp_tools as $tool ) {
			$params = $tool['inputSchema'] ?? array( 'type' => 'object', 'properties' => new stdClass() );
			
			// Ensure proper structure
			if ( ! isset( $params['type'] ) ) {
				$params['type'] = 'object';
			}
			if ( ! isset( $params['properties'] ) ) {
				$params['properties'] = new stdClass();
			}

			$formatted[] = array(
				'type'        => 'function',
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $params,
			);
		}

		return $formatted;
	}

	/**
	 * Parse OpenAI response
	 *
	 * @param array $response API response.
	 * @param array $input    Input messages sent.
	 * @return array Parsed response.
	 */
	private function parse_response( $response, $input ) {
		$result = array(
			'text'         => '',
			'tool_calls'   => array(),
			'conversation' => $input,
			'finished'     => true,
		);

		if ( ! isset( $response['output'] ) || ! is_array( $response['output'] ) ) {
			return $result;
		}

		foreach ( $response['output'] as $item ) {
			// Add to conversation history
			$result['conversation'][] = $item;

			if ( $item['type'] === 'message' ) {
				// Extract text from message content
				if ( isset( $item['content'] ) && is_array( $item['content'] ) ) {
					foreach ( $item['content'] as $content ) {
						if ( isset( $content['type'] ) && $content['type'] === 'output_text' ) {
							$result['text'] .= $content['text'];
						}
					}
				}
			} elseif ( $item['type'] === 'function_call' ) {
				// Tool call detected
				$result['tool_calls'][] = array(
					'id'        => $item['id'] ?? '',
					'call_id'   => $item['call_id'] ?? '',
					'name'      => $item['name'] ?? '',
					'arguments' => json_decode( $item['arguments'] ?? '{}', true ),
				);
				$result['finished'] = false;
			}
		}

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
