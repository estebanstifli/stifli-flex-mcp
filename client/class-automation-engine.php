<?php
/**
 * Automation Engine - Handles scheduled task execution
 *
 * @package StifliFlexMcp
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StifliFlexMcp_Automation_Engine
 * 
 * Handles the execution of scheduled automation tasks
 */
class StifliFlexMcp_Automation_Engine {

	/**
	 * Hook name for the worker cron
	 */
	const HOOK_WORKER = 'sflmcp_automation_worker';

	/**
	 * Maximum execution time per task (seconds)
	 */
	const MAX_EXECUTION_TIME = 120;

	/**
	 * Maximum iterations in agentic loop
	 */
	const MAX_ITERATIONS = 10;

	/**
	 * Schedule presets with cron expressions
	 */
	const SCHEDULE_PRESETS = array(
		// Hourly
		'every_hour' => array(
			'label'       => 'Every hour',
			'interval'    => 3600,
			'wp_schedule' => 'hourly',
			'icon'        => 'dashicons-clock',
		),
		'every_2_hours' => array(
			'label'       => 'Every 2 hours',
			'interval'    => 7200,
			'wp_schedule' => 'twicedaily', // Fallback, will use custom
			'icon'        => 'dashicons-clock',
		),
		'every_6_hours' => array(
			'label'       => 'Every 6 hours',
			'interval'    => 21600,
			'wp_schedule' => 'sflmcp_every_6_hours',
			'icon'        => 'dashicons-clock',
		),
		'every_12_hours' => array(
			'label'       => 'Every 12 hours',
			'interval'    => 43200,
			'wp_schedule' => 'twicedaily',
			'icon'        => 'dashicons-clock',
		),
		// Daily
		'daily_morning' => array(
			'label'       => 'Daily at 8:00 AM',
			'interval'    => 86400,
			'wp_schedule' => 'daily',
			'time'        => '08:00',
			'icon'        => 'dashicons-calendar-alt',
		),
		'daily_noon' => array(
			'label'       => 'Daily at 12:00 PM',
			'interval'    => 86400,
			'wp_schedule' => 'daily',
			'time'        => '12:00',
			'icon'        => 'dashicons-calendar-alt',
		),
		'daily_evening' => array(
			'label'       => 'Daily at 6:00 PM',
			'interval'    => 86400,
			'wp_schedule' => 'daily',
			'time'        => '18:00',
			'icon'        => 'dashicons-calendar-alt',
		),
		'daily_custom' => array(
			'label'         => 'Daily (custom time)',
			'interval'      => 86400,
			'wp_schedule'   => 'daily',
			'requires_time' => true,
			'icon'          => 'dashicons-calendar-alt',
		),
		// Weekly
		'weekly_monday' => array(
			'label'       => 'Every Monday',
			'interval'    => 604800,
			'wp_schedule' => 'weekly',
			'day'         => 1,
			'time'        => '09:00',
			'icon'        => 'dashicons-calendar',
		),
		'weekly_friday' => array(
			'label'       => 'Every Friday',
			'interval'    => 604800,
			'wp_schedule' => 'weekly',
			'day'         => 5,
			'time'        => '17:00',
			'icon'        => 'dashicons-calendar',
		),
		'weekly_sunday' => array(
			'label'       => 'Every Sunday',
			'interval'    => 604800,
			'wp_schedule' => 'weekly',
			'day'         => 0,
			'time'        => '10:00',
			'icon'        => 'dashicons-calendar',
		),
		// Monthly
		'monthly_first' => array(
			'label'       => 'First day of month',
			'interval'    => 2592000,
			'wp_schedule' => 'monthly',
			'day_of_month' => 1,
			'time'        => '09:00',
			'icon'        => 'dashicons-calendar',
		),
		'monthly_15' => array(
			'label'       => '15th of month',
			'interval'    => 2592000,
			'wp_schedule' => 'monthly',
			'day_of_month' => 15,
			'time'        => '09:00',
			'icon'        => 'dashicons-calendar',
		),
	);

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - Register hooks
	 */
	public function __construct() {
		// Register custom cron schedules
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		
		// Register worker hook
		add_action( self::HOOK_WORKER, array( $this, 'process_pending_tasks' ) );
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['sflmcp_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'stifli-flex-mcp' ),
		);
		$schedules['sflmcp_every_2_hours'] = array(
			'interval' => 7200,
			'display'  => __( 'Every 2 Hours', 'stifli-flex-mcp' ),
		);
		$schedules['sflmcp_every_6_hours'] = array(
			'interval' => 21600,
			'display'  => __( 'Every 6 Hours', 'stifli-flex-mcp' ),
		);
		return $schedules;
	}

	/**
	 * Activate the automation system (called on plugin activation)
	 */
	public function activate() {
		if ( ! wp_next_scheduled( self::HOOK_WORKER ) ) {
			wp_schedule_event( time(), 'sflmcp_every_minute', self::HOOK_WORKER );
		}
	}

