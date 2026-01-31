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
});
