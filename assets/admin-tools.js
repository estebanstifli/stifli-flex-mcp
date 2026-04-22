/**
 * StifLi Flex MCP - Tools Toggle AJAX Handler
 * Handles enable/disable for WordPress and WooCommerce tools tabs
 */
jQuery(document).ready(function($) {
    // Toggle tool status via AJAX
    $(document).on('click', '.sflmcp-tool-toggle', function(e) {
        e.preventDefault();
        
        var btn = $(this);
        var toolId = btn.data('id');
        var currentStatus = btn.data('enabled');
        
        // Disable button during request
        btn.prop('disabled', true).addClass('updating');
        
        $.post(sflmcpTools.ajaxUrl, {
            action: 'sflmcp_toggle_tool',
            nonce: sflmcpTools.nonce,
            tool_id: toolId
        }, function(response) {
            btn.prop('disabled', false).removeClass('updating');
            
            if (response.success) {
                var newStatus = response.data.enabled;
                btn.data('enabled', newStatus);
                
                if (newStatus == 1) {
                    btn.removeClass('status-disabled').addClass('status-enabled');
                    btn.find('.dashicons').removeClass('dashicons-no').addClass('dashicons-yes');
                    btn.find('.status-text').text(sflmcpTools.i18n.enabled);
                } else {
                    btn.removeClass('status-enabled').addClass('status-disabled');
                    btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-no');
                    btn.find('.status-text').text(sflmcpTools.i18n.disabled);
                }
                
                // Update token totals if provided
                if (response.data.total_tokens !== undefined) {
                    $('.sflmcp-total-tokens').text(response.data.total_tokens);
                }
            } else {
                alert(response.data.message || sflmcpTools.i18n.error);
            }
        }).fail(function() {
            btn.prop('disabled', false).removeClass('updating');
            alert(sflmcpTools.i18n.error);
        });
    });
    
    // Bulk enable/disable all in category
    $(document).on('click', '.sflmcp-bulk-toggle', function(e) {
        e.preventDefault();
        
        var btn = $(this);
        var action = btn.data('action'); // 'enable' or 'disable'
        var category = btn.data('category');
        
        btn.prop('disabled', true);
        
        $.post(sflmcpTools.ajaxUrl, {
            action: 'sflmcp_bulk_toggle_tools',
            nonce: sflmcpTools.nonce,
            bulk_action: action,
            category: category
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                // Reload page to show updated states
                location.reload();
            } else {
                alert(response.data.message || sflmcpTools.i18n.error);
            }
        });
    });
    
    // Category checkbox: toggle all tools in category
    $(document).on('change', '.sflmcp-category-checkbox', function() {
        var checkbox = $(this);
        var category = checkbox.data('category');
        var isChecked = checkbox.is(':checked');
        var detailsElement = checkbox.closest('details[data-category="' + category + '"]');
        
        // Get all tool checkboxes in this category
        var toolCheckboxes = detailsElement.find('.sflmcp-tool-checkbox');
        
        // Determine action based on checkbox state
        var action = isChecked ? 'enable' : 'disable';
        var toolsToToggle = [];
        
        toolCheckboxes.each(function() {
            var toolCheckbox = $(this);
            var toolId = toolCheckbox.data('id');
            var currentlyEnabled = toolCheckbox.is(':checked');
            
            // Only toggle if state needs to change
            if ((isChecked && !currentlyEnabled) || (!isChecked && currentlyEnabled)) {
                toolsToToggle.push(toolId);
                // Visual update immediately
                toolCheckbox.prop('checked', isChecked);
            }
        });
        
        // Make AJAX calls to toggle all tools
        if (toolsToToggle.length > 0) {
            $.post(sflmcpTools.ajaxUrl, {
                action: 'sflmcp_bulk_toggle_tools_by_id',
                nonce: sflmcpTools.nonce,
                tool_ids: toolsToToggle
            }, function(response) {
                if (response.success) {
                    // Update category checkbox status and progress bar
                    updateCategoryProgress(detailsElement);
                    // Update token counts
                    if (response.data.total_tokens !== undefined) {
                        $('.sflmcp-total-tokens').text(response.data.total_tokens);
                    }
                } else {
                    alert(response.data.message || sflmcpTools.i18n.error);
                    // Revert checkbox if failed
                    checkbox.prop('checked', !isChecked);
                }
            }).fail(function() {
                alert(sflmcpTools.i18n.error);
                // Revert checkbox if failed
                checkbox.prop('checked', !isChecked);
            });
        }
    });
    
    // Individual tool checkbox: toggle single tool and sync category checkbox
    $(document).on('change', '.sflmcp-tool-checkbox', function() {
        var checkbox = $(this);
        var toolId = checkbox.data('id');
        var isChecked = checkbox.is(':checked');
        var detailsElement = checkbox.closest('details');
        
        // Make AJAX call to toggle tool
        $.post(sflmcpTools.ajaxUrl, {
            action: 'sflmcp_toggle_tool_by_checkbox',
            nonce: sflmcpTools.nonce,
            tool_id: toolId,
            enabled: isChecked ? 1 : 0
        }, function(response) {
            if (response.success) {
                // Update category checkbox and progress bar
                updateCategoryProgress(detailsElement);
                // Update token counts
                if (response.data.total_tokens !== undefined) {
                    $('.sflmcp-total-tokens').text(response.data.total_tokens);
                }
            } else {
                alert(response.data.message || sflmcpTools.i18n.error);
                // Revert checkbox if failed
                checkbox.prop('checked', !isChecked);
            }
        }).fail(function() {
            alert(sflmcpTools.i18n.error);
            // Revert checkbox if failed
            checkbox.prop('checked', !isChecked);
        });
    });
    
    // Helper function to update category checkbox and enabled count
    function updateCategoryProgress(detailsElement) {
        var category = detailsElement.data('category');
        var categoryCheckbox = detailsElement.find('.sflmcp-category-checkbox');
        var toolCheckboxes = detailsElement.find('.sflmcp-tool-checkbox');
        var enabledCount = toolCheckboxes.filter(':checked').length;
        var totalCount = toolCheckboxes.length;
        
        // Update category checkbox state (with indeterminate for partial selection)
        var isAll = enabledCount === totalCount && totalCount > 0;
        var isSome = enabledCount > 0 && enabledCount < totalCount;
        categoryCheckbox.prop('checked', isAll);
        if (categoryCheckbox[0]) {
            categoryCheckbox[0].indeterminate = isSome;
            console.log('[SFLMCP] category=' + category + ' enabled=' + enabledCount + '/' + totalCount + ' isAll=' + isAll + ' isSome=' + isSome + ' indeterminate=' + categoryCheckbox[0].indeterminate + ' classList=' + categoryCheckbox[0].className);
        }
        categoryCheckbox.toggleClass('sflmcp-partial', isSome);
        
        // Compute enabled read/write counts and token total from data attributes
        var enabledRead = 0, enabledWrite = 0, enabledTokens = 0;
        toolCheckboxes.filter(':checked').each(function() {
            var mode = $(this).data('mode');
            var tokens = parseInt($(this).data('tokens'), 10) || 0;
            enabledTokens += tokens;
            if (mode === 'WRITE') {
                enabledWrite++;
            } else {
                enabledRead++;
            }
        });
        
        // Update color class
        var enabledCountElement = detailsElement.find('.sflmcp-enabled-count');
        enabledCountElement.removeClass('sflmcp-count-partial sflmcp-count-full');
        if (enabledCount === totalCount && totalCount > 0) {
            enabledCountElement.addClass('sflmcp-count-full');
        } else if (enabledCount > 0) {
            enabledCountElement.addClass('sflmcp-count-partial');
        }
        
        // Build summary HTML with conditional read/write labels
        var html = enabledCount + '/' + totalCount + ' enabled';
        if (enabledRead > 0) {
            html += ' &middot; <span class="sflmcp-mode-label">' + enabledRead + ' read</span>';
        }
        if (enabledWrite > 0) {
            html += ' &middot; <span class="sflmcp-mode-label">' + enabledWrite + ' write</span>';
        }
        if (enabledCount > 0) {
            html += ' &middot; ' + enabledTokens.toLocaleString() + ' tokens';
        }
        enabledCountElement.html(html);
    }

    // Initialize indeterminate state on page load
    $('.sflmcp-tools-category').each(function() {
        updateCategoryProgress($(this));
    });
});

