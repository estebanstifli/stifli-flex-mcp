/**
 * OAuth Clients Admin Tab
 *
 * @package StifliFlexMcp
 * @since 3.1.0
 */
(function($) {
	'use strict';

	var OAuthAdmin = {
		init: function() {
			this.bindEvents();
		},

		bindEvents: function() {
			// Toggle tokens detail row
			$(document).on('click', '.sflmcp-oauth-toggle-tokens', function(e) {
				e.preventDefault();
				var clientId = $(this).data('client-id');
				var $detail = $('#sflmcp-tokens-' + clientId);
				$detail.toggleClass('expanded');
				$(this).toggleClass('active');
			});

			// Delete client
			$(document).on('click', '.sflmcp-oauth-delete-client', function(e) {
				e.preventDefault();
				if (!confirm(sflmcpOAuth.i18n.confirmDeleteClient)) {
					return;
				}
				var $btn = $(this);
				var clientId = $btn.data('client-id');
				$btn.prop('disabled', true);

				$.post(sflmcpOAuth.ajaxUrl, {
					action: 'sflmcp_oauth_delete_client',
					nonce: sflmcpOAuth.nonce,
					client_id: clientId
				}, function(response) {
					if (response.success) {
						OAuthAdmin.showNotice(sflmcpOAuth.i18n.clientDeleted);
						$btn.closest('tr').next('.sflmcp-oauth-tokens-row').remove();
						$btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
					} else {
						OAuthAdmin.showNotice(response.data.message || sflmcpOAuth.i18n.error, 'error');
						$btn.prop('disabled', false);
					}
				}).fail(function() {
					OAuthAdmin.showNotice(sflmcpOAuth.i18n.error, 'error');
					$btn.prop('disabled', false);
				});
			});

			// Revoke a specific token
			$(document).on('click', '.sflmcp-oauth-revoke-token', function(e) {
				e.preventDefault();
				if (!confirm(sflmcpOAuth.i18n.confirmRevokeToken)) {
					return;
				}
				var $btn = $(this);
				var tokenId = $btn.data('token-id');
				$btn.prop('disabled', true);

				$.post(sflmcpOAuth.ajaxUrl, {
					action: 'sflmcp_oauth_revoke_token',
					nonce: sflmcpOAuth.nonce,
					token_id: tokenId
				}, function(response) {
					if (response.success) {
						$btn.closest('tr').fadeOut(300, function() {
							$(this).remove();
							// Update token count in parent row
							var clientId = $btn.data('client-id');
							var remaining = $('#sflmcp-tokens-' + clientId + ' tbody tr').length;
							var $count = $('[data-client-count="' + clientId + '"]');
							$count.text(remaining);
							if (remaining === 0) {
								$count.removeClass('has-tokens');
							}
						});
					} else {
						OAuthAdmin.showNotice(response.data.message || sflmcpOAuth.i18n.error, 'error');
						$btn.prop('disabled', false);
					}
				}).fail(function() {
					OAuthAdmin.showNotice(sflmcpOAuth.i18n.error, 'error');
					$btn.prop('disabled', false);
				});
			});

			// Toggle auto-approve setting
			$(document).on('change', '#sflmcp_oauth_auto_approve', function() {
				var enabled = $(this).is(':checked') ? '1' : '0';

				$.post(sflmcpOAuth.ajaxUrl, {
					action: 'sflmcp_oauth_save_settings',
					nonce: sflmcpOAuth.nonce,
					auto_approve: enabled
				}, function(response) {
					if (response.success) {
						OAuthAdmin.showNotice(sflmcpOAuth.i18n.settingsSaved);
					} else {
						OAuthAdmin.showNotice(response.data.message || sflmcpOAuth.i18n.error, 'error');
					}
				});
			});
		},

		showNotice: function(message, type) {
			type = type || 'success';
			var $notice = $('<div class="sflmcp-oauth-notice ' + type + '">' + $('<span>').text(message).html() + '</div>');
			$('.sflmcp-oauth-wrap .sflmcp-oauth-notice').remove();
			$('.sflmcp-oauth-header').after($notice);
			setTimeout(function() {
				$notice.fadeOut(400, function() { $(this).remove(); });
			}, 4000);
		}
	};

	$(document).ready(function() {
		OAuthAdmin.init();
	});

})(jQuery);
