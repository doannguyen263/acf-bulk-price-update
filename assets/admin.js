(function ($) {
	'use strict';

	var previewOk = false;
	var busy = false;

	function log(msg) {
		var $log = $('#abpu-log');
		$log.append(document.createTextNode(msg + '\n'));
		$log.scrollTop($log[0].scrollHeight);
	}

	function setProgress(processed, total) {
		var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
		$('#abpu-progress').show();
		$('#abpu-progress-fill').css('width', pct + '%');
		$('#abpu-progress-text').text(processed + ' / ' + total + ' (' + pct + '%)');
	}

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = ABPU.nonce;
		return $.post(ABPU.ajaxUrl, data);
	}

	function getFormArgs() {
		return {
			post_type: $('#abpu-post-type').val(),
			field_name: $('#abpu-field').val(),
			amount: $('#abpu-amount').val(),
			mode: $('input[name="abpu-mode"]:checked').val(),
			post_ids: $('#abpu-post-ids').val(),
			chunk_size: $('#abpu-chunk').val()
		};
	}

	function invalidatePreview() {
		previewOk = false;
		$('#abpu-run-btn').prop('disabled', true);
		$('#abpu-preview-result').hide().empty();
	}

	function fillPostTypes() {
		var $sel = $('#abpu-post-type');
		$sel.empty();
		(ABPU.postTypes || []).forEach(function (pt) {
			$sel.append($('<option/>').val(pt.name).text(pt.label));
		});
		if (ABPU.defaultType) {
			$sel.val(ABPU.defaultType);
		}
	}

	function loadFields() {
		var postType = $('#abpu-post-type').val();
		var $field = $('#abpu-field');
		$field.prop('disabled', true).empty().append($('<option/>').text('Loading…'));
		invalidatePreview();

		return post('abpu_get_fields', { post_type: postType }).done(function (res) {
			$field.empty();
			if (!res.success || !res.data.fields || !res.data.fields.length) {
				$field.append($('<option/>').val('').text('No fields found'));
				return;
			}
			res.data.fields.forEach(function (f) {
				$field.append($('<option/>').val(f.name).text(f.label));
			});
			if (ABPU.defaultField) {
				var hasDefault = res.data.fields.some(function (f) {
					return f.name === ABPU.defaultField;
				});
				if (hasDefault) {
					$field.val(ABPU.defaultField);
				}
			}
		}).fail(function () {
			$field.empty().append($('<option/>').val('').text('Failed to load fields'));
		}).always(function () {
			$field.prop('disabled', false);
		});
	}

	function loadHistory(page) {
		page = page || 1;
		var $box = $('#abpu-history');
		$box.html('<p>Loading…</p>');

		post('abpu_list_batches', { page: page }).done(function (res) {
			if (!res.success) {
				$box.html('<p>Failed to load history.</p>');
				return;
			}
			var items = res.data.items || [];
			if (!items.length) {
				$box.html('<p>No batches yet.</p>');
				return;
			}

			var html = '<table class="abpu-history-table"><thead><tr>' +
				'<th>ID</th><th>Date</th><th>Post type</th><th>Field</th><th>Amount</th><th>Total</th><th>Status</th><th></th>' +
				'</tr></thead><tbody>';

			items.forEach(function (b) {
				html += '<tr>' +
					'<td>#' + b.id + '</td>' +
					'<td>' + (b.created_at || '') + '</td>' +
					'<td>' + b.post_type + '</td>' +
					'<td>' + b.field_name + '</td>' +
					'<td>' + b.amount + '</td>' +
					'<td>' + b.total + '</td>' +
					'<td><span class="abpu-badge ' + b.status + '">' + b.status + '</span></td>' +
					'<td class="abpu-row-actions">' +
						'<button type="button" class="button button-small abpu-detail" data-id="' + b.id + '">Details</button>' +
						(b.status === 'completed'
							? '<button type="button" class="button button-small abpu-rollback" data-id="' + b.id + '">Rollback</button>'
							: '') +
						(b.status !== 'running'
							? '<button type="button" class="button button-small abpu-delete" data-id="' + b.id + '">Delete</button>'
							: '') +
					'</td></tr>';
			});
			html += '</tbody></table>';
			$box.html(html);
		});
	}

	function showDetail(batchId, page) {
		page = parseInt(page, 10) || 1;
		var perPage = 50;
		var $detail = $('#abpu-history-detail').show();
		$detail.html('<p>Loading batch #' + batchId + '…</p>');

		post('abpu_list_batch_items', { batch_id: batchId, page: page }).done(function (res) {
			if (!res.success) {
				$detail.html('<p>Failed to load items.</p>');
				return;
			}
			var items = res.data.items || [];
			var total = parseInt(res.data.total, 10) || 0;
			var totalPages = Math.max(1, Math.ceil(total / perPage));
			if (page > totalPages) {
				page = totalPages;
			}
			var from = total ? ((page - 1) * perPage) + 1 : 0;
			var to = Math.min(page * perPage, total);

			var html = '<h3>Batch #' + batchId + ' items</h3>';
			html += '<p class="abpu-detail-meta">Showing ' + from + '–' + to + ' of ' + total +
				' (page ' + page + ' / ' + totalPages + ')</p>';
			html += '<table class="abpu-history-table"><thead><tr>' +
				'<th>Post ID</th><th>Old</th><th>New</th><th>Status</th><th>Note</th></tr></thead><tbody>';
			items.forEach(function (it) {
				html += '<tr>' +
					'<td>' + it.post_id + '</td>' +
					'<td>' + (it.old_value != null ? it.old_value : '') + '</td>' +
					'<td>' + (it.new_value != null ? it.new_value : '') + '</td>' +
					'<td>' + it.status + '</td>' +
					'<td>' + (it.error_message || '') + '</td></tr>';
			});
			html += '</tbody></table>';

			if (totalPages > 1) {
				html += '<p class="abpu-pagination">' +
					'<button type="button" class="button abpu-detail-page" data-id="' + batchId + '" data-page="' + (page - 1) + '"' +
					(page <= 1 ? ' disabled' : '') + '>Prev</button> ' +
					'<button type="button" class="button abpu-detail-page" data-id="' + batchId + '" data-page="' + (page + 1) + '"' +
					(page >= totalPages ? ' disabled' : '') + '>Next</button>' +
					'</p>';
			}

			$detail.html(html);
		});
	}

	function runChunks(action, payload, onDone) {
		busy = true;
		$('#abpu-run-btn, #abpu-preview-btn').prop('disabled', true);
		$('#abpu-progress').show();
		$('#abpu-log').empty();
		log(ABPU.i18n.running);

		function step() {
			post(action, payload).done(function (res) {
				if (!res.success) {
					log(ABPU.i18n.error + ': ' + (res.data && res.data.message ? res.data.message : 'unknown'));
					busy = false;
					$('#abpu-preview-btn').prop('disabled', false);
					invalidatePreview();
					loadHistory();
					return;
				}
				var d = res.data;
				setProgress(d.processed || 0, d.total || 0);
				log('Processed ' + (d.processed || 0) + ' / ' + (d.total || 0));
				if (d.done) {
					log(ABPU.i18n.done + (d.status ? ' [' + d.status + ']' : ''));
					busy = false;
					$('#abpu-preview-btn').prop('disabled', false);
					invalidatePreview();
					loadHistory();
					if (typeof onDone === 'function') {
						onDone(d);
					}
					return;
				}
				// Continue without re-sending start flag for rollback.
				if (payload.start) {
					payload.start = 0;
				}
				step();
			}).fail(function (xhr) {
				log(ABPU.i18n.error + ': HTTP ' + xhr.status);
				busy = false;
				$('#abpu-preview-btn').prop('disabled', false);
				loadHistory();
			});
		}

		step();
	}

	$(function () {
		fillPostTypes();
		loadFields();
		loadHistory();

		$('#abpu-post-type').on('change', loadFields);
		$('#abpu-field, #abpu-amount, #abpu-chunk, #abpu-post-ids').on('change input', invalidatePreview);
		$('input[name="abpu-mode"]').on('change', function () {
			var selected = $('input[name="abpu-mode"]:checked').val() === 'selected';
			$('#abpu-post-ids').toggle(selected);
			invalidatePreview();
		});

		$('#abpu-preview-btn').on('click', function () {
			if (busy) {
				return;
			}
			var args = getFormArgs();
			$('#abpu-preview-result').show().text('Loading preview…');
			post('abpu_preview', args).done(function (res) {
				if (!res.success) {
					previewOk = false;
					$('#abpu-run-btn').prop('disabled', true);
					$('#abpu-preview-result').text(res.data && res.data.message ? res.data.message : 'Preview failed');
					return;
				}
				var d = res.data;
				previewOk = d.parseable > 0;
				$('#abpu-run-btn').prop('disabled', !previewOk);
				$('#abpu-preview-result').html(
					'<strong>Preview</strong><br>' +
					'Total in scope: ' + d.total + '<br>' +
					'Has field value: ' + d.has_meta + '<br>' +
					'Parseable (will update): ' + d.parseable + '<br>' +
					'Will skip: ' + d.skip
				);
			}).fail(function () {
				$('#abpu-preview-result').text('Preview request failed');
			});
		});

		$('#abpu-run-btn').on('click', function () {
			if (busy) {
				return;
			}
			if (!previewOk) {
				window.alert(ABPU.i18n.previewFirst);
				return;
			}
			if (!window.confirm(ABPU.i18n.confirmRun)) {
				return;
			}
			var args = getFormArgs();
			post('abpu_start_batch', args).done(function (res) {
				if (!res.success) {
					window.alert(res.data && res.data.message ? res.data.message : 'Start failed');
					return;
				}
				runChunks('abpu_process_chunk', {
					batch_id: res.data.batch_id,
					chunk_size: res.data.chunk_size || args.chunk_size
				});
			});
		});

		$('#abpu-history').on('click', '.abpu-detail', function () {
			showDetail($(this).data('id'), 1);
		});

		$('#abpu-history-detail').on('click', '.abpu-detail-page', function () {
			var $btn = $(this);
			if ($btn.prop('disabled')) {
				return;
			}
			showDetail($btn.data('id'), $btn.data('page'));
		});

		$('#abpu-history').on('click', '.abpu-rollback', function () {
			if (busy) {
				return;
			}
			if (!window.confirm(ABPU.i18n.confirmRollback)) {
				return;
			}
			var id = $(this).data('id');
			var chunk = $('#abpu-chunk').val() || 50;
			runChunks('abpu_rollback_chunk', {
				batch_id: id,
				chunk_size: chunk,
				start: 1
			});
		});

		$('#abpu-history').on('click', '.abpu-delete', function () {
			if (busy) {
				return;
			}
			if (!window.confirm(ABPU.i18n.confirmDelete)) {
				return;
			}
			var id = $(this).data('id');
			post('abpu_delete_batch', { batch_id: id }).done(function (res) {
				if (!res.success) {
					window.alert(res.data && res.data.message ? res.data.message : 'Delete failed');
					return;
				}
				$('#abpu-history-detail').hide().empty();
				loadHistory();
			});
		});
	});
})(jQuery);
