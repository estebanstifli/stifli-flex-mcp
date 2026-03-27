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
});
