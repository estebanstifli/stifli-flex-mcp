<?php
/**
 * WordPress AI Client Provider Handler
 *
 * @package StifliFlexMcp
 * @since 1.0.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-provider-base.php';

/**
 * Adapter for providers registered through WordPress\AiClient.
 */
class StifliFlexMcp_Client_AI_Client extends StifliFlexMcp_Client_Provider_Base {

	/**
	 * Providers handled natively by StifLi.
	 *
	 * @var string[]
	 */
	const NATIVE_PROVIDERS = array( 'openai', 'claude', 'gemini' );

	/**
	 * WordPress AI Client provider credentials option.
	 *
	 * @var string
	 */
	const WP_AI_CLIENT_CREDENTIALS_OPTION = 'wp_ai_client_provider_credentials';

	/**
	 * Default AI Client request timeout in seconds.
	 *
	 * @var float
	 */
	const DEFAULT_REQUEST_TIMEOUT = 90.0;

	/**
	 * Default AI Client connect timeout in seconds.
	 *
	 * @var float
	 */
	const DEFAULT_CONNECT_TIMEOUT = 15.0;

	/**
	 * AI Client provider ID.
	 *
	 * @var string
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param string $provider AI Client provider ID.
	 */
	public function __construct( $provider ) {
		$this->provider = sanitize_key( $provider );
	}

