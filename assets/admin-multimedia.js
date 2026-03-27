/**
 * Multimedia Settings – Auto-save JavaScript
 *
 * Settings are saved automatically on every field change (no Save button).
 * Settings are loaded via AJAX on page init for freshness.
 * Per-field indicators show a brief checkmark on save.
 *
 * @package StifliFlexMcp
 * @since 2.3.0
 */

/* global jQuery, sflmcpMultimedia */
(function ($) {
	'use strict';

	var saveTimer = null;
	var DEBOUNCE_MS = 600;
	var isSaving = false;
	var isLoading = false;
	var lastChangedField = null; // track which field triggered the save

	// ── Field mappings ──────────────────────────────────────
	// selector → POST key (only for val()-based fields)
	var fieldMap = {
		'#sflmcp_mm_image_provider':      'image_provider',
		'#sflmcp_mm_openai_model':        'openai_model',
		'#sflmcp_mm_openai_quality':      'openai_quality',
		'#sflmcp_mm_openai_size':         'openai_size',
		'#sflmcp_mm_openai_style':        'openai_style',
		'#sflmcp_mm_openai_background':   'openai_background',
		'#sflmcp_mm_openai_format':       'openai_output_format',
		'#sflmcp_mm_gemini_model':        'gemini_model',
		'#sflmcp_mm_gemini_aspect':       'gemini_aspect_ratio',
		'#sflmcp_mm_pp_max_width':        'pp_max_width',
		'#sflmcp_mm_pp_max_height':       'pp_max_height',
		'#sflmcp_mm_pp_quality':          'pp_quality',
		'#sflmcp_mm_pp_format':           'pp_format',
		'#sflmcp_mm_video_provider':      'video_provider',
		'#sflmcp_mm_video_gemini_model':  'video_gemini_model',
		'#sflmcp_mm_video_openai_model':  'video_openai_model',
		'#sflmcp_mm_video_duration':      'video_duration',
		'#sflmcp_mm_video_aspect':        'video_aspect_ratio',
		'#sflmcp_mm_video_resolution':    'video_resolution',
		'#sflmcp_mm_video_poll':          'video_poll_interval',
		'#sflmcp_mm_video_max_wait':      'video_max_wait'
	};

	// API key fields use data-key attribute for the shared DB key name.
	// All inputs with class .sflmcp-shared-apikey map via data-key to the POST key.
	// Image tab: #sflmcp_mm_openai_key (data-key=openai_api_key)
	// Image tab: #sflmcp_mm_gemini_key (data-key=gemini_api_key)
	// Video tab: #sflmcp_mm_video_openai_key (data-key=openai_api_key)  ← same DB field
	// Video tab: #sflmcp_mm_video_gemini_key (data-key=gemini_api_key)  ← same DB field
	// Regex to detect masked API key values (contain • bullet chars)
	var MASK_PATTERN = /•/;

	// ── Per-field save indicator ─────────────────────────────
	// Appends a small indicator span next to a field, or reuses existing one.
	function getIndicator($el) {
		var $parent = $el.closest('td');
		if (!$parent.length) { $parent = $el.parent(); }
		console.log('[SFLMCP-indicator] getIndicator for', $el.attr('id') || $el.attr('type'), 'parent tag:', $parent.prop('tagName'), 'parentLen:', $parent.length);
		var $ind = $parent.find('.sflmcp-field-indicator');
		if (!$ind.length) {
			$ind = $('<span class="sflmcp-field-indicator"></span>');
			// Place after the field or after a wrapper
			if ($el.closest('.sflmcp-api-key-field').length) {
				$el.closest('.sflmcp-api-key-field').after($ind);
				console.log('[SFLMCP-indicator] Inserted after .sflmcp-api-key-field');
			} else if ($el.closest('.sflmcp-range-field').length) {
				$el.closest('.sflmcp-range-field').after($ind);
				console.log('[SFLMCP-indicator] Inserted after .sflmcp-range-field');
			} else {
				$el.after($ind);
				console.log('[SFLMCP-indicator] Inserted after element directly');
			}
		} else {
			console.log('[SFLMCP-indicator] Reusing existing indicator');
		}
		return $ind;
	}

	function showFieldSaving($el) {
		if (!$el || !$el.length) { console.log('[SFLMCP-indicator] showFieldSaving: $el is empty/null'); return; }
		console.log('[SFLMCP-indicator] showFieldSaving for', $el.attr('id') || $el.attr('type'));
		var $ind = getIndicator($el);
		$ind.removeClass('saved error').addClass('saving visible')
			.html('<span class="dashicons dashicons-update sflmcp-spin"></span>');
		console.log('[SFLMCP-indicator] indicator classes:', $ind.attr('class'), 'visible:', $ind.is(':visible'));
	}

	function showFieldSaved($el) {
		if (!$el || !$el.length) { console.log('[SFLMCP-indicator] showFieldSaved: $el is empty/null'); return; }
		console.log('[SFLMCP-indicator] showFieldSaved for', $el.attr('id') || $el.attr('type'));
		var $ind = getIndicator($el);
		$ind.removeClass('saving error').addClass('saved visible')
			.html('<span class="dashicons dashicons-yes-alt"></span>');
		console.log('[SFLMCP-indicator] saved indicator classes:', $ind.attr('class'));
		setTimeout(function () { $ind.removeClass('visible'); }, 1800);
	}

	function showFieldError($el) {
		if (!$el || !$el.length) { return; }
		var $ind = getIndicator($el);
		$ind.removeClass('saving saved').addClass('error visible')
			.html('<span class="dashicons dashicons-warning"></span>');
		setTimeout(function () { $ind.removeClass('visible'); }, 3000);
	}

	// ── Collect all fields present in the DOM ───────────────
	function collectFields() {
		var data = {
			action: 'sflmcp_save_multimedia_settings',
			nonce: sflmcpMultimedia.nonce
		};

		// Regular value fields — only include those present in the DOM
		$.each(fieldMap, function (sel, key) {
			var $el = $(sel);
			if ($el.length) {
				data[key] = $el.val();
			}
		});

		// Checkbox
		var $pp = $('#sflmcp_mm_pp_enabled');
		if ($pp.length) {
			data.pp_enabled = $pp.is(':checked') ? '1' : '0';
		}

		// API keys — collect from all .sflmcp-shared-apikey inputs,
		// deduplicate by data-key (first non-masked value wins)
		var apiKeysCollected = {};
		$('.sflmcp-shared-apikey').each(function () {
			var dbKey = $(this).data('key');
			var val = $(this).val();
			// Only send if user typed a real new key (no bullet chars = not masked)
			if (val && !MASK_PATTERN.test(val) && !apiKeysCollected[dbKey]) {
				apiKeysCollected[dbKey] = val;
			}
		});
		$.each(apiKeysCollected, function (key, val) {
			data[key] = val;
		});

		return data;
	}

	// ── AJAX save ───────────────────────────────────────────
	function doSave() {
		if (isSaving || isLoading) {
			console.log('[SFLMCP-indicator] doSave skipped: isSaving=' + isSaving + ' isLoading=' + isLoading);
			return;
		}
		isSaving = true;
		var $changed = lastChangedField;
		console.log('[SFLMCP-indicator] doSave: $changed=', $changed ? ($changed.attr('id') || $changed.attr('type') || 'unknown') : 'NULL');
		if ($changed) { showFieldSaving($changed); }

		$.post(sflmcpMultimedia.ajaxUrl, collectFields(), function (response) {
			isSaving = false;
			if (response.success) {
				if ($changed) { showFieldSaved($changed); }
			} else {
				if ($changed) { showFieldError($changed); }
			}
		}).fail(function () {
			isSaving = false;
			if ($changed) { showFieldError($changed); }
		});
	}

	function triggerSave($el) {
		lastChangedField = $el || null;
		clearTimeout(saveTimer);
		saveTimer = setTimeout(doSave, DEBOUNCE_MS);
	}

	function triggerSaveImmediate($el) {
		lastChangedField = $el || null;
		clearTimeout(saveTimer);
		doSave();
	}

	// ── AJAX load ───────────────────────────────────────────
	function loadSettings() {
		isLoading = true;

		$.post(sflmcpMultimedia.ajaxUrl, {
			action: 'sflmcp_load_multimedia_settings',
			nonce: sflmcpMultimedia.nonce
		}, function (response) {
			isLoading = false;
			if (!response.success || !response.data) {
				return;
			}
			var s = response.data;

			// Populate regular value fields
			$.each(fieldMap, function (sel, key) {
				var $el = $(sel);
				if ($el.length && s[key] !== undefined && s[key] !== null) {
					$el.val(String(s[key]));
				}
			});

			// Populate shared API key inputs by data-key attribute
			$('.sflmcp-shared-apikey').each(function () {
				var dbKey = $(this).data('key');
				if (s[dbKey]) {
					$(this).val(s[dbKey]);
				}
			});

			// Checkbox
			if ($('#sflmcp_mm_pp_enabled').length) {
				var ppOn = s.pp_enabled === '1';
				$('#sflmcp_mm_pp_enabled').prop('checked', ppOn);
				if (ppOn) {
					$('.sflmcp-postprocess-fields').removeClass('hidden');
					$('.sflmcp-postprocess-section').removeClass('disabled');
				} else {
					$('.sflmcp-postprocess-fields').addClass('hidden');
					$('.sflmcp-postprocess-section').addClass('disabled');
				}
			}

			// Quality slider label
			if ($('#sflmcp_mm_pp_quality').length && s.pp_quality) {
				$('#sflmcp_mm_pp_quality_val').text(s.pp_quality + '%');
			}

			// Tool toggles
			$('.sflmcp-mm-tool-toggle').each(function () {
				var toolName = $(this).data('tool');
				var key = 'tool_enabled_' + toolName;
				if (s[key] !== undefined) {
					var on = s[key] === '1';
					$(this).prop('checked', on);
					var $banner = $(this).closest('.sflmcp-tool-toggle-banner');
					$banner.toggleClass('disabled', !on);
					$banner.find('.sflmcp-toggle-status').text(on ? sflmcpMultimedia.i18n.enabled : sflmcpMultimedia.i18n.disabled);
				}
			});

			// Sync provider panel visibility from loaded data
			syncProviderPanels(s);

		}).fail(function () {
			isLoading = false;
		});
	}

	// ── Sync provider panels with loaded data ───────────────
	function syncProviderPanels(s) {
		// Image provider (only on Images tab)
		if ($('#sflmcp_mm_image_provider').length && s.image_provider) {
			var imgProv = s.image_provider;
			var $imgCard = $('#sflmcp_mm_image_provider').closest('.card');
			$imgCard.find('.sflmcp-provider-tab').removeClass('active');
			$imgCard.find('.sflmcp-provider-tab[data-provider="' + imgProv + '"]').addClass('active');
			var $imgForm = $('#sflmcp_mm_image_provider').closest('form');
			$imgForm.find('.sflmcp-provider-panel').addClass('sflmcp-hidden');
			$imgForm.find('#sflmcp-panel-' + imgProv).removeClass('sflmcp-hidden');
		}

		// Video provider (only on Videos tab)
		if ($('#sflmcp_mm_video_provider').length && s.video_provider) {
			var vidProv = s.video_provider;
			var $vidCard = $('#sflmcp_mm_video_provider').closest('.card');
			$vidCard.find('.sflmcp-provider-tab').removeClass('active');
			$vidCard.find('.sflmcp-provider-tab[data-provider="' + vidProv + '"]').addClass('active');
			var $vidForm = $('#sflmcp_mm_video_provider').closest('form');
			$vidForm.find('.sflmcp-provider-panel').addClass('sflmcp-hidden');
			$vidForm.find('#sflmcp-panel-' + vidProv).removeClass('sflmcp-hidden');
		}
	}

	// ── Init ────────────────────────────────────────────────
	$(document).ready(function () {

		// Load current settings from DB via AJAX
		loadSettings();

		// ── Tool toggle (enable/disable) ─────────────────────
		$('.sflmcp-mm-tool-toggle').on('change', function () {
			var $cb = $(this);
			var toolName = $cb.data('tool');
			var enabled = $cb.is(':checked') ? 1 : 0;
			var $banner = $cb.closest('.sflmcp-tool-toggle-banner');
			var $status = $banner.find('.sflmcp-toggle-status');

			$banner.toggleClass('disabled', !enabled);
			$status.text(sflmcpMultimedia.i18n.saving);

			$.post(sflmcpMultimedia.ajaxUrl, {
				action: 'sflmcp_mm_toggle_tool',
				nonce: sflmcpMultimedia.nonce,
				tool_name: toolName,
				enabled: enabled
			}, function (response) {
				if (response.success) {
					$status.text(enabled ? sflmcpMultimedia.i18n.enabled : sflmcpMultimedia.i18n.disabled);
				} else {
					$status.text(sflmcpMultimedia.i18n.error);
					$cb.prop('checked', !enabled);
					$banner.toggleClass('disabled', enabled);
				}
			}).fail(function () {
				$status.text(sflmcpMultimedia.i18n.error);
				$cb.prop('checked', !enabled);
				$banner.toggleClass('disabled', enabled);
			});
		});

		// ── Provider tab switching + auto-save ───────────────
		$('.sflmcp-provider-tab').on('click', function () {
			var provider = $(this).data('provider');
			var $card = $(this).closest('.card');
			$card.find('.sflmcp-provider-tab').removeClass('active');
			$(this).addClass('active');
			var $hidden = $card.find('input[type="hidden"]');
			$hidden.val(provider);
			var $form = $(this).closest('form');
			$form.find('.sflmcp-provider-panel').addClass('sflmcp-hidden');
			$form.find('#sflmcp-panel-' + provider).removeClass('sflmcp-hidden');
			triggerSaveImmediate($hidden);
		});

		// ── Toggle API key visibility (fetches real key via AJAX) ──
		$(document).on('click', '.sflmcp-api-key-toggle', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $field = $btn.closest('.sflmcp-api-key-field').find('input');
			if (!$field.length) { return; }
			var $icon = $btn.find('.dashicons');

			if ($field.attr('type') === 'password') {
				// Reveal: if value contains bullets (masked), fetch real key from server
				var currentVal = $field.val();
				if (MASK_PATTERN.test(currentVal)) {
					var dbKey = $field.data('key');
					if (!dbKey) { return; }
					$btn.prop('disabled', true);
					$.post(sflmcpMultimedia.ajaxUrl, {
						action: 'sflmcp_mm_reveal_key',
						nonce: sflmcpMultimedia.nonce,
						key_name: dbKey
					}, function (response) {
						$btn.prop('disabled', false);
						if (response.success && response.data.key) {
							// Store masked value to restore later, show real key
							$field.data('masked', currentVal);
							$field.val(response.data.key);
							$field.attr('type', 'text');
							$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
						}
					}).fail(function () {
						$btn.prop('disabled', false);
					});
				} else {
					// User typed a new key (no bullets) — just toggle type
					$field.attr('type', 'text');
					$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
				}
			} else {
				// Hide: restore masked value if we have one
				var masked = $field.data('masked');
				if (masked && !$field.data('user-edited')) {
					$field.val(masked);
				}
				$field.attr('type', 'password');
				$icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
			}
		});

		// Track if user manually edits an API key field (so we don't overwrite with mask on hide)
		$(document).on('input', '.sflmcp-shared-apikey', function () {
			$(this).data('user-edited', true);
		});

		// ── Post-processing toggle + auto-save ───────────────
		$('#sflmcp_mm_pp_enabled').on('change', function () {
			if ($(this).is(':checked')) {
				$('.sflmcp-postprocess-fields').removeClass('hidden');
				$('.sflmcp-postprocess-section').removeClass('disabled');
			} else {
				$('.sflmcp-postprocess-fields').addClass('hidden');
				$('.sflmcp-postprocess-section').addClass('disabled');
			}
			triggerSaveImmediate($('#sflmcp_mm_pp_enabled'));
		});

		// ── Compression quality slider label + auto-save ─────
		$('#sflmcp_mm_pp_quality').on('input', function () {
			$('#sflmcp_mm_pp_quality_val').text($(this).val() + '%');
			triggerSave($(this));
		});

		// ── Auto-save: selects → immediate ───────────────────
		$('#sflmcp-multimedia-form select, #sflmcp-multimedia-form-video select').on('change', function () {
			console.log('[SFLMCP-indicator] select change:', $(this).attr('id'));
			triggerSaveImmediate($(this));
		});

		// ── Auto-save: text/number/password inputs → debounced
		$('#sflmcp-multimedia-form, #sflmcp-multimedia-form-video').on('input', 'input[type="text"], input[type="number"], input[type="password"]', function () {
			console.log('[SFLMCP-indicator] input change:', $(this).attr('id'));
			triggerSave($(this));
		});

		// ── Prevent default form submission ──────────────────
		$('#sflmcp-multimedia-form, #sflmcp-multimedia-form-video').on('submit', function (e) {
			e.preventDefault();
			doSave();
		});
	});
})(jQuery);
