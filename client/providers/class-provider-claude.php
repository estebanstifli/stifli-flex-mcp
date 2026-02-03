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

		// Add conversation history
		foreach ( $conversation as $msg ) {
			$messages[] = $msg;
		}

		// If we have a tool result, add it as a user message
		if ( $tool_result ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'        => 'tool_result',
						'tool_use_id' => $tool_result['tool_use_id'],
						'content'     => wp_json_encode( $tool_result['output'] ),
					),
				),
			);
		} elseif ( ! empty( $message ) ) {
			// Add new user message
			$messages[] = array(
				'role'    => 'user',
				'content' => $message,
			);
		}

		// Build request body
		$body = array(
			'model'       => $model,
			'max_tokens'  => intval( $max_tokens ),
			'messages'    => $messages,
			'system'      => $this->get_system_prompt( $system_prompt ),
			'temperature' => floatval( $temperature ),
			'top_p'       => floatval( $top_p ),
		);

		// Add tools if available
		if ( ! empty( $tools ) ) {
			$body['tools'] = $this->format_tools( $tools );
		}

		// Make request
		$response = $this->make_request(
			self::API_URL,
			array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
			),
			$body
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( $response, $messages );
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

		return $formatted;
	}

	/**
	 * Parse Claude response
	 *
	 * @param array $response API response.
	 * @param array $messages Messages sent.
	 * @return array Parsed response.
	 */
	private function parse_response( $response, $messages ) {
		$result = array(
			'text'         => '',
			'tool_calls'   => array(),
			'conversation' => $messages,
			'finished'     => true,
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
			if ( $block['type'] === 'text' ) {
				$result['text'] .= $block['text'];
			} elseif ( $block['type'] === 'tool_use' ) {
				// Tool call detected
				$result['tool_calls'][] = array(
					'id'          => $block['id'] ?? '',
					'tool_use_id' => $block['id'] ?? '',
					'name'        => $block['name'] ?? '',
					'arguments'   => $block['input'] ?? array(),
				);
				$result['finished'] = false;
			}
		}

		// Check stop reason
		if ( isset( $response['stop_reason'] ) && $response['stop_reason'] === 'tool_use' ) {
			$result['finished'] = false;
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