	/**
	 * Check whether WordPress AI Client is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( '\\WordPress\\AiClient\\AiClient' );
	}

	/**
	 * Check whether a provider is registered through AI Client.
	 *
	 * @param string $provider Provider ID.
	 * @return bool
	 */
	public static function is_provider_available( $provider ) {
		$provider = sanitize_key( $provider );
		if ( '' === $provider || ! self::is_available() ) {
			return false;
		}

		try {
			$registry = self::get_registry();
			return $registry && method_exists( $registry, 'hasProvider' ) && $registry->hasProvider( $provider );
		} catch ( Exception $e ) {
			stifli_flex_mcp_log( '[AI Client] Provider detection failed: ' . $e->getMessage() );
			return false;
		} catch ( Error $e ) {
			stifli_flex_mcp_log( '[AI Client] Provider detection failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get installed non-native AI Client providers for UI options.
	 *
	 * @return array Provider ID => label.
	 */
	public static function get_available_providers() {
		$providers = array();
		if ( ! self::is_available() ) {
			return $providers;
		}

		try {
			$registry = self::get_registry();
			if ( ! $registry || ! method_exists( $registry, 'getRegisteredProviderIds' ) ) {
				return $providers;
			}

			foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
				$provider_id = sanitize_key( $provider_id );
				if ( '' === $provider_id || in_array( $provider_id, self::NATIVE_PROVIDERS, true ) ) {
					continue;
				}

				$providers[ $provider_id ] = self::get_provider_label( $provider_id );
			}
		} catch ( Exception $e ) {
			stifli_flex_mcp_log( '[AI Client] Listing providers failed: ' . $e->getMessage() );
		} catch ( Error $e ) {
			stifli_flex_mcp_log( '[AI Client] Listing providers failed: ' . $e->getMessage() );
		}

		return $providers;
	}

	/**
	 * Get model options for a provider.
	 *
	 * @param string $provider Provider ID.
	 * @return array Model ID => label.
	 */
	public static function get_provider_models( $provider ) {
		$provider = sanitize_key( $provider );
		$models   = array();

		if ( ! self::is_provider_available( $provider ) ) {
			return $models;
		}

		try {
			$registry   = self::get_registry();
			self::maybe_set_registry_authentication( $registry, $provider, '' );

			$class_name = method_exists( $registry, 'getProviderClassName' ) ? $registry->getProviderClassName( $provider ) : '';
			if ( $class_name && class_exists( $class_name ) && method_exists( $class_name, 'modelMetadataDirectory' ) ) {
				$directory = $class_name::modelMetadataDirectory();
				if ( is_object( $directory ) && method_exists( $directory, 'listModelMetadata' ) ) {
					foreach ( $directory->listModelMetadata() as $metadata ) {
						$model_id = self::read_object_value( $metadata, array( 'getId', 'id' ) );
						if ( ! is_string( $model_id ) || '' === trim( $model_id ) ) {
							continue;
						}

						$model_name = self::read_object_value( $metadata, array( 'getName', 'getLabel', 'name' ) );
						if ( ! is_string( $model_name ) || '' === trim( $model_name ) ) {
							$model_name = $model_id;
						}

						$models[ $model_id ] = sprintf(
							/* translators: %s is the model display name. */
							__( '%s (AI Client)', 'stifli-flex-mcp' ),
							$model_name
						);
					}
				}
			}
		} catch ( Exception $e ) {
			stifli_flex_mcp_log( '[AI Client] Listing models failed: ' . $e->getMessage() );
		} catch ( Error $e ) {
			stifli_flex_mcp_log( '[AI Client] Listing models failed: ' . $e->getMessage() );
		}

		if ( empty( $models ) ) {
			$models = self::get_fallback_models( $provider );
		}

		return $models;
	}

	/**
	 * Send a message through an AI Client provider.
	 *
	 * @param array $args Message arguments.
	 * @return array|WP_Error Response data or error.
	 */
	public function send_message( $args ) {
		if ( ! self::is_provider_available( $this->provider ) ) {
			return new WP_Error( 'ai_client_provider_missing', __( 'The selected AI Client provider is not installed or registered.', 'stifli-flex-mcp' ) );
		}

		$model            = ! empty( $args['model'] ) ? (string) $args['model'] : $this->get_default_model();
		$api_key          = isset( $args['api_key'] ) ? (string) $args['api_key'] : '';
		$messages         = $this->build_messages( $args );
		$tools_for_config = isset( $args['tools'] ) && is_array( $args['tools'] ) ? array_values( $args['tools'] ) : array();
		$model_config     = $this->build_model_config( $args, $tools_for_config );

		if ( empty( $messages ) ) {
			return new WP_Error( 'ai_client_empty_prompt', __( 'Message is required', 'stifli-flex-mcp' ) );
		}

		try {
			$registry = self::get_registry();
			$this->maybe_set_authentication( $registry, $api_key );

			$model_instance = $registry->getProviderModel( $this->provider, $model, $model_config );
			if ( ! is_object( $model_instance ) || ! method_exists( $model_instance, 'generateTextResult' ) ) {
				return new WP_Error( 'ai_client_model_unsupported', __( 'The selected AI Client model does not support text generation.', 'stifli-flex-mcp' ) );
			}

			$this->maybe_set_request_options( $model_instance, $args );

			stifli_flex_mcp_log( sprintf(
				'[AI Client] Request summary provider=%s model=%s messages=%d tools=%d',
				$this->provider,
				$model,
				count( $messages ),
				count( $tools_for_config )
			) );

			$result = $model_instance->generateTextResult( $messages );
			return $this->parse_result( $result, $messages );
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_client_error', $e->getMessage() );
		} catch ( Error $e ) {
			return new WP_Error( 'ai_client_error', $e->getMessage() );
		}
	}

	/**
	 * Format MCP tools into AI Client function declarations.
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array FunctionDeclaration objects.
	 */
	protected function format_tools( $mcp_tools ) {
		$declarations = array();
		$class_name   = '\\WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration';
		if ( ! class_exists( $class_name ) ) {
			return $declarations;
		}

		foreach ( $mcp_tools as $tool ) {
			$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$params = StifliFlexMcpUtils::normalizeToolInputSchema( $tool['inputSchema'] ?? array() );
			$declarations[] = new $class_name(
				$name,
				isset( $tool['description'] ) ? (string) $tool['description'] : $name,
				$params
			);
		}

		return $declarations;
	}

	/**
	 * Get the default AI Client registry.
	 *
	 * @return object|null
	 */
	private static function get_registry() {
		if ( ! self::is_available() ) {
			return null;
		}

		$class_name = '\\WordPress\\AiClient\\AiClient';
		return $class_name::defaultRegistry();
	}

	/**
	 * Read a value from an object using method names or public properties.
	 *
	 * @param object $object Object to inspect.
	 * @param array  $keys   Method/property names.
	 * @return mixed|null
	 */
	private static function read_object_value( $object, $keys ) {
		if ( ! is_object( $object ) ) {
			return null;
		}

		foreach ( $keys as $key ) {
			if ( method_exists( $object, $key ) ) {
				return $object->{$key}();
			}
			if ( isset( $object->{$key} ) ) {
				return $object->{$key};
			}
		}

		return null;
	}

	/**
	 * Get a provider label from provider metadata.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	private static function get_provider_label( $provider ) {
		$label = ucwords( str_replace( array( '-', '_' ), ' ', $provider ) );

		try {
			$registry   = self::get_registry();
			$class_name = $registry && method_exists( $registry, 'getProviderClassName' ) ? $registry->getProviderClassName( $provider ) : '';
			if ( $class_name && class_exists( $class_name ) && method_exists( $class_name, 'metadata' ) ) {
				$metadata = $class_name::metadata();
				$name     = self::read_object_value( $metadata, array( 'getName', 'name' ) );
				if ( is_string( $name ) && '' !== trim( $name ) ) {
					$label = $name;
				}
			}
		} catch ( Exception $e ) {
			stifli_flex_mcp_log( '[AI Client] Provider label lookup failed: ' . $e->getMessage() );
		} catch ( Error $e ) {
			stifli_flex_mcp_log( '[AI Client] Provider label lookup failed: ' . $e->getMessage() );
		}

		return sprintf(
			/* translators: %s is the provider display name. */
			__( '%s (AI Client)', 'stifli-flex-mcp' ),
			$label
		);
	}