	/**
	 * Deactivate the automation system (called on plugin deactivation)
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( self::HOOK_WORKER );
	}

	/**
	 * Process all pending tasks
	 */
	public function process_pending_tasks() {
		global $wpdb;

		$table = $wpdb->prefix . 'sflmcp_automation_tasks';
		$now   = current_time( 'mysql', true );

		// Get active tasks that are due
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tasks = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'active' AND next_run <= %s ORDER BY next_run ASC LIMIT 3",
			$now
		) );

		if ( empty( $tasks ) ) {
			return;
		}

		foreach ( $tasks as $task ) {
			// Acquire a per-task lock to prevent concurrent execution.
			// If another cron request is already running this task, skip it.
			$lock_key = 'sflmcp_task_lock_' . $task->id;
			if ( get_transient( $lock_key ) ) {
				stifli_flex_mcp_log( sprintf( '[Automation] Task %d is already running (locked), skipping', $task->id ) );
				continue;
			}
			// Lock for up to 3 minutes (longer than MAX_EXECUTION_TIME to prevent stale locks)
			set_transient( $lock_key, time(), 3 * MINUTE_IN_SECONDS );

			// Move next_run to the future BEFORE executing.
			// Primary guard against duplicate execution: even if the transient lock
			// fails (DB race, object-cache flush), the SQL WHERE next_run <= NOW()
			// will no longer pick up this task during execution.
			$future_next_run = $this->calculate_next_run( $task );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'next_run' => $future_next_run ),
				array( 'id' => $task->id ),
				array( '%s' ),
				array( '%d' )
			);
			stifli_flex_mcp_log( sprintf( '[Automation] Task %d next_run moved to %s before execution', $task->id, $future_next_run ) );

			try {
				$this->execute_task( $task );
			} finally {
				delete_transient( $lock_key );
			}
		}
	}

	/**
	 * Execute a single task
	 *
	 * @param object $task Task object from database.
	 * @return array Execution result.
	 */
	public function execute_task( $task ) {
		global $wpdb;

		set_time_limit( 120 );

		$task_table = $wpdb->prefix . 'sflmcp_automation_tasks';
		$log_table  = $wpdb->prefix . 'sflmcp_automation_logs';
		$start_time = microtime( true );

		stifli_flex_mcp_log( sprintf( '[Automation] Starting task: %s (ID: %d)', $task->task_name, $task->id ) );

		// Save current user and switch to task creator for permissions
		$original_user_id = get_current_user_id();
		$task_user_id     = ! empty( $task->created_by ) ? intval( $task->created_by ) : 0;

		// If no user set, try to get an admin
		if ( 0 === $task_user_id ) {
			$admins = get_users( array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			) );
			$task_user_id = ! empty( $admins ) ? intval( $admins[0] ) : 0;
		}

		// Switch to task user for tool permissions
		if ( $task_user_id > 0 ) {
			wp_set_current_user( $task_user_id );
			stifli_flex_mcp_log( sprintf( '[Automation] Switched to user ID: %d for task execution', $task_user_id ) );
		} else {
			stifli_flex_mcp_log( '[Automation] Warning: No user available for task execution, tools may fail' );
		}

		// Check monthly token budget before execution
		$budget = intval( $task->token_budget_monthly ?? 0 );
		if ( $budget > 0 ) {
			$tokens_used = $this->get_monthly_tokens_used( $task->id );
			if ( $tokens_used >= $budget ) {
				stifli_flex_mcp_log( sprintf(
					'[Automation] Task %d skipped: monthly token budget exceeded (%d / %d)',
					$task->id, $tokens_used, $budget
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$task_table,
					array(
						'last_run'   => current_time( 'mysql', true ),
						'last_error' => sprintf( __( 'Monthly token budget exceeded: %d / %d tokens used', 'stifli-flex-mcp' ), $tokens_used, $budget ),
						'next_run'   => $this->calculate_next_run( $task ),
					),
					array( 'id' => $task->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				wp_set_current_user( $original_user_id );
				return array( 'success' => false, 'error' => 'Token budget exceeded' );
			}
		}

		// Create log entry
		$log_id = $this->create_log_entry( $task->id, $task->prompt );

		try {
			// Get settings from AI Chat Agent configuration
			$client_settings   = get_option( 'sflmcp_client_settings', array() );
			$advanced_settings = get_option( 'sflmcp_client_settings_advanced', array() );

			// Always use AI Chat Agent provider/model (centralized config)
			$provider = $client_settings['provider'] ?? 'openai';
			$model    = $client_settings['model'] ?? 'gpt-5.2-chat-latest';
			$api_key  = $client_settings['api_key'] ?? '';

			if ( empty( $api_key ) ) {
				throw new Exception( __( 'API key not configured. Please configure it in AI Chat Agent settings.', 'stifli-flex-mcp' ) );
			}

			// Decrypt API key
			$api_key = $this->decrypt_api_key( $api_key );

			// Get tools for this task
			$tools = $this->get_task_tools( $task );

			// Prepare prompt with variables
			$prompt = $this->process_prompt_variables( $task->prompt );
			$system_prompt = ! empty( $task->system_prompt ) 
				? $task->system_prompt 
				: ( $advanced_settings['system_prompt'] ?? __( 'You are an AI assistant with access to WordPress tools.', 'stifli-flex-mcp' ) );

			// Force sequential tool execution for reliability (same as AI Chat Agent)
			$system_prompt .= "\n\n" . __( 'IMPORTANT: When using tools, execute only ONE tool at a time. Wait for the result before deciding if you need another tool. Never call multiple tools in parallel.', 'stifli-flex-mcp' );

			// Prepare arguments
			$args = array(
				'api_key'       => $api_key,
				'model'         => $model,
				'message'       => $prompt,
				'conversation'  => array(),
				'tools'         => $tools,
				'system_prompt' => $system_prompt,
				'temperature'   => floatval( $advanced_settings['temperature'] ?? 0.7 ),
				'max_tokens'    => intval( $advanced_settings['max_tokens'] ?? 4096 ),
				'top_p'         => floatval( $advanced_settings['top_p'] ?? 1.0 ),
			);

			// Get provider instance
			$provider_instance = $this->get_provider_instance( $provider );
			if ( is_wp_error( $provider_instance ) ) {
				throw new Exception( $provider_instance->get_error_message() );
			}

			// Run agentic loop
			$result = $this->run_agentic_loop( $provider_instance, $args, $log_id );

			// Calculate execution time
			$execution_time = ( microtime( true ) - $start_time ) * 1000;

			// Update log with success
			$this->complete_log_entry( $log_id, 'success', $result, $execution_time );

			// Update task timestamps
			$this->update_task_success( $task );

			// Execute output actions
			$this->execute_output_actions( $task, $result );

			stifli_flex_mcp_log( sprintf( '[Automation] Task completed: %s (%.2fs)', $task->task_name, $execution_time / 1000 ) );

			// Restore original user
			wp_set_current_user( $original_user_id );

			return array(
				'success' => true,
				'result'  => $result,
			);

		} catch ( Exception $e ) {
			$execution_time = ( microtime( true ) - $start_time ) * 1000;
			
			// Update log with error
			$this->complete_log_entry( $log_id, 'error', null, $execution_time, $e->getMessage() );
			
			// Update task with error
			$this->update_task_error( $task, $e->getMessage() );

			stifli_flex_mcp_log( sprintf( '[Automation] Task failed: %s - %s', $task->task_name, $e->getMessage() ) );

			// Restore original user
			wp_set_current_user( $original_user_id );

			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Execute a task internally (used by event automations)
	 *
	 * @param object $task Task-like object with prompt, system_prompt, tools_enabled, etc.
	 * @return array Result with response, tools_executed, tokens_used.
	 */
	public function execute_task_internal( $task ) {
		$start_time = microtime( true );
		$result     = array(
			'response'       => '',
			'tools_executed' => array(),
			'tokens_used'    => 0,
		);

		try {
			// Get settings from AI Chat Agent configuration
			$client_settings   = get_option( 'sflmcp_client_settings', array() );
			$advanced_settings = get_option( 'sflmcp_client_settings_advanced', array() );

			// Use task provider/model or fallback to global config
			$provider = ! empty( $task->provider ) ? $task->provider : ( $client_settings['provider'] ?? 'openai' );
			$model    = ! empty( $task->model ) ? $task->model : ( $client_settings['model'] ?? 'gpt-5.2-chat-latest' );
			$api_key  = $client_settings['api_key'] ?? '';

			if ( empty( $api_key ) ) {
				throw new Exception( __( 'API key not configured.', 'stifli-flex-mcp' ) );
			}

			// Decrypt API key
			$api_key = $this->decrypt_api_key( $api_key );

			// Get tools
			$tools_enabled = ! empty( $task->tools_enabled ) 
				? ( is_string( $task->tools_enabled ) ? json_decode( $task->tools_enabled, true ) : $task->tools_enabled )
				: array();
			
			// If no specific tools selected (profile mode), use active profile tools
			if ( empty( $tools_enabled ) ) {
				global $stifliFlexMcp;
				$tools = ! empty( $stifliFlexMcp->model ) ? $stifliFlexMcp->model->getToolsList() : array();
				stifli_flex_mcp_log( '[Automation] execute_task_internal - Using active profile, got ' . count( $tools ) . ' tools' );
			} else {
				$tools = $this->get_tools_by_names( $tools_enabled );
				stifli_flex_mcp_log( '[Automation] execute_task_internal - Using custom tools: ' . count( $tools_enabled ) . ' requested, ' . count( $tools ) . ' found' );
			}

			// Prepare prompts
			$prompt = $task->prompt;
			$system_prompt = ! empty( $task->system_prompt ) 
				? $task->system_prompt 
				: ( $advanced_settings['system_prompt'] ?? __( 'You are an AI assistant with access to WordPress tools.', 'stifli-flex-mcp' ) );

			// Force sequential tool execution for reliability (same as AI Chat Agent)
			$system_prompt .= "\n\n" . __( 'IMPORTANT: When using tools, execute only ONE tool at a time. Wait for the result before deciding if you need another tool. Never call multiple tools in parallel.', 'stifli-flex-mcp' );

			// Prepare arguments
			$args = array(
				'api_key'       => $api_key,
				'model'         => $model,
				'message'       => $prompt,
				'conversation'  => array(),
				'tools'         => $tools,
				'system_prompt' => $system_prompt,
				'temperature'   => floatval( $advanced_settings['temperature'] ?? 0.7 ),
				'max_tokens'    => intval( $task->max_tokens ?? $advanced_settings['max_tokens'] ?? 4096 ),
				'top_p'         => floatval( $advanced_settings['top_p'] ?? 1.0 ),
			);

			// Get provider instance
			$provider_instance = $this->get_provider_instance( $provider );
			if ( is_wp_error( $provider_instance ) ) {
				throw new Exception( $provider_instance->get_error_message() );
			}

			// Run agentic loop (simplified - no log_id needed)
			$loop_result = $this->run_agentic_loop_internal( $provider_instance, $args );

			$result['response']       = $loop_result['response'] ?? '';
			$result['tools_executed'] = $loop_result['tools_called'] ?? array();
			$result['tokens_used']    = ( $loop_result['tokens']['input'] ?? 0 ) + ( $loop_result['tokens']['output'] ?? 0 );

		} catch ( Exception $e ) {
			$result['error'] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Run agentic loop internally (without logging)
	 *
	 * @param object $provider Provider instance.
	 * @param array  $args     Arguments for the provider.
	 * @return array Result.
	 */
	private function run_agentic_loop_internal( $provider, $args ) {
		$iteration      = 0;
		$tools_called   = array();
		$tools_results  = array();
		$final_response = '';
		$total_tokens   = array( 'input' => 0, 'output' => 0 );

		while ( $iteration < self::MAX_ITERATIONS ) {
			$response = $provider->send_message( $args );

			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message from WP_Error
				throw new Exception( $response->get_error_message() );
			}

			// Track tokens
			if ( isset( $response['usage'] ) ) {
				$total_tokens['input']  += $response['usage']['input_tokens'] ?? 0;
				$total_tokens['output'] += $response['usage']['output_tokens'] ?? 0;
			}

			// Extract tool calls
			$tool_calls = $this->extract_tool_calls( $response, $args['model'] );

			if ( empty( $tool_calls ) ) {
				$final_response = $this->extract_text_response( $response );
				break;
			}

			// Execute each tool call and collect ALL results
			$all_tool_results = array();
			foreach ( $tool_calls as $tool_call ) {
				$tool_name = $tool_call['name'];
				$tool_args = $tool_call['arguments'];

				$tools_called[] = array(
					'name'      => $tool_name,
					'arguments' => $tool_args,
				);

				$result = $this->execute_tool( $tool_name, $tool_args );
				$tools_results[ $tool_name ] = $result;

				$all_tool_results[] = $this->format_tool_result( $tool_call, $result, $args['model'] );
			}

			$args['tool_result'] = $all_tool_results;
			$args['message']     = '';

			$args['conversation'] = $this->build_conversation_context( $response, $tool_call, $tools_results );
			$iteration++;
		}

		return array(
			'response'     => $final_response,
			'tools_called' => $tools_called,
			'tokens'       => $total_tokens,
		);
	}

	/**
	 * Get tools by their names (returns MCP format for provider conversion)
	 *
	 * @param array $names Tool names.
	 * @return array Tools array in MCP format (with inputSchema).
	 */
	private function get_tools_by_names( $names ) {
		if ( empty( $names ) || ! is_array( $names ) ) {
			return array();
		}

		global $stifliFlexMcp;
		if ( ! $stifliFlexMcp || ! isset( $stifliFlexMcp->model ) ) {
			return array();
		}

		$model = $stifliFlexMcp->model;
		if ( ! $model ) {
			return array();
		}

		$all_tools = $model->getTools();
		$tools     = array();

		foreach ( $names as $name ) {
			if ( isset( $all_tools[ $name ] ) ) {
				// Return in MCP format (same as getToolsList) - providers will convert to their format
				$tools[] = array(
					'name'        => $all_tools[ $name ]['name'],
					'description' => $all_tools[ $name ]['description'],
					'inputSchema' => $all_tools[ $name ]['inputSchema'],
				);
			}
		}

		return $tools;
	}

	/**
	 * Run an agentic loop (prompt → tools → response)
	 *
	 * @param object $provider Provider instance.
	 * @param array  $args     Arguments for the provider.
	 * @param int    $log_id   Log entry ID.
	 * @return array Result with response and tools used.
	 */
	private function run_agentic_loop( $provider, $args, $log_id ) {
		$iteration      = 0;
		$tools_called   = array();
		$tools_results  = array();
		$final_response = '';
		$total_tokens   = array( 'input' => 0, 'output' => 0 );

		stifli_flex_mcp_log( '[Automation] run_agentic_loop - Starting loop' );

		while ( $iteration < self::MAX_ITERATIONS ) {
			stifli_flex_mcp_log( sprintf( '[Automation] run_agentic_loop - Iteration %d, conversation items: %d', $iteration, count( $args['conversation'] ?? array() ) ) );
			
			$response = $provider->send_message( $args );

			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message from WP_Error
				throw new Exception( $response->get_error_message() );
			}

			stifli_flex_mcp_log( '[Automation] run_agentic_loop - Response keys: ' . implode( ', ', array_keys( $response ) ) );

			// Track tokens if available
			if ( isset( $response['usage'] ) ) {
				$total_tokens['input']  += $response['usage']['input_tokens'] ?? 0;
				$total_tokens['output'] += $response['usage']['output_tokens'] ?? 0;
			}

			// Extract tool calls from response
			$tool_calls = $this->extract_tool_calls( $response, $args['model'] );

			if ( empty( $tool_calls ) ) {
				// No more tool calls - get final response
				$final_response = $this->extract_text_response( $response );
				break;
			}

			// Execute each tool call and collect ALL results
			$all_tool_results = array();
			foreach ( $tool_calls as $tool_call ) {
				$tool_name = $tool_call['name'];
				$tool_args = $tool_call['arguments'];

				stifli_flex_mcp_log( sprintf( '[Automation] run_agentic_loop - Executing tool: %s', $tool_name ) );

				$tools_called[] = array(
					'name'      => $tool_name,
					'arguments' => $tool_args,
					'iteration' => $iteration,
				);

				// Execute the tool
				$result = $this->execute_tool( $tool_name, $tool_args );
				$tools_results[ $tool_name ] = $result;

				// Collect formatted result for this tool call
				$all_tool_results[] = $this->format_tool_result( $tool_call, $result, $args['model'] );
			}

			// Pass ALL tool results (not just the last one) for the next API call
			$args['tool_result'] = $all_tool_results;
			$args['message']     = ''; // Clear message for continuation

			// Update conversation context from response
			$args['conversation'] = $this->build_conversation_context( $response, $tool_call, $tools_results );
			stifli_flex_mcp_log( sprintf( '[Automation] run_agentic_loop - Updated conversation, items: %d', count( $args['conversation'] ) ) );

			$iteration++;
		}

		// Update log with token usage
		$this->update_log_tokens( $log_id, $total_tokens, $tools_called, $tools_results );

		return array(
			'response'      => $final_response,
			'tools_called'  => $tools_called,
			'tools_results' => $tools_results,
			'iterations'    => $iteration,
			'tokens'        => $total_tokens,
		);
	}

	/**
	 * Extract tool calls from provider response
	 *
	 * @param array  $response Provider response.
	 * @param string $model    Model name.
	 * @return array Tool calls.
	 */
	private function extract_tool_calls( $response, $model ) {
		$tool_calls = array();

		stifli_flex_mcp_log( '[Automation] extract_tool_calls - response keys: ' . implode( ', ', array_keys( $response ) ) );

		// Parsed provider format (from our providers)
		if ( isset( $response['tool_calls'] ) && is_array( $response['tool_calls'] ) && ! empty( $response['tool_calls'] ) ) {
			stifli_flex_mcp_log( '[Automation] Found ' . count( $response['tool_calls'] ) . ' tool calls in parsed format' );
			return $response['tool_calls'];
		}

		// OpenAI Responses API format (raw)
		if ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
			foreach ( $response['output'] as $item ) {
				if ( isset( $item['type'] ) && 'function_call' === $item['type'] ) {
					$tool_calls[] = array(
						'name'      => $item['name'] ?? '',
						'arguments' => json_decode( $item['arguments'] ?? '{}', true ) ?: array(),
						'call_id'   => $item['call_id'] ?? '',
					);
				}
			}
		}

		// Claude format
		if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
			foreach ( $response['content'] as $item ) {
				if ( isset( $item['type'] ) && 'tool_use' === $item['type'] ) {
					$tool_calls[] = array(
						'name'        => $item['name'] ?? '',
						'arguments'   => $item['input'] ?? array(),
						'tool_use_id' => $item['id'] ?? '',
					);
				}
			}
		}

		// Gemini format
		if ( isset( $response['candidates'][0]['content']['parts'] ) ) {
			foreach ( $response['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['functionCall'] ) ) {
					$tool_calls[] = array(
						'name'      => $part['functionCall']['name'] ?? '',
						'arguments' => $part['functionCall']['args'] ?? array(),
					);
				}
			}
		}

		stifli_flex_mcp_log( '[Automation] extract_tool_calls - found ' . count( $tool_calls ) . ' tool calls' );
		return $tool_calls;
	}

	/**
	 * Extract text response from provider response
	 *
	 * @param array $response Provider response.
	 * @return string Text response.
	 */
	private function extract_text_response( $response ) {
		stifli_flex_mcp_log( '[Automation] extract_text_response - response keys: ' . implode( ', ', array_keys( $response ) ) );

		// Parsed provider format (from our providers) - check this FIRST
		if ( isset( $response['text'] ) && ! empty( $response['text'] ) ) {
			stifli_flex_mcp_log( '[Automation] Found text in parsed format: ' . substr( $response['text'], 0, 100 ) . '...' );
			return $response['text'];
		}

		// OpenAI Responses API (raw)
		if ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
			foreach ( $response['output'] as $item ) {
				if ( isset( $item['type'] ) && 'message' === $item['type'] ) {
					if ( isset( $item['content'][0]['text'] ) ) {
						stifli_flex_mcp_log( '[Automation] Found text in raw OpenAI output format' );
						return $item['content'][0]['text'];
					}
				}
			}
			// Also check for direct text in output_text
			if ( isset( $response['output_text'] ) ) {
				stifli_flex_mcp_log( '[Automation] Found output_text in raw OpenAI format' );
				return $response['output_text'];
			}
		}

		// Claude format
		if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
			foreach ( $response['content'] as $item ) {
				if ( isset( $item['type'] ) && 'text' === $item['type'] ) {
					stifli_flex_mcp_log( '[Automation] Found text in Claude format' );
					return $item['text'] ?? '';
				}
			}
		}

		// Gemini format
		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			stifli_flex_mcp_log( '[Automation] Found text in Gemini format' );
			return $response['candidates'][0]['content']['parts'][0]['text'];
		}

		stifli_flex_mcp_log( '[Automation] No text found in response. Full response: ' . wp_json_encode( $response ) );
		return '';
	}

	/**
	 * Execute a single tool via MCP
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array Tool result.
	 */
	private function execute_tool( $tool_name, $arguments ) {
		global $stifliFlexMcp;

		// Normalize arguments: json_decode('{}') returns stdClass, dispatchTool expects array
		$arguments = $this->normalize_args( $arguments );

		stifli_flex_mcp_log( '[Automation] execute_tool - START ' . $tool_name . ' args=' . wp_json_encode( $arguments ) );

		if ( ! isset( $stifliFlexMcp ) || ! isset( $stifliFlexMcp->model ) ) {
			return array(
				'error'   => true,
				'message' => __( 'MCP model not available', 'stifli-flex-mcp' ),
			);
		}

		if ( class_exists( 'StifliFlexMcp_ChangeTracker' ) ) {
			StifliFlexMcp_ChangeTracker::setSourceContext( 'automation', 'Automation Task' );
		}

		$result = $stifliFlexMcp->model->dispatchTool( $tool_name, $arguments, null );

		stifli_flex_mcp_log( '[Automation] execute_tool - END ' . $tool_name . ' error=' . ( isset( $result['error'] ) ? 'yes' : 'no' ) );

		if ( isset( $result['error'] ) ) {
			return array(
				'error'   => true,
				'message' => $result['error']['message'] ?? __( 'Tool execution failed', 'stifli-flex-mcp' ),
			);
		}

		// Extract content
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
	 * Recursively normalize arguments from stdClass to array.
	 *
	 * json_decode('{}') returns stdClass which crashes dispatchTool.
	 * This ensures all nested objects are converted to associative arrays.
	 *
	 * @param mixed $args Arguments to normalize.
	 * @return array Normalized arguments.
	 */
	private function normalize_args( $args ) {
		if ( $args instanceof stdClass ) {
			$args = (array) $args;
		}
		if ( ! is_array( $args ) ) {
			return array();
		}
		foreach ( $args as $key => $value ) {
			if ( $value instanceof stdClass || is_array( $value ) ) {
				$args[ $key ] = $this->normalize_args( $value );
			}
		}
		return $args;
	}

	/**
	 * Format tool result for the provider
	 *
	 * @param array  $tool_call Tool call data.
	 * @param array  $result    Tool result.
	 * @param string $model     Model name.
	 * @return array Formatted result.
	 */
	private function format_tool_result( $tool_call, $result, $model ) {
		// OpenAI format
		if ( isset( $tool_call['call_id'] ) ) {
			return array(
				'call_id' => $tool_call['call_id'],
				'output'  => $result,
			);
		}

		// Claude format
		if ( isset( $tool_call['tool_use_id'] ) ) {
			return array(
				'tool_use_id' => $tool_call['tool_use_id'],
				'output'      => $result,
			);
		}

		// Gemini format
		return array(
			'name'   => $tool_call['name'],
			'output' => $result,
		);
	}

	/**
	 * Build conversation context for next iteration
	 *
	 * @param array $response     Provider response.
	 * @param array $tool_call    Tool call data.
	 * @param array $tool_results Tool results.
	 * @return array Conversation context.
	 */
	private function build_conversation_context( $response, $tool_call, $tool_results ) {
		// Use conversation from parsed response if available (contains properly formatted history)
		if ( isset( $response['conversation'] ) && is_array( $response['conversation'] ) ) {
			return $response['conversation'];
		}
		
		// Fallback: return empty array to avoid invalid format
		return array();
	}

	/**
	 * Get tools allowed for a task
	 *
	 * @param object $task Task object.
	 * @return array Tools.
	 */
	private function get_task_tools( $task ) {
		global $stifliFlexMcp;

		// If task has allowed_tools JSON configuration
		if ( ! empty( $task->allowed_tools ) ) {
			$tools_config = json_decode( $task->allowed_tools, true );
			
			// New JSON format with mode, custom, detected
			if ( is_array( $tools_config ) && isset( $tools_config['mode'] ) ) {
				$mode = $tools_config['mode'];
				
				if ( 'profile' === $mode ) {
					// Use enabled tools from active profile
					return $stifliFlexMcp->model->getToolsList();
				}
				
				if ( 'detected' === $mode && ! empty( $tools_config['detected'] ) ) {
					// Use only detected tools from test
					$allowed = $tools_config['detected'];
				} elseif ( 'custom' === $mode && ! empty( $tools_config['custom'] ) ) {
					// Use custom selected tools
					$allowed = $tools_config['custom'];
				} else {
					// Fallback to profile
					return $stifliFlexMcp->model->getToolsList();
				}
				
				// Filter full tool definitions by allowed names
				$all_tools = $stifliFlexMcp->model->getTools();
				$filtered  = array();
				foreach ( $all_tools as $tool ) {
					if ( in_array( $tool['name'], $allowed, true ) ) {
						$filtered[] = $tool;
					}
				}
				return $filtered;
			}
			
			// Legacy format: direct array of tool names
			if ( is_array( $tools_config ) && ! empty( $tools_config ) && ! isset( $tools_config['mode'] ) ) {
				$all_tools = $stifliFlexMcp->model->getTools();
				$filtered  = array();
				foreach ( $all_tools as $tool ) {
					if ( in_array( $tool['name'], $tools_config, true ) ) {
						$filtered[] = $tool;
					}
				}
				return $filtered;
			}
		}

		// Fall back to enabled tools from profile
		return $stifliFlexMcp->model->getToolsList();
	}

	/**
	 * Process prompt variables
	 *
	 * @param string $prompt The prompt with variables.
	 * @return string Processed prompt.
	 */
	private function process_prompt_variables( $prompt ) {
		$replacements = array(
			'{site_name}'    => get_bloginfo( 'name' ),
			'{site_url}'     => home_url(),
			'{admin_email}'  => get_option( 'admin_email' ),
			'{date}'         => wp_date( 'Y-m-d' ),
			'{time}'         => wp_date( 'H:i:s' ),
			'{datetime}'     => wp_date( 'Y-m-d H:i:s' ),
			'{day_of_week}'  => wp_date( 'l' ),
			'{month}'        => wp_date( 'F' ),
			'{year}'         => wp_date( 'Y' ),
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $prompt );
	}

	/**
	 * Get provider instance
	 *
	 * @param string $provider Provider name.
	 * @return object|WP_Error Provider instance or error.
	 */
	private function get_provider_instance( $provider ) {
		$provider_dir = plugin_dir_path( __FILE__ ) . 'providers/';

		switch ( $provider ) {
			case 'openai':
				require_once $provider_dir . 'class-provider-openai.php';
				return new StifliFlexMcp_Client_OpenAI();

			case 'claude':
				require_once $provider_dir . 'class-provider-claude.php';
				return new StifliFlexMcp_Client_Claude();

			case 'gemini':
				require_once $provider_dir . 'class-provider-gemini.php';
				return new StifliFlexMcp_Client_Gemini();

			default:
				return new WP_Error( 'invalid_provider', __( 'Invalid AI provider', 'stifli-flex-mcp' ) );
		}
	}

	/**
	 * Decrypt API key
	 *
	 * @param string $encrypted Encrypted API key.
	 * @return string Decrypted API key.
	 */
	private function decrypt_api_key( $encrypted ) {
		if ( empty( $encrypted ) || strpos( $encrypted, ':' ) === false ) {
			return $encrypted;
		}

		$parts = explode( ':', $encrypted, 2 );
		if ( count( $parts ) !== 2 ) {
			return $encrypted;
		}

		$iv         = base64_decode( $parts[0] );
		$ciphertext = $parts[1];
		$key        = hash( 'sha256', wp_salt( 'auth' ), true );

		$decrypted = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, 0, $iv );
		if ( false === $decrypted ) {
			return $encrypted;
		}

		return $decrypted;
	}

	/**
	 * Create a log entry for task execution
	 *
	 * @param int    $task_id Task ID.
	 * @param string $prompt  Prompt used.
	 * @return int Log entry ID.
	 */
	private function create_log_entry( $task_id, $prompt ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sflmcp_automation_logs';
		$now   = current_time( 'mysql', true );

		stifli_flex_mcp_log( sprintf( '[Automation] create_log_entry - Creating log for task %d', $task_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'task_id'     => $task_id,
				'started_at'  => $now,
				'status'      => 'running',
				'prompt_used' => $prompt,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			stifli_flex_mcp_log( sprintf( '[Automation] create_log_entry - DB error: %s', $wpdb->last_error ) );
			return 0;
		}

		$log_id = $wpdb->insert_id;
		stifli_flex_mcp_log( sprintf( '[Automation] create_log_entry - Created log ID: %d', $log_id ) );

		return $log_id;
	}

	/**
	 * Complete a log entry
	 *
	 * @param int    $log_id         Log ID.
	 * @param string $status         Status (success, error).
	 * @param array  $result         Execution result.
	 * @param float  $execution_time Execution time in ms.
	 * @param string $error_message  Error message if any.
	 */
	private function complete_log_entry( $log_id, $status, $result, $execution_time, $error_message = '' ) {
		global $wpdb;

		if ( empty( $log_id ) ) {
			stifli_flex_mcp_log( '[Automation] complete_log_entry - No log_id provided, skipping' );
			return;
		}

		$table = $wpdb->prefix . 'sflmcp_automation_logs';
		$now   = current_time( 'mysql', true );

		// Build data and formats dynamically
		$data    = array();
		$formats = array();

		// Always set these fields
		$data['completed_at']      = $now;
		$formats[]                 = '%s';
		$data['status']            = $status;
		$formats[]                 = '%s';
		$data['execution_time_ms'] = intval( $execution_time );
		$formats[]                 = '%d';

		if ( 'success' === $status && $result ) {
			$data['ai_response']   = $result['response'] ?? '';
			$formats[]             = '%s';
			$data['tools_called']  = wp_json_encode( $result['tools_called'] ?? array() );
			$formats[]             = '%s';
			$data['tools_results'] = wp_json_encode( $result['tools_results'] ?? array() );
			$formats[]             = '%s';
		}

		if ( ! empty( $error_message ) ) {
			$data['error_message'] = $error_message;
			$formats[]             = '%s';
		}

		stifli_flex_mcp_log( sprintf( '[Automation] complete_log_entry - Updating log %d with status: %s, fields: %d', $log_id, $status, count( $data ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $log_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $updated ) {
			stifli_flex_mcp_log( sprintf( '[Automation] complete_log_entry - DB error: %s', $wpdb->last_error ) );
		} else {
			stifli_flex_mcp_log( sprintf( '[Automation] complete_log_entry - Updated %d rows', $updated ) );
		}
	}

	/**
	 * Update log with token usage
	 *
	 * @param int   $log_id        Log ID.
	 * @param array $tokens        Token counts.
	 * @param array $tools_called  Tools called.
	 * @param array $tools_results Tool results.
	 */
	private function update_log_tokens( $log_id, $tokens, $tools_called, $tools_results ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sflmcp_automation_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'tokens_input'  => $tokens['input'],
				'tokens_output' => $tokens['output'],
				'tools_called'  => wp_json_encode( $tools_called ),
				'tools_results' => wp_json_encode( $tools_results ),
			),
			array( 'id' => $log_id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get total tokens used by a task in the current calendar month.
	 *
	 * @param int $task_id Task ID.
	 * @return int Total tokens (input + output) used this month.
	 */
	private function get_monthly_tokens_used( $task_id ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'sflmcp_automation_logs';
		$period_start = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$spent = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(tokens_input), 0) + COALESCE(SUM(tokens_output), 0)
			 FROM {$table}
			 WHERE task_id = %d AND status = 'success' AND started_at >= %s",
			$task_id,
			$period_start
		) );

		return intval( $spent );
	}

	/**
	 * Update task after successful execution
	 *
	 * @param object $task Task object.
	 */
	private function update_task_success( $task ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'sflmcp_automation_tasks';
		$now     = current_time( 'mysql', true );
		$next    = $this->calculate_next_run( $task );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'last_run'     => $now,
				'last_success' => $now,
				'next_run'     => $next,
				'retry_count'  => 0,
				'last_error'   => null,
				'updated_at'   => $now,
			),
			array( 'id' => $task->id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update task after failed execution
	 *
	 * @param object $task  Task object.
	 * @param string $error Error message.
	 */
	private function update_task_error( $task, $error ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'sflmcp_automation_tasks';
		$now         = current_time( 'mysql', true );
		$retry_count = intval( $task->retry_count ) + 1;
		$max_retries = intval( $task->max_retries ) ?: 3;

		$data = array(
			'last_run'    => $now,
			'last_error'  => $error,
			'retry_count' => $retry_count,
			'updated_at'  => $now,
		);

		// If max retries reached, pause the task
		if ( $retry_count >= $max_retries ) {
			$data['status'] = 'error';
			// Also set next_run to the correct scheduled time so when re-enabled
			// it resumes on the proper schedule, not from a stale past date.
			$data['next_run'] = $this->calculate_next_run( $task );
		} else {
			// Schedule retry: use the later of +5 minutes or the correct scheduled next_run.
			// This prevents retries from overwriting a correct far-future next_run
			// (e.g., weekly schedule) that was already set by a concurrent success.
			$retry_time    = gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS );
			$scheduled     = $this->calculate_next_run( $task );
			$data['next_run'] = ( strtotime( $retry_time ) < strtotime( $scheduled ) )
				? $retry_time
				: $scheduled;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			$data,
			array( 'id' => $task->id )
		);
	}

	/**
	 * Calculate next run time based on schedule
	 *
	 * @param object $task Task object.
	 * @return string Next run datetime.
	 */
	public function calculate_next_run( $task ) {
		$preset = $task->schedule_preset ?? 'daily_morning';
		$time   = $task->schedule_time ?? '08:00';
		$tz     = $task->schedule_timezone ?? wp_timezone_string();

		// Get preset configuration
		$presets = self::SCHEDULE_PRESETS;
		$config  = $presets[ $preset ] ?? $presets['daily_morning'];

		$timezone = new DateTimeZone( $tz );
		$now      = new DateTime( 'now', $timezone );

		// Handle different preset types
		if ( strpos( $preset, 'every_' ) === 0 ) {
			// Interval-based (hourly schedules)
			$interval = $config['interval'];
			$next     = new DateTime( 'now', $timezone );
			$next->add( new DateInterval( 'PT' . $interval . 'S' ) );
		} elseif ( strpos( $preset, 'daily' ) === 0 ) {
			// Daily at specific time
			$parts = explode( ':', $time );
			$hour  = intval( $parts[0] ?? 8 );
			$min   = intval( $parts[1] ?? 0 );

			$next = new DateTime( 'today', $timezone );
			$next->setTime( $hour, $min );

			if ( $next <= $now ) {
				$next->add( new DateInterval( 'P1D' ) );
			}
		} elseif ( strpos( $preset, 'weekly' ) === 0 ) {
			// Weekly on specific day
			$day   = $config['day'] ?? 1;
			$parts = explode( ':', $config['time'] ?? '09:00' );
			$hour  = intval( $parts[0] ?? 9 );
			$min   = intval( $parts[1] ?? 0 );

			$days = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
			$next = new DateTime( 'next ' . $days[ $day ], $timezone );
			$next->setTime( $hour, $min );

			if ( $next <= $now ) {
				$next->add( new DateInterval( 'P7D' ) );
			}
		} elseif ( strpos( $preset, 'monthly' ) === 0 ) {
			// Monthly on specific day
			$day_of_month = $config['day_of_month'] ?? 1;
			$parts        = explode( ':', $config['time'] ?? '09:00' );
			$hour         = intval( $parts[0] ?? 9 );
			$min          = intval( $parts[1] ?? 0 );

			$next = new DateTime( 'first day of next month', $timezone );
			$next->setDate( $next->format( 'Y' ), $next->format( 'm' ), $day_of_month );
			$next->setTime( $hour, $min );

			// If day doesn't exist in month, use last day
			if ( $next->format( 'd' ) != $day_of_month ) {
				$next = new DateTime( 'last day of next month', $timezone );
				$next->setTime( $hour, $min );
			}
		} else {
			// Default: tomorrow at 8am
			$next = new DateTime( 'tomorrow 08:00', $timezone );
		}

		// Convert to UTC for storage
		$next->setTimezone( new DateTimeZone( 'UTC' ) );
		return $next->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Execute output actions for a task
	 *
	 * @param object $task   Task object.
	 * @param array  $result Execution result.
	 */
	private function execute_output_actions( $task, $result ) {
		$action = $task->output_action ?? 'log';
		$config = json_decode( $task->output_config ?? '{}', true ) ?: array();

		switch ( $action ) {
			case 'email':
				$this->send_email_output( $task, $result, $config );
				break;

			case 'webhook':
				$this->send_webhook_output( $task, $result, $config );
				break;

			case 'draft':
				$this->create_draft_post( $task, $result, $config );
				break;

			case 'custom':
				do_action( 'sflmcp_automation_custom_output', $task, $result, $config );
				break;

			case 'log':
			default:
				// Already logged in execute_task
				break;
		}
	}

	/**
	 * Send email with task output
	 *
	 * @param object $task   Task object.
	 * @param array  $result Execution result.
	 * @param array  $config Email configuration.
	 */
	private function send_email_output( $task, $result, $config ) {
		$recipients = $config['recipients'] ?? get_option( 'admin_email' );
		if ( is_string( $recipients ) ) {
			$recipients = str_replace( '{admin_email}', get_option( 'admin_email' ), $recipients );
		}

		$subject_template = $config['subject_template'] ?? '[{site_name}] Task: {task_name}';
		$subject = str_replace(
			array( '{site_name}', '{task_name}', '{date}' ),
			array( get_bloginfo( 'name' ), $task->task_name, wp_date( 'Y-m-d' ) ),
			$subject_template
		);

		$body = sprintf(
			/* translators: 1: Task name, 2: AI response */
			__( "Automation Task: %1\$s\n\n%2\$s", 'stifli-flex-mcp' ),
			$task->task_name,
			$result['response'] ?? ''
		);

		if ( ! empty( $config['include_log'] ) ) {
			$body .= "\n\n---\n";
			$body .= sprintf(
				/* translators: 1: Number of tools, 2: Execution iterations */
				__( "Tools executed: %1\$d\nIterations: %2\$d", 'stifli-flex-mcp' ),
				count( $result['tools_called'] ?? array() ),
				$result['iterations'] ?? 0
			);
		}

		wp_mail( $recipients, $subject, $body );
	}

	/**
	 * Send webhook with task output
	 *
	 * @param object $task   Task object.
	 * @param array  $result Execution result.
	 * @param array  $config Webhook configuration.
	 */
	private function send_webhook_output( $task, $result, $config ) {
		$url = $config['url'] ?? '';
		if ( empty( $url ) ) {
			return;
		}

		$payload_template = $config['payload_template'] ?? '{"task": "{task_name}", "result": "{result}"}';
		$payload = str_replace(
			array( '{task_name}', '{task_id}', '{result}', '{timestamp}', '{status}' ),
			array(
				$task->task_name,
				$task->id,
				$result['response'] ?? '',
				wp_date( 'c' ),
				'success',
			),
			$payload_template
		);

		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $config['headers'] ) ) {
			$extra_headers = json_decode( $config['headers'], true );
			if ( is_array( $extra_headers ) ) {
				$headers = array_merge( $headers, $extra_headers );
			}
		}

		wp_remote_post( $url, array(
			'headers' => $headers,
			'body'    => $payload,
			'timeout' => 30,
		) );
	}

	/**
	 * Create a draft post with task output
	 *
	 * @param object $task   Task object.
	 * @param array  $result Execution result.
	 * @param array  $config Post configuration.
	 */
	private function create_draft_post( $task, $result, $config ) {
		$title_template = $config['title_template'] ?? '{task_name} - {date}';
		$title = str_replace(
			array( '{task_name}', '{date}' ),
			array( $task->task_name, wp_date( 'Y-m-d' ) ),
			$title_template
		);

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $result['response'] ?? '',
			'post_status'  => 'draft',
			'post_type'    => $config['post_type'] ?? 'post',
			'post_author'  => $task->created_by ?? get_current_user_id(),
		);

		if ( ! empty( $config['category'] ) ) {
			$post_data['post_category'] = array( intval( $config['category'] ) );
		}

		wp_insert_post( $post_data );
	}

	/**
	 * Run a task immediately (for testing)
	 *
	 * @param int $task_id Task ID.
	 * @return array Execution result.
	 */
	public function run_task_now( $task_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'sflmcp_automation_tasks';
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$task = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$task_id
		) );

		if ( ! $task ) {
			return array(
				'success' => false,
				'error'   => __( 'Task not found', 'stifli-flex-mcp' ),
			);
		}

		return $this->execute_task( $task );
	}

	/**
	 * Test a prompt without saving (for preview)
	 *
	 * @param array $args Prompt arguments.
	 * @return array Test result with tools detected.
	 */
	public function test_prompt( $args ) {
		$start_time = microtime( true );
		$tools_used = array();
		$steps      = array(); // Track intermediate steps for display

		try {
			// Get settings from AI Chat Agent (centralized config)
			$client_settings   = get_option( 'sflmcp_client_settings', array() );
			$advanced_settings = get_option( 'sflmcp_client_settings_advanced', array() );
			$api_key           = $this->decrypt_api_key( $client_settings['api_key'] ?? '' );

			if ( empty( $api_key ) ) {
				throw new Exception( __( 'API key not configured in AI Chat Agent. Please configure it first.', 'stifli-flex-mcp' ) );
			}

			// Always use AI Chat Agent provider/model
			$provider = $client_settings['provider'] ?? 'openai';
			$model    = $client_settings['model'] ?? 'gpt-5.2-chat-latest';

			// Get all available tools for testing
			global $stifliFlexMcp;
			$tools = $stifliFlexMcp->model->getToolsList();

			// Force sequential tool execution for reliability (same as AI Chat Agent)
			$system_prompt = $args['system_prompt'] ?? '';
			$system_prompt .= "\n\n" . __( 'IMPORTANT: When using tools, execute only ONE tool at a time. Wait for the result before deciding if you need another tool. Never call multiple tools in parallel.', 'stifli-flex-mcp' );

			$provider_args = array(
				'api_key'       => $api_key,
				'model'         => $model,
				'message'       => $args['prompt'],
				'conversation'  => array(),
				'tools'         => $tools,
				'system_prompt' => $system_prompt,
				'temperature'   => 0.7,
				'max_tokens'    => 4096,
			);

			// Get provider instance
			$provider_instance = $this->get_provider_instance( $provider );
			if ( is_wp_error( $provider_instance ) ) {
				throw new Exception( $provider_instance->get_error_message() );
			}

			stifli_flex_mcp_log( '[Automation] test_prompt - Starting with provider=' . $provider . ' model=' . $model );
			stifli_flex_mcp_log( '[Automation] test_prompt - Prompt: ' . substr( $args['prompt'], 0, 100 ) . '...' );

			// Run loop - increased to 10 iterations to handle longer tool chains
			$iteration           = 0;
			$max_test_iterations = 10;
			$final_response      = '';
			$has_pending_tool    = false;

			while ( $iteration < $max_test_iterations ) {
				stifli_flex_mcp_log( '[Automation] test_prompt - Iteration ' . $iteration );

				$response = $provider_instance->send_message( $provider_args );

				if ( is_wp_error( $response ) ) {
					throw new Exception( $response->get_error_message() );
				}

				stifli_flex_mcp_log( '[Automation] test_prompt - Response received, keys: ' . implode( ', ', array_keys( $response ) ) );

				$tool_calls = $this->extract_tool_calls( $response, $model );

				// Capture any text from this response (intermediate thinking)
				$intermediate_text = $this->extract_text_response( $response );
				if ( ! empty( $intermediate_text ) ) {
					$steps[] = array(
						'type'    => 'text',
						'content' => $intermediate_text,
					);
					stifli_flex_mcp_log( '[Automation] test_prompt - Intermediate text: ' . substr( $intermediate_text, 0, 100 ) . '...' );
				}

				if ( empty( $tool_calls ) ) {
					stifli_flex_mcp_log( '[Automation] test_prompt - No tool calls, final response' );
					$final_response = $intermediate_text;
					$has_pending_tool = false;
					break;
				}

				stifli_flex_mcp_log( '[Automation] test_prompt - Found ' . count( $tool_calls ) . ' tool calls' );

				$all_tool_results = array();
				foreach ( $tool_calls as $tool_call ) {
					$tool_name = $tool_call['name'];
					stifli_flex_mcp_log( '[Automation] test_prompt - Executing tool: ' . $tool_name );

					if ( ! in_array( $tool_name, $tools_used, true ) ) {
						$tools_used[] = $tool_name;
					}

					// Execute tool
					$result = $this->execute_tool( $tool_name, $tool_call['arguments'] );

					// Track step
					$steps[] = array(
						'type'      => 'tool',
						'name'      => $tool_name,
						'arguments' => $tool_call['arguments'],
						'result'    => is_string( $result ) ? substr( $result, 0, 500 ) : wp_json_encode( $result ),
					);

					$all_tool_results[] = $this->format_tool_result( $tool_call, $result, $model );

					$has_pending_tool = true;
				}

				// Pass ALL tool results for next iteration
				$provider_args['tool_result'] = $all_tool_results;
				$provider_args['message']     = '';

				// Update conversation from response
				if ( isset( $response['conversation'] ) ) {
					$provider_args['conversation'] = $response['conversation'];
				}

				$iteration++;
			}

			// If we exited the loop with a pending tool result, make one more API call
			if ( $has_pending_tool && ! empty( $provider_args['tool_result'] ) ) {
				stifli_flex_mcp_log( '[Automation] test_prompt - Making final API call after tool execution' );

				$response = $provider_instance->send_message( $provider_args );

				if ( ! is_wp_error( $response ) ) {
					$final_response = $this->extract_text_response( $response );
					if ( ! empty( $final_response ) ) {
						$steps[] = array(
							'type'    => 'text',
							'content' => $final_response,
						);
					}
					stifli_flex_mcp_log( '[Automation] test_prompt - Final response after tool: ' . strlen( $final_response ) . ' chars' );
				}
			}

			stifli_flex_mcp_log( '[Automation] test_prompt - Loop ended. Iterations: ' . $iteration . ', Final response length: ' . strlen( $final_response ) );
			stifli_flex_mcp_log( '[Automation] test_prompt - Tools used: ' . implode( ', ', $tools_used ) );

			$execution_time = ( microtime( true ) - $start_time ) * 1000;

			// Estimate token savings
			$all_tools_count    = count( $tools );
			$detected_count     = count( $tools_used );
			$estimated_savings  = ( $all_tools_count - $detected_count ) * 25; // ~25 tokens per tool

			return array(
				'success'         => true,
				'response'        => $final_response,
				'steps'           => $steps, // Intermediate steps for display
				'tools_detected'  => $tools_used,
				'tools_count'     => $detected_count,
				'iterations'      => $iteration,
				'execution_time'  => round( $execution_time ),
				'token_savings'   => $estimated_savings,
				'suggestion'      => $detected_count < 10
					? sprintf(
						/* translators: 1: Number of tools, 2: Estimated token savings */
						__( 'This task uses only %1$d tools. Saving just these tools will save ~%2$d tokens per execution.', 'stifli-flex-mcp' ),
						$detected_count,
						$estimated_savings
					)
					: '',
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Start step-based test prompt - initializes session and gets first response
	 *
	 * @param array $args Arguments with prompt and system_prompt.
	 * @return array Result with session_id, tool_calls or text, and finished flag.
	 */
	public function test_prompt_start( $args ) {
		try {
			// Get settings from AI Chat Agent
			$client_settings = get_option( 'sflmcp_client_settings', array() );
			$api_key         = $this->decrypt_api_key( $client_settings['api_key'] ?? '' );

			if ( empty( $api_key ) ) {
				throw new Exception( __( 'API key not configured in AI Chat Agent.', 'stifli-flex-mcp' ) );
			}

			$provider = $client_settings['provider'] ?? 'openai';
			$model    = $client_settings['model'] ?? 'gpt-5.2-chat-latest';

			// Get tools
			global $stifliFlexMcp;
			$tools = $stifliFlexMcp->model->getToolsList();

			// Create session
			$session_id = wp_generate_uuid4();

			// Force sequential tool execution for reliability (same as AI Chat Agent)
			$system_prompt = $args['system_prompt'] ?? '';
			$system_prompt .= "\n\n" . __( 'IMPORTANT: When using tools, execute only ONE tool at a time. Wait for the result before deciding if you need another tool. Never call multiple tools in parallel.', 'stifli-flex-mcp' );

			$provider_args = array(
				'api_key'       => $api_key,
				'model'         => $model,
				'message'       => $args['prompt'],
				'conversation'  => array(),
				'tools'         => $tools,
				'system_prompt' => $system_prompt,
				'temperature'   => 0.7,
				'max_tokens'    => 4096,
			);

			// Get provider instance
			$provider_instance = $this->get_provider_instance( $provider );
			if ( is_wp_error( $provider_instance ) ) {
				throw new Exception( $provider_instance->get_error_message() );
			}

			stifli_flex_mcp_log( '[Automation] test_start - Session ' . $session_id . ' provider=' . $provider );

			// Send first message
			$response = $provider_instance->send_message( $provider_args );

			if ( is_wp_error( $response ) ) {
				throw new Exception( $response->get_error_message() );
			}

			// Extract tool calls
			$tool_calls = $this->extract_tool_calls( $response, $model );
			$text       = $this->extract_text_response( $response );

			// Store session state
			$session_data = array(
				'provider'        => $provider,
				'model'           => $model,
				'api_key'         => $api_key,
				'tools'           => $tools,
				'system_prompt'   => $args['system_prompt'] ?? '',
				'conversation'    => $response['conversation'] ?? array(),
				'pending_tools'   => $tool_calls,
				'tools_used'      => array(),
				'iteration'       => 0,
				'start_time'      => microtime( true ),
			);
			set_transient( 'sflmcp_test_session_' . $session_id, $session_data, 300 ); // 5 min expiry

			// If no tool calls, we're done
			if ( empty( $tool_calls ) ) {
				delete_transient( 'sflmcp_test_session_' . $session_id );
				return array(
					'success'    => true,
					'session_id' => $session_id,
					'finished'   => true,
					'text'       => $text,
					'tool_calls' => array(),
					'tools_used' => array(),
				);
			}

			return array(
				'success'    => true,
				'session_id' => $session_id,
				'finished'   => false,
				'text'       => $text,
				'tool_calls' => array_map( function( $tc ) {
					return array(
						'name'      => $tc['name'],
						'arguments' => $tc['arguments'],
					);
				}, $tool_calls ),
				'tools_used' => array(),
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Execute one step of test prompt - runs pending tools and gets next response
	 *
	 * @param string $session_id Session ID from test_prompt_start.
	 * @return array Result with tool results, next tool_calls or final text.
	 */
	public function test_prompt_step( $session_id ) {
		try {
			// Get session data
			$session_data = get_transient( 'sflmcp_test_session_' . $session_id );
			if ( ! $session_data ) {
				throw new Exception( __( 'Test session expired or not found.', 'stifli-flex-mcp' ) );
			}

			$pending_tools = $session_data['pending_tools'] ?? array();
			if ( empty( $pending_tools ) ) {
				delete_transient( 'sflmcp_test_session_' . $session_id );
				return array(
					'success'  => true,
					'finished' => true,
					'text'     => '',
					'tools_used' => $session_data['tools_used'],
				);
			}

			// Get provider instance
			$provider_instance = $this->get_provider_instance( $session_data['provider'] );
			if ( is_wp_error( $provider_instance ) ) {
				throw new Exception( $provider_instance->get_error_message() );
			}

			// Execute all pending tools and collect results
			$tool_results = array();
			$tools_executed = array();

			foreach ( $pending_tools as $tool_call ) {
				$tool_name = $tool_call['name'];

				stifli_flex_mcp_log( '[Automation] test_step - Executing: ' . $tool_name . ' args=' . wp_json_encode( $tool_call['arguments'] ) );

				// Track tool usage
				if ( ! in_array( $tool_name, $session_data['tools_used'], true ) ) {
					$session_data['tools_used'][] = $tool_name;
				}

				// Execute tool
				$result = $this->execute_tool( $tool_name, $tool_call['arguments'] );

				stifli_flex_mcp_log( '[Automation] test_step - Tool result for ' . $tool_name . ': ' . substr( wp_json_encode( $result ), 0, 300 ) );

				$tools_executed[] = array(
					'name'      => $tool_name,
					'arguments' => $tool_call['arguments'],
					'result'    => is_string( $result ) ? substr( $result, 0, 500 ) : wp_json_encode( $result ),
				);

				// Format tool result for provider
				$tool_results[] = $this->format_tool_result( $tool_call, $result, $session_data['model'] );
			}

			stifli_flex_mcp_log( '[Automation] test_step - All tools executed, sending ' . count( $tool_results ) . ' results to LLM' );

			// Send tool results to LLM
			$provider_args = array(
				'api_key'       => $session_data['api_key'],
				'model'         => $session_data['model'],
				'message'       => '',
				'conversation'  => $session_data['conversation'],
				'tools'         => $session_data['tools'],
				'system_prompt' => $session_data['system_prompt'],
				'temperature'   => 0.7,
				'max_tokens'    => 4096,
				'tool_result'   => count( $tool_results ) === 1 ? $tool_results[0] : $tool_results,
			);

			stifli_flex_mcp_log( '[Automation] test_step - Calling LLM with conversation length=' . count( $provider_args['conversation'] ) );

			$response = $provider_instance->send_message( $provider_args );

			if ( is_wp_error( $response ) ) {
				stifli_flex_mcp_log( '[Automation] test_step - LLM error: ' . $response->get_error_message() );
				throw new Exception( $response->get_error_message() );
			}

			stifli_flex_mcp_log( '[Automation] test_step - LLM response received, keys: ' . implode( ', ', array_keys( $response ) ) );

			// Extract next tool calls
			$next_tool_calls = $this->extract_tool_calls( $response, $session_data['model'] );
			$text            = $this->extract_text_response( $response );

			stifli_flex_mcp_log( '[Automation] test_step - Next tool_calls: ' . count( $next_tool_calls ) . ', text length: ' . strlen( $text ) );

			// Update session
			$session_data['conversation']  = $response['conversation'] ?? $session_data['conversation'];
			$session_data['pending_tools'] = $next_tool_calls;
			$session_data['iteration']++;

			// Check iteration limit
			if ( $session_data['iteration'] >= 10 ) {
				delete_transient( 'sflmcp_test_session_' . $session_id );
				return array(
					'success'         => true,
					'finished'        => true,
					'text'            => $text ?: __( 'Maximum iterations reached.', 'stifli-flex-mcp' ),
					'tools_executed'  => $tools_executed,
					'tools_used'      => $session_data['tools_used'],
					'execution_time'  => round( ( microtime( true ) - $session_data['start_time'] ) * 1000 ),
				);
			}

			// If no more tool calls, we're done
			if ( empty( $next_tool_calls ) ) {
				delete_transient( 'sflmcp_test_session_' . $session_id );
				return array(
					'success'         => true,
					'finished'        => true,
					'text'            => $text,
					'tools_executed'  => $tools_executed,
					'tools_used'      => $session_data['tools_used'],
					'execution_time'  => round( ( microtime( true ) - $session_data['start_time'] ) * 1000 ),
				);
			}

			// Save session for next step
			set_transient( 'sflmcp_test_session_' . $session_id, $session_data, 300 );

			return array(
				'success'        => true,
				'finished'       => false,
				'text'           => $text,
				'tools_executed' => $tools_executed,
				'tool_calls'     => array_map( function( $tc ) {
					return array(
						'name'      => $tc['name'],
						'arguments' => $tc['arguments'],
					);
				}, $next_tool_calls ),
				'tools_used'     => $session_data['tools_used'],
			);

		} catch ( Exception $e ) {
			delete_transient( 'sflmcp_test_session_' . $session_id );
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Get schedule presets
	 *
	 * @return array Schedule presets.
	 */
	public static function get_schedule_presets() {
		return apply_filters( 'sflmcp_automation_schedule_presets', self::SCHEDULE_PRESETS );
	}
}
