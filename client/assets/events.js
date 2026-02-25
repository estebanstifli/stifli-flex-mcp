/**
 * StifLi Flex MCP - Event Automations JavaScript
 *
 * @package StifliFlexMcp
 * @since 2.1.0
 */

(function($) {
    'use strict';

    // Module state
    const state = {
        currentTab: 'list',
        automations: [],
        triggers: {},
        triggersByCategory: {},
        tools: [],
        selectedTools: [],
        detectedTools: [],
        logs: []
    };

    // DOM ready
    $(document).ready(function() {
        initializePage();
        bindEvents();
        loadInitialData();
    });

    /**
     * Initialize page based on current tab
     */
    function initializePage() {
        const urlParams = new URLSearchParams(window.location.search);
        state.currentTab = urlParams.get('tab') || 'list';

        switch (state.currentTab) {
            case 'list':
                loadAutomations();
                break;
            case 'create':
                initializeTaskForm();
                break;
            case 'logs':
                loadLogs();
                break;
        }
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // List tab
        $('#sflmcp-refresh-events').on('click', loadAutomations);
        $('#sflmcp-event-filter-status, #sflmcp-event-filter-trigger').on('change', loadAutomations);
        $(document).on('click', '[data-action="toggle"]', handleToggle);
        $(document).on('click', '[data-action="edit"]', handleEdit);
        $(document).on('click', '[data-action="delete"]', handleDelete);

        // Create tab
        $('#sflmcp-task-form').on('submit', function(e) {
            e.preventDefault();
            saveAutomation('active');
        });
        $('#sflmcp-save-draft').on('click', function() {
            saveAutomation('draft');
        });
        $('#sflmcp-save-active').on('click', function(e) {
            e.preventDefault();
            saveAutomation('active');
        });
        $('#sflmcp-test-prompt').on('click', handleTestPrompt);
        $('#sflmcp-clear-test-chat').on('click', clearTestChat);
        $('#trigger_id').on('change', updateTriggerInfo);
        $('#add-condition').on('click', addConditionRow);
        $(document).on('click', '[data-action="remove-condition"]', function(e) {
            e.preventDefault();
            $(this).closest('.sflmcp-condition-row').remove();
        });
        $(document).on('click', '.sflmcp-placeholder', insertPlaceholder);

        // Tools mode
        $('[name="tools_mode"]').on('change', handleToolsModeChange);
        $('#sflmcp-tools-search').on('input', filterTools);
        $('#sflmcp-show-all-tools').on('change', renderToolsList);
        $(document).on('click', '.sflmcp-tool-item', handleToolSelect);

        // Output actions
        $('#sflmcp-output-email').on('change', function() {
            $('#sflmcp-email-config').toggle(this.checked);
        });
        $('#sflmcp-output-webhook').on('change', function() {
            $('#sflmcp-webhook-config').toggle(this.checked);
        });
        $('#sflmcp-output-draft').on('change', function() {
            $('#sflmcp-draft-config').toggle(this.checked);
        });

        // Trigger family cards
        $(document).on('click', '.sflmcp-trigger-family-card', function() {
            const $card = $(this);
            const family = $card.data('family');
            
            $('.sflmcp-trigger-family-card').removeClass('active');
            $card.addClass('active');
            
            // Show/hide WooCommerce warning
            if (family === 'woocommerce' && !sflmcpEvents.woocommerceActive) {
                $('#sflmcp-wc-warning').show();
            } else {
                $('#sflmcp-wc-warning').hide();
            }
            
            // Re-populate triggers filtered by platform
            populateTriggerSelect();
            
            // Reset trigger selection
            $('#trigger_id').val('').trigger('change');
        });

        // Collapsible sections
        $(document).on('click', '.sflmcp-collapsible-header', function() {
            const $section = $(this).closest('.sflmcp-collapsible');
            const $content = $section.find('.sflmcp-collapsible-content');
            const $arrow = $(this).find('.dashicons-arrow-down-alt2');
            
            $content.slideToggle(200);
            $arrow.toggleClass('rotate-180');
        });

        // Logs tab
        $('#sflmcp-refresh-logs').on('click', loadLogs);
        $('#sflmcp-log-filter-status, #sflmcp-log-filter-automation').on('change', loadLogs);
        $(document).on('click', '.sflmcp-task-card[data-log-id]', handleViewLog);

        // Modal
        $(document).on('click', '.sflmcp-modal-close', closeModal);
        $(document).on('click', '.sflmcp-modal', function(e) {
            if ($(e.target).hasClass('sflmcp-modal')) {
                closeModal();
            }
        });
    }

    /**
     * Load initial data (triggers, tools)
     */
    function loadInitialData() {
        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_events_get_triggers',
                nonce: sflmcpEvents.nonce,
                include_tools: 1
            },
            success: function(response) {
                if (response.success) {
                    state.triggers = response.data.triggers || {};
                    state.triggersByCategory = response.data.grouped || {};
                    if (response.data.tools) {
                        state.tools = response.data.tools;
                    }
                    populateTriggerSelect();
                    
                    if (state.currentTab === 'create') {
                        initializeTaskForm();
                    }
                }
            }
        });
    }

    // ==========================================================================
    // List Tab Functions
    // ==========================================================================

    /**
     * Load automations list
     */
    function loadAutomations() {
        const $container = $('#sflmcp-tasks-list');
        const status = $('#sflmcp-event-filter-status').val();
        const trigger = $('#sflmcp-event-filter-trigger').val();

        $container.html('<div class="sflmcp-loading"><span class="spinner is-active"></span> Loading automations...</div>');

        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_events_get_automations',
                nonce: sflmcpEvents.nonce,
                status: status,
                trigger_id: trigger
            },
            success: function(response) {
                if (response.success) {
                    state.automations = response.data.automations;
                    renderAutomations();
                } else {
                    $container.html('<div class="sflmcp-empty-state"><span class="dashicons dashicons-warning"></span><p>' + (response.data?.message || 'Error loading automations') + '</p></div>');
                }
            },
            error: function() {
                $container.html('<div class="sflmcp-empty-state"><span class="dashicons dashicons-warning"></span><p>Connection error</p></div>');
            }
        });
    }

    /**
     * Render automations cards
     */
    function renderAutomations() {
        const $container = $('#sflmcp-tasks-list');

        if (!state.automations || state.automations.length === 0) {
            $container.html(
                '<div class="sflmcp-empty-state">' +
                    '<span class="dashicons dashicons-superhero-alt"></span>' +
                    '<h3>No event automations yet</h3>' +
                    '<p>Event automations run AI prompts when WordPress events occur.</p>' +
                    '<a href="?page=sflmcp-events&tab=create" class="button button-primary">Create Your First Automation</a>' +
                '</div>'
            );
            return;
        }

        let html = '';
        state.automations.forEach(function(auto) {
            const statusIcon = getStatusIcon(auto.status);
            const triggerInfo = state.triggers[auto.trigger_id] || {};

            html += '<div class="sflmcp-task-card status-' + auto.status + '" data-id="' + auto.id + '">' +
                '<div class="sflmcp-task-info">' +
                    '<h3>' +
                        '<span class="dashicons dashicons-superhero-alt"></span> ' +
                        escapeHtml(auto.automation_name) +
                        '<span class="sflmcp-task-status status-' + auto.status + '">' + statusIcon + ' ' + auto.status + '</span>' +
                    '</h3>' +
                    '<div class="sflmcp-task-description">' +
                        '<span class="trigger-badge">' + escapeHtml(auto.trigger_name || auto.trigger_id) + '</span>' +
                    '</div>' +
                    '<div class="sflmcp-task-meta">' +
                        '<span><span class="dashicons dashicons-performance"></span> ' + (auto.run_count || 0) + ' runs</span>' +
                        '<span><span class="dashicons dashicons-calendar-alt"></span> ' + (auto.last_run ? formatDateTime(auto.last_run) : 'Never') + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="sflmcp-task-actions">' +
                    '<button type="button" class="button" data-action="toggle" title="' + (auto.status === 'active' ? 'Pause' : 'Activate') + '">' +
                        (auto.status === 'active' ? 'Pause' : 'Activate') +
                    '</button>' +
                    '<button type="button" class="button" data-action="edit">Edit</button>' +
                    '<button type="button" class="button button-link-delete" data-action="delete">Delete</button>' +
                '</div>' +
            '</div>';
        });

        $container.html(html);
    }

    function handleToggle() {
        const $card = $(this).closest('[data-id]');
        const id = $card.data('id');
        const currentStatus = $card.hasClass('status-active') ? 'active' : 'paused';
        const newStatus = currentStatus === 'active' ? 'paused' : 'active';

        $(this).prop('disabled', true).text('...');

        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_events_toggle_status',
                nonce: sflmcpEvents.nonce,
                id: id,
                status: newStatus
            },
            success: function(response) {
                if (response.success) {
                    loadAutomations();
                } else {
                    alert(response.data?.message || 'Error');
                }
            },
            complete: function() {
                $(this).prop('disabled', false);
            }
        });
    }

    function handleEdit() {
        const id = $(this).closest('[data-id]').data('id');
        window.location.href = '?page=sflmcp-events&tab=create&edit=' + id;
    }

    function handleDelete() {
        if (!confirm(sflmcpEvents.i18n.confirmDelete)) {
            return;
        }

        const $card = $(this).closest('[data-id]');
        const id = $card.data('id');

        $(this).prop('disabled', true);

        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_events_delete_automation',
                nonce: sflmcpEvents.nonce,
                id: id
            },
            success: function(response) {
                if (response.success) {
                    loadAutomations();
                } else {
                    alert(response.data?.message || 'Error');
                }
            }
        });
    }

    // ==========================================================================
    // Create/Edit Tab Functions
    // ==========================================================================

    /**
     * Initialize task form
     */
    function initializeTaskForm() {
        // Set platform based on existing trigger (for editing)
        const triggerId = $('#trigger_id').data('value');
        if (triggerId && triggerId.startsWith('wc_')) {
            // WooCommerce trigger - switch platform
            $('.sflmcp-trigger-family-card').removeClass('active');
            $('.sflmcp-trigger-family-card[data-family="woocommerce"]').addClass('active');
            
            // Show warning if WooCommerce not active
            if (!sflmcpEvents.woocommerceActive) {
                $('#sflmcp-wc-warning').show();
            }
        }
        
        populateTriggerSelect();
        renderToolsList();
        loadFormData();
        
        // Set initial trigger value if editing
        if (triggerId) {
            setTimeout(function() {
                $('#trigger_id').val(triggerId);
                updateTriggerInfo();
            }, 500);
        }

        // Show custom tools section if mode is custom
        const existingTools = $('#tools-enabled-json').val();
        if (existingTools && existingTools !== '[]') {
            $('[name="tools_mode"][value="custom"]').prop('checked', true);
            $('#sflmcp-custom-tools-section').show();
        }
    }

    /**
     * Populate trigger select dropdown
     */
    function populateTriggerSelect() {
        const $select = $('#trigger_id');
        if (!$select.length || Object.keys(state.triggersByCategory).length === 0) return;

        // Get selected platform
        const $activeFamily = $('.sflmcp-trigger-family-card.active');
        const selectedPlatform = $activeFamily.length ? $activeFamily.data('family') : 'wordpress';

        const currentVal = $select.val() || $select.data('value');
        $select.empty();
        $select.append('<option value="">-- Select a trigger --</option>');

        Object.keys(state.triggersByCategory).forEach(function(category) {
            // Filter categories by platform
            const categoryLower = category.toLowerCase();
            const isWooCommerce = categoryLower.startsWith('woocommerce');
            
            // Skip categories not matching the selected platform
            if (selectedPlatform === 'wordpress' && isWooCommerce) return;
            if (selectedPlatform === 'woocommerce' && !isWooCommerce) return;
            
            const $optgroup = $('<optgroup label="' + category + '"></optgroup>');
            
            state.triggersByCategory[category].forEach(function(trigger) {
                const $option = $('<option></option>')
                    .val(trigger.trigger_id)
                    .text(trigger.trigger_name);
                
                if (trigger.plugin_required && !trigger.available) {
                    $option.prop('disabled', true);
                    $option.text($option.text() + ' (requires plugin)');
                }
                
                $optgroup.append($option);
            });
            
            $select.append($optgroup);
        });

        if (currentVal) {
            $select.val(currentVal);
        }
    }

    /**
     * Update trigger info and placeholders
     */
    function updateTriggerInfo() {
        const triggerId = $('#trigger_id').val();
        const trigger = state.triggers[triggerId];

        const $description = $('#trigger-description');
        const $placeholders = $('#available-placeholders');
        const $placeholdersRow = $('.sflmcp-placeholders-row');

        if (trigger) {
            $description.html('<strong>' + escapeHtml(trigger.trigger_name) + '</strong><br>' + escapeHtml(trigger.trigger_description || ''));
            
            let payloadSchema = trigger.payload_schema;
            if (typeof payloadSchema === 'string') {
                try { payloadSchema = JSON.parse(payloadSchema); } catch(e) { payloadSchema = []; }
            }
            payloadSchema = payloadSchema || [];
            
            if (payloadSchema.length > 0) {
                let placeholderHtml = payloadSchema.map(function(field) {
                    return '<span class="sflmcp-placeholder" data-placeholder="{{' + field + '}}">{{' + field + '}}</span>';
                }).join('');
                $placeholders.html(placeholderHtml);
                $placeholdersRow.show();
                
                // Update condition field options
                updateConditionFieldOptions(payloadSchema);
            } else {
                $placeholders.html('<span class="description">No placeholders available for this trigger.</span>');
                $placeholdersRow.show();
            }
        } else {
            $description.html('<span class="description">Select a trigger to see available options.</span>');
            $placeholdersRow.hide();
        }
    }

    /**
     * Insert placeholder into prompt
     */
    function insertPlaceholder() {
        const placeholder = $(this).data('placeholder') || $(this).text();
        const $textarea = $('#prompt');
        const cursorPos = $textarea[0].selectionStart;
        const text = $textarea.val();
        const newText = text.substring(0, cursorPos) + placeholder + text.substring(cursorPos);
        $textarea.val(newText);
        $textarea.focus();
        $textarea[0].selectionStart = $textarea[0].selectionEnd = cursorPos + placeholder.length;
    }

    /**
     * Load form data for editing
     */
    function loadFormData() {
        // Load conditions
        const existingConditions = $('#conditions-json').val();
        if (existingConditions && existingConditions !== '[]') {
            try {
                const conditions = JSON.parse(existingConditions);
                conditions.forEach(addConditionRow);
            } catch(e) {}
        }

        // Load selected tools
        const existingTools = $('#tools-enabled-json').val();
        if (existingTools && existingTools !== '[]') {
            try {
                state.selectedTools = JSON.parse(existingTools);
            } catch(e) {}
        }
    }

    /**
     * Update condition field options
     */
    function updateConditionFieldOptions(fields) {
        $('.condition-field').each(function() {
            const currentVal = $(this).val();
            $(this).empty();
            $(this).append('<option value="">-- Select field --</option>');
            
            fields.forEach(function(field) {
                $(this).append('<option value="' + field + '">' + field + '</option>');
            }.bind(this));
            
            if (currentVal) $(this).val(currentVal);
        });
    }

    /**
     * Add condition row
     */
    function addConditionRow(condition) {
        condition = condition || {};
        
        const triggerId = $('#trigger_id').val();
        const trigger = state.triggers[triggerId];
        let payloadSchema = trigger ? trigger.payload_schema : [];
        
        if (typeof payloadSchema === 'string') {
            try { payloadSchema = JSON.parse(payloadSchema); } catch(e) { payloadSchema = []; }
        }
        const fields = payloadSchema || [];

        let html = '<div class="sflmcp-condition-row">';
        html += '  <select class="condition-field" name="condition_field[]">';
        html += '    <option value="">-- Field --</option>';
        fields.forEach(function(field) {
            const selected = condition.field === field ? ' selected' : '';
            html += '    <option value="' + field + '"' + selected + '>' + field + '</option>';
        });
        html += '  </select>';
        
        html += '  <select class="condition-operator" name="condition_operator[]">';
        const operators = [
            { value: 'equals', label: 'equals' },
            { value: 'not_equals', label: 'not equals' },
            { value: 'contains', label: 'contains' },
            { value: 'not_contains', label: 'not contains' },
            { value: 'greater_than', label: '>' },
            { value: 'less_than', label: '<' },
            { value: 'is_empty', label: 'is empty' },
            { value: 'is_not_empty', label: 'is not empty' }
        ];
        operators.forEach(function(op) {
            const selected = condition.operator === op.value ? ' selected' : '';
            html += '    <option value="' + op.value + '"' + selected + '>' + op.label + '</option>';
        });
        html += '  </select>';
        
        html += '  <input type="text" class="condition-value" name="condition_value[]" placeholder="Value" value="' + escapeHtml(condition.value || '') + '">';
        html += '  <button type="button" class="button-link" data-action="remove-condition"><span class="dashicons dashicons-dismiss"></span></button>';
        html += '</div>';

        $('#conditions-builder').append(html);
    }

    /**
     * Gather conditions from form
     */
    function gatherConditions() {
        const conditions = [];
        
        $('.sflmcp-condition-row').each(function() {
            const field = $(this).find('.condition-field').val();
            const operator = $(this).find('.condition-operator').val();
            const value = $(this).find('.condition-value').val();
            
            if (field) {
                conditions.push({ field: field, operator: operator, value: value });
            }
        });
        
        return conditions;
    }

    // ==========================================================================
    // Tools Selection
    // ==========================================================================

    /**
     * Handle tools mode change
     */
    function handleToolsModeChange() {
        const mode = $('[name="tools_mode"]:checked').val();
        $('#sflmcp-custom-tools-section').toggle(mode === 'custom');
        $('#sflmcp-tools-mode').val(mode);
    }

    /**
     * Render tools list
     */
    function renderToolsList() {
        const $container = $('#sflmcp-tools-list');
        if (!$container.length) return;

        const showAll = $('#sflmcp-show-all-tools').prop('checked');
        const tools = showAll ? (sflmcpEvents.allTools || sflmcpEvents.tools) : (sflmcpEvents.tools || state.tools);
        
        if (!tools || tools.length === 0) {
            $container.html('<p class="description">Loading tools...</p>');
            return;
        }

        // Group by category
        const toolsByCategory = {};
        tools.forEach(function(tool) {
            const category = tool.category || 'Other';
            if (!toolsByCategory[category]) toolsByCategory[category] = [];
            toolsByCategory[category].push(tool);
        });

        let html = '';
        Object.keys(toolsByCategory).sort().forEach(function(category) {
            html += '<div class="sflmcp-tools-category">';
            html += '  <div class="sflmcp-tools-category-header">' + escapeHtml(category) + '</div>';
            html += '  <div class="sflmcp-tools-grid">';
            
            toolsByCategory[category].forEach(function(tool) {
                const isSelected = state.selectedTools.includes(tool.name);
                const checked = isSelected ? ' checked' : '';
                const selectedClass = isSelected ? ' selected' : '';
                html += '<label class="sflmcp-tool-item' + selectedClass + '" data-tool="' + escapeHtml(tool.name) + '">' +
                    '<input type="checkbox" value="' + escapeHtml(tool.name) + '"' + checked + '>' +
                    escapeHtml(tool.name) +
                '</label>';
            });
            
            html += '  </div>';
            html += '</div>';
        });

        $container.html(html);
    }

    /**
     * Handle tool selection
     */
    function handleToolSelect(e) {
        e.preventDefault();
        const $el = $(this);
        const toolName = $el.data('tool');

        // Toggle visual selection
        $el.toggleClass('selected');

        // Update internal state
        if ($el.hasClass('selected')) {
            if (!state.selectedTools.includes(toolName)) {
                state.selectedTools.push(toolName);
            }
        } else {
            state.selectedTools = state.selectedTools.filter(function(t) { return t !== toolName; });
        }

        // Update hidden checkbox for form compatibility
        $el.find('input[type="checkbox"]').prop('checked', $el.hasClass('selected'));
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

        // Hide empty categories
        $('.sflmcp-tools-category').each(function() {
            const hasVisible = $(this).find('.sflmcp-tool-item:visible').length > 0;
            $(this).toggle(hasVisible);
        });
    }

    /**
     * Gather selected tools
     */
    function gatherSelectedTools() {
        const tools = [];
        $('#sflmcp-tools-list input[type="checkbox"]:checked').each(function() {
            tools.push($(this).val());
        });
        return tools;
    }

    // ==========================================================================
    // Test Chat Functions
    // ==========================================================================

    /**
     * Handle test prompt - show placeholder modal first
     */
    function handleTestPrompt() {
        const $btn = $(this);
        const prompt = $('#prompt').val();
        const triggerId = $('#trigger_id').val();

        console.log('[SFLMCP Events] Test Prompt clicked');
        console.log('[SFLMCP Events] Prompt:', prompt);
        console.log('[SFLMCP Events] Trigger:', triggerId);

        if (!prompt.trim()) {
            alert('Please enter a prompt first');
            return;
        }

        if (!triggerId) {
            alert('Please select a trigger first');
            return;
        }

        // Get placeholders from the trigger
        const trigger = state.triggers[triggerId];
        let payloadSchema = trigger ? trigger.payload_schema : [];
        
        if (typeof payloadSchema === 'string') {
            try { payloadSchema = JSON.parse(payloadSchema); } catch(e) { payloadSchema = []; }
        }
        payloadSchema = payloadSchema || [];

        console.log('[SFLMCP Events] Trigger payload schema:', payloadSchema);

        // Show modal to input placeholder values
        showTestPlaceholderModal(payloadSchema, function(payload) {
            console.log('[SFLMCP Events] Payload from modal:', payload);
            executeTestWithPayload(prompt, payload);
        });
    }

    /**
     * Show modal for entering test placeholder values
     */
    function showTestPlaceholderModal(placeholders, onConfirm) {
        closeModal();

        let fieldsHtml = '';
        if (placeholders.length > 0) {
            fieldsHtml = '<div class="sflmcp-test-placeholder-fields">';
            placeholders.forEach(function(field) {
                fieldsHtml += '<div class="sflmcp-form-row">' +
                    '<label for="test-placeholder-' + escapeHtml(field) + '">{{' + escapeHtml(field) + '}}</label>' +
                    '<input type="text" id="test-placeholder-' + escapeHtml(field) + '" ' +
                           'data-placeholder="' + escapeHtml(field) + '" ' +
                           'placeholder="Sample value for ' + escapeHtml(field) + '">' +
                '</div>';
            });
            fieldsHtml += '</div>';
        } else {
            fieldsHtml = '<p class="description">This trigger has no placeholders. Click "Run Test" to proceed.</p>';
        }

        const html = '<div class="sflmcp-test-modal-intro">' +
            '<p><span class="dashicons dashicons-info-outline"></span> ' +
            '<strong>Simulate Event Data</strong></p>' +
            '<p class="description">Enter sample values for the event placeholders below. ' +
            'These values will replace the {{placeholders}} in your prompt during the test. ' +
            'This is optional - leave empty to test with placeholder names unchanged.</p>' +
        '</div>' + fieldsHtml;

        const footer = '<button type="button" class="button" id="sflmcp-test-cancel">Cancel</button>' +
            '<button type="button" class="button button-primary" id="sflmcp-test-run">' +
            '<span class="dashicons dashicons-controls-play"></span> Run Test</button>';

        const $modal = $('<div class="sflmcp-modal">' +
            '<div class="sflmcp-modal-content">' +
                '<div class="sflmcp-modal-header">' +
                    '<h3><span class="dashicons dashicons-superhero-alt"></span> Test Event Automation</h3>' +
                    '<button type="button" class="sflmcp-modal-close">&times;</button>' +
                '</div>' +
                '<div class="sflmcp-modal-body">' + html + '</div>' +
                '<div class="sflmcp-modal-footer">' + footer + '</div>' +
            '</div>' +
        '</div>');

        $('body').append($modal);

        // Handle cancel
        $modal.find('#sflmcp-test-cancel, .sflmcp-modal-close').on('click', function() {
            closeModal();
        });

        // Handle run test
        $modal.find('#sflmcp-test-run').on('click', function() {
            const payload = {};
            $modal.find('[data-placeholder]').each(function() {
                const key = $(this).data('placeholder');
                const val = $(this).val();
                if (val) {
                    payload[key] = val;
                }
            });
            closeModal();
            onConfirm(payload);
        });

        // Focus first input
        setTimeout(function() {
            $modal.find('input:first').focus();
        }, 100);
    }

    /**
     * Execute test with payload
     */
    function executeTestWithPayload(prompt, payload) {
        const $btn = $('#sflmcp-test-prompt');
        const systemPrompt = $('#system_prompt').val();
        const triggerId = $('#trigger_id').val();
        const toolsMode = $('[name="tools_mode"]:checked').val();
        
        let toolsEnabled = '[]';
        if (toolsMode === 'custom') {
            toolsEnabled = JSON.stringify(gatherSelectedTools());
        } else if (toolsMode === 'detected' && state.detectedTools.length > 0) {
            toolsEnabled = JSON.stringify(state.detectedTools);
        }

        console.log('[SFLMCP Events] Executing test with:');
        console.log('[SFLMCP Events]   trigger_id:', triggerId);
        console.log('[SFLMCP Events]   prompt:', prompt);
        console.log('[SFLMCP Events]   system_prompt:', systemPrompt);
        console.log('[SFLMCP Events]   payload:', payload);
        console.log('[SFLMCP Events]   tools_enabled:', toolsEnabled);

        // Add user message to chat
        addChatMessage('user', prompt);

        // Show thinking indicator
        showThinking();

        $btn.prop('disabled', true);
        $('#sflmcp-test-summary').hide();
        state.detectedTools = [];

        const startTime = Date.now();

        console.log('[SFLMCP Events] Making AJAX request to sflmcp_events_test_automation');

        // Use the event automation test endpoint
        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_events_test_automation',
                nonce: sflmcpEvents.nonce,
                trigger_id: triggerId,
                prompt: prompt,
                system_prompt: systemPrompt,
                tools_enabled: toolsEnabled,
                payload: JSON.stringify(payload)
            },
            success: function(response) {
                console.log('[SFLMCP Events] AJAX response:', response);
                hideThinking();
                $btn.prop('disabled', false);

                if (!response.success) {
                    console.error('[SFLMCP Events] Test failed:', response.data);
                    addChatMessage('assistant', 'Error: ' + (response.data?.message || 'Test failed'));
                    return;
                }

                // Show resolved prompt if different
                if (response.data.resolved_prompt && response.data.resolved_prompt !== prompt) {
                    addChatMessage('assistant', '📝 Resolved prompt:\n"' + response.data.resolved_prompt + '"');
                }

                // Show tools executed
                if (response.data.tools_executed && response.data.tools_executed.length > 0) {
                    response.data.tools_executed.forEach(function(tool) {
                        addToolMessagePending(tool.name, tool.arguments || {});
                        updateToolMessageWithResult(tool.name, tool.result);
                        
                        if (!state.detectedTools.includes(tool.name)) {
                            state.detectedTools.push(tool.name);
                        }
                    });
                }

                // Show response
                if (response.data.response) {
                    addChatMessage('assistant', response.data.response);
                }

                // Show error if any
                if (response.data.error) {
                    addChatMessage('assistant', '⚠️ Error: ' + response.data.error);
                }

                // Finish
                finishTest(startTime, state.detectedTools);
            },
            error: function(xhr, status, error) {
                console.error('[SFLMCP Events] AJAX error:', status, error);
                console.error('[SFLMCP Events] XHR response:', xhr.responseText);
                hideThinking();
                $btn.prop('disabled', false);
                addChatMessage('assistant', 'Connection error: ' + error + '. Check browser console (F12) for details.');
            }
        });
    }

    /**
     * Add a message to the test chat
     */
    function addChatMessage(role, content) {
        const $messages = $('#sflmcp-test-chat-messages');
        
        // Remove welcome message if present
        $messages.find('.sflmcp-test-welcome').remove();
        
        const avatarIcon = role === 'user' ? 'admin-users' : 'superhero';
        const roleClass = role === 'user' ? 'sflmcp-test-message-user' : 'sflmcp-test-message-assistant';
        
        const $message = $('<div class="sflmcp-test-message ' + roleClass + '">' +
            '<div class="sflmcp-test-avatar">' +
                '<span class="dashicons dashicons-' + avatarIcon + '"></span>' +
            '</div>' +
            '<div class="sflmcp-test-content">' + escapeHtml(content) + '</div>' +
        '</div>');
        
        $messages.append($message);
        $messages.scrollTop($messages[0].scrollHeight);
        
        return $message;
    }

    /**
     * Add a pending tool message
     */
    function addToolMessagePending(toolName, args) {
        const $messages = $('#sflmcp-test-chat-messages');

        let argsPreview = '';
        if (args && typeof args === 'object') {
            const entries = Object.entries(args).slice(0, 2);
            argsPreview = entries.map(function(entry) {
                const k = entry[0];
                const v = entry[1];
                const val = typeof v === 'string' ? v.substring(0, 30) : JSON.stringify(v).substring(0, 30);
                return k + ': ' + val + (val.length >= 30 ? '...' : '');
            }).join(', ');
        }

        const $message = $('<div class="sflmcp-test-message sflmcp-test-message-tool" data-tool="' + escapeHtml(toolName) + '">' +
            '<div class="sflmcp-test-avatar">' +
                '<span class="dashicons dashicons-admin-tools"></span>' +
            '</div>' +
            '<div class="sflmcp-test-content">' +
                '<div class="sflmcp-tool-header">' +
                    '<span class="sflmcp-tool-executed">' + escapeHtml(toolName) + '</span>' +
                    '<span class="sflmcp-tool-status sflmcp-tool-pending">' +
                        '<span class="spinner is-active"></span>' +
                    '</span>' +
                '</div>' +
                (argsPreview ? '<div class="sflmcp-tool-args-preview">' + escapeHtml(argsPreview) + '</div>' : '') +
            '</div>' +
        '</div>');

        $messages.append($message);
        $messages.scrollTop($messages[0].scrollHeight);
    }

    /**
     * Update a pending tool message with result
     */
    function updateToolMessageWithResult(toolName, result) {
        const $message = $('.sflmcp-test-message-tool[data-tool="' + toolName + '"]').last();

        if ($message.length) {
            $message.find('.sflmcp-tool-status').html('&#10003;').removeClass('sflmcp-tool-pending').addClass('sflmcp-tool-done');

            if (result) {
                const resultStr = typeof result === 'string' ? result : JSON.stringify(result);
                const preview = resultStr.substring(0, 150) + (resultStr.length > 150 ? '...' : '');
                $message.find('.sflmcp-test-content').append(
                    '<div class="sflmcp-tool-result"><small>' + escapeHtml(preview) + '</small></div>'
                );
            }
        }

        $('#sflmcp-test-chat-messages').scrollTop($('#sflmcp-test-chat-messages')[0].scrollHeight);
    }

    /**
     * Show thinking indicator
     */
    function showThinking() {
        const $messages = $('#sflmcp-test-chat-messages');
        $messages.find('.sflmcp-test-thinking').remove();
        
        const $thinking = $('<div class="sflmcp-test-thinking">' +
            '<span class="sflmcp-thinking-dots">' +
                '<span></span><span></span><span></span>' +
            '</span>' +
            '<span class="sflmcp-thinking-text">' + (sflmcpEvents.i18n?.testing || 'Testing...') + '</span>' +
        '</div>');
        
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
        $messages.empty().append(
            '<div class="sflmcp-test-welcome">' +
                '<span class="dashicons dashicons-lightbulb"></span>' +
                'Write your prompt below and click "Test Prompt" to see how the AI responds. Tool executions will appear here.' +
            '</div>'
        );
        $('#sflmcp-test-summary').hide();
        state.detectedTools = [];
    }

    /**
     * Finish test and show summary
     */
    function finishTest(startTime, toolsUsed) {
        const duration = ((Date.now() - startTime) / 1000).toFixed(1);

        state.detectedTools = toolsUsed;
        $('#sflmcp-detected-tools').val(state.detectedTools.join(','));

        if (state.detectedTools.length > 0) {
            let toolsHtml = state.detectedTools.map(function(t) {
                return '<span class="sflmcp-detected-tool"><span class="dashicons dashicons-yes"></span> ' + t + '</span>';
            }).join('');
            $('#sflmcp-detected-tools-list').html(toolsHtml);

            $('#sflmcp-test-suggestion').html(
                '<strong>Tip:</strong> ' +
                state.detectedTools.length + ' tools detected (' + duration + 's). ' +
                '<button type="button" class="button button-small" id="sflmcp-apply-detected">' +
                    'Apply detected tools' +
                '</button>'
            );

            $('#sflmcp-apply-detected').on('click', function() {
                state.selectedTools = state.detectedTools.slice();
                $('[name="tools_mode"][value="custom"]').prop('checked', true).trigger('change');
                renderToolsList();
            });

            $('#sflmcp-test-summary').show();
        } else {
            $('#sflmcp-detected-tools-list').html(
                '<span class="sflmcp-no-tools">No tools were called</span>'
            );
            $('#sflmcp-test-suggestion').html('<small>' + duration + 's</small>');
            $('#sflmcp-test-summary').show();
        }
    }

    // ==========================================================================
    // Save Automation
    // ==========================================================================

    /**
     * Save automation
     */
    function saveAutomation(status) {
        const $form = $('#sflmcp-task-form');
        const $submitBtn = status === 'active' ? $('#sflmcp-save-active') : $('#sflmcp-save-draft');

        // Update hidden JSON fields
        $('#conditions-json').val(JSON.stringify(gatherConditions()));
        
        // Determine tools based on mode
        const toolsMode = $('[name="tools_mode"]:checked').val();
        let toolsEnabled = '[]';
        if (toolsMode === 'custom') {
            toolsEnabled = JSON.stringify(gatherSelectedTools());
        } else if (toolsMode === 'detected') {
            // Validate detected tools exist
            if (state.detectedTools.length === 0) {
                alert('No tools detected yet. Please run "Test Prompt" first to detect which tools are needed, or switch to "Custom Selection" and select tools manually.');
                return;
            }
            toolsEnabled = JSON.stringify(state.detectedTools);
        }
        $('#tools-enabled-json').val(toolsEnabled);

        const formData = {
            action: 'sflmcp_events_save_automation',
            nonce: sflmcpEvents.nonce,
            id: $('#sflmcp-task-id').val() || 0,
            automation_name: $('#automation_name').val(),
            trigger_id: $('#trigger_id').val(),
            prompt: $('#prompt').val(),
            system_prompt: $('#system_prompt').val(),
            conditions: $('#conditions-json').val(),
            tools_enabled: toolsEnabled,
            provider: $('#provider').val() || '',
            model: $('#model').val() || '',
            max_tokens: $('#max_tokens').val() || 2000,
            status: status,
            // Output actions
            output_email: $('#sflmcp-output-email').is(':checked') ? '1' : '0',
            email_recipients: $('#sflmcp-email-recipients').val() || '',
            email_subject: $('#sflmcp-email-subject').val() || '',
            output_webhook: $('#sflmcp-output-webhook').is(':checked') ? '1' : '0',
            webhook_url: $('#sflmcp-webhook-url').val() || '',
            webhook_preset: $('#sflmcp-webhook-preset').val() || 'custom',
            output_draft: $('#sflmcp-output-draft').is(':checked') ? '1' : '0',
            draft_post_type: $('#sflmcp-draft-post-type').val() || 'post'
        };

        // Validate
        if (!formData.automation_name) {
            alert('Please enter a name for the automation.');
            return;
        }
        if (!formData.trigger_id) {
            alert('Please select a trigger.');
            return;
        }
        if (!formData.prompt) {
            alert('Please enter a prompt.');
            return;
        }

        $submitBtn.prop('disabled', true);

        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: formData,
            success: function(response) {
                $submitBtn.prop('disabled', false);

                if (response.success) {
                    window.location.href = '?page=sflmcp-events&tab=list&saved=1';
                } else {
                    alert(response.data?.message || 'Error saving automation');
                }
            },
            error: function() {
                $submitBtn.prop('disabled', false);
                alert('Connection error');
            }
        });
    }

    // ==========================================================================
    // Logs Tab Functions
    // ==========================================================================

    /**
     * Load logs
     */
    function loadLogs() {
        const $container = $('#sflmcp-logs-list');
        const status = $('#sflmcp-log-filter-status').val();
        const automationId = $('#sflmcp-log-filter-automation').val();

        $container.html('<div class="sflmcp-loading"><span class="spinner is-active"></span> Loading logs...</div>');

        $.ajax({
            url: sflmcpEvents.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_events_get_logs',
                nonce: sflmcpEvents.nonce,
                status: status,
                automation_id: automationId
            },
            success: function(response) {
                if (response.success) {
                    state.logs = response.data.logs;
                    renderLogs();
                    
                    // Populate automation filter
                    if (response.data.automations) {
                        populateAutomationFilter(response.data.automations);
                    }
                } else {
                    $container.html('<div class="sflmcp-empty-state"><span class="dashicons dashicons-warning"></span><p>Error loading logs</p></div>');
                }
            },
            error: function() {
                $container.html('<div class="sflmcp-empty-state"><span class="dashicons dashicons-warning"></span><p>Connection error</p></div>');
            }
        });
    }

    /**
     * Populate automation filter dropdown
     */
    function populateAutomationFilter(automations) {
        const $select = $('#sflmcp-log-filter-automation');
        const currentVal = $select.val();
        
        $select.find('option:not(:first)').remove();
        automations.forEach(function(auto) {
            $select.append('<option value="' + auto.id + '">' + escapeHtml(auto.automation_name) + '</option>');
        });
        
        if (currentVal) $select.val(currentVal);
    }

    /**
     * Render logs cards
     */
    function renderLogs() {
        const $container = $('#sflmcp-logs-list');

        if (!state.logs || state.logs.length === 0) {
            $container.html(
                '<div class="sflmcp-empty-state">' +
                    '<span class="dashicons dashicons-backup"></span>' +
                    '<h3>No execution logs yet</h3>' +
                    '<p>Logs will appear here when event automations are triggered.</p>' +
                '</div>'
            );
            return;
        }

        let html = '';
        state.logs.forEach(function(log) {
            const statusIcon = getStatusIcon(log.status);
            
            html += '<div class="sflmcp-task-card status-' + log.status + '" data-log-id="' + log.id + '">' +
                '<div class="sflmcp-task-info">' +
                    '<h3>' +
                        '<span class="dashicons dashicons-backup"></span> ' +
                        escapeHtml(log.automation_name || 'Unknown') +
                        '<span class="sflmcp-task-status status-' + log.status + '">' + statusIcon + ' ' + log.status + '</span>' +
                    '</h3>' +
                    '<div class="sflmcp-task-meta">' +
                        '<span><span class="dashicons dashicons-tag"></span> ' + escapeHtml(log.trigger_id) + '</span>' +
                        '<span><span class="dashicons dashicons-clock"></span> ' + formatDateTime(log.created_at) + '</span>' +
                        (log.execution_time ? '<span><span class="dashicons dashicons-performance"></span> ' + (log.execution_time / 1000).toFixed(2) + 's</span>' : '') +
                    '</div>' +
                '</div>' +
                '<div class="sflmcp-task-actions">' +
                    '<button type="button" class="button">View Details</button>' +
                '</div>' +
            '</div>';
        });

        $container.html(html);
    }

    /**
     * Handle view log
     */
    function handleViewLog() {
        const logId = $(this).data('log-id');
        const log = state.logs.find(function(l) { return l.id == logId; });
        
        if (log) {
            showLogModal(log);
        }
    }

    /**
     * Show log detail modal
     */
    function showLogModal(log) {
        let html = '<div class="sflmcp-log-detail">';
        
        html += '<table class="widefat striped">';
        html += '<tr><th>Automation</th><td>' + escapeHtml(log.automation_name || 'Unknown') + '</td></tr>';
        html += '<tr><th>Trigger</th><td>' + escapeHtml(log.trigger_id) + '</td></tr>';
        html += '<tr><th>Status</th><td><span class="sflmcp-task-status status-' + log.status + '">' + log.status + '</span></td></tr>';
        html += '<tr><th>Time</th><td>' + escapeHtml(log.created_at) + '</td></tr>';
        if (log.execution_time) {
            html += '<tr><th>Duration</th><td>' + parseFloat(log.execution_time).toFixed(2) + 's</td></tr>';
        }
        if (log.tokens_used) {
            html += '<tr><th>Tokens Used</th><td>' + log.tokens_used + '</td></tr>';
        }
        html += '</table>';

        // Trigger Payload
        if (log.trigger_payload) {
            html += '<h4>Trigger Payload</h4>';
            try {
                html += '<pre>' + escapeHtml(JSON.stringify(JSON.parse(log.trigger_payload), null, 2)) + '</pre>';
            } catch(e) {
                html += '<pre>' + escapeHtml(log.trigger_payload) + '</pre>';
            }
        }

        // Prompt Sent
        if (log.prompt_sent) {
            html += '<h4>Prompt Sent</h4>';
            html += '<pre>' + escapeHtml(log.prompt_sent) + '</pre>';
        }

        // AI Response (text)
        if (log.response) {
            html += '<h4>AI Response</h4>';
            html += '<pre class="sflmcp-log-response">' + escapeHtml(log.response) + '</pre>';
        }

        // Tools Executed
        if (log.tools_executed) {
            let tools = [];
            try {
                tools = JSON.parse(log.tools_executed);
            } catch(e) {
                tools = [];
            }
            if (tools && tools.length > 0) {
                html += '<h4>Tools Executed (' + tools.length + ')</h4>';
                html += '<div class="sflmcp-tools-executed">';
                tools.forEach(function(tool) {
                    const toolName = typeof tool === 'string' ? tool : (tool.name || 'unknown');
                    const toolResult = typeof tool === 'object' && tool.result ? tool.result : null;
                    html += '<div class="sflmcp-tool-item">';
                    html += '<strong>' + escapeHtml(toolName) + '</strong>';
                    if (toolResult) {
                        try {
                            html += '<pre>' + escapeHtml(JSON.stringify(toolResult, null, 2)) + '</pre>';
                        } catch(e) {
                            html += '<pre>' + escapeHtml(String(toolResult)) + '</pre>';
                        }
                    }
                    html += '</div>';
                });
                html += '</div>';
            }
        }

        // Error Message
        if (log.error_message) {
            html += '<h4>Error</h4>';
            html += '<pre class="sflmcp-log-error">' + escapeHtml(log.error_message) + '</pre>';
        }

        html += '</div>';

        showModal('Log Details', html);
    }

    // ==========================================================================
    // Utility Functions
    // ==========================================================================

    /**
     * Show modal
     */
    function showModal(title, content, footer) {
        closeModal();
        
        const $modal = $('<div class="sflmcp-modal">' +
            '<div class="sflmcp-modal-content sflmcp-modal-large">' +
                '<div class="sflmcp-modal-header">' +
                    '<h3>' + escapeHtml(title) + '</h3>' +
                    '<button type="button" class="sflmcp-modal-close">&times;</button>' +
                '</div>' +
                '<div class="sflmcp-modal-body">' + content + '</div>' +
                (footer ? '<div class="sflmcp-modal-footer">' + footer + '</div>' : '') +
            '</div>' +
        '</div>');
        
        $('body').append($modal);
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('.sflmcp-modal').remove();
    }

    /**
     * Get status icon
     */
    function getStatusIcon(status) {
        const icons = {
            'active': '&#9679;',
            'paused': '&#9684;',
            'error': '&#10005;',
            'draft': '&#9675;',
            'success': '&#10003;',
            'skipped': '&#8722;'
        };
        return icons[status] || '&#9675;';
    }

    /**
     * Format date time
     */
    function formatDateTime(dateStr) {
        if (!dateStr) return 'Never';
        try {
            const date = new Date(dateStr);
            return date.toLocaleString();
        } catch(e) {
            return dateStr;
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);