	/**
	 * Fallback models for providers that accept arbitrary model IDs.
	 *
	 * @param string $provider Provider ID.
	 * @return array
	 */
	private static function get_fallback_models( $provider ) {
		if ( 'openrouter' === $provider ) {
			return array(
				'openrouter/auto'           => __( 'OpenRouter Auto (AI Client)', 'stifli-flex-mcp' ),
				'openai/gpt-4o-mini'        => __( 'GPT-4o Mini via OpenRouter (AI Client)', 'stifli-flex-mcp' ),
				'google/gemini-2.0-flash-001' => __( 'Gemini 2.0 Flash via OpenRouter (AI Client)', 'stifli-flex-mcp' ),
				'anthropic/claude-3.5-sonnet' => __( 'Claude 3.5 Sonnet via OpenRouter (AI Client)', 'stifli-flex-mcp' ),
			);
		}

		if ( 'grok' === $provider ) {
			return array(
				'grok-4'      => __( 'Grok 4 (AI Client)', 'stifli-flex-mcp' ),
				'grok-3-mini' => __( 'Grok 3 Mini (AI Client)', 'stifli-flex-mcp' ),
			);
		}

		return array();
	}

	/**
	 * Get default model for the current provider.
	 *
	 * @return string
	 */
	private function get_default_model() {
		$models = self::get_provider_models( $this->provider );
		if ( ! empty( $models ) ) {
			$ids = array_keys( $models );
			return (string) reset( $ids );
		}

		return '';
	}

	/**
	 * Build AI Client model config.
	 *
	 * @param array $args Message arguments.
	 * @return object
	 */
	private function build_model_config( $args, $tools ) {
		$config_class = '\\WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig';

		$config_data  = array(
			'systemInstruction' => $this->get_system_prompt( $args['system_prompt'] ?? '' ),
			'maxTokens'         => intval( $args['max_tokens'] ?? 4096 ),
			'temperature'       => floatval( $args['temperature'] ?? 0.7 ),
			'topP'              => floatval( $args['top_p'] ?? 1.0 ),
		);

		if ( isset( $args['presence_penalty'] ) ) {
			$config_data['presencePenalty'] = floatval( $args['presence_penalty'] );
		}
		if ( isset( $args['frequency_penalty'] ) ) {
			$config_data['frequencyPenalty'] = floatval( $args['frequency_penalty'] );
		}

		if ( ! empty( $tools ) ) {
			$config_data['functionDeclarations'] = array_map(
				static function ( $declaration ) {
					return is_object( $declaration ) && method_exists( $declaration, 'toArray' ) ? $declaration->toArray() : $declaration;
				},
				$this->format_tools( $tools )
			);
		}

		if ( class_exists( $config_class ) && method_exists( $config_class, 'fromArray' ) ) {
			return $config_class::fromArray( $config_data );
		}

		return new $config_class();
	}

