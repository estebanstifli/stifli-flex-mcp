/* global jQuery, sflmcpChangelog */
(function($) {
	'use strict';

	var currentPage = 1;
	var perPage = 25;

	function loadChangelog(page) {
		page = page || 1;
		currentPage = page;

		var filters = {
			action: 'sflmcp_get_changelog',
			nonce: sflmcpChangelog.nonce,
			page: page,
			per_page: perPage,
			tool_name: $('#sflmcp-filter-tool').val(),
			operation_type: $('#sflmcp-filter-operation').val(),
			object_type: $('#sflmcp-filter-object').val(),
			date_from: $('#sflmcp-filter-date-from').val(),
			date_to: $('#sflmcp-filter-date-to').val(),
			rolled_back: $('#sflmcp-filter-status').val(),
			source: $('#sflmcp-filter-source').val()
		};

		$('#sflmcp-changelog-body').html('<tr><td colspan="9" style="text-align:center;padding:20px;">' + sflmcpChangelog.i18n.loading + '</td></tr>');

		$.post(sflmcpChangelog.ajaxUrl, filters, function(response) {
			if (response.success) {
				renderTable(response.data);
				renderStats(response.data.stats);
				renderPagination(response.data.total, page);
			} else {
				$('#sflmcp-changelog-body').html('<tr><td colspan="8" style="text-align:center;color:#d63638;">' + (response.data || sflmcpChangelog.i18n.error) + '</td></tr>');
			}
		});
	}

	function renderTable(data) {
		var rows = data.rows;
		var $body = $('#sflmcp-changelog-body');
		$body.empty();

		if (!rows || rows.length === 0) {
			$body.html('<tr><td colspan="9"><div class="sflmcp-changelog-empty"><span class="dashicons dashicons-clipboard"></span><p>' + sflmcpChangelog.i18n.noEntries + '</p></div></td></tr>');
			return;
		}

		$.each(rows, function(i, row) {
			var rolledBack = parseInt(row.rolled_back, 10) === 1;
			var tr = $('<tr>').addClass(rolledBack ? 'rolled-back' : '');

			tr.append($('<td>').text('#' + row.id));
			tr.append($('<td>').html('<code>' + escHtml(row.tool_name) + '</code>'));
			tr.append($('<td>').html('<span class="sflmcp-op-badge op-' + escHtml(row.operation_type) + '">' + escHtml(row.operation_type) + '</span>'));
			tr.append($('<td>').html('<span class="sflmcp-obj-badge">' + escHtml(row.object_type) + '</span>' + (row.object_id ? ' <strong>#' + escHtml(row.object_id) + '</strong>' : '')));
			tr.append($('<td>').html('<span class="sflmcp-source-badge source-' + escHtml(row.source || 'unknown') + '">' + escHtml(row.source_display || row.source || '-') + '</span>'));
			tr.append($('<td>').text(row.created_at));

			// User
			tr.append($('<td>').text(row.user_display || '-'));

			// Status
			var statusText = rolledBack ? '↩ ' + sflmcpChangelog.i18n.rolledBack : '✓ ' + sflmcpChangelog.i18n.active;
			tr.append($('<td>').text(statusText));

			// Actions
			var actions = $('<td>').addClass('row-actions');
			actions.append($('<button>').addClass('button button-small').text('🔍 ' + (sflmcpChangelog.i18n.viewDetail || sflmcpChangelog.i18n.detail || 'View')).attr('data-id', row.id).on('click', function() { showDetail(row.id); }));

			if (rolledBack) {
				actions.append($('<button>').addClass('button button-small sflmcp-btn-redo').text(sflmcpChangelog.i18n.redo).attr('data-id', row.id).on('click', function() { redoChange(row.id); }));
			} else {
				actions.append($('<button>').addClass('button button-small sflmcp-btn-rollback').text(sflmcpChangelog.i18n.rollback).attr('data-id', row.id).on('click', function() { rollbackChange(row.id); }));
			}

			tr.append(actions);
			$body.append(tr);
		});
	}

	function renderStats(stats) {
		if (!stats) return;
		$('#stat-total').text(stats.total || 0);
		$('#stat-creates').text(stats.creates || 0);
		$('#stat-updates').text(stats.updates || 0);
		$('#stat-deletes').text(stats.deletes || 0);
		$('#stat-rolled-back').text(stats.rolled_back || 0);
	}

	function renderPagination(total, page) {
		var totalPages = Math.ceil(total / perPage);
		$('#sflmcp-pagination-info').text(
			sflmcpChangelog.i18n.showing + ' ' + (((page - 1) * perPage) + 1) + '-' + Math.min(page * perPage, total) + ' ' + sflmcpChangelog.i18n.of + ' ' + total
		);

		var $buttons = $('#sflmcp-pagination-buttons');
		$buttons.empty();

		if (page > 1) {
			$buttons.append($('<button>').addClass('button').text('« ' + sflmcpChangelog.i18n.prev).on('click', function() { loadChangelog(page - 1); }));
		}
		if (page < totalPages) {
			$buttons.append($('<button>').addClass('button').text(sflmcpChangelog.i18n.next + ' »').on('click', function() { loadChangelog(page + 1); }));
		}
	}

	function showDetail(id) {
		$.post(sflmcpChangelog.ajaxUrl, {
			action: 'sflmcp_get_changelog_detail',
			nonce: sflmcpChangelog.nonce,
			id: id
		}, function(response) {
			if (response.success) {
				var row = response.data;
				$('#detail-id').text('#' + row.id);
				$('#detail-tool').text(row.tool_name);
				$('#detail-operation').html('<span class="sflmcp-op-badge op-' + escHtml(row.operation_type) + '">' + escHtml(row.operation_type) + '</span>');
				$('#detail-object').text(row.object_type + (row.object_id ? ' #' + row.object_id : ''));
				$('#detail-subtype').text(row.object_subtype || '-');
				$('#detail-user').text(row.user_display || (row.user_id > 0 ? 'User #' + row.user_id : '-'));
				$('#detail-ip').text(row.ip_address || '-');
				$('#detail-source').text(row.source_display || row.source || '-');
				$('#detail-date').text(row.created_at);
				$('#detail-session').text(row.session_id || '-');
				// Show session rollback button if session exists and entry is not yet rolled back
				if (row.session_id && parseInt(row.rolled_back) !== 1) {
					$('#sflmcp-rollback-session-btn').data('session-id', row.session_id).show();
				} else {
					$('#sflmcp-rollback-session-btn').hide();
				}
				$('#detail-status').text(parseInt(row.rolled_back) === 1 ? sflmcpChangelog.i18n.rolledBack + ' (' + row.rolled_back_at + ')' : sflmcpChangelog.i18n.active);

				$('#detail-args').text(formatJson(row.args_json));
				$('#detail-before').text(formatJson(row.before_state));
				$('#detail-after').text(formatJson(row.after_state));

				$('#sflmcp-modal-overlay').addClass('active');
			}
		});
	}

	function rollbackChange(id) {
		if (!confirm(sflmcpChangelog.i18n.confirmRollback)) return;

		$.post(sflmcpChangelog.ajaxUrl, {
			action: 'sflmcp_rollback_change',
			nonce: sflmcpChangelog.nonce,
			id: id
		}, function(response) {
			if (response.success) {
				alert(response.data.message);
				loadChangelog(currentPage);
			} else {
				alert(sflmcpChangelog.i18n.error + ': ' + (response.data || ''));
			}
		});
	}

	function rollbackSession(sessionId) {
		if (!confirm(sflmcpChangelog.i18n.confirmSessionRollback)) return;

		$.post(sflmcpChangelog.ajaxUrl, {
			action: 'sflmcp_rollback_session',
			nonce: sflmcpChangelog.nonce,
			session_id: sessionId
		}, function(response) {
			if (response.success) {
				alert(response.data.message);
				$('#sflmcp-modal-overlay').removeClass('active');
				loadChangelog(currentPage);
			} else {
				alert(sflmcpChangelog.i18n.error + ': ' + (response.data || ''));
			}
		});
	}

	function redoChange(id) {
		if (!confirm(sflmcpChangelog.i18n.confirmRedo)) return;

		$.post(sflmcpChangelog.ajaxUrl, {
			action: 'sflmcp_redo_change',
			nonce: sflmcpChangelog.nonce,
			id: id
		}, function(response) {
			if (response.success) {
				alert(response.data.message);
				loadChangelog(currentPage);
			} else {
				alert(sflmcpChangelog.i18n.error + ': ' + (response.data || ''));
			}
		});
	}

	function purgeChangelog() {
		var days = parseInt($('#sflmcp-purge-days').val(), 10) || 30;
		if (!confirm(sflmcpChangelog.i18n.confirmPurge.replace('%d', days))) return;

		$.post(sflmcpChangelog.ajaxUrl, {
			action: 'sflmcp_purge_changelog',
			nonce: sflmcpChangelog.nonce,
			days: days
		}, function(response) {
			if (response.success) {
				alert(response.data.message);
				loadChangelog(1);
			} else {
				alert(sflmcpChangelog.i18n.error + ': ' + (response.data || ''));
			}
		});
	}

	function exportChangelog() {
		var filters = {
			tool_name: $('#sflmcp-filter-tool').val(),
			operation_type: $('#sflmcp-filter-operation').val(),
			object_type: $('#sflmcp-filter-object').val(),
			date_from: $('#sflmcp-filter-date-from').val(),
			date_to: $('#sflmcp-filter-date-to').val()
		};

		$.post(sflmcpChangelog.ajaxUrl, {
			action: 'sflmcp_export_changelog',
			nonce: sflmcpChangelog.nonce,
			filters: JSON.stringify(filters)
		}, function(response) {
			if (response.success) {
				var blob = new Blob([response.data.csv], {type: 'text/csv'});
				var link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = 'changelog-' + new Date().toISOString().slice(0,10) + '.csv';
				link.click();
			}
		});
	}

	function formatJson(str) {
		if (!str) return '(empty)';
		try {
			return JSON.stringify(JSON.parse(str), null, 2);
		} catch(e) {
			return str;
		}
	}

	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// Event bindings
	$(function() {
		loadChangelog(1);

		$('#sflmcp-filter-apply').on('click', function() { loadChangelog(1); });
		$('#sflmcp-filter-reset').on('click', function() {
			$('#sflmcp-filter-tool, #sflmcp-filter-operation, #sflmcp-filter-object, #sflmcp-filter-status, #sflmcp-filter-source').val('');
			$('#sflmcp-filter-date-from, #sflmcp-filter-date-to').val('');
			loadChangelog(1);
		});
		$('#sflmcp-purge-btn').on('click', purgeChangelog);
		$('#sflmcp-export-btn').on('click', exportChangelog);

		$('#sflmcp-modal-overlay, .sflmcp-modal-close').on('click', function(e) {
			if (e.target === this) {
				$('#sflmcp-modal-overlay').removeClass('active');
			}
		});

		// Session rollback button inside detail modal
		$('#sflmcp-rollback-session-btn').on('click', function() {
			var sid = $(this).data('session-id');
			if (sid) { rollbackSession(sid); }
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape') {
				$('#sflmcp-modal-overlay').removeClass('active');
			}
		});

		// Toggle changelog enabled
		$('#sflmcp-changelog-toggle').on('change', function() {
			$.post(sflmcpChangelog.ajaxUrl, {
				action: 'sflmcp_toggle_changelog',
				nonce: sflmcpChangelog.nonce,
				enabled: $(this).is(':checked') ? 1 : 0
			});
		});
	});

})(jQuery);
