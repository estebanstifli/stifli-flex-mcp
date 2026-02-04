<?php
/**
 * Base Provider Handler for AI APIs
 *
 * @package StifliFlexMcp
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for AI provider handlers
 */
abstract class StifliFlexMcp_Client_Provider_Base {

	/**
	 * Send a message to the AI provider
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	abstract public function send_message( $args );

	/**
	 * Execute an MCP tool locally
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array Tool result.
	 */
	protected function execute_tool( $tool_name, $arguments ) {
		global $stifliFlexMcp;

		if ( ! isset( $stifliFlexMcp ) || ! isset( $stifliFlexMcp->model ) ) {
			return array(
				'error' => true,
				'message' => __( 'MCP model not available', 'stifli-flex-mcp' ),
			);
		}

		// Dispatch the tool
		$result = $stifliFlexMcp->model->dispatchTool( $tool_name, $arguments, null );

		if ( isset( $result['error'] ) ) {
			return array(
				'error' => true,
				'message' => $result['error']['message'] ?? __( 'Tool execution failed', 'stifli-flex-mcp' ),
			);
		}

		// Extract text content from result
		$content = '';
		if ( isset( $result['result']['content'] ) && is_array( $result['result']['content'] ) ) {
			foreach ( $result['result']['content'] as $item ) {
				if ( isset( $item['text'] ) ) {
					$content .= $item['text'] . "\n";
				}
			}
		}

		return array(
			'success' => true,
			'content' => trim( $content ),
		);
	}

	/**
	 * Make an HTTP request to an API
	 *
	 * @param string $url     API URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body.
	 * @return array|WP_Error Response or error.
	 */
	protected function make_request( $url, $headers, $body ) {
		$response = wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 120, // AI responses can be slow
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$body_json   = json_decode( $body_raw, true );

		if ( $status_code >= 400 ) {
			$error_msg = isset( $body_json['error']['message'] ) 
				? $body_json['error']['message'] 
				: ( isset( $body_json['error'] ) && is_string( $body_json['error'] ) 
					? $body_json['error'] 
					/* translators: %d is the HTTP status code */
					: sprintf( __( 'API error: %d', 'stifli-flex-mcp' ), $status_code ) );
			
			return new WP_Error( 'api_error', $error_msg );
		}

		return $body_json;
	}

	/**
	 * Format tools for the specific provider
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array Formatted tools for the provider.
	 */
	abstract protected function format_tools( $mcp_tools );

	/**
	 * Get system prompt for MCP context
	 *
	 * @param string $custom_prompt Optional custom prompt from settings.
	 * @return string
	 */
	protected function get_system_prompt( $custom_prompt = '' ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = get_bloginfo( 'url' );
		
		// Base context about the WordPress site
		$base_context = sprintf(
			/* translators: %1$s is the site name, %2$s is the site URL */
			__( 'You are connected to the WordPress site "%1$s" (%2$s). You have access to MCP (Model Context Protocol) tools that can read and modify WordPress content, WooCommerce data, and more.', 'stifli-flex-mcp' ),
			$site_name,
			$site_url
		);
		
		// If custom prompt is provided, combine them
		if ( ! empty( $custom_prompt ) ) {
			return $custom_prompt . "\n\n" . $base_context;
		}
		
		// Default behavior instructions
		return $base_context . ' ' . __( 'When the user asks you to perform actions on the site, use the available tools. Always explain what you\'re doing and show the results clearly.', 'stifli-flex-mcp' );
	}
}