	/**
	 * Build AI Client messages from normalized StifLi payloads.
	 *
	 * @param array $args Message arguments.
	 * @return array
	 */
	private function build_messages( $args ) {
		$messages     = array();
		$conversation = isset( $args['conversation'] ) && is_array( $args['conversation'] ) ? $args['conversation'] : array();
		$tool_result  = $args['tool_result'] ?? null;
		$message      = isset( $args['message'] ) ? (string) $args['message'] : '';

		foreach ( $conversation as $item ) {
			$converted = $this->message_from_array( $item );
			if ( $converted ) {
				$messages[] = $converted;
			}
		}

		if ( ! empty( $tool_result ) ) {
			foreach ( $this->normalize_tool_results( $tool_result ) as $result ) {
				$messages[] = $this->make_function_response_message( $result, $messages );
			}
		} elseif ( '' !== trim( $message ) ) {
			$messages[] = $this->make_text_message( 'user', $message );
		}

		return array_values( array_filter( $messages ) );
	}

	/**
	 * Convert stored conversation array into an AI Client Message.
	 *
	 * @param mixed $item Conversation item.
	 * @return object|null
	 */
	private function message_from_array( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$message_class = '\\WordPress\\AiClient\\Messages\\DTO\\Message';
		if ( isset( $item['role'], $item['parts'] ) && class_exists( $message_class ) && method_exists( $message_class, 'fromArray' ) ) {
			try {
				return $message_class::fromArray( $item );
			} catch ( Exception $e ) {
				stifli_flex_mcp_log( '[AI Client] Conversation item skipped: ' . $e->getMessage() );
			} catch ( Error $e ) {
				stifli_flex_mcp_log( '[AI Client] Conversation item skipped: ' . $e->getMessage() );
			}
		}

		$role    = $item['role'] ?? '';
		$content = $item['content'] ?? '';
		if ( is_string( $content ) && '' !== trim( $content ) && in_array( $role, array( 'user', 'assistant', 'model' ), true ) ) {
			return $this->make_text_message( 'assistant' === $role ? 'model' : $role, $content );
		}

		return null;
	}

	/**
	 * Create a text message.
	 *
	 * @param string $role Role: user or model.
	 * @param string $text Text content.
	 * @return object|null
	 */
	private function make_text_message( $role, $text ) {
		$part_class  = '\\WordPress\\AiClient\\Messages\\DTO\\MessagePart';
		$user_class  = '\\WordPress\\AiClient\\Messages\\DTO\\UserMessage';
		$model_class = '\\WordPress\\AiClient\\Messages\\DTO\\ModelMessage';

		if ( ! class_exists( $part_class ) ) {
			return null;
		}

		$part = new $part_class( (string) $text );
		if ( 'model' === $role && class_exists( $model_class ) ) {
			return new $model_class( array( $part ) );
		}

		if ( class_exists( $user_class ) ) {
			return new $user_class( array( $part ) );
		}

		return null;
	}

	/**
	 * Normalize tool_result into a list.
	 *
	 * @param mixed $tool_result Tool result payload.
	 * @return array
	 */
	private function normalize_tool_results( $tool_result ) {
		if ( ! is_array( $tool_result ) ) {
			return array();
		}

		if ( isset( $tool_result['output'] ) ) {
			return array( $tool_result );
		}

		return array_values( array_filter( $tool_result, 'is_array' ) );
	}

	/**
	 * Create a function response message.
	 *
	 * @param array $result   Tool result payload.
	 * @param array $messages Existing messages.
	 * @return object|null
	 */
	private function make_function_response_message( $result, $messages ) {
		$response_class = '\\WordPress\\AiClient\\Tools\\DTO\\FunctionResponse';
		$part_class     = '\\WordPress\\AiClient\\Messages\\DTO\\MessagePart';
		$user_class     = '\\WordPress\\AiClient\\Messages\\DTO\\UserMessage';

		if ( ! class_exists( $response_class ) || ! class_exists( $part_class ) || ! class_exists( $user_class ) ) {
			return null;
		}

		$id     = $result['call_id'] ?? ( $result['tool_use_id'] ?? ( $result['id'] ?? null ) );
		$name   = $result['name'] ?? null;
		$output = $result['output'] ?? null;

		if ( empty( $name ) && ! empty( $id ) ) {
			$name = $this->find_function_name_for_id( $messages, $id );
		}

		if ( empty( $id ) && empty( $name ) ) {
			return null;
		}

		$response = new $response_class( $id ? (string) $id : null, $name ? (string) $name : null, $output );
		return new $user_class( array( new $part_class( $response ) ) );
	}

