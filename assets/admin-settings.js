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
	});
})();
