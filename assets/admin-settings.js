(function(){
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {

		// Copy-to-clipboard buttons (data-copy-target + data-copy-notice)
		document.querySelectorAll('.sflmcp-copy-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var target = document.querySelector(btn.getAttribute('data-copy-target'));
				if (!target) return;
				var text = target.textContent;
				navigator.clipboard.writeText(text).then(function() {
					var notice = btn.getAttribute('data-copy-notice');
					if (notice) alert(notice);
				});
			});
		});

		// Confirm before submit (data-confirm)
		document.querySelectorAll('.sflmcp-reseed-btn').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				var msg = btn.getAttribute('data-confirm');
				if (msg && !confirm(msg)) {
					e.preventDefault();
				}
			});
		});

		// "View More Details" toggle on Settings tab
		var toggleBtn = document.getElementById('sflmcp-toggle-details');
		if (toggleBtn) {
			toggleBtn.addEventListener('click', function() {
				var details = document.getElementById('sflmcp-settings-details');
				var icon = toggleBtn.querySelector('.dashicons');
				if (!details) return;
				if (details.classList.contains('sflmcp-hidden')) {
					details.classList.remove('sflmcp-hidden');
					if (icon) {
						icon.classList.remove('dashicons-arrow-down-alt2');
						icon.classList.add('dashicons-arrow-up-alt2');
					}
				} else {
					details.classList.add('sflmcp-hidden');
					if (icon) {
						icon.classList.remove('dashicons-arrow-up-alt2');
						icon.classList.add('dashicons-arrow-down-alt2');
					}
				}
			});
		}

		// Generate Application Password via AJAX (no page refresh)
		var generateBtn = document.getElementById('sflmcp-generate-app-password-btn');
		if (generateBtn && window.sflmcpSettings && sflmcpSettings.ajaxUrl) {
			var statusEl = document.getElementById('sflmcp-generate-app-password-status');
			var feedbackEl = document.getElementById('sflmcp-app-password-feedback');
			var generatedWrap = document.getElementById('sflmcp-generated-app-password-wrap');
			var generatedUser = document.getElementById('sflmcp-generated-app-password-user');
			var generatedName = document.getElementById('sflmcp-generated-app-password-name');
			var generatedPassword = document.getElementById('sflmcp_generated_app_password');
			var i18n = sflmcpSettings.i18n || {};

			var setLoading = function(isLoading) {
				generateBtn.disabled = isLoading;
				if (statusEl) {
					if (isLoading) {
						statusEl.textContent = i18n.appPasswordGenerating || 'Generating...';
						statusEl.classList.remove('sflmcp-hidden');
					} else {
						statusEl.classList.add('sflmcp-hidden');
					}
				}
			};

			var showFeedback = function(type, message) {
				if (!feedbackEl) return;

				if (!message) {
					feedbackEl.className = 'sflmcp-hidden';
					feedbackEl.textContent = '';
					return;
				}

				feedbackEl.className = 'notice inline ' + (type === 'success' ? 'notice-success' : 'notice-error');
				feedbackEl.textContent = '';
				var p = document.createElement('p');
				p.textContent = message;
				feedbackEl.appendChild(p);
			};

			generateBtn.addEventListener('click', function() {
				if (generatedWrap) {
					generatedWrap.classList.add('sflmcp-hidden');
				}
				showFeedback('', '');
				setLoading(true);

				var formData = new FormData();
				formData.append('action', 'sflmcp_generate_app_password');
				formData.append('nonce', sflmcpSettings.nonce || '');

				fetch(sflmcpSettings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
					.then(function(response) {
						return response.json();
					})
					.then(function(payload) {
						if (!payload || payload.success !== true || !payload.data) {
							var errorMessage = (payload && payload.data && payload.data.message)
								? payload.data.message
								: (i18n.appPasswordGenerateError || 'Could not generate Application Password. Please try again.');
							throw new Error(errorMessage);
						}

						var data = payload.data;
						if (generatedUser) {
							generatedUser.textContent = data.user_login || '';
						}
						if (generatedName) {
							generatedName.textContent = data.app_name || '';
						}
						if (generatedPassword) {
							generatedPassword.textContent = data.password || '';
						}
						if (generatedWrap) {
							generatedWrap.classList.remove('sflmcp-hidden');
						}

						showFeedback('success', data.message || '');
					})
					.catch(function(error) {
						showFeedback('error', error.message || (i18n.appPasswordGenerateError || 'Could not generate Application Password. Please try again.'));
					})
					.finally(function() {
						setLoading(false);
					});
			});
		}
	});
})();
