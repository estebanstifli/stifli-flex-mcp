/**
 * StifLi Flex MCP — Copilot Settings Page
 *
 * Handles the save-settings form on the AI Copilot admin page.
 *
 * @since 2.3.0
 */
jQuery(function($) {
	$('#sflmcp-copilot-settings-form').on('submit', function(e) {
		e.preventDefault();
		var $btn = $(this).find('#sflmcp-copilot-save');
		$btn.prop('disabled', true);

		$.post(ajaxurl, {
			action: 'sflmcp_copilot_save_settings',
			nonce:  $('#sflmcp_copilot_settings_nonce').val(),
			enabled: $('#sflmcp-copilot-enabled').is(':checked') ? '1' : '0',
			tools_mode: $('#sflmcp-copilot-tools-mode').val()
		}, function(res) {
			$btn.prop('disabled', false);
			if (res.success) {
				$('#sflmcp-copilot-settings-notice').slideDown().delay(3000).slideUp();
			} else {
				alert(res.data && res.data.message ? res.data.message : 'Error saving settings');
			}
		}).fail(function() {
			$btn.prop('disabled', false);
			alert('Network error');
		});
	});

	// WebMCP form.
	$('#sflmcp-copilot-webmcp-form').on('submit', function(e) {
		e.preventDefault();
		var $btn = $(this).find('#sflmcp-copilot-webmcp-save');
		$btn.prop('disabled', true);

		// Collect disabled tools (unchecked checkboxes).
		var disabledTools = [];
		$('.sflmcp-webmcp-tool-check').each(function() {
			if (!$(this).is(':checked')) {
				disabledTools.push($(this).data('tool'));
			}
		});

		var data = {
			action: 'sflmcp_copilot_save_webmcp',
			nonce:  $('#sflmcp_copilot_webmcp_nonce').val(),
			webmcp_enabled:       $('#sflmcp-webmcp-enabled').is(':checked') ? '1' : '0',
			webmcp_language:      $('#sflmcp-webmcp-language').val(),
			webmcp_system_prompt: $('#sflmcp-webmcp-system-prompt').val()
		};

		// Send disabled tools as array.
		for (var i = 0; i < disabledTools.length; i++) {
			data['webmcp_disabled_tools[' + i + ']'] = disabledTools[i];
		}

		$.post(ajaxurl, data, function(res) {
			$btn.prop('disabled', false);
			if (res.success) {
				$('#sflmcp-copilot-settings-notice').slideDown().delay(3000).slideUp();
			} else {
				alert(res.data && res.data.message ? res.data.message : 'Error saving settings');
			}
		}).fail(function() {
			$btn.prop('disabled', false);
			alert('Network error');
		});
	});
});
