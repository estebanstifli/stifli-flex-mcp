/**
 * StifLi Flex MCP - Automation Tasks JavaScript
 *
 * @package StifliFlexMcp
 * @since 2.1.0
 */

(function($) {
	'use strict';

	// Module state
	const state = {
		currentTab: 'tasks',
		selectedTools: [],
		detectedTools: [],
		tasks: [],
		logs: []
	};

	// DOM ready
	$(document).ready(function() {
		initializePage();
		bindEvents();
	});

	/**
	 * Initialize page based on current tab
	 */
	function initializePage() {
		// Determine current tab from URL
		const urlParams = new URLSearchParams(window.location.search);
		state.currentTab = urlParams.get('tab') || 'tasks';

		switch (state.currentTab) {
			case 'tasks':
				loadTasks();
				break;
			case 'create':
				initializeTaskForm();
				break;
			case 'logs':
				loadLogs();
				break;
			case 'templates':
				// Templates are rendered server-side
				break;
		}
	}

	/**
	 * Bind event handlers
	 */
	function bindEvents() {
		// Tasks tab
		$('#sflmcp-refresh-tasks').on('click', loadTasks);
		$('#sflmcp-task-filter-status').on('change', loadTasks);
		$(document).on('click', '.sflmcp-task-toggle', handleTaskToggle);
		$(document).on('click', '.sflmcp-task-run', handleTaskRun);
		$(document).on('click', '.sflmcp-task-edit', handleTaskEdit);
		$(document).on('click', '.sflmcp-task-delete', handleTaskDelete);

		// Create tab
		$('#sflmcp-task-form').on('submit', handleTaskSubmit);
		$('#sflmcp-save-draft').on('click', handleSaveDraft);
		$('#sflmcp-test-prompt').on('click', handleTestPrompt);
		$('#sflmcp-clear-test-chat').on('click', clearTestChat);
		$('[name="tools_mode"]').on('change', handleToolsModeChange);
		$('#sflmcp-output-email').on('change', function() {
			$('#sflmcp-email-config').toggle(this.checked);
		});
		$('#sflmcp-output-webhook').on('change', function() {
			$('#sflmcp-webhook-config').toggle(this.checked);
		});
		$('#sflmcp-output-draft').on('change', function() {
			$('#sflmcp-draft-config').toggle(this.checked);
		});
		$('#sflmcp-tools-search').on('input', filterTools);
		$('#sflmcp-show-all-tools').on('change', renderToolsList);

		// Templates
		$(document).on('click', '.sflmcp-use-template', handleUseTemplate);
		$(document).on('click', '.sflmcp-template-btn', handleQuickTemplate);

		// Logs tab
		$('#sflmcp-refresh-logs').on('click', loadLogs);
		$('#sflmcp-log-filter-task, #sflmcp-log-filter-status, #sflmcp-log-filter-date').on('change', loadLogs);
		$(document).on('click', '.sflmcp-view-log', handleViewLog);

		// Modal
		$(document).on('click', '.sflmcp-modal-close', closeModal);
		$(document).on('click', '.sflmcp-modal', function(e) {
			if ($(e.target).hasClass('sflmcp-modal')) {
				closeModal();
			}
		});

		// Tool selection
		$(document).on('click', '.sflmcp-tool-item', handleToolSelect);

		// Schedule preset selection
		$(document).on('click', '.sflmcp-schedule-preset', handleScheduleSelect);
	}

	// ==========================================================================
	// Tasks Tab Functions
	// ==========================================================================

	/**
	 * Load tasks list
	 */
	function loadTasks() {
		const $container = $('#sflmcp-tasks-list');
		const status = $('#sflmcp-task-filter-status').val();

		$container.html('<div class="sflmcp-loading"><span class="spinner is-active"></span> ' + sflmcpAutomation.i18n.noTasks.replace('No automation tasks yet.', 'Loading tasks...') + '</div>');

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: {
				action: 'sflmcp_automation_get_tasks',
				nonce: sflmcpAutomation.nonce,
				status: status
			},
			success: function(response) {
				if (response.success) {
					state.tasks = response.data.tasks;
					renderTasks();
				} else {
					$container.html('<div class="sflmcp-empty-state"><span class="dashicons dashicons-warning"></span><p>' + (response.data?.message || 'Error loading tasks') + '</p></div>');
				}
			},
			error: function() {
				$container.html('<div class="sflmcp-empty-state"><span class="dashicons dashicons-warning"></span><p>Connection error</p></div>');
			}
		});
	}

	/**
	 * Render tasks cards
	 */
	function renderTasks() {
		const $container = $('#sflmcp-tasks-list');

		if (!state.tasks || state.tasks.length === 0) {
			$container.html(
				'<div class="sflmcp-empty-state">' +
					'<span class="dashicons dashicons-clock"></span>' +
					'<h3>' + sflmcpAutomation.i18n.noTasks + '</h3>' +
					'<p>Automation tasks run AI prompts on a schedule.</p>' +
					'<a href="?page=sflmcp-automation&tab=create" class="button button-primary">Create Your First Task</a>' +
				'</div>'
			);
			return;
		}

		let html = '';
		state.tasks.forEach(function(task) {
			const statusIcon = getStatusIcon(task.status);
			const scheduleLabel = getScheduleLabel(task.schedule_preset);
			const nextRunFormatted = task.next_run ? formatDateTime(task.next_run) : 'Not scheduled';
			const lastRunFormatted = task.last_run ? formatDateTime(task.last_run) : 'Never';

			html += `
				<div class="sflmcp-task-card status-${task.status}" data-task-id="${task.id}">
					<div class="sflmcp-task-info">
						<h3>
							<span class="dashicons dashicons-clock"></span>
							${escapeHtml(task.task_name)}
							<span class="sflmcp-task-status status-${task.status}">${statusIcon} ${task.status}</span>
						</h3>
						${task.task_description ? `<div class="sflmcp-task-description">${escapeHtml(task.task_description)}</div>` : ''}
						<div class="sflmcp-task-meta">
							<span><span class="dashicons dashicons-calendar-alt"></span> ${scheduleLabel}</span>
							<span><span class="dashicons dashicons-clock"></span> Next: ${nextRunFormatted}</span>
							<span><span class="dashicons dashicons-backup"></span> Last: ${lastRunFormatted}</span>
						</div>
					</div>
					<div class="sflmcp-task-actions">
						<button type="button" class="button sflmcp-task-toggle" data-task-id="${task.id}" data-status="${task.status === 'active' ? 'paused' : 'active'}">
							${task.status === 'active' ? 'Pause' : 'Activate'}
						</button>
						<button type="button" class="button sflmcp-task-run" data-task-id="${task.id}">
							<span class="dashicons dashicons-controls-play"></span> Run Now
						</button>
						<button type="button" class="button sflmcp-task-edit" data-task-id="${task.id}">Edit</button>
						<button type="button" class="button button-link-delete sflmcp-task-delete" data-task-id="${task.id}">Delete</button>
					</div>
				</div>
			`;
		});

		$container.html(html);
	}

	/**
	 * Handle task toggle (active/paused)
	 */
	function handleTaskToggle() {
		const $btn = $(this);
		const taskId = $btn.data('task-id');
		const newStatus = $btn.data('status');

		$btn.prop('disabled', true).text('...');

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: {
				action: 'sflmcp_automation_toggle_task',
				nonce: sflmcpAutomation.nonce,
				task_id: taskId,
				status: newStatus
			},
			success: function(response) {
				if (response.success) {
					loadTasks();
				} else {
					alert(response.data?.message || 'Error');
					$btn.prop('disabled', false).text(newStatus === 'active' ? 'Pause' : 'Activate');
				}
			},
			error: function() {
				alert('Connection error');
				$btn.prop('disabled', false);
			}
		});
	}

	/**
	 * Handle run task now
	 */
	function handleTaskRun() {
		const $btn = $(this);
		const taskId = $btn.data('task-id');
		const originalHtml = $btn.html();

		$btn.prop('disabled', true).html('<span class="spinner is-active" style="margin:0;float:none;"></span>');

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: {
				action: 'sflmcp_automation_run_task',
				nonce: sflmcpAutomation.nonce,
				task_id: taskId
			},
			success: function(response) {
				$btn.prop('disabled', false).html(originalHtml);
				
				if (response.success) {
					showModal('Task Completed', `
						<div class="sflmcp-test-results">
							${response.data.tools && response.data.tools.length > 0 ? `
								<div class="sflmcp-test-tools">
									<h5>Tools Called</h5>
									${response.data.tools.map(t => `<span class="sflmcp-detected-tool"><span class="dashicons dashicons-yes"></span> ${t}</span>`).join('')}
								</div>
							` : ''}
							<div class="sflmcp-test-response">${escapeHtml(response.data.response || 'No response')}</div>
						</div>
					`);
				} else {
					alert(sflmcpAutomation.i18n.taskFailed + ': ' + (response.data?.message || 'Unknown error'));
				}
			},
			error: function() {
				$btn.prop('disabled', false).html(originalHtml);
				alert('Connection error');
			}
		});
	}

	/**
	 * Handle task edit
	 */
	function handleTaskEdit() {
		const taskId = $(this).data('task-id');
		window.location.href = '?page=sflmcp-automation&tab=create&edit=' + taskId;
	}

	/**
	 * Handle task delete
	 */
	function handleTaskDelete() {
		if (!confirm(sflmcpAutomation.i18n.confirmDelete)) {
			return;
		}

		const $btn = $(this);
		const taskId = $btn.data('task-id');

		$btn.prop('disabled', true);

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: {
				action: 'sflmcp_automation_delete_task',
				nonce: sflmcpAutomation.nonce,
				task_id: taskId
			},
			success: function(response) {
				if (response.success) {
					loadTasks();
				} else {
					alert(response.data?.message || 'Error');
					$btn.prop('disabled', false);
				}
			},
			error: function() {
				alert('Connection error');
				$btn.prop('disabled', false);
			}
		});
	}

	// ==========================================================================
	// Create/Edit Task Functions
	// ==========================================================================

	/**
	 * Initialize task form
	 */
	function initializeTaskForm() {
		// Render schedule presets
		renderSchedulePresets();

		// Render tools list
		renderToolsList();

		// Render quick templates
		renderQuickTemplates();

		// Initialize selected tools from hidden field
		const existingTools = $('#sflmcp-allowed-tools').val();
		if (existingTools) {
			state.selectedTools = existingTools.split(',');
			updateToolsUI();
		}

		// Update next runs preview
		updateNextRuns();

		// Select current preset
		const currentPreset = $('#sflmcp-schedule-preset').val() || 'daily_morning';
		$(`.sflmcp-schedule-preset[data-preset="${currentPreset}"]`).addClass('selected');

		// Show/hide custom time based on current preset
		const presetInfo = sflmcpAutomation.presets[currentPreset];
		if (presetInfo) {
			$('#sflmcp-schedule-time-row').toggle(presetInfo.requires_time === true);
		}

		// Check for template parameter in URL
		const urlParams = new URLSearchParams(window.location.search);
		const templateSlug = urlParams.get('template');
		if (templateSlug) {
			applyTemplate(templateSlug);
		}
	}

	/**
	 * Render schedule presets
	 */
	function renderSchedulePresets() {
		const $container = $('#sflmcp-schedule-presets');
		if (!$container.length) return;

		const presets = sflmcpAutomation.presets;
		let html = '';

		Object.keys(presets).forEach(function(key) {
			const preset = presets[key];
			const icon = preset.icon || 'dashicons-clock';

			html += `
				<div class="sflmcp-schedule-preset" data-preset="${key}">
					<span class="dashicons ${icon}"></span>
					<strong>${preset.label}</strong>
				</div>
			`;
		});

		$container.html(html);
	}

	/**
	 * Render tools list for selection
	 */
	function renderToolsList() {
		const $container = $('#sflmcp-tools-list');
		if (!$container.length) return;

		const showAll = $('#sflmcp-show-all-tools').prop('checked');
		const enabledTools = sflmcpAutomation.tools || [];
		const allTools = sflmcpAutomation.allTools || enabledTools;
		
		const tools = showAll ? allTools : enabledTools;
		let html = '';

		tools.forEach(function(tool) {
			const isEnabled = enabledTools.some(t => t.name === tool.name);
			const disabledClass = !isEnabled ? ' sflmcp-tool-disabled' : '';
			
			html += `
				<label class="sflmcp-tool-item${disabledClass}" data-tool="${tool.name}">
					<input type="checkbox" value="${tool.name}">
					${tool.name}
					${!isEnabled ? '<span class="sflmcp-tool-badge-disabled">(disabled)</span>' : ''}
				</label>
			`;
		});

		$container.html(html);
		
		// Re-check previously selected tools
		state.selectedTools.forEach(function(toolName) {
			$container.find(`[data-tool="${toolName}"] input`).prop('checked', true);
		});
	}

	/**
	 * Render quick template buttons
	 */
	function renderQuickTemplates() {
		const $container = $('#sflmcp-quick-templates');
		if (!$container.length) return;

		const templates = sflmcpAutomation.templates || [];
		let html = '';

		// Show first 3 as buttons
		templates.slice(0, 3).forEach(function(template) {
			html += `
				<button type="button" class="sflmcp-template-btn" data-template="${template.slug}">
					<span class="dashicons ${template.icon}"></span>
					${template.name}
				</button>
			`;
		});

		// Add a select for remaining templates if more than 3
		if (templates.length > 3) {
			html += `
				<select id="sflmcp-more-templates" class="sflmcp-template-select">
					<option value="">${sflmcpAutomation.i18n?.moreTemplates || 'More templates...'}</option>
					${templates.slice(3).map(t => `<option value="${t.slug}">${t.name}</option>`).join('')}
				</select>
			`;
		}

		$container.html(html);

		// Bind select change
		$('#sflmcp-more-templates').on('change', function() {
			const slug = $(this).val();
			if (slug) {
				applyTemplate(slug);
				$(this).val(''); // Reset select
			}
		});
	}

	/**
	 * Handle schedule preset selection
	 */
	function handleScheduleSelect() {
		const $el = $(this);
		const preset = $el.data('preset');

		$('.sflmcp-schedule-preset').removeClass('selected');
		$el.addClass('selected');
		$('#sflmcp-schedule-preset').val(preset);

		// Show/hide time input - only for presets with requires_time flag
		const presetInfo = sflmcpAutomation.presets[preset];
		const needsTime = presetInfo && presetInfo.requires_time === true;
		$('#sflmcp-schedule-time-row').toggle(needsTime);

		updateNextRuns();
	}

	/**
	 * Update next runs preview
	 */
	function updateNextRuns() {
		const preset = $('#sflmcp-schedule-preset').val() || 'daily_morning';
		const customTime = $('#sflmcp-schedule-time').val() || '08:00';
		const presetInfo = sflmcpAutomation.presets[preset];
		
		if (!presetInfo) return;

		const $list = $('#sflmcp-next-runs-list');
		let html = '';
		const interval = presetInfo.interval; // Interval in seconds

		// Get next run time
		let nextRun = calculateFirstRun(presetInfo, customTime);

		// Generate next 3 execution times
		for (let i = 0; i < 3; i++) {
			if (i > 0) {
				// Add interval (in seconds -> milliseconds)
				nextRun = new Date(nextRun.getTime() + (interval * 1000));
			}
			html += `<li>${formatDateTime(nextRun.toISOString())}</li>`;
		}

		$list.html(html);
	}

	/**
	 * Calculate the first scheduled run based on preset
	 */
	function calculateFirstRun(presetInfo, customTime) {
		const now = new Date();
		let nextRun = new Date();

		// Determine target time (from preset or custom input)
		const targetTime = presetInfo.requires_time ? customTime : (presetInfo.time || '08:00');
		const [hours, minutes] = targetTime.split(':').map(Number);

		// For daily/weekly/monthly presets, set the specific time
		if (presetInfo.interval >= 86400) { // Daily or longer
			nextRun.setHours(hours, minutes, 0, 0);

			// Handle day_of_month for monthly presets
			if (presetInfo.day_of_month) {
				nextRun.setDate(presetInfo.day_of_month);
				if (nextRun <= now) {
					nextRun.setMonth(nextRun.getMonth() + 1);
				}
			}
			// Handle day (weekday) for weekly presets
			else if (typeof presetInfo.day !== 'undefined') {
				const dayDiff = presetInfo.day - now.getDay();
				nextRun.setDate(now.getDate() + dayDiff);
				if (nextRun <= now) {
					nextRun.setDate(nextRun.getDate() + 7);
				}
			}
			// Daily: if time passed today, schedule tomorrow
			else if (nextRun <= now) {
				nextRun.setDate(nextRun.getDate() + 1);
			}
		} else {
			// Hourly intervals: round to next interval
			const intervalHours = presetInfo.interval / 3600;
			const currentHour = now.getHours();
			const nextHour = Math.ceil((currentHour + 1) / intervalHours) * intervalHours;
			nextRun.setHours(nextHour % 24, 0, 0, 0);
			if (nextRun <= now) {
				nextRun.setHours(nextRun.getHours() + intervalHours);
			}
		}

		return nextRun;
	}

	/**
	 * Handle tools mode change
	 */
	function handleToolsModeChange() {
		const mode = $('[name="tools_mode"]:checked').val();
		$('#sflmcp-custom-tools-section').toggle(mode === 'custom');
	}

	/**
	 * Handle tool selection
	 */
	function handleToolSelect() {
		const $el = $(this);
		const toolName = $el.data('tool');

		$el.toggleClass('selected');

		if ($el.hasClass('selected')) {
			if (!state.selectedTools.includes(toolName)) {
				state.selectedTools.push(toolName);
			}
		} else {
			state.selectedTools = state.selectedTools.filter(t => t !== toolName);
		}

		$('#sflmcp-allowed-tools').val(state.selectedTools.join(','));
	}

	/**
	 * Update tools UI based on state
	 */
	function updateToolsUI() {
		$('.sflmcp-tool-item').each(function() {
			const toolName = $(this).data('tool');
			$(this).toggleClass('selected', state.selectedTools.includes(toolName));
		});
	}

	/**
	 * Filter tools by search
	 */
	function filterTools() {
		const search = $(this).val().toLowerCase();

		$('.sflmcp-tool-item').each(function() {
			const toolName = $(this).data('tool').toLowerCase();
			$(this).toggle(toolName.includes(search));
		});
	}

	/**
	 * Handle test prompt
	 */
	/**
	 * Add a message to the test chat
	 */
	function addChatMessage(role, content) {
		const $messages = $('#sflmcp-test-chat-messages');
		
		// Remove welcome message if present
		$messages.find('.sflmcp-test-welcome').remove();
		
		const avatarIcon = role === 'user' ? 'admin-users' : 'superhero';
		const roleClass = role === 'user' ? 'sflmcp-test-message-user' : 'sflmcp-test-message-assistant';
		
		const $message = $(`
			<div class="sflmcp-test-message ${roleClass}">
				<div class="sflmcp-test-avatar">
					<span class="dashicons dashicons-${avatarIcon}"></span>
				</div>
				<div class="sflmcp-test-content">${escapeHtml(content)}</div>
			</div>
		`);
		
		$messages.append($message);
		$messages.scrollTop($messages[0].scrollHeight);
		
		return $message;
	}

	/**
	 * Add a tool execution message to the chat
	 */
	function addToolMessage(toolName) {
		const $messages = $('#sflmcp-test-chat-messages');
		
		const $message = $(`
			<div class="sflmcp-test-message sflmcp-test-message-tool">
				<div class="sflmcp-test-avatar">
					<span class="dashicons dashicons-admin-tools"></span>
				</div>
				<div class="sflmcp-test-content">
					<span class="sflmcp-tool-executed">${escapeHtml(toolName)}</span>
				</div>
			</div>
		`);
		
		$messages.append($message);
		$messages.scrollTop($messages[0].scrollHeight);
	}

	/**
	 * Add a tool execution message with arguments and result
	 */
	function addToolMessageWithResult(toolName, args, result) {
		const $messages = $('#sflmcp-test-chat-messages');
		
		// Format arguments for display
		let argsDisplay = '';
		if (args && typeof args === 'object') {
			const argEntries = Object.entries(args).slice(0, 3); // Show max 3 args
			argsDisplay = argEntries.map(([k, v]) => {
				const valStr = typeof v === 'string' ? v.substring(0, 50) : JSON.stringify(v).substring(0, 50);
				return `<span class="sflmcp-tool-arg">${escapeHtml(k)}: ${escapeHtml(valStr)}${valStr.length >= 50 ? '...' : ''}</span>`;
			}).join('');
		}
		
		// Format result preview
		let resultPreview = '';
		if (result) {
			const resultStr = typeof result === 'string' ? result : JSON.stringify(result);
			resultPreview = resultStr.substring(0, 150) + (resultStr.length > 150 ? '...' : '');
		}
		
		const $message = $(`
			<div class="sflmcp-test-message sflmcp-test-message-tool">
				<div class="sflmcp-test-avatar">
					<span class="dashicons dashicons-admin-tools"></span>
				</div>
				<div class="sflmcp-test-content">
					<div class="sflmcp-tool-header">
						<span class="sflmcp-tool-executed">${escapeHtml(toolName)}</span>
						<span class="sflmcp-tool-status">✓</span>
					</div>
					${argsDisplay ? `<div class="sflmcp-tool-args">${argsDisplay}</div>` : ''}
					${resultPreview ? `<div class="sflmcp-tool-result"><small>${escapeHtml(resultPreview)}</small></div>` : ''}
				</div>
			</div>
		`);
		
		$messages.append($message);
		$messages.scrollTop($messages[0].scrollHeight);
	}

	/**
	 * Show thinking indicator
	 */
	function showThinking() {
		const $messages = $('#sflmcp-test-chat-messages');
		
		// Remove any existing thinking indicator
		$messages.find('.sflmcp-test-thinking').remove();
		
		const $thinking = $(`
			<div class="sflmcp-test-thinking">
				<span class="sflmcp-thinking-dots">
					<span></span><span></span><span></span>
				</span>
				<span class="sflmcp-thinking-text">${sflmcpAutomation.i18n.testing}</span>
			</div>
		`);
		
		$messages.append($thinking);
		$messages.scrollTop($messages[0].scrollHeight);
	}

	/**
	 * Hide thinking indicator
	 */
	function hideThinking() {
		$('#sflmcp-test-chat-messages .sflmcp-test-thinking').remove();
	}

	/**
	 * Clear test chat
	 */
	function clearTestChat() {
		const $messages = $('#sflmcp-test-chat-messages');
		$messages.empty().append(`
			<div class="sflmcp-test-welcome">
				<span class="dashicons dashicons-lightbulb"></span>
				${sflmcpAutomation.i18n.testWelcome || 'Write your prompt below and click "Test Prompt" to see how the AI responds. Tool executions will appear here.'}
			</div>
		`);
		$('#sflmcp-test-summary').hide();
		state.detectedTools = [];
	}

	/**
	 * Escape HTML for safe insertion
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Handle test prompt (chat-style)
	 */
	function handleTestPrompt() {
		const $btn = $(this);
		const prompt = $('#sflmcp-task-prompt').val();
		const systemPrompt = $('#sflmcp-task-system-prompt').val();

		if (!prompt.trim()) {
			alert('Please enter a prompt first');
			return;
		}

		// Add user message to chat
		addChatMessage('user', prompt);
		
		// Show thinking indicator
		showThinking();
		
		$btn.prop('disabled', true);
		$('#sflmcp-test-summary').hide();

		const startTime = Date.now();

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: {
				action: 'sflmcp_automation_test_prompt',
				nonce: sflmcpAutomation.nonce,
				prompt: prompt,
				system_prompt: systemPrompt
			},
			success: function(response) {
				hideThinking();
				$btn.prop('disabled', false);
				const duration = ((Date.now() - startTime) / 1000).toFixed(1);

				if (response.success) {
					// Display intermediate steps (tool calls and intermediate text)
					const steps = response.data.steps || [];
					steps.forEach(step => {
						if (step.type === 'tool') {
							addToolMessageWithResult(step.name, step.arguments, step.result);
						} else if (step.type === 'text' && step.content) {
							// Only show non-final text responses as intermediate
							if (steps.indexOf(step) < steps.length - 1) {
								addChatMessage('assistant', step.content);
							}
						}
					});
					
					state.detectedTools = response.data.tools_detected || [];
					
					// Always save detected tools to hidden input for later use
					$('#sflmcp-detected-tools').val(state.detectedTools.join(','));
					
					// Add final assistant response
					const aiResponse = response.data.response || 'No response';
					addChatMessage('assistant', aiResponse);
					
					// Show summary below chat
					if (state.detectedTools.length > 0) {
						const toolsHtml = state.detectedTools.map(t => 
							`<span class="sflmcp-detected-tool"><span class="dashicons dashicons-yes"></span> ${t}</span>`
						).join('');
						$('#sflmcp-detected-tools-list').html(toolsHtml);
						
						$('#sflmcp-test-suggestion').html(`
							<strong>💡 ${sflmcpAutomation.i18n.tip || 'Tip'}:</strong> 
							${state.detectedTools.length} tools detected (${duration}s). 
							<button type="button" class="button button-small" id="sflmcp-apply-detected">
								${sflmcpAutomation.i18n.applyDetected || 'Apply detected tools'}
							</button>
						`);
						
						$('#sflmcp-apply-detected').on('click', function() {
							state.selectedTools = [...state.detectedTools];
							$('#sflmcp-allowed-tools').val(state.selectedTools.join(','));
							$('[name="tools_mode"][value="custom"]').prop('checked', true).trigger('change');
							updateToolsUI();
						});
						
						$('#sflmcp-test-summary').show();
					} else {
						$('#sflmcp-detected-tools-list').html(
							`<span class="sflmcp-no-tools">${sflmcpAutomation.i18n.noToolsCalled || 'No tools were called'}</span>`
						);
						$('#sflmcp-test-suggestion').html(`<small>${duration}s</small>`);
						$('#sflmcp-test-summary').show();
					}
				} else {
					// Add error message as assistant response
					addChatMessage('assistant', '❌ ' + (response.data?.message || 'Unknown error'));
				}
			},
			error: function() {
				hideThinking();
				$btn.prop('disabled', false);
				addChatMessage('assistant', '❌ Connection error');
			}
		});
	}

	/**
	 * Handle task form submit
	 */
	function handleTaskSubmit(e) {
		e.preventDefault();
		saveTask('active');
	}

	/**
	 * Handle save as draft
	 */
	function handleSaveDraft() {
		saveTask('draft');
	}

	/**
	 * Save task
	 */
	function saveTask(status) {
		const $form = $('#sflmcp-task-form');
		const $submitBtn = status === 'active' ? $('#sflmcp-save-active') : $('#sflmcp-save-draft');

		$submitBtn.prop('disabled', true);

		const formData = {
			action: 'sflmcp_automation_save_task',
			nonce: sflmcpAutomation.nonce,
			task_id: $('#sflmcp-task-id').val(),
			task_name: $('#sflmcp-task-name').val(),
			prompt: $('#sflmcp-task-prompt').val(),
			system_prompt: $('#sflmcp-task-system-prompt').val(),
			schedule_preset: $('#sflmcp-schedule-preset').val(),
			schedule_time: $('#sflmcp-schedule-time').val(),
			schedule_timezone: $('#sflmcp-schedule-timezone').val(),
			tools_mode: $('[name="tools_mode"]:checked').val(),
			allowed_tools: $('#sflmcp-allowed-tools').val(),
			detected_tools: $('#sflmcp-detected-tools').val(),
			output_email: $('#sflmcp-output-email').prop('checked') ? 1 : 0,
			output_webhook: $('#sflmcp-output-webhook').prop('checked') ? 1 : 0,
			output_draft: $('#sflmcp-output-draft').prop('checked') ? 1 : 0,
			email_recipients: $('#sflmcp-email-recipients').val(),
			email_subject: $('#sflmcp-email-subject').val(),
			webhook_url: $('#sflmcp-webhook-url').val(),
			draft_post_type: $('#sflmcp-draft-post-type').val(),
			status: status
		};

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: formData,
			success: function(response) {
				$submitBtn.prop('disabled', false);

				if (response.success) {
					window.location.href = '?page=sflmcp-automation&tab=tasks&saved=1';
				} else {
					alert(response.data?.message || 'Error saving task');
				}
			},
			error: function() {
				$submitBtn.prop('disabled', false);
				alert('Connection error');
			}
		});
	}

	/**
	 * Handle use template button
	 */
	function handleUseTemplate() {
		const $card = $(this).closest('.sflmcp-template-card');
		const templateSlug = $card.data('template');
		applyTemplate(templateSlug);
	}

	/**
	 * Handle quick template button
	 */
	function handleQuickTemplate() {
		const templateSlug = $(this).data('template');
		applyTemplate(templateSlug);
	}

	/**
	 * Apply template to form
	 */
	function applyTemplate(slug) {
		const templates = sflmcpAutomation.templates || [];
		const template = templates.find(t => t.slug === slug);

		if (!template) return;

		// If on templates tab, redirect to create tab with template
		if (state.currentTab === 'templates') {
			window.location.href = '?page=sflmcp-automation&tab=create&template=' + slug;
			return;
		}

		// Apply template values
		$('#sflmcp-task-name').val(template.name);
		$('#sflmcp-task-prompt').val(template.prompt);

		// Set schedule preset
		$('#sflmcp-schedule-preset').val(template.suggested_schedule);
		$('.sflmcp-schedule-preset').removeClass('selected');
		$(`.sflmcp-schedule-preset[data-preset="${template.suggested_schedule}"]`).addClass('selected');

		// Set suggested tools
		if (template.suggested_tools && template.suggested_tools.length > 0) {
			state.selectedTools = template.suggested_tools;
			$('#sflmcp-allowed-tools').val(state.selectedTools.join(','));
			$('[name="tools_mode"][value="custom"]').prop('checked', true).trigger('change');
			updateToolsUI();
		}

		updateNextRuns();

		// Scroll to top
		$('html, body').animate({ scrollTop: 0 }, 300);
	}

	// ==========================================================================
	// Logs Tab Functions
	// ==========================================================================

	/**
	 * Load execution logs
	 */
	function loadLogs() {
		const $body = $('#sflmcp-logs-body');
		const taskId = $('#sflmcp-log-filter-task').val();
		const status = $('#sflmcp-log-filter-status').val();
		const days = $('#sflmcp-log-filter-date').val();

		$body.html('<tr><td colspan="6" class="sflmcp-loading"><span class="spinner is-active"></span> Loading logs...</td></tr>');

		$.ajax({
			url: sflmcpAutomation.ajaxUrl,
			method: 'POST',
			data: {
				action: 'sflmcp_automation_get_logs',
				nonce: sflmcpAutomation.nonce,
				task_id: taskId,
				status: status,
				days: days
			},
			success: function(response) {
				if (response.success) {
					state.logs = response.data.logs;
					renderLogs();
					renderLogsStats(response.data.stats);
					populateTaskFilter(response.data.tasks);
				} else {
					$body.html('<tr><td colspan="6">' + (response.data?.message || 'Error loading logs') + '</td></tr>');
				}
			},
			error: function() {
				$body.html('<tr><td colspan="6">Connection error</td></tr>');
			}
		});
	}

	/**
	 * Render logs table
	 */
	function renderLogs() {
		const $body = $('#sflmcp-logs-body');

		if (!state.logs || state.logs.length === 0) {
			$body.html('<tr><td colspan="6">No logs found for the selected filters.</td></tr>');
			return;
		}

		let html = '';
		state.logs.forEach(function(log) {
			const statusClass = 'status-' + log.status;
			const statusIcon = log.status === 'success' ? 'yes' : (log.status === 'error' ? 'no' : 'update');
			const duration = log.execution_time_ms ? (log.execution_time_ms / 1000).toFixed(1) + 's' : '-';
			const tokens = (parseInt(log.tokens_input || 0) + parseInt(log.tokens_output || 0)) || '-';

			html += `
				<tr>
					<td>${formatDateTime(log.started_at)}</td>
					<td>${escapeHtml(log.task_name || 'Unknown')}</td>
					<td class="${statusClass}"><span class="dashicons dashicons-${statusIcon}"></span> ${log.status}</td>
					<td>${duration}</td>
					<td>${tokens}</td>
					<td>
						<button type="button" class="button button-small sflmcp-view-log" data-log-id="${log.id}">View</button>
					</td>
				</tr>
			`;
		});

		$body.html(html);
	}

	/**
	 * Render logs stats
	 */
	function renderLogsStats(stats) {
		const $container = $('#sflmcp-logs-stats');

		$container.html(`
			<div class="sflmcp-stat-card">
				<div class="stat-value">${stats.total}</div>
				<div class="stat-label">Total Executions</div>
			</div>
			<div class="sflmcp-stat-card success">
				<div class="stat-value">${stats.success}</div>
				<div class="stat-label">Successful</div>
			</div>
			<div class="sflmcp-stat-card error">
				<div class="stat-value">${stats.error}</div>
				<div class="stat-label">Failed</div>
			</div>
			<div class="sflmcp-stat-card">
				<div class="stat-value">${stats.success_rate || 0}%</div>
				<div class="stat-label">Success Rate</div>
			</div>
			<div class="sflmcp-stat-card">
				<div class="stat-value">${formatNumber(stats.total_tokens)}</div>
				<div class="stat-label">Total Tokens</div>
			</div>
		`);
	}

	/**
	 * Populate task filter dropdown
	 */
	function populateTaskFilter(tasks) {
		const $select = $('#sflmcp-log-filter-task');
		const currentVal = $select.val();

		// Keep first option
		$select.find('option:not(:first)').remove();

		tasks.forEach(function(task) {
			$select.append(`<option value="${task.id}">${escapeHtml(task.task_name)}</option>`);
		});

		// Restore selection
		if (currentVal) {
			$select.val(currentVal);
		}
	}

	/**
	 * Handle view log details
	 */
	function handleViewLog() {
		const logId = $(this).data('log-id');
		const log = state.logs.find(l => l.id == logId);

		if (!log) return;

		let toolsHtml = '';
		if (log.tools_called) {
			try {
				const tools = JSON.parse(log.tools_called);
				if (Array.isArray(tools) && tools.length > 0) {
					toolsHtml = `
						<div class="sflmcp-test-tools">
							<h5>Tools Called</h5>
							${tools.map(t => `<span class="sflmcp-detected-tool"><span class="dashicons dashicons-yes"></span> ${typeof t === 'string' ? t : t.name}</span>`).join('')}
						</div>
					`;
				}
			} catch (e) {}
		}

		const content = `
			<div class="sflmcp-log-detail">
				<p><strong>Task:</strong> ${escapeHtml(log.task_name || 'Unknown')}</p>
				<p><strong>Started:</strong> ${formatDateTime(log.started_at)}</p>
				<p><strong>Completed:</strong> ${log.completed_at ? formatDateTime(log.completed_at) : 'N/A'}</p>
				<p><strong>Duration:</strong> ${log.execution_time_ms ? (log.execution_time_ms / 1000).toFixed(2) + 's' : 'N/A'}</p>
				<p><strong>Tokens:</strong> Input: ${log.tokens_input || 0}, Output: ${log.tokens_output || 0}</p>
				${log.error_message ? `<p><strong>Error:</strong> <span style="color:#d63638">${escapeHtml(log.error_message)}</span></p>` : ''}
				${toolsHtml}
				<h4>AI Response</h4>
				<div class="sflmcp-test-response">${escapeHtml(log.ai_response || 'No response recorded')}</div>
			</div>
		`;

		showModal('Execution Details - ' + formatDateTime(log.started_at), content, 'sflmcp-log-modal');
	}

	// ==========================================================================
	// Utility Functions
	// ==========================================================================

	/**
	 * Show modal
	 */
	function showModal(title, content, modalId = 'sflmcp-task-modal') {
		const $modal = $('#' + modalId);
		$modal.find('.sflmcp-modal-header h3, #sflmcp-modal-title').text(title);
		$modal.find('.sflmcp-modal-body, #sflmcp-modal-body').html(content);
		$modal.show();
	}

	/**
	 * Close modal
	 */
	function closeModal() {
		$('.sflmcp-modal').hide();
	}

	/**
	 * Get status icon
	 */
	function getStatusIcon(status) {
		const icons = {
			active: '●',
			paused: '◐',
			error: '✕',
			draft: '○'
		};
		return icons[status] || '○';
	}

	/**
	 * Get schedule icon
	 */
	function getScheduleIcon(preset) {
		if (preset.includes('hourly') || preset.includes('minute')) return 'dashicons-backup';
		if (preset.includes('daily')) return 'dashicons-calendar-alt';
		if (preset.includes('weekly')) return 'dashicons-calendar';
		if (preset.includes('monthly')) return 'dashicons-calendar';
		return 'dashicons-clock';
	}

	/**
	 * Get schedule label
	 */
	function getScheduleLabel(preset) {
		const presets = sflmcpAutomation.presets;
		return presets[preset]?.label || preset;
	}

	/**
	 * Format date time
	 */
	function formatDateTime(dateStr) {
		if (!dateStr) return 'N/A';
		try {
			const date = new Date(dateStr);
			return date.toLocaleString();
		} catch (e) {
			return dateStr;
		}
	}

	/**
	 * Format number with commas
	 */
	function formatNumber(num) {
		if (!num) return '0';
		return parseInt(num).toLocaleString();
	}

	/**
	 * Escape HTML
	 */
	function escapeHtml(str) {
		if (!str) return '';
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

})(jQuery);
