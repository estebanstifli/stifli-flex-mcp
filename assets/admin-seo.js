/**
 * SEO settings admin behavior.
 *
 * @package StifliFlexMcp
 */

/* global jQuery, sflmcpSeo */
(function ($) {
	'use strict';

	var saveTimer = null;
	var isSaving = false;
	var isLoading = false;
	var DEBOUNCE_MS = 650;

	function showNotice(type, message) {
		var $notice = $('#sflmcp-seo-notice');
		if (!$notice.length) {
			return;
		}
		$notice.removeClass('success error info').addClass(type).text(message).show();
		if (type === 'success') {
			setTimeout(function () {
				$notice.fadeOut(180);
			}, 2500);
		}
	}

	function setAutosave(text) {
		$('#sflmcp-seo-autosave-status').text(text || '');
	}

	function collectSettings() {
		var data = {
			action: 'sflmcp_seo_save_settings',
			nonce: sflmcpSeo.nonce,
			gsc_enabled: $('#sflmcp_seo_gsc_enabled').is(':checked') ? '1' : '0',
			gsc_site_url: $('#sflmcp_seo_gsc_site_url').val() || '',
			gsc_cache_ttl: $('#sflmcp_seo_gsc_cache_ttl').val() || '900',
			gsc_oauth_client_id: $('#sflmcp_seo_gsc_client_id').val() || ''
		};

		var clientSecret = $('#sflmcp_seo_gsc_client_secret').val();
		if (clientSecret && $.trim(clientSecret) !== '') {
			data.gsc_oauth_client_secret = clientSecret;
		}

		return data;
	}

	function updateStatus(settings) {
		if (!settings) {
			return;
		}

		$('#sflmcp_seo_gsc_client_id_status').text(settings.gsc_oauth_client_id || '');
		$('#sflmcp_seo_gsc_secret_status').text(settings.gsc_oauth_client_secret_configured ? 'Configured' : 'Not configured');
		$('#sflmcp_seo_gsc_connected_user').text(settings.gsc_oauth_connected_user || '');
		$('#sflmcp_seo_gsc_connected_at').text(settings.gsc_oauth_connected_at || '');
		$('#sflmcp_seo_gsc_last_test').text(settings.gsc_last_test_status || '');

		if ($('#sflmcp_seo_gsc_client_id').length && settings.gsc_oauth_client_id !== undefined) {
			$('#sflmcp_seo_gsc_client_id').val(settings.gsc_oauth_client_id);
		}
		if ($('#sflmcp_seo_gsc_site_url').length && settings.gsc_site_url) {
			$('#sflmcp_seo_gsc_site_url').val(settings.gsc_site_url);
		}
		if ($('#sflmcp_seo_gsc_cache_ttl').length && settings.gsc_cache_ttl !== undefined) {
			$('#sflmcp_seo_gsc_cache_ttl').val(settings.gsc_cache_ttl);
		}
		if ($('#sflmcp_seo_gsc_enabled').length) {
			var enabled = String(settings.gsc_enabled) === '1';
			$('#sflmcp_seo_gsc_enabled').prop('checked', enabled);
			var $banner = $('#sflmcp_seo_gsc_enabled').closest('.sflmcp-tool-toggle-banner');
			$banner.toggleClass('disabled', !enabled);
			$banner.find('.sflmcp-toggle-status').text(enabled ? sflmcpSeo.i18n.enabled : sflmcpSeo.i18n.disabled);
		}

		var $pill = $('.sflmcp-seo-status-pill');
		if ($pill.length) {
			if (settings.configured) {
				$pill.removeClass('is-warning').addClass('is-ok').text('Connected');
			} else {
				$pill.removeClass('is-ok').addClass('is-warning').text('Not connected');
			}
		}
	}

	function saveSettings(done) {
		if (isSaving || isLoading) {
			return;
		}

		isSaving = true;
		setAutosave(sflmcpSeo.i18n.saving);

		$.post(sflmcpSeo.ajaxUrl, collectSettings(), function (response) {
			isSaving = false;
			if (response.success) {
				setAutosave(sflmcpSeo.i18n.saved);
				updateStatus(response.data);
				$('#sflmcp_seo_gsc_client_secret').val('');
				if (typeof done === 'function') {
					done(true, response.data || {});
				}
			} else {
				setAutosave(sflmcpSeo.i18n.error);
				showNotice('error', response.data && response.data.message ? response.data.message : sflmcpSeo.i18n.error);
				if (typeof done === 'function') {
					done(false, {});
				}
			}
		}).fail(function (xhr) {
			isSaving = false;
			setAutosave(sflmcpSeo.i18n.error);
			var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : sflmcpSeo.i18n.error;
			showNotice('error', message);
			if (typeof done === 'function') {
				done(false, {});
			}
		});
	}

	function scheduleSave() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(saveSettings, DEBOUNCE_MS);
	}

	function loadSettings() {
		if (!$('#sflmcp-seo-gsc-form').length) {
			return;
		}
		isLoading = true;
		$.post(sflmcpSeo.ajaxUrl, {
			action: 'sflmcp_seo_load_settings',
			nonce: sflmcpSeo.nonce
		}, function (response) {
			isLoading = false;
			if (response.success) {
				updateStatus(response.data);
			}
		}).fail(function () {
			isLoading = false;
		});
	}

	function testConnection() {
		showNotice('info', sflmcpSeo.i18n.testing);
		$.post(sflmcpSeo.ajaxUrl, {
			action: 'sflmcp_seo_test_gsc',
			nonce: sflmcpSeo.nonce,
			gsc_site_url: $('#sflmcp_seo_gsc_site_url').val() || ''
		}, function (response) {
			if (response.success) {
				showNotice('success', response.data.message || 'Connected');
				$('#sflmcp_seo_gsc_last_test').text(response.data.message || '');
			} else {
				showNotice('error', response.data && response.data.message ? response.data.message : 'Connection failed');
			}
		}).fail(function (xhr) {
			var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Connection failed';
			showNotice('error', message);
		});
	}

	function toggleTool($toggle) {
		var enabled = $toggle.is(':checked');
		var toolName = $toggle.data('tool');
		var $banner = $toggle.closest('.sflmcp-tool-toggle-banner');
		$banner.toggleClass('disabled', !enabled);
		$banner.find('.sflmcp-toggle-status').text(enabled ? sflmcpSeo.i18n.enabled : sflmcpSeo.i18n.disabled);

		$.post(sflmcpSeo.ajaxUrl, {
			action: 'sflmcp_seo_toggle_tool',
			nonce: sflmcpSeo.nonce,
			tool_name: toolName,
			enabled: enabled ? '1' : '0'
		}, function (response) {
			if (!response.success) {
				$toggle.prop('checked', !enabled);
				$banner.toggleClass('disabled', enabled);
				$banner.find('.sflmcp-toggle-status').text(!enabled ? sflmcpSeo.i18n.enabled : sflmcpSeo.i18n.disabled);
				showNotice('error', response.data && response.data.message ? response.data.message : sflmcpSeo.i18n.error);
			}
		}).fail(function () {
			$toggle.prop('checked', !enabled);
			$banner.toggleClass('disabled', enabled);
			$banner.find('.sflmcp-toggle-status').text(!enabled ? sflmcpSeo.i18n.enabled : sflmcpSeo.i18n.disabled);
			showNotice('error', sflmcpSeo.i18n.error);
		});
	}

	$(function () {
		loadSettings();

		$('#sflmcp_seo_gsc_enabled, #sflmcp_seo_gsc_site_url, #sflmcp_seo_gsc_cache_ttl, #sflmcp_seo_gsc_client_id').on('input change', scheduleSave);

		$('#sflmcp_seo_copy_redirect').on('click', function () {
			var value = $('#sflmcp_seo_redirect_uri').val() || '';
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(value).then(function () {
					showNotice('success', sflmcpSeo.i18n.copySuccess);
				});
			} else {
				$('#sflmcp_seo_redirect_uri').trigger('select');
				document.execCommand('copy');
				showNotice('success', sflmcpSeo.i18n.copySuccess);
			}
		});

		$('#sflmcp_seo_connect_google').on('click', function () {
			var url = $(this).data('url');
			clearTimeout(saveTimer);
			saveSettings(function (ok, settings) {
				if (!ok) {
					return;
				}
				if (!settings.client_configured) {
					showNotice('error', sflmcpSeo.i18n.clientRequired);
					return;
				}
				window.location.href = url;
			});
		});

		$('#sflmcp_seo_test_gsc').on('click', function () {
			clearTimeout(saveTimer);
			saveSettings(function (ok) {
				if (ok) {
					testConnection();
				}
			});
		});

		$('#sflmcp_seo_clear_gsc_cache').on('click', function () {
			$.post(sflmcpSeo.ajaxUrl, {
				action: 'sflmcp_seo_clear_gsc_cache',
				nonce: sflmcpSeo.nonce
			}, function (response) {
				showNotice(response.success ? 'success' : 'error', response.success ? sflmcpSeo.i18n.cacheCleared : sflmcpSeo.i18n.error);
			}).fail(function () {
				showNotice('error', sflmcpSeo.i18n.error);
			});
		});

		$('#sflmcp_seo_disconnect_gsc').on('click', function () {
			if (!window.confirm('Disconnect the connected Google account?')) {
				return;
			}
			$.post(sflmcpSeo.ajaxUrl, {
				action: 'sflmcp_seo_disconnect_gsc',
				nonce: sflmcpSeo.nonce
			}, function (response) {
				if (response.success) {
					updateStatus(response.data);
					showNotice('success', sflmcpSeo.i18n.accountDisconnected);
				} else {
					showNotice('error', response.data && response.data.message ? response.data.message : sflmcpSeo.i18n.error);
				}
			}).fail(function () {
				showNotice('error', sflmcpSeo.i18n.error);
			});
		});

		$('#sflmcp_seo_remove_gsc_credentials').on('click', function () {
			if (!window.confirm('Remove OAuth Client ID, Client Secret, and connected Google account?')) {
				return;
			}
			$.post(sflmcpSeo.ajaxUrl, {
				action: 'sflmcp_seo_remove_gsc_credentials',
				nonce: sflmcpSeo.nonce
			}, function (response) {
				if (response.success) {
					updateStatus(response.data);
					showNotice('success', sflmcpSeo.i18n.credentialsRemoved);
				} else {
					showNotice('error', response.data && response.data.message ? response.data.message : sflmcpSeo.i18n.error);
				}
			}).fail(function () {
				showNotice('error', sflmcpSeo.i18n.error);
			});
		});

		$('.sflmcp-seo-tool-toggle').on('change', function () {
			toggleTool($(this));
		});
	});
})(jQuery);
