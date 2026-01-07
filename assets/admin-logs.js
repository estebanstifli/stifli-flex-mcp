/**
 * StifLi Flex MCP - Logs Tab JavaScript
 * 
 * @package StifLi_Flex_MCP
 */

/* global jQuery, sflmcpLogs */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Toggle logging
		$('#sflmcp_logging_enabled').on('change', function() {
			var enabled = $(this).is(':checked') ? 1 : 0;
			$.ajax({
				url: sflmcpLogs.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sflmcp_toggle_logging',
					enabled: enabled,
					nonce: sflmcpLogs.nonce
				},
				success: function(response) {
					if (response.success) {
						var msg = enabled ? sflmcpLogs.i18n.loggingEnabled : sflmcpLogs.i18n.loggingDisabled;
						alert(msg);
					} else {
						alert(response.data.message || sflmcpLogs.i18n.errorSaving);
					}
				}
			});
		});

		// Refresh logs
		$('#sflmcp_refresh_logs').on('click', function() {
			var $btn = $(this);
			var originalText = $btn.text();
			$btn.prop('disabled', true).text(sflmcpLogs.i18n.loading);
			$.ajax({
				url: sflmcpLogs.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sflmcp_refresh_logs',
					nonce: sflmcpLogs.nonce
				},
				success: function(response) {
					if (response.success) {
						$('#sflmcp_log_viewer').val(response.data.contents);
						// Scroll to bottom
						var textarea = document.getElementById('sflmcp_log_viewer');
						if (textarea) {
							textarea.scrollTop = textarea.scrollHeight;
						}
					}
					$btn.prop('disabled', false).text(originalText);
				},
				error: function() {
					$btn.prop('disabled', false).text(originalText);
				}
			});
		});

		// Clear logs
		$('#sflmcp_clear_logs').on('click', function() {
			if (!confirm(sflmcpLogs.i18n.confirmClear)) {
				return;
			}
			var $btn = $(this);
			$btn.prop('disabled', true);
			$.ajax({
				url: sflmcpLogs.ajaxUrl,
				type: 'POST',
				data: {
					action: 'sflmcp_clear_logs',
					nonce: sflmcpLogs.nonce
				},
				success: function(response) {
					if (response.success) {
						$('#sflmcp_log_viewer').val('');
						alert(sflmcpLogs.i18n.logsCleared);
					} else {
						alert(response.data.message || sflmcpLogs.i18n.errorClearing);
					}
					$btn.prop('disabled', false);
				},
				error: function() {
					$btn.prop('disabled', false);
				}
			});
		});

		// Scroll log viewer to bottom on load
		var textarea = document.getElementById('sflmcp_log_viewer');
		if (textarea) {
			textarea.scrollTop = textarea.scrollHeight;
		}
	});
})(jQuery);
