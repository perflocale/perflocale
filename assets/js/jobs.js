/* global perflocaleJobs */
/**
 * PerfLocale — Background Jobs admin page.
 *
 *   1. Polls /wp-json/perflocale/v1/jobs every 5 seconds while any row is
 *      in 'queued' or 'running' state and re-renders the table body in
 *      place. Stops as soon as no row is in-flight.
 *
 *   2. Wires per-row [data-perflocale-jobs-action] buttons (cancel /
 *      retry / delete) to the REST API. One delegated click handler at
 *      document level, so newly-rendered rows pick up the same wiring
 *      without re-binding.
 *
 * All dynamic data (REST URLs, REST nonce, translated labels) is passed
 * in via wp_localize_script() as window.perflocaleJobs.
 */

(function () {
	'use strict';

	if (typeof perflocaleJobs === 'undefined') {
		return;
	}

	var labels = perflocaleJobs.labels || {};
	var pollUrl = perflocaleJobs.pollUrl || '';
	var nonce = perflocaleJobs.nonce || '';
	var ago = perflocaleJobs.ago || '';
	var isMultisite = !!perflocaleJobs.isMultisite;
	var i18n = perflocaleJobs.i18n || {};
	var t = function (key, fallback) { return i18n[key] || fallback; };

	var escapeHtml = function (s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return ({
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			})[c];
		});
	};

	var humanDelta = function (ts) {
		if (!ts) {
			return '';
		}
		var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
		if (s < 60) {
			return s + 's ' + ago;
		}
		if (s < 3600) {
			return Math.floor(s / 60) + 'm ' + ago;
		}
		return Math.floor(s / 3600) + 'h ' + ago;
	};

	// 1. Auto-refresh poller — only fires when at least one row is in-flight.
	// Updates the status / progress / updated cells in place rather than
	// replacing the entire tbody. That keeps the per-row action buttons,
	// detail-toggle state, and the expanded detail row (a sibling <tr>)
	// from being wiped on each poll tick.
	//
	// When a row transitions to a terminal state we do one full reload so
	// the server-rendered actions cell swaps (cancel → retry/delete).
	var startPolling = function () {
		var anyRunning = document.querySelectorAll(
			'.perflocale-jobs-status.running, .perflocale-jobs-status.queued'
		).length > 0;

		if (!anyRunning || !pollUrl) {
			return;
		}

		var tbody = document.querySelector('.perflocale-jobs-table tbody');
		if (!tbody) {
			return;
		}

		var pollFailures = 0;

		var poll = function () {
			fetch(pollUrl, { headers: { 'X-WP-Nonce': nonce } })
				.then(function (r) {
					// A non-2xx (expired REST nonce 403, transient 502/503
					// exactly while jobs hammer the server) must take the
					// SAME bounded-retry path as a network error — mapping
					// it to null used to exit without rescheduling, leaving
					// stale "running" badges frozen forever.
					if (!r.ok) { throw new Error('poll ' + r.status); }
					return r.json();
				})
				.then(function (data) {
					if (!data || !Array.isArray(data.jobs)) {
						throw new Error('poll malformed payload');
					}
					var stillRunning = false;
					var anyTerminalNow = false;

					data.jobs.forEach(function (j) {
						var status = String(j.status || '');
						if (status === 'queued' || status === 'running') {
							stillRunning = true;
						}
						var row = tbody.querySelector(
							'[data-perflocale-job-row="' + String(j.id || '').replace(/"/g, '') + '"]'
						);
						if (!row) {
							return;
						}

						// Status cell — preserve the inline error sub-div if any.
						var statusCell = row.querySelector('[data-perflocale-cell="status"]');
						if (statusCell) {
							var span = statusCell.querySelector('.perflocale-jobs-status');
							if (span && !span.classList.contains(status)) {
								if (status === 'complete' || status === 'failed' || status === 'canceled') {
									anyTerminalNow = true;
								}
								span.className = 'perflocale-jobs-status ' + status;
								span.textContent = labels[status] || status;
							}
						}

						// Progress cell — replace just the "%" text node + bar width.
						var progressCell = row.querySelector('[data-perflocale-cell="progress"]');
						if (progressCell) {
							var pct = Math.min(100, Math.max(0, j.progress | 0));
							progressCell.firstChild.nodeValue = '\n\t\t\t\t\t' + pct + '%\n\t\t\t\t\t';
							var track = progressCell.querySelector('.perflocale-jobs-progress');
							if (track) {
								// Keep the progressbar's accessible value in
								// sync with the visual width — screen readers
								// announce aria-valuenow, not CSS.
								track.setAttribute('aria-valuenow', String(pct));
							}
							var bar = progressCell.querySelector('.perflocale-jobs-progress span');
							if (bar) {
								bar.style.width = pct + '%';
							}
						}

						// "X ago" cell — pure text replace.
						var updatedCell = row.querySelector('[data-perflocale-cell="updated"]');
						if (updatedCell) {
							updatedCell.textContent = humanDelta(j.updated_at);
						}
					});

					if (anyTerminalNow) {
						// One row just finished — server-rendered actions
						// cell needs to swap (cancel → retry/delete). Reload once.
						location.reload();
						return;
					}
					if (stillRunning) {
						pollFailures = 0; // a successful poll clears the transient-error budget
						setTimeout(poll, 5000);
					}
				})
				.catch(function () {
					// A transient fetch/parse error must not freeze live progress
					// or throw an unhandled rejection; re-arm with a bounded retry
					// so a permanently-down endpoint eventually stops polling.
					if (pollFailures++ < 5) {
						setTimeout(poll, 5000);
						return;
					}
					// Budget exhausted: say so instead of leaving stale
					// "running" badges that silently stopped updating.
					var table = document.querySelector('.perflocale-jobs-table');
					if (table && !document.querySelector('.perflocale-jobs-poll-notice')) {
						var notice = document.createElement('div');
						notice.className = 'notice notice-warning perflocale-jobs-poll-notice';
						notice.setAttribute('role', 'status');
						var p = document.createElement('p');
						p.textContent = (window.perflocaleJobs && window.perflocaleJobs.i18n && window.perflocaleJobs.i18n.pollPaused) ||
							'Live updates paused after repeated errors — reload the page to resume.';
						notice.appendChild(p);
						table.parentNode.insertBefore(notice, table);
					}
				});
		};

		setTimeout(poll, 5000);
	};

	// Render the lazy-loaded detail panel for one job. Pulls args / log /
	// error / engine / blog_id from /jobs/{id} and lays them out as a
	// definition list. Args + result render as JSON in <pre>. Log renders
	// as a <ul> in chronological order. Safely escapes all values.
	var renderDetail = function (data) {
		var rows = [];

		var dt = function (label, value, asPre) {
			if (value === null || value === undefined || value === '') {
				return;
			}
			var inner = asPre
				? '<pre>' + escapeHtml(value) + '</pre>'
				: '<span>' + escapeHtml(value) + '</span>';
			rows.push(
				'<dt>' + escapeHtml(label) + '</dt>' +
				'<dd>' + inner + '</dd>'
			);
		};

		dt(t('id', 'ID'), data.id || '');
		dt(t('type', 'Type'), data.type || '');
		dt(t('engine', 'Engine'), data.engine || '');

		// Prefer the human-readable label the server resolves (display
		// name / username); fall back to the integer user ID only when
		// the server didn't ship a label.
		var createdByLabel = (data.created_by_label && String(data.created_by_label))
			|| (data.created_by ? String(data.created_by) : '');
		dt(t('created_by', 'Created by'), createdByLabel);

		// Blog ID is only meaningful on multisite. Single-site installs
		// always have blog_id=1; surfacing it just adds noise.
		if (isMultisite && data.blog_id) {
			dt(t('blog_id', 'Blog ID'), data.blog_id);
		}

		dt(t('attempts', 'Attempts'), data.attempts || 0);
		if (data.error) {
			dt(t('error', 'Error'), data.error, true);
		}
		if (data.args && typeof data.args === 'object') {
			try {
				dt(t('args', 'Args'), JSON.stringify(data.args, null, 2), true);
			} catch (e) {
				dt(t('args', 'Args'), '(unserializable)');
			}
		} else if (data.args_redacted) {
			dt(t('args', 'Args'), data.args_redacted, false);
		}
		if (data.result && typeof data.result === 'object' && Object.keys(data.result).length) {
			try {
				dt(t('result', 'Result'), JSON.stringify(data.result, null, 2), true);
			} catch (e) { /* no-op */ }
		}
		if (Array.isArray(data.log) && data.log.length) {
			var items = data.log.map(function (l) {
				var when = l && l.t ? humanDelta(l.t) : '';
				var msg = l && l.m ? l.m : '';
				return '<li><span class="perflocale-jobs-log-when">' +
					escapeHtml(when) + '</span> ' + escapeHtml(msg) + '</li>';
			}).join('');
			rows.push(
				'<dt>' + escapeHtml(t('log', 'Log')) + '</dt>' +
				'<dd><ul class="perflocale-jobs-log">' + items + '</ul></dd>'
			);
		}

		return '<dl class="perflocale-jobs-detail">' + rows.join('') + '</dl>';
	};

	// 2. Delegated click handler for cancel / retry / delete / details buttons.
	var wireButtons = function () {
		document.addEventListener('click', function (e) {
			if (!e.target.closest) {
				return;
			}
			var btn = e.target.closest('[data-perflocale-jobs-action]');
			if (!btn) {
				return;
			}
			var action = btn.dataset.perflocaleJobsAction;
			var base = btn.dataset.base;
			var rowNonce = btn.dataset.nonce;
			var jobId = btn.dataset.job;

			// Details toggle: fetch the per-job state on first expand, then
			// cache the rendered detail in the sibling tr. Subsequent clicks
			// just toggle visibility — no extra request.
			if (action === 'details') {
				var detailRow = document.querySelector(
					'[data-perflocale-job-detail="' + (jobId || '').replace(/"/g, '') + '"]'
				);
				if (!detailRow) {
					return;
				}
				var isOpen = !detailRow.hasAttribute('hidden');
				if (isOpen) {
					detailRow.setAttribute('hidden', 'hidden');
					btn.setAttribute('aria-expanded', 'false');
					return;
				}
				detailRow.removeAttribute('hidden');
				btn.setAttribute('aria-expanded', 'true');

				// Only fetch if we haven't already cached the body.
				if (detailRow.dataset.loaded === '1') {
					return;
				}

				fetch(base, { headers: { 'X-WP-Nonce': rowNonce } })
					.then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
					.then(function (data) {
						var cell = detailRow.querySelector('.perflocale-jobs-detail-cell');
						if (cell) {
							cell.innerHTML = renderDetail(data);
							detailRow.dataset.loaded = '1';
						}
					})
					.catch(function (err) {
						var cell = detailRow.querySelector('.perflocale-jobs-detail-cell');
						if (cell) {
							cell.innerHTML = '<p class="perflocale-jobs-detail-error">' +
								escapeHtml(String(err && err.message ? err.message : err)) + '</p>';
						}
					});
				return;
			}

			var path = action === 'cancel'
				? '/cancel'
				: (action === 'retry' ? '/retry' : '');
			var method = action === 'delete' ? 'DELETE' : 'POST';

			btn.disabled = true;
			fetch(base + path, { method: method, headers: { 'X-WP-Nonce': rowNonce } })
				.then(function (r) {
					if (r.ok) {
						location.reload();
					} else {
						btn.disabled = false;
						window.alert(t('requestFailed', 'Failed: %1$s').replace('%1$s', 'HTTP ' + r.status));
					}
				})
				.catch(function (err) {
					btn.disabled = false;
					window.alert(t('requestFailed', 'Failed: %1$s').replace('%1$s', String(err)));
				});
		});
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			wireButtons();
			startPolling();
		});
	} else {
		wireButtons();
		startPolling();
	}
}());
