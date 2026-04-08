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
				if (details.style.display === 'none') {
					details.style.display = '';
					if (icon) {
						icon.classList.remove('dashicons-arrow-down-alt2');
						icon.classList.add('dashicons-arrow-up-alt2');
					}
				} else {
					details.style.display = 'none';
					if (icon) {
						icon.classList.remove('dashicons-arrow-up-alt2');
						icon.classList.add('dashicons-arrow-down-alt2');
					}
				}
			});
		}
	});
})();
