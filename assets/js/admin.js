/**
 * Smart WebP Flow - Admin Dashboard Script
 *
 * @package SmartWebPFlow
 */

(function () {
	'use strict';

	// Retrieve localized data injected by PHP
	const config = window.smartWebPFlowData || {
		restUrl: '/wp-json/smart-webp/v1',
		nonce: '',
		strings: {}
	};

	let isInteractiveRunning = false;
	let bgPollTimer = null;

	document.addEventListener('DOMContentLoaded', function () {
		initTabs();
		initSliders();
		initEngineToggle();
		initBulkSync();
		initContentMigration();
	});

	/**
	 * 1. Tab Navigation Controller
	 */
	function initTabs() {
		const tabLinks = document.querySelectorAll('.smart-webp-tabs .nav-tab');
		const tabContents = document.querySelectorAll('.smart-webp-tab-content');

		function switchTab(targetId) {
			if (!targetId) return;

			tabLinks.forEach(function (t) {
				if (t.getAttribute('data-tab') === targetId) {
					t.classList.add('nav-tab-active');
				} else {
					t.classList.remove('nav-tab-active');
				}
			});

			tabContents.forEach(function (c) {
				if (c.id === 'tab-' + targetId) {
					c.classList.add('active');
					c.style.display = 'block';
				} else {
					c.classList.remove('active');
					c.style.display = 'none';
				}
			});
		}

		tabLinks.forEach(function (tab) {
			tab.addEventListener('click', function (e) {
				e.preventDefault();
				const targetId = this.getAttribute('data-tab');
				if (targetId) {
					switchTab(targetId);
					if (window.history && window.history.replaceState) {
						window.history.replaceState(null, null, '#' + targetId);
					} else {
						window.location.hash = '#' + targetId;
					}
				}
			});
		});

		// Check hash on page load (e.g. #bulk-sync, #content-migrator, #settings)
		if (window.location.hash) {
			const hashId = window.location.hash.replace('#', '');
			const matchingTab = document.querySelector('.smart-webp-tabs .nav-tab[data-tab="' + hashId + '"]');
			if (matchingTab) {
				switchTab(hashId);
			}
		}

		// Handle browser back/forward buttons
		window.addEventListener('hashchange', function () {
			if (window.location.hash) {
				const hashId = window.location.hash.replace('#', '');
				switchTab(hashId);
			}
		});
	}

	/**
	 * 2. Range Sliders Live Value Synchronization
	 */
	function initSliders() {
		const sliders = [
			{ id: 'quality_jpeg', display: 'quality_jpeg_val', suffix: '%' },
			{ id: 'quality_png', display: 'quality_png_val', suffix: '%' },
			{ id: 'throttle_pacing_ms', display: 'pacing_val', suffix: ' ms' }
		];

		sliders.forEach(function (item) {
			const input = document.getElementById(item.id);
			const label = document.getElementById(item.display);
			if (input && label) {
				input.addEventListener('input', function () {
					label.textContent = this.value + item.suffix;
				});
			}
		});
	}

	/**
	 * 3. Engine Selection Visibility Toggle
	 */
	function initEngineToggle() {
		const select = document.getElementById('engine_mode');
		const apiRow = document.getElementById('api_settings_row');
		if (select && apiRow) {
			function update() {
				apiRow.style.display = (select.value === 'api') ? '' : 'none';
			}
			select.addEventListener('change', update);
			update();
		}
	}

	/**
	 * 4. Bulk Synchronization Subsystem
	 */
	function initBulkSync() {
		const btnInteractive = document.getElementById('btn-start-interactive');
		const btnBg = document.getElementById('btn-start-background');
		const btnPause = document.getElementById('btn-pause');
		const btnResume = document.getElementById('btn-resume');
		const btnReset = document.getElementById('btn-reset');

		if (btnInteractive) {
			btnInteractive.addEventListener('click', function () {
				startInteractiveSync();
			});
		}

		if (btnBg) {
			btnBg.addEventListener('click', function () {
				startBackgroundDaemon();
			});
		}

		if (btnPause) {
			btnPause.addEventListener('click', function () {
				pauseSync();
			});
		}

		if (btnResume) {
			btnResume.addEventListener('click', function () {
				resumeSync();
			});
		}

		if (btnReset) {
			btnReset.addEventListener('click', function () {
				if (confirm('Reset all progress and lock state?')) {
					resetSync();
				}
			});
		}
	}

	/**
	 * Helper: Executes a REST API fetch with nonce headers
	 */
	function apiRequest(endpoint, method, data) {
		const url = config.restUrl.replace(/\/$/, '') + '/' + endpoint.replace(/^\//, '');
		const options = {
			method: method || 'GET',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json'
			}
		};

		if (data && (method === 'POST' || method === 'PUT')) {
			options.body = JSON.stringify(data);
		}

		return fetch(url, options).then(function (res) {
			return res.json();
		});
	}

	/**
	 * Interactive Browser Mode: Chunks processed in browser loop
	 */
	function startInteractiveSync() {
		isInteractiveRunning = true;
		updateSyncButtons('running_interactive');
		runNextInteractiveChunk();
	}

	function runNextInteractiveChunk() {
		if (!isInteractiveRunning) {
			return;
		}

		apiRequest('/bulk-sync/process-chunk', 'POST', { chunk_size: 5 })
			.then(function (res) {
				if (res.success && res.result) {
					const progress = res.result.state;
					updateProgressUI(progress);

					if (res.result.is_completed) {
						isInteractiveRunning = false;
						updateSyncButtons('completed');
						alert('Bulk WebP conversion completed successfully!');
					} else if (isInteractiveRunning) {
						// Pacing before next batch
						setTimeout(runNextInteractiveChunk, 300);
					}
				} else {
					isInteractiveRunning = false;
					updateSyncButtons('idle');
				}
			})
			.catch(function (err) {
				isInteractiveRunning = false;
				updateSyncButtons('idle');
				console.error('Error during chunk processing:', err);
			});
	}

	/**
	 * Autonomous Background Mode: Spawns server worker and polls status
	 */
	function startBackgroundDaemon() {
		apiRequest('/background/start', 'POST')
			.then(function (res) {
				if (res.success) {
					updateSyncButtons('running_background');
					startStatusPolling();
				}
			});
	}

	function pauseSync() {
		isInteractiveRunning = false;
		apiRequest('/background/pause', 'POST')
			.then(function () {
				stopStatusPolling();
				updateSyncButtons('paused');
			});
	}

	function resumeSync() {
		apiRequest('/background/resume', 'POST')
			.then(function () {
				updateSyncButtons('running_background');
				startStatusPolling();
			});
	}

	function resetSync() {
		isInteractiveRunning = false;
		stopStatusPolling();
		apiRequest('/bulk-sync/reset', 'POST')
			.then(function (res) {
				if (res.success && res.state) {
					updateProgressUI(res.state);
					updateSyncButtons('idle');
				}
			});
	}

	function startStatusPolling() {
		stopStatusPolling();
		bgPollTimer = setInterval(function () {
			apiRequest('/bulk-sync/status', 'GET')
				.then(function (res) {
					if (res.success && res.state) {
						updateProgressUI(res.state);
						if (res.state.is_completed) {
							stopStatusPolling();
							updateSyncButtons('completed');
						} else if (res.state.status === 'paused') {
							stopStatusPolling();
							updateSyncButtons('paused');
						}
					}
				});
		}, 2000);
	}

	function stopStatusPolling() {
		if (bgPollTimer) {
			clearInterval(bgPollTimer);
			bgPollTimer = null;
		}
	}

	function updateProgressUI(progress) {
		const fill = document.getElementById('progress-fill');
		const text = document.getElementById('progress-text');
		const statProcessed = document.getElementById('stat-processed');
		const statTotal = document.getElementById('stat-total');
		const statConverted = document.getElementById('stat-converted');
		const statSaved = document.getElementById('stat-saved');
		const statEta = document.getElementById('stat-eta');

		const percent = progress.percent || 0;
		if (fill) {
			fill.style.width = percent + '%';
		}
		if (text) {
			text.textContent = percent + '%';
		}

		if (statProcessed) { statProcessed.textContent = progress.processed || 0; }
		if (statTotal) { statTotal.textContent = progress.total || 0; }
		if (statConverted) { statConverted.textContent = progress.converted || 0; }
		if (statSaved) { statSaved.textContent = progress.saved_formatted || '0 B'; }
		if (statEta) {
			statEta.textContent = progress.eta_seconds ? (progress.eta_seconds + 's') : '--';
		}
	}

	function updateSyncButtons(status) {
		const btnInteractive = document.getElementById('btn-start-interactive');
		const btnBg = document.getElementById('btn-start-background');
		const btnPause = document.getElementById('btn-pause');
		const btnResume = document.getElementById('btn-resume');

		const isRunning = (status === 'running_interactive' || status === 'running_background');

		if (btnInteractive) { btnInteractive.disabled = isRunning; }
		if (btnBg) { btnBg.disabled = isRunning; }
		if (btnPause) { btnPause.disabled = !isRunning; }
		if (btnResume) { btnResume.disabled = (status !== 'paused'); }
	}

	/**
	 * 5. Content Migration Wizard Controller
	 */
	function initContentMigration() {
		const btnScan = document.getElementById('btn-scan-content');
		const btnDryRun = document.getElementById('btn-dry-run-content');
		const btnExecute = document.getElementById('btn-execute-content');
		const resultBox = document.getElementById('content-migration-results');

		if (btnScan) {
			btnScan.addEventListener('click', function () {
				btnScan.disabled = true;
				apiRequest('/content-sync/scan', 'POST')
					.then(function (res) {
						btnScan.disabled = false;
						if (res.success) {
							const count = parseInt(res.candidate_posts || 0, 10);
							const msg = 'Found ' + count + ' posts/pages available for image URL optimization.';
							if (resultBox) {
								resultBox.innerHTML = '<div class="alert-box alert-info">' + msg + '</div>';
							}
							if (btnDryRun) {
								btnDryRun.disabled = (count === 0);
							}
						}
					});
			});
		}

		if (btnDryRun) {
			btnDryRun.addEventListener('click', function () {
				btnDryRun.disabled = true;
				apiRequest('/content-sync/dry-run', 'POST', { chunk_size: 20 })
					.then(function (res) {
						btnDryRun.disabled = false;
						if (res.success && res.result) {
							renderDryRunPreview(res.result);
							if (btnExecute && res.result.replacements > 0) {
								btnExecute.disabled = false;
							}
						}
					});
			});
		}

		if (btnExecute) {
			btnExecute.addEventListener('click', function () {
				if (!confirm('Proceed with permanent database content migration? (Rollback snapshots will be saved)')) {
					return;
				}
				btnExecute.disabled = true;
				apiRequest('/content-sync/execute', 'POST', { chunk_size: 20 })
					.then(function (res) {
						btnExecute.disabled = false;
						if (res.success && res.result) {
							alert('Migration complete! Updated ' + res.result.updated_posts + ' posts with ' + res.result.replacements + ' image replacements.');
							location.reload();
						}
					});
			});
		}

		// Delegated rollback click handler
		document.addEventListener('click', function (e) {
			if (e.target && e.target.classList.contains('btn-rollback-post')) {
				const postId = e.target.getAttribute('data-post-id');
				if (postId && confirm('Roll back content for post #' + postId + ' to pre-migration state?')) {
					apiRequest('/content-sync/rollback', 'POST', { post_id: parseInt(postId, 10) })
						.then(function (res) {
							if (res.success) {
								alert(res.message);
								location.reload();
							} else {
								alert('Rollback failed: ' + res.message);
							}
						});
				}
			}
		});
	}

	function renderDryRunPreview(result) {
		const resultBox = document.getElementById('content-migration-results');
		if (!resultBox) {
			return;
		}

		let html = '<div class="alert-box alert-success">' +
			'Dry-Run Preview: Scanned ' + result.scanned_posts + ' posts. ' +
			'Found <strong>' + result.replacements + '</strong> replaceable images across ' + result.matched_posts + ' posts. (0 database writes performed)' +
			'</div>';

		if (result.previews && result.previews.length > 0) {
			html += '<table class="diff-table">';
			html += '<thead><tr><th>Post ID & Title</th><th>Image Replacements</th></tr></thead><tbody>';

			result.previews.forEach(function (p) {
				html += '<tr><td><strong>#' + p.post_id + '</strong> ' + p.post_title + '</td><td>';
				p.replacements.forEach(function (r) {
					html += '<div style="margin-bottom:4px;">' +
						'<span class="diff-before">' + r.original + '</span> &rarr; ' +
						'<span class="diff-after">' + r.webp + '</span>' +
						'</div>';
				});
				html += '</td></tr>';
			});

			html += '</tbody></table>';
		}

		resultBox.innerHTML = html;
	}
})();
