/**
 * StifLi Flex MCP - AI Chat Agent JavaScript
 * 
 * Handles chat UI, AI provider communication, tool execution, and history management
 * 
 * @package StifliFlexMcp
 * @since 1.0.5
 */

(function($) {
    'use strict';

    // Chat state
    const state = {
        conversation: [],
        chatHistory: [], // Full history with tool details
        isProcessing: false,
        isStopped: false, // User clicked Stop
        pendingToolCalls: [],
        currentToolIndex: 0,
        settings: sflmcpClient.settings || {},
        advanced: sflmcpClient.advanced || {},
        saveTimeout: null,
        advancedSaveTimeout: null,
        sessionId: generateSessionId()
    };

    /**
     * Generate a unique session ID for change tracking
     */
    function generateSessionId() {
        return 'chat-' + Date.now().toString(36) + '-' + Math.random().toString(36).substring(2, 10);
    }

    // Cache DOM elements (chat tab)
    let $chatMessages, $chatInput, $sendBtn, $clearBtn;
    let $providerSelect, $modelSelect, $apiKeyInput, $permissionSelect, $toolModal;
    let $autosaveIndicator, $settingsAutosave;

    // Cache DOM elements (advanced tab)
    let $advProviderSelect, $advModelSelect;
    let $systemPrompt, $toolDisplay, $maxTools;
    let $maxHistoryTurns;
    let $temperature, $temperatureValue;
    let $maxTokens, $topP, $topPValue;
    let $frequencyPenalty, $frequencyPenaltyValue;
    let $presencePenalty, $presencePenaltyValue;
    let $advancedSaveIndicator;
    let $enableSuggestions, $suggestionsCount, $explicitCaching;

    /**
     * Initialize the chat client
     */
    function init() {
        // Cache elements based on which tab is active
        cacheChatElements();
        cacheAdvancedElements();
        
        bindEvents();
        
        // If on chat tab, load history
        if ($chatMessages && $chatMessages.length) {
            loadHistory();
        }
        
        // If on advanced tab, setup auto-save
        if ($systemPrompt && $systemPrompt.length) {
            updateAdvancedModelOptions();
            updateModelParamVisibility();
        }
    }

    /**
     * Cache chat tab elements
     */
    function cacheChatElements() {
        $chatMessages = $('#sflmcp-chat-messages');
        $chatInput = $('#sflmcp-chat-input');
        $sendBtn = $('#sflmcp-send-btn');
        $clearBtn = $('#sflmcp-clear-chat');
        $providerSelect = $('#sflmcp-provider');
        $modelSelect = $('#sflmcp-model');
        $apiKeyInput = $('#sflmcp-api-key');
        $permissionSelect = $('#sflmcp-permission');
        $toolModal = $('#sflmcp-tool-modal');
        $autosaveIndicator = $('#sflmcp-autosave-indicator');
        $settingsAutosave = $('#sflmcp-settings-autosave');
    }

    /**
     * Cache advanced tab elements
     */
    function cacheAdvancedElements() {
        $advProviderSelect = $('#sflmcp-adv-provider');
        $advModelSelect = $('#sflmcp-adv-model');
        $systemPrompt = $('#sflmcp-system-prompt');
        $toolDisplay = $('#sflmcp-tool-display');
        $maxHistoryTurns = $('#sflmcp-max-history-turns');
        $maxTools = $('#sflmcp-max-tools');
        $temperature = $('#sflmcp-temperature');
        $temperatureValue = $('#sflmcp-temperature-value');
        $maxTokens = $('#sflmcp-max-tokens');
        $topP = $('#sflmcp-top-p');
        $topPValue = $('#sflmcp-top-p-value');
        $frequencyPenalty = $('#sflmcp-frequency-penalty');
        $frequencyPenaltyValue = $('#sflmcp-frequency-penalty-value');
        $presencePenalty = $('#sflmcp-presence-penalty');
        $presencePenaltyValue = $('#sflmcp-presence-penalty-value');
        $advancedSaveIndicator = $('#sflmcp-advanced-save-indicator');
        $enableSuggestions = $('#sflmcp-enable-suggestions');
        $suggestionsCount = $('#sflmcp-suggestions-count');
        $explicitCaching = $('#sflmcp-explicit-caching');
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Chat tab events
        if ($sendBtn && $sendBtn.length) {
            $sendBtn.on('click', handleSendButtonClick);
            $chatInput.on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    if (!state.isProcessing) {
                        sendMessage();
                    }
                }
            });
            $clearBtn.on('click', clearChat);
            $providerSelect.on('change', function() {
                updateModelOptions();
                triggerChatAutoSave();
            });
            $modelSelect.on('change', triggerChatAutoSave);
            $apiKeyInput.on('input', triggerChatAutoSave);
            $permissionSelect.on('change', triggerChatAutoSave);

            // API key show/hide toggle
            $('#sflmcp-api-key-toggle').on('click', function() {
                var $icon = $(this).find('.dashicons');
                if ($apiKeyInput.attr('type') === 'password') {
                    $apiKeyInput.attr('type', 'text');
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                } else {
                    $apiKeyInput.attr('type', 'password');
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                }
            });
            
            // Modal buttons
            $toolModal.find('.sflmcp-modal-allow').on('click', function() {
                $toolModal.hide();
                processToolCall(true);
            });
            $toolModal.find('.sflmcp-modal-deny').on('click', function() {
                $toolModal.hide();
                processToolCall(false);
            });
            
            // Initialize model options
            updateModelOptions();
        }

        // Advanced tab events
        if ($advProviderSelect && $advProviderSelect.length) {
            $advProviderSelect.on('change', function() {
                updateAdvancedModelOptions();
                updateModelParamVisibility();
                triggerAdvancedAutoSave();
            });
            
            $advModelSelect.on('change', triggerAdvancedAutoSave);
            
            // Auto-save on all advanced fields
            $systemPrompt.on('input', triggerAdvancedAutoSave);
            $toolDisplay.on('change', triggerAdvancedAutoSave);
            $maxHistoryTurns.on('input', triggerAdvancedAutoSave);
            $maxTools.on('input', triggerAdvancedAutoSave);
            $maxTokens.on('input', triggerAdvancedAutoSave);
            
            // Suggestions fields
            $enableSuggestions.on('change', function() {
                state.advanced.enable_suggestions = $(this).is(':checked');
                triggerAdvancedAutoSave();
            });
            $suggestionsCount.on('input', function() {
                state.advanced.suggestions_count = parseInt($(this).val()) || 3;
                triggerAdvancedAutoSave();
            });

            // Explicit caching toggle
            $explicitCaching.on('change', function() {
                state.advanced.explicit_caching = $(this).is(':checked');
                triggerAdvancedAutoSave();
            });
            
            // Range sliders with value display
            $temperature.on('input', function() {
                $temperatureValue.text($(this).val());
                triggerAdvancedAutoSave();
            });
            $topP.on('input', function() {
                $topPValue.text($(this).val());
                triggerAdvancedAutoSave();
            });
            $frequencyPenalty.on('input', function() {
                $frequencyPenaltyValue.text($(this).val());
                triggerAdvancedAutoSave();
            });
            $presencePenalty.on('input', function() {
                $presencePenaltyValue.text($(this).val());
                triggerAdvancedAutoSave();
            });
        }
    }

    /**
     * Update model options based on provider (chat tab)
     */
    function updateModelOptions() {
        const provider = $providerSelect.val();
        let firstMatch = null;
        let currentVisible = false;
        $modelSelect.find('option').each(function() {
            const $opt = $(this);
            if ($opt.data('provider') === provider) {
                $opt.prop('disabled', false).prop('hidden', false);
                if (!firstMatch) firstMatch = $opt;
                if ($opt.is(':selected')) currentVisible = true;
            } else {
                $opt.prop('disabled', true).prop('hidden', true);
            }
        });
        // Force select first matching option if current is not for this provider
        if (!currentVisible && firstMatch) {
            firstMatch.prop('selected', true);
        }
    }

    /**
     * Update model options based on provider (advanced tab)
     */
    function updateAdvancedModelOptions() {
        const provider = $advProviderSelect.val();
        let firstMatch = null;
        let currentVisible = false;
        $advModelSelect.find('option').each(function() {
            const $opt = $(this);
            if ($opt.data('provider') === provider) {
                $opt.prop('disabled', false).prop('hidden', false);
                if (!firstMatch) firstMatch = $opt;
                if ($opt.is(':selected')) currentVisible = true;
            } else {
                $opt.prop('disabled', true).prop('hidden', true);
            }
        });
        if (!currentVisible && firstMatch) {
            firstMatch.prop('selected', true);
        }
    }

    /**
     * Show/hide model parameters based on provider
     */
    function updateModelParamVisibility() {
        const provider = $advProviderSelect.val();
        $('.sflmcp-model-param').each(function() {
            const $row = $(this);
            const providers = ($row.data('providers') || '').split(',');
            if (providers.includes(provider)) {
                $row.show();
            } else {
                $row.hide();
            }
        });
    }

    /**
     * Trigger auto-save for chat header settings (debounced)
     */
    function triggerChatAutoSave() {
        if (state.saveTimeout) {
            clearTimeout(state.saveTimeout);
        }
        state.saveTimeout = setTimeout(saveSettings, 500);
    }

    /**
     * Trigger auto-save for advanced settings (debounced)
     */
    function triggerAdvancedAutoSave() {
        if (state.advancedSaveTimeout) {
            clearTimeout(state.advancedSaveTimeout);
        }
        state.advancedSaveTimeout = setTimeout(saveAdvancedSettings, 500);
    }

    /**
     * Save advanced settings via AJAX
     */
    function saveAdvancedSettings() {
        $.ajax({
            url: sflmcpClient.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_client_save_advanced',
                nonce: sflmcpClient.nonce,
                system_prompt: $systemPrompt.val(),
                tool_display: $toolDisplay.val(),
                max_history_turns: $maxHistoryTurns.val(),
                max_tools_per_turn: $maxTools.val(),
                temperature: $temperature.val(),
                max_tokens: $maxTokens.val(),
                top_p: $topP.val(),
                frequency_penalty: $frequencyPenalty.val(),
                presence_penalty: $presencePenalty.val(),
                enable_suggestions: $enableSuggestions.is(':checked') ? 1 : 0,
                suggestions_count: $suggestionsCount.val(),
                explicit_caching: $explicitCaching.is(':checked') ? 1 : 0,
                adv_provider: $advProviderSelect.val(),
                adv_model: $advModelSelect.val()
            },
            success: function(response) {
                if (response.success) {
                    showAdvancedSaveIndicator();
                }
            }
        });
    }

    /**
     * Show the advanced save indicator
     */
    function showAdvancedSaveIndicator() {
        $advancedSaveIndicator.fadeIn(200);
        setTimeout(function() {
            $advancedSaveIndicator.fadeOut(300);
        }, 2000);
    }

    /**
     * Load chat history from server
     */
    function loadHistory() {
        $.ajax({
            url: sflmcpClient.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_client_load_history',
                nonce: sflmcpClient.nonce
            },
            success: function(response) {
                if (response.success && response.data.history && response.data.history.length > 0) {
                    state.chatHistory = response.data.history;
                    rebuildChatFromHistory();
                    showNotice(sflmcpClient.i18n.historyRestored, 'info');
                }
            }
        });
    }

    /**
     * Save chat history to server
     */
    function saveHistory() {
        if (state.saveTimeout) {
            clearTimeout(state.saveTimeout);
        }
        
        state.saveTimeout = setTimeout(function() {
            $.ajax({
                url: sflmcpClient.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'sflmcp_client_save_history',
                    nonce: sflmcpClient.nonce,
                    history: JSON.stringify(state.chatHistory)
                },
                success: function(response) {
                    if (response.success) {
                        $autosaveIndicator.fadeIn(200);
                        setTimeout(function() {
                            $autosaveIndicator.fadeOut(300);
                        }, 2000);
                    }
                }
            });
        }, 1000); // Debounce 1 second
    }

    /**
     * Rebuild chat UI from history
     */
    function rebuildChatFromHistory() {
        // Remove welcome message
        $('.sflmcp-welcome-message').remove();
        
        // Clear messages but not the container
        $chatMessages.empty();
        
        // Rebuild conversation for API
        state.conversation = [];
        
        // Render each history item
        state.chatHistory.forEach(function(item) {
            if (item.type === 'user') {
                addMessage('user', item.content, false);
                state.conversation.push({ role: 'user', content: item.content });
            } else if (item.type === 'assistant') {
                addMessage('assistant', item.content, false);
                state.conversation.push({ role: 'assistant', content: item.content });
            } else if (item.type === 'tool') {
                renderToolFromHistory(item);
            }
        });
        
        scrollToBottom();
    }

    /**
     * Render a tool execution from history
     */
    function renderToolFromHistory(item) {
        const displayMode = state.advanced.tool_display || 'full';
        const description = getToolDescription(item.name);
        const $msg = $('<div class="sflmcp-tool-message sflmcp-tool-' + item.status + '"></div>');
        const $header = $('<div class="sflmcp-tool-header"></div>');
        const $body = $('<div class="sflmcp-tool-body"></div>');

        $header.html('<span class="dashicons dashicons-admin-tools"></span> <strong>' + escapeHtml(item.name) + '</strong>');
        
        let bodyHtml = '';
        
        // Helper to wrap tool output in appropriate tag (div for images, pre for text)
        function wrapOutput(formatted) {
            var isHtml = formatted.indexOf('<img ') !== -1 || formatted.indexOf('<div ') !== -1;
            var tag = isHtml ? 'div' : 'pre';
            return '<' + tag + ' class="sflmcp-tool-result">' + formatted + '</' + tag + '>';
        }
        
        if (displayMode === 'full') {
            if (description) {
                bodyHtml += '<div class="sflmcp-tool-description">' + escapeHtml(description) + '</div>';
            }
            bodyHtml += '<div class="sflmcp-tool-status">' + (item.status === 'success' ? sflmcpClient.i18n.toolResult : sflmcpClient.i18n.toolDenied) + '</div>';
            bodyHtml += '<div class="sflmcp-tool-section"><strong>Input:</strong></div><pre class="sflmcp-tool-args">' + JSON.stringify(item.input, null, 2) + '</pre>';
            if (item.output) {
                bodyHtml += '<div class="sflmcp-tool-section"><strong>Output:</strong></div>' + wrapOutput(formatToolOutput(item.output));
            }
        } else if (displayMode === 'compact') {
            if (description) {
                const shortDesc = description.length > 80 ? description.substring(0, 80) + '...' : description;
                bodyHtml += '<div class="sflmcp-tool-description sflmcp-tool-description-short">' + escapeHtml(shortDesc) + '</div>';
            }
            bodyHtml += '<div class="sflmcp-tool-status">' + (item.status === 'success' ? '✓' : '✗') + ' ' + item.name + '</div>';
        } else if (displayMode === 'name_only') {
            bodyHtml += '<span class="sflmcp-tool-badge">' + item.name + '</span>';
        } else if (displayMode === 'hidden') {
            $msg.addClass('sflmcp-tool-collapsed');
            $header.append('<span class="sflmcp-tool-toggle dashicons dashicons-arrow-down"></span>');
            bodyHtml += '<div class="sflmcp-tool-details" style="display:none;">';
            if (description) {
                bodyHtml += '<div class="sflmcp-tool-description">' + escapeHtml(description) + '</div>';
            }
            bodyHtml += '<pre class="sflmcp-tool-args">' + JSON.stringify(item.input, null, 2) + '</pre>';
            if (item.output) {
                bodyHtml += wrapOutput(formatToolOutput(item.output));
            }
            bodyHtml += '</div>';
        }
        
        $body.html(bodyHtml);
        $msg.append($header).append($body);
        
        // Toggle for hidden mode
        if (displayMode === 'hidden') {
            $header.on('click', function() {
                $msg.toggleClass('sflmcp-tool-collapsed');
                $body.find('.sflmcp-tool-details').slideToggle(200);
            });
        }
        
        $chatMessages.append($msg);
    }

    /**
     * Format tool output for display
     */
    function formatToolOutput(output) {
        var text = '';
        if (typeof output === 'string') {
            text = output;
        } else if (output && output.content) {
            text = output.content;
        } else {
            text = JSON.stringify(output, null, 2);
        }

        // Detect wp_generate_image JSON result and render inline image
        try {
            var parsed = (typeof text === 'string') ? JSON.parse(text.trim()) : null;
            if (parsed && parsed.medium_url && parsed.attachment_id) {
                var imgUrl = parsed.medium_url || parsed.url;
                var fullUrl = parsed.url || imgUrl;
                return '<div class="sflmcp-generated-image">' +
                    '<a href="' + escapeHtml(fullUrl) + '" target="_blank" rel="noopener">' +
                    '<img src="' + escapeHtml(imgUrl) + '" alt="' + escapeHtml(parsed.prompt || 'AI generated image') + '" style="max-width:300px;max-height:300px;border-radius:8px;margin:8px 0;display:block;" />' +
                    '</a>' +
                    '<div style="font-size:11px;color:#666;margin-top:4px;">' +
                    'ID: ' + parsed.attachment_id + ' | Provider: ' + escapeHtml(parsed.provider || 'unknown') +
                    ' | <a href="' + escapeHtml(fullUrl) + '" target="_blank" rel="noopener">Full size</a>' +
                    '</div></div>';
            }
        } catch (e) {
            // Not JSON, render as text
        }

        return escapeHtml(text);
    }

    /**
     * Handle Send/Stop button click
     */
    function handleSendButtonClick() {
        if (state.isProcessing) {
            // Stop mode - user wants to stop
            handleStopClick();
        } else {
            // Send mode - send message
            sendMessage();
        }
    }

    /**
     * Send a message to the AI
     */
    function sendMessage() {
        if (state.isProcessing) return;

        const message = $chatInput.val().trim();
        const apiKey = $apiKeyInput.val().trim();

        if (!apiKey) {
            showError(sflmcpClient.i18n.apiKeyRequired);
            return;
        }

        if (!message) {
            return;
        }

        state.isProcessing = true;
        state.isStopped = false;
        setButtonStopMode();
        $chatInput.val('');

        // Remove welcome message if present
        $('.sflmcp-welcome-message').remove();
        
        // Remove any existing suggestion chips
        $('.sflmcp-suggestions').remove();

        // Add user message to chat
        addMessage('user', message);
        
        // Add to history
        state.chatHistory.push({
            type: 'user',
            content: message,
            timestamp: new Date().toISOString()
        });
        saveHistory();

        // Show typing indicator
        const $thinking = addThinkingIndicator();

        // Send to AI
        sendToAI(message, null)
            .then(response => {
                $thinking.remove();
                if (!state.isStopped) {
                    handleAIResponse(response);
                }
            })
            .catch(error => {
                $thinking.remove();
                showError(error.message || sflmcpClient.i18n.error);
                finishProcessing();
            });
    }

    /**
     * Send message to AI via AJAX
     */
    function sendToAI(message, toolResult) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: sflmcpClient.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'sflmcp_client_chat',
                    nonce: sflmcpClient.nonce,
                    message: message || '',
                    provider: $providerSelect.val(),
                    api_key: $apiKeyInput.val(),
                    model: $modelSelect.val(),
                    conversation: JSON.stringify(state.conversation),
                    tool_result: toolResult ? JSON.stringify(toolResult) : null
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        reject(new Error(response.data.message));
                    }
                },
                error: function(xhr, status, error) {
                    reject(new Error(error || 'Network error'));
                }
            });
        });
    }

    /**
     * Handle AI response
     */
    function handleAIResponse(response) {
        // Update token bars if usage data is present
        if (response.usage) {
            updateTokenBars(response.usage);
        }

        // Update conversation history
        if (response.conversation) {
            state.conversation = response.conversation;
        }

        // Add text response if any
        if (response.text) {
            // Parse suggestions from text if enabled
            const { cleanText, suggestions } = parseSuggestions(response.text);
            
            addMessage('assistant', cleanText);
            
            // Add to history (store clean text)
            state.chatHistory.push({
                type: 'assistant',
                content: cleanText,
                timestamp: new Date().toISOString()
            });
            saveHistory();
            
            // Render suggestion chips if enabled and available
            if (state.advanced.enable_suggestions && suggestions.length > 0) {
                renderSuggestionChips(suggestions);
            }
        }

        // Check for tool calls - process ONE at a time sequentially
        if (response.tool_calls && response.tool_calls.length > 0) {
            // Take only the FIRST tool call for sequential processing
            // The AI is instructed to call one tool at a time, but we enforce it here too
            state.pendingToolCalls = [response.tool_calls[0]];
            state.currentToolIndex = 0;
            state.isStopped = false;
            processNextToolCall();
        } else {
            // Finished
            finishProcessing();
        }
    }

    /**
     * Update token usage bars with smooth animation
     */
    function updateTokenBars(usage) {
        var MAX_TOKENS = 12000;
        var $bars = $('#sflmcp-token-bars');
        var $fill = $('#sflmcp-token-fill');
        var $value = $('#sflmcp-token-value');
        var $cachedFill = $('#sflmcp-token-cached-fill');
        var $cachedValue = $('#sflmcp-token-cached-value');

        if (!$bars.length) return;

        var input = parseInt(usage.input_tokens, 10) || 0;
        var output = parseInt(usage.output_tokens, 10) || 0;
        var cached = parseInt(usage.cached_tokens, 10) || 0;
        // Show non-cached tokens only (reflects actual billable cost)
        var total = Math.max(0, input + output - cached);

        // Show the bars container
        $bars.show();

        // Calculate percentages (capped at 100%)
        var totalPct = Math.min((total / MAX_TOKENS) * 100, 100);
        var cachedPct = Math.min((cached / MAX_TOKENS) * 100, 100);

        // Determine color level
        function getLevel(pct) {
            if (pct <= 25) return 'sflmcp-level-low';
            if (pct <= 50) return 'sflmcp-level-medium';
            if (pct <= 75) return 'sflmcp-level-high';
            return 'sflmcp-level-critical';
        }

        // Reset width to 0 for animation effect, then animate
        $fill.css('width', '0%')
            .removeClass('sflmcp-level-low sflmcp-level-medium sflmcp-level-high sflmcp-level-critical')
            .addClass(getLevel(totalPct));

        $cachedFill.css('width', '0%')
            .removeClass('sflmcp-level-low sflmcp-level-medium sflmcp-level-high sflmcp-level-critical')
            .addClass(getLevel(cachedPct));

        // Trigger reflow then animate
        $fill[0].offsetWidth; // force reflow
        $cachedFill[0].offsetWidth;

        requestAnimationFrame(function() {
            $fill.css('width', totalPct + '%');
            $cachedFill.css('width', cachedPct + '%');
        });

        // Animate the number count-up
        animateCounter($value, total);
        animateCounter($cachedValue, cached);
    }

    /**
     * Animate a counter from current value to target
     */
    function animateCounter($el, target) {
        var current = parseInt($el.text().replace(/,/g, ''), 10) || 0;
        if (current === target) return;

        var duration = 600;
        var start = performance.now();

        function step(now) {
            var elapsed = now - start;
            var progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic
            var eased = 1 - Math.pow(1 - progress, 3);
            var val = Math.round(current + (target - current) * eased);
            $el.text(val.toLocaleString());
            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }
        requestAnimationFrame(step);
    }

    /**
     * Finish processing and reset button state
     */
    function finishProcessing() {
        state.isProcessing = false;
        state.isStopped = false;
        state.pendingToolCalls = [];
        state.currentToolIndex = 0;
        setButtonSendMode();
    }

    /**
     * Set button to Send mode
     */
    function setButtonSendMode() {
        $sendBtn.removeClass('sflmcp-btn-stop').addClass('button-primary');
        $sendBtn.html('<span class="dashicons dashicons-arrow-right-alt"></span> ' + sflmcpClient.i18n.send);
        $sendBtn.prop('disabled', false);
    }

    /**
     * Set button to Stop mode
     */
    function setButtonStopMode() {
        $sendBtn.removeClass('button-primary').addClass('sflmcp-btn-stop');
        $sendBtn.html('<span class="dashicons dashicons-controls-pause"></span> ' + sflmcpClient.i18n.stop);
        $sendBtn.prop('disabled', false);
    }

    /**
     * Handle Stop button click
     */
    function handleStopClick() {
        state.isStopped = true;
        $('.sflmcp-thinking').remove();
        $toolModal.hide();
        showNotice(sflmcpClient.i18n.stopped, 'warning');
        finishProcessing();
    }

    /**
     * Process next tool call in queue (sequential: one at a time)
     */
    function processNextToolCall() {
        // Check if user stopped
        if (state.isStopped) {
            return;
        }

        if (state.currentToolIndex >= state.pendingToolCalls.length) {
            // No more tools to process
            finishProcessing();
            return;
        }

        const toolCall = state.pendingToolCalls[state.currentToolIndex];
        const permission = $permissionSelect.val();

        if (permission === 'always') {
            // Execute immediately
            executeToolCallSequential(toolCall);
        } else {
            // Ask user
            showToolModal(toolCall);
        }
    }

    /**
     * Execute a single tool and send result immediately to AI (sequential flow)
     */
    function executeToolCallSequential(toolCall) {
        // Show executing message
        addToolMessage(toolCall.name, sflmcpClient.i18n.executingTool, 'executing', toolCall.arguments);

        // Execute tool via AJAX
        executeToolViaAjax(toolCall)
            .then(result => {
                if (state.isStopped) return;
                
                // Update the tool message with result
                updateLastToolMessage(toolCall.name, result, 'success');

                // Add to history with full details
                state.chatHistory.push({
                    type: 'tool',
                    name: toolCall.name,
                    input: toolCall.arguments,
                    output: result,
                    status: 'success',
                    timestamp: new Date().toISOString()
                });
                saveHistory();

                // Build tool result and send immediately to AI
                const toolResult = buildToolResult(toolCall, result);
                
                // Show thinking indicator
                const $thinking = addThinkingIndicator();
                
                // Send result back to AI immediately
                return sendToAI('', toolResult);
            })
            .then(response => {
                if (state.isStopped) return;
                
                $('.sflmcp-thinking').remove();
                
                // Handle new response (may have more tool calls)
                handleAIResponse(response);
            })
            .catch(error => {
                if (state.isStopped) return;
                
                $('.sflmcp-thinking').remove();
                updateLastToolMessage(toolCall.name, { error: error.message }, 'error');
                
                // Add to history
                state.chatHistory.push({
                    type: 'tool',
                    name: toolCall.name,
                    input: toolCall.arguments,
                    output: { error: error.message },
                    status: 'error',
                    timestamp: new Date().toISOString()
                });
                saveHistory();

                // Send error result to AI so it knows what happened
                const toolResult = buildToolResult(toolCall, { error: error.message });
                const $thinking = addThinkingIndicator();
                
                sendToAI('', toolResult)
                    .then(response => {
                        $('.sflmcp-thinking').remove();
                        if (!state.isStopped) {
                            handleAIResponse(response);
                        }
                    })
                    .catch(() => {
                        $('.sflmcp-thinking').remove();
                        finishProcessing();
                    });
            });
    }

    /**
     * Show tool execution modal
     */
    function showToolModal(toolCall) {
        $toolModal.find('.sflmcp-tool-name').text(toolCall.name);
        $toolModal.find('.sflmcp-tool-args').html(
            '<pre>' + JSON.stringify(toolCall.arguments, null, 2) + '</pre>'
        );
        $toolModal.show();
    }

    /**
     * Process a tool call from modal (execute or deny)
     */
    function processToolCall(allowed) {
        const toolCall = state.pendingToolCalls[state.currentToolIndex];

        if (!allowed) {
            // Add denied message
            addToolMessage(toolCall.name, sflmcpClient.i18n.toolDenied, 'denied', toolCall.arguments);
            
            // Add to history
            state.chatHistory.push({
                type: 'tool',
                name: toolCall.name,
                input: toolCall.arguments,
                output: { denied: true },
                status: 'denied',
                timestamp: new Date().toISOString()
            });
            saveHistory();
            
            // Send denied result to AI so it knows and can continue
            const toolResult = buildToolResult(toolCall, { error: 'Tool execution denied by user' });
            const $thinking = addThinkingIndicator();
            
            sendToAI('', toolResult)
                .then(response => {
                    $('.sflmcp-thinking').remove();
                    if (!state.isStopped) {
                        handleAIResponse(response);
                    }
                })
                .catch(() => {
                    $('.sflmcp-thinking').remove();
                    finishProcessing();
                });
            return;
        }

        // Execute the tool sequentially
        executeToolCallSequential(toolCall);
    }

    /**
     * Execute tool via AJAX (supports async long-running tools)
     */
    function executeToolViaAjax(toolCall) {
        var ajaxStart = Date.now();
        return new Promise((resolve, reject) => {
            $.ajax({
                url: sflmcpClient.ajaxUrl,
                method: 'POST',
                timeout: 0,
                data: {
                    action: 'sflmcp_client_execute_tool',
                    nonce: sflmcpClient.nonce,
                    tool_name: toolCall.name,
                    arguments: JSON.stringify(toolCall.arguments),
                    session_id: state.sessionId
                },
                success: function(response) {
                    var elapsed = Math.round((Date.now() - ajaxStart) / 1000);
                    if (response.success && response.data && response.data.async) {
                        // Long-running tool — switch to polling
                        pollToolResult(response.data.job_id, toolCall, resolve, reject);
                    } else if (response.success) {
                        resolve(response.data);
                    } else {
                        reject(new Error(response.data.message || 'Tool returned error'));
                    }
                },
                error: function(xhr, status, error) {
                    var elapsed = Math.round((Date.now() - ajaxStart) / 1000);
                    reject(new Error('Tool execution failed (' + status + ' after ' + elapsed + 's: ' + (error || xhr.status || 'connection lost') + ')'));
                }
            });
        });
    }

    /**
     * Poll for async tool result until completed, failed, or timed out.
     */
    function pollToolResult(jobId, toolCall, resolve, reject) {
        var POLL_INTERVAL = 5000;  // 5 seconds
        var MAX_POLLS     = 72;    // 6 minutes max
        var pollCount     = 0;

        function poll() {
            if (state.isStopped) {
                reject(new Error('Stopped by user'));
                return;
            }
            pollCount++;
            if (pollCount > MAX_POLLS) {
                reject(new Error('Tool timed out after ' + MAX_POLLS * (POLL_INTERVAL / 1000) + 's'));
                return;
            }

            $.ajax({
                url: sflmcpClient.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'sflmcp_client_poll_tool',
                    nonce: sflmcpClient.nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.status === 'running') {
                            setTimeout(poll, POLL_INTERVAL);
                        } else {
                            resolve(response.data);
                        }
                    } else {
                        // error from tool
                        reject(new Error(response.data.message || 'Tool failed'));
                    }
                },
                error: function(xhr, status) {
                    // Network error — retry silently
                    setTimeout(poll, POLL_INTERVAL);
                }
            });
        }

        setTimeout(poll, POLL_INTERVAL);
    }

    /**
     * Build tool result object for the AI
     */
    function buildToolResult(toolCall, result) {
        const provider = $providerSelect.val();

        switch (provider) {
            case 'openai':
                return {
                    call_id: toolCall.call_id,
                    output: result
                };
            case 'claude':
                return {
                    tool_use_id: toolCall.tool_use_id || toolCall.id,
                    output: result
                };
            case 'gemini':
                return {
                    name: toolCall.name,
                    output: result
                };
            default:
                return { output: result };
        }
    }

    /**
     * Add a message to the chat
     */
    function addMessage(role, content, scroll = true) {
        const $msg = $('<div class="sflmcp-message sflmcp-message-' + role + '"></div>');
        const $avatar = $('<div class="sflmcp-avatar"></div>');
        const $content = $('<div class="sflmcp-content"></div>');

        if (role === 'user') {
            $avatar.html('<span class="dashicons dashicons-admin-users"></span>');
        } else {
            $avatar.html('<span class="dashicons dashicons-format-chat"></span>');
        }

        // Parse markdown-like content
        $content.html(formatContent(content));

        $msg.append($avatar).append($content);
        $chatMessages.append($msg);
        
        if (scroll) {
            scrollToBottom();
        }
    }

    /**
     * Get tool description from tools list
     */
    function getToolDescription(toolName) {
        if (sflmcpClient.tools && sflmcpClient.tools[toolName]) {
            return sflmcpClient.tools[toolName].description || '';
        }
        return '';
    }

    /**
     * Add a tool execution message
     */
    function addToolMessage(toolName, status, type, args) {
        const displayMode = state.advanced.tool_display || 'full';
        const description = getToolDescription(toolName);
        const $msg = $('<div class="sflmcp-tool-message sflmcp-tool-' + type + '"></div>');
        const $header = $('<div class="sflmcp-tool-header"></div>');
        const $body = $('<div class="sflmcp-tool-body"></div>');

        $header.html('<span class="dashicons dashicons-admin-tools"></span> <strong>' + escapeHtml(toolName) + '</strong>' + (type === 'executing' ? '<span class="sflmcp-tool-spinner"></span>' : ''));
        
        let bodyHtml = '';
        
        if (displayMode === 'full') {
            if (description) {
                bodyHtml = '<div class="sflmcp-tool-description">' + escapeHtml(description) + '</div>';
            }
            bodyHtml += '<div class="sflmcp-tool-status">' + status + '</div>';
            if (args) {
                bodyHtml += '<div class="sflmcp-tool-section"><strong>Parameters:</strong></div>';
                bodyHtml += '<pre class="sflmcp-tool-args">' + JSON.stringify(args, null, 2) + '</pre>';
            }
        } else if (displayMode === 'compact') {
            if (description) {
                // Show shortened description (first 80 chars)
                const shortDesc = description.length > 80 ? description.substring(0, 80) + '...' : description;
                bodyHtml = '<div class="sflmcp-tool-description sflmcp-tool-description-short">' + escapeHtml(shortDesc) + '</div>';
            }
            bodyHtml += '<div class="sflmcp-tool-status">' + status + '</div>';
        } else if (displayMode === 'name_only') {
            bodyHtml = '<span class="sflmcp-tool-badge">' + escapeHtml(toolName) + ': ' + status + '</span>';
        } else if (displayMode === 'hidden') {
            $msg.addClass('sflmcp-tool-collapsed');
            $header.append('<span class="sflmcp-tool-toggle dashicons dashicons-arrow-down"></span>');
            bodyHtml = '<div class="sflmcp-tool-details" style="display:none;">';
            if (description) {
                bodyHtml += '<div class="sflmcp-tool-description">' + escapeHtml(description) + '</div>';
            }
            bodyHtml += '<div class="sflmcp-tool-status">' + status + '</div>';
            if (args) {
                bodyHtml += '<div class="sflmcp-tool-section"><strong>Parameters:</strong></div>';
                bodyHtml += '<pre class="sflmcp-tool-args">' + JSON.stringify(args, null, 2) + '</pre>';
            }
            bodyHtml += '</div>';
        }
        
        $body.html(bodyHtml);
        $msg.append($header).append($body);
        
        // Toggle for hidden mode
        if (displayMode === 'hidden') {
            $header.on('click', function() {
                $msg.toggleClass('sflmcp-tool-collapsed');
                $body.find('.sflmcp-tool-details').slideToggle(200);
            });
        }
        
        $chatMessages.append($msg);
        scrollToBottom();
    }

    /**
     * Update last tool message with result
     */
    function updateLastToolMessage(toolName, result, type) {
        const displayMode = state.advanced.tool_display || 'full';
        const $lastTool = $chatMessages.find('.sflmcp-tool-message').last();
        $lastTool.removeClass('sflmcp-tool-executing').addClass('sflmcp-tool-' + type);
        
        // Remove spinner from header
        $lastTool.find('.sflmcp-tool-spinner').remove();
        
        const $body = $lastTool.find('.sflmcp-tool-body');
        const formatted = formatToolOutput(result);
        // Use div instead of pre when output contains HTML (e.g. generated images)
        const isHtml = formatted.indexOf('<img ') !== -1 || formatted.indexOf('<div ') !== -1;
        const wrapTag = isHtml ? 'div' : 'pre';
        
        if (displayMode === 'full') {
            $body.find('.sflmcp-tool-status').text(sflmcpClient.i18n.toolResult);
            
            // Add result
            const resultHtml = '<' + wrapTag + ' class="sflmcp-tool-result">' + 
                formatted + '</' + wrapTag + '>';
            $body.append(resultHtml);
        } else if (displayMode === 'compact') {
            $body.find('.sflmcp-tool-status').html('✓ ' + escapeHtml(toolName));
        } else if (displayMode === 'hidden') {
            const $details = $body.find('.sflmcp-tool-details');
            $details.find('.sflmcp-tool-status').text(sflmcpClient.i18n.toolResult);
            $details.append('<' + wrapTag + ' class="sflmcp-tool-result">' + formatted + '</' + wrapTag + '>');
        }
        
        scrollToBottom();
    }

    /**
     * Add thinking indicator
     */
    function addThinkingIndicator() {
        const $thinking = $('<div class="sflmcp-thinking"><span class="sflmcp-dot"></span><span class="sflmcp-dot"></span><span class="sflmcp-dot"></span></div>');
        $chatMessages.append($thinking);
        scrollToBottom();
        return $thinking;
    }

    /**
     * Show error message
     */
    function showError(message) {
        const $error = $('<div class="sflmcp-error"><span class="dashicons dashicons-warning"></span> ' + escapeHtml(message) + '</div>');
        $chatMessages.append($error);
        scrollToBottom();
    }

    /**
     * Clear chat
     */
    function clearChat() {
        $chatMessages.empty();
        state.conversation = [];
        state.chatHistory = [];
        state.pendingToolCalls = [];
        state.currentToolIndex = 0;
        state.sessionId = generateSessionId();
        
        // Clear history on server
        $.ajax({
            url: sflmcpClient.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_client_clear_history',
                nonce: sflmcpClient.nonce
            },
            success: function() {
                showNotice(sflmcpClient.i18n.historyCleared, 'success');
            }
        });
        
        // Add welcome message back
        $chatMessages.html(`
            <div class="sflmcp-welcome-message">
                <div class="sflmcp-welcome-icon">🤖</div>
                <h3>${sflmcpClient.i18n.welcome || 'Welcome to AI Chat Agent'}</h3>
                <p>${sflmcpClient.i18n.welcomeDesc || 'Configure your API key above and start chatting!'}</p>
            </div>
        `);
    }

    /**
     * Save settings
     */
    function saveSettings() {
        $.ajax({
            url: sflmcpClient.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sflmcp_client_save_settings',
                nonce: sflmcpClient.nonce,
                provider: $providerSelect.val(),
                api_key: $apiKeyInput.val(),
                model: $modelSelect.val(),
                permission: $permissionSelect.val()
            },
            success: function(response) {
                if (response.success && $settingsAutosave && $settingsAutosave.length) {
                    $settingsAutosave.fadeIn(200);
                    setTimeout(function() { $settingsAutosave.fadeOut(300); }, 2000);
                }
            }
        });
    }

    /**
     * Show notice
     */
    function showNotice(message, type) {
        const $notice = $('<div class="sflmcp-notice sflmcp-notice-' + type + '">' + escapeHtml(message) + '</div>');
        $('.sflmcp-client-settings, .sflmcp-advanced-settings').first().after($notice);
        setTimeout(() => $notice.fadeOut(300, () => $notice.remove()), 3000);
    }

    /**
     * Format content (basic markdown support)
     */
    function formatContent(content) {
        if (!content) return '';
        
        // Escape HTML first
        let html = escapeHtml(content);
        
        // Code blocks
        html = html.replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        
        // Images (markdown syntax: ![alt](url))
        html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1" style="max-width:300px;max-height:300px;border-radius:8px;margin:8px 0;display:block;" />');
        
        // Links (markdown syntax: [text](url))
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
        
        // Bold
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        
        // Italic
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        
        // Line breaks
        html = html.replace(/\n/g, '<br>');
        
        return html;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Scroll chat to bottom
     */
    function scrollToBottom() {
        if ($chatMessages && $chatMessages.length) {
            $chatMessages.scrollTop($chatMessages[0].scrollHeight);
        }
    }

    /**
     * Parse suggestions from AI response text
     * Looks for lines starting with [SUGGESTION]
     */
    function parseSuggestions(text) {
        const suggestions = [];
        const lines = text.split('\n');
        const cleanLines = [];
        
        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith('[SUGGESTION]')) {
                const suggestion = trimmed.replace('[SUGGESTION]', '').trim();
                if (suggestion) {
                    suggestions.push(suggestion);
                }
            } else {
                cleanLines.push(line);
            }
        }
        
        // Remove trailing empty lines from clean text
        while (cleanLines.length > 0 && cleanLines[cleanLines.length - 1].trim() === '') {
            cleanLines.pop();
        }
        
        return {
            cleanText: cleanLines.join('\n'),
            suggestions: suggestions
        };
    }

    /**
     * Render suggestion chips below the last message
     */
    function renderSuggestionChips(suggestions) {
        // Remove any existing suggestion chips
        $('.sflmcp-suggestions').remove();
        
        const $container = $('<div class="sflmcp-suggestions"></div>');
        
        suggestions.forEach(suggestion => {
            const $chip = $('<button type="button" class="sflmcp-suggestion-chip"></button>');
            $chip.text(suggestion);
            $chip.on('click', function() {
                // Remove all suggestion chips when one is clicked
                $('.sflmcp-suggestions').remove();
                // Set the suggestion as the input value and send
                $chatInput.val(suggestion);
                sendMessage();
            });
            $container.append($chip);
        });
        
        $chatMessages.append($container);
        scrollToBottom();
    }

    // Initialize on ready
    $(document).ready(init);

})(jQuery);