	/**
	 * Find function name for a previous function call ID.
	 *
	 * @param array  $messages AI Client messages.
	 * @param string $id       Function call ID.
	 * @return string|null
	 */
	private function find_function_name_for_id( $messages, $id ) {
		foreach ( array_reverse( $messages ) as $message ) {
			if ( ! is_object( $message ) || ! method_exists( $message, 'getParts' ) ) {
				continue;
			}

			foreach ( $message->getParts() as $part ) {
				if ( ! is_object( $part ) || ! method_exists( $part, 'getFunctionCall' ) ) {
					continue;
				}

				$call = $part->getFunctionCall();
				if ( $call && method_exists( $call, 'getId' ) && (string) $call->getId() === (string) $id ) {
					return method_exists( $call, 'getName' ) ? $call->getName() : null;
				}
			}
		}

		return null;
	}

	/**
	 * Apply explicit API key if the user supplied one.
	 *
	 * @param object $registry AI Client registry.
	 * @param string $api_key  API key.
	 * @return void
	 */
	private function maybe_set_authentication( $registry, $api_key ) {
		self::maybe_set_registry_authentication( $registry, $this->provider, $api_key );
	}

	/**
	 * Apply HTTP request options (timeouts) to API-based AI Client models.
	 *
	 * @param object $model_instance AI Client model instance.
	 * @param array  $args           Message arguments.
	 * @return void
	 */
	private function maybe_set_request_options( $model_instance, $args ) {
		$options_class = '\\WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions';

		if ( ! is_object( $model_instance ) || ! method_exists( $model_instance, 'setRequestOptions' ) || ! class_exists( $options_class ) ) {
			return;
		}

		$timeout = self::normalize_timeout_value(
			apply_filters(
				'sflmcp_ai_client_request_timeout',
				$args['request_timeout'] ?? self::DEFAULT_REQUEST_TIMEOUT,
				$this->provider,
				$args
			),
			self::DEFAULT_REQUEST_TIMEOUT
		);

		$connect_timeout = self::normalize_timeout_value(
			apply_filters(
				'sflmcp_ai_client_connect_timeout',
				$args['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT,
				$this->provider,
				$args,
				$timeout
			),
			self::DEFAULT_CONNECT_TIMEOUT
		);

		if ( $connect_timeout > $timeout ) {
			$connect_timeout = $timeout;
		}

		try {
			$request_options = new $options_class();

			if ( method_exists( $request_options, 'setTimeout' ) ) {
				$request_options->setTimeout( $timeout );
			}

			if ( method_exists( $request_options, 'setConnectTimeout' ) ) {
				$request_options->setConnectTimeout( $connect_timeout );
			}

			$model_instance->setRequestOptions( $request_options );

			stifli_flex_mcp_log(
				sprintf(
					'[AI Client] Request options configured provider=%s timeout=%.1fs connect_timeout=%.1fs',
					$this->provider,
					$timeout,
					$connect_timeout
				)
			);
		} catch ( Exception $e ) {
			stifli_flex_mcp_log( '[AI Client] Request options skipped: ' . $e->getMessage() );
		} catch ( Error $e ) {
			stifli_flex_mcp_log( '[AI Client] Request options skipped: ' . $e->getMessage() );
		}
	}

	/**
	 * Normalize timeout values to a safe numeric range.
	 *
	 * @param mixed $value   Timeout value.
	 * @param float $default Fallback timeout.
	 * @return float
	 */
	private static function normalize_timeout_value( $value, $default ) {
		$timeout = is_numeric( $value ) ? (float) $value : (float) $default;
		if ( $timeout <= 0 ) {
			$timeout = (float) $default;
		}

		return max( 1.0, min( 300.0, $timeout ) );
	}

	/**
	 * Apply explicit or WordPress AI Client stored API key to the registry.
	 *
	 * @param object $registry AI Client registry.
	 * @param string $provider Provider ID.
	 * @param string $api_key  Optional explicit API key.
	 * @return void
	 */
	private static function maybe_set_registry_authentication( $registry, $provider, $api_key ) {
		$provider   = sanitize_key( $provider );
		$api_key = trim( (string) $api_key );
		$source     = 'stifli';
		$auth_class = '\\WordPress\\AiClient\\Providers\\Http\\DTO\\ApiKeyRequestAuthentication';

		if ( '' === $api_key ) {
			$api_key = self::get_stored_provider_api_key( $provider );
			$source  = 'wp-ai-client';
		}

		if ( '' === $provider || '' === $api_key || ! is_object( $registry ) || ! method_exists( $registry, 'setProviderRequestAuthentication' ) || ! class_exists( $auth_class ) ) {
			return;
		}

		try {
			$registry->setProviderRequestAuthentication( $provider, new $auth_class( $api_key ) );
			stifli_flex_mcp_log( sprintf( '[AI Client] Authentication configured provider=%s source=%s', $provider, $source ) );
		} catch ( Exception $e ) {
			stifli_flex_mcp_log( '[AI Client] Custom authentication skipped: ' . $e->getMessage() );
		} catch ( Error $e ) {
			stifli_flex_mcp_log( '[AI Client] Custom authentication skipped: ' . $e->getMessage() );
		}
	}

	/**
	 * Read a provider API key from the WordPress AI Client credentials option.
	 *
	 * @param string $provider Provider ID.
	 * @return string
	 */
	private static function get_stored_provider_api_key( $provider ) {
		$provider    = sanitize_key( $provider );
		$credentials = get_option( self::WP_AI_CLIENT_CREDENTIALS_OPTION, array() );

		if ( ! is_array( $credentials ) || ! isset( $credentials[ $provider ] ) || ! is_string( $credentials[ $provider ] ) ) {
			return '';
		}

		return trim( $credentials[ $provider ] );
	}

	/**
	 * Parse AI Client result into StifLi normalized provider response.
	 *
	 * @param object $result   AI Client result.
	 * @param array  $messages Prompt messages sent.
	 * @return array
	 */
	private function parse_result( $result, $messages ) {
		$parsed = array(
			'text'         => '',
			'tool_calls'   => array(),
			'conversation' => $this->messages_to_array( $messages ),
			'finished'     => true,
			'usage'        => array(
				'input_tokens'          => 0,
				'output_tokens'         => 0,
				'cached_tokens'         => 0,
				'billable_input_tokens' => 0,
			),
		);

		if ( ! is_object( $result ) || ! method_exists( $result, 'toMessage' ) ) {
			return $parsed;
		}

		$message = $result->toMessage();
		$parsed['conversation'][] = method_exists( $message, 'toArray' ) ? $message->toArray() : array();

		if ( method_exists( $message, 'getParts' ) ) {
			foreach ( $message->getParts() as $part ) {
				if ( is_object( $part ) && method_exists( $part, 'getText' ) && null !== $part->getText() ) {
					$parsed['text'] .= (string) $part->getText();
				}

				if ( ! is_object( $part ) || ! method_exists( $part, 'getFunctionCall' ) ) {
					continue;
				}

				$call = $part->getFunctionCall();
				if ( ! $call ) {
					continue;
				}

				$id   = method_exists( $call, 'getId' ) ? (string) $call->getId() : '';
				$name = method_exists( $call, 'getName' ) ? (string) $call->getName() : '';
				$args = method_exists( $call, 'getArgs' ) ? $call->getArgs() : array();
				if ( ! is_array( $args ) ) {
					$args = array();
				}

				$parsed['tool_calls'][] = array(
					'id'        => $id,
					'call_id'   => $id,
					'name'      => $name,
					'arguments' => $args,
				);
				$parsed['finished'] = false;
			}
		}

		if ( method_exists( $result, 'getTokenUsage' ) ) {
			$usage = $result->getTokenUsage();
			if ( is_object( $usage ) ) {
				$input  = method_exists( $usage, 'getPromptTokens' ) ? (int) $usage->getPromptTokens() : 0;
				$output = method_exists( $usage, 'getCompletionTokens' ) ? (int) $usage->getCompletionTokens() : 0;
				$parsed['usage'] = array(
					'input_tokens'          => $input,
					'output_tokens'         => $output,
					'cached_tokens'         => 0,
					'billable_input_tokens' => $input,
				);
			}
		}

		if ( empty( $parsed['usage']['output_tokens'] ) && '' !== $parsed['text'] ) {
			$parsed['usage']['output_tokens'] = (int) ceil( strlen( $parsed['text'] ) / 4 );
		}

		return $parsed;
	}

	/**
	 * Convert AI Client message objects to arrays.
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	private function messages_to_array( $messages ) {
		$items = array();
		foreach ( $messages as $message ) {
			if ( is_object( $message ) && method_exists( $message, 'toArray' ) ) {
				$items[] = $message->toArray();
			}
		}

		return $items;
	}
}