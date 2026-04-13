/**
 * Batch enrichment UI controller for the Peptide Search AI admin page.
 *
 * What: Drives the AJAX polling loop that enriches one peptide at a time with a progress bar.
 * Who loads it: PSA_Admin::enqueue_batch_scripts() on the PSA settings page.
 * Dependencies: jQuery (WP core), wp.ajax (WP admin AJAX helper), psaBatchEnrich localized data.
 *
 * @see includes/class-psa-batch-enrichment.php
 * @see includes/class-psa-admin.php
 */
/* global jQuery, psaBatchEnrich */
(function ($) {
	'use strict';

	var isRunning = false;
	var processed = 0;
	var errors = 0;
	var total = 0;
	var remaining = 0;

	/**
	 * Initialize: bind the start button and fetch initial status.
	 */
	function init() {
		var $btn = $('#psa-batch-enrich-start');
		if (!$btn.length) {
			return;
		}

		$btn.on('click', function (e) {
			e.preventDefault();
			if (isRunning) {
				return;
			}
			startEnrichment();
		});

		// Fetch initial count on page load.
		fetchStatus();
	}

	/**
	 * Fetch the current enrichment status (how many need enrichment).
	 */
	function fetchStatus() {
		$.post(psaBatchEnrich.ajaxUrl, {
			action: 'psa_batch_enrich_status',
			_nonce: psaBatchEnrich.nonce
		}, function (response) {
			if (response.success) {
				remaining = response.data.remaining;
				total = response.data.remaining; // Total to process this run.
				updateStatusText(response.data.remaining + ' peptides need enrichment');

				if (response.data.remaining === 0) {
					$('#psa-batch-enrich-start').prop('disabled', true).text('All enriched');
				}
			}
		});
	}

	/**
	 * Start the enrichment loop.
	 */
	function startEnrichment() {
		isRunning = true;
		processed = 0;
		errors = 0;

		$('#psa-batch-enrich-start').prop('disabled', true).text('Running...');
		$('#psa-batch-enrich-progress').show();
		$('#psa-batch-enrich-log').empty().show();

		updateProgress(0, total);
		processNext();
	}

	/**
	 * Process one peptide, then loop.
	 */
	function processNext() {
		if (!isRunning) {
			return;
		}

		$.post(psaBatchEnrich.ajaxUrl, {
			action: 'psa_batch_enrich_next',
			_nonce: psaBatchEnrich.nonce
		}, function (response) {
			if (!response.success) {
				appendLog('Server error: ' + (response.data || 'Unknown'), 'error');
				finish();
				return;
			}

			var data = response.data;

			if (data.status === 'done') {
				appendLog('All peptides enriched!', 'success');
				finish();
				return;
			}

			if (data.status === 'cap_reached') {
				appendLog(data.message, 'warning');
				finish();
				return;
			}

			processed++;
			remaining = data.remaining;

			if (data.status === 'ok') {
				appendLog('Enriched: ' + data.name, 'success');
			} else if (data.status === 'error') {
				errors++;
				appendLog('Failed: ' + data.name + ' — ' + data.message, 'error');
			}

			updateProgress(processed, processed + remaining);

			// Brief delay to avoid hammering the server — 2 seconds between requests.
			setTimeout(processNext, 2000);

		}).fail(function (xhr) {
			appendLog('Request failed (HTTP ' + xhr.status + '). Retrying in 10s...', 'warning');
			setTimeout(processNext, 10000);
		});
	}

	/**
	 * Update the progress bar.
	 */
	function updateProgress(done, total) {
		var pct = total > 0 ? Math.round((done / total) * 100) : 0;
		$('#psa-batch-enrich-bar').css('width', pct + '%');
		$('#psa-batch-enrich-pct').text(pct + '% (' + done + '/' + total + ')');
	}

	/**
	 * Update the status text below the button.
	 */
	function updateStatusText(text) {
		$('#psa-batch-enrich-status').text(text);
	}

	/**
	 * Append a line to the log.
	 */
	function appendLog(message, type) {
		var color = type === 'error' ? '#dc3232' : type === 'warning' ? '#dba617' : '#46b450';
		var icon = type === 'error' ? '✗' : type === 'warning' ? '⚠' : '✓';
		$('#psa-batch-enrich-log').prepend(
			'<div style="color:' + color + ';margin:2px 0;font-size:13px;">' +
			icon + ' ' + $('<span>').text(message).html() +
			'</div>'
		);
	}

	/**
	 * End the run.
	 */
	function finish() {
		isRunning = false;
		$('#psa-batch-enrich-start').prop('disabled', false).text('Re-enrich Peptides');

		var summary = 'Done. ' + processed + ' processed, ' + errors + ' errors, ' + remaining + ' remaining.';
		updateStatusText(summary);
	}

	$(document).ready(init);
})(jQuery);
