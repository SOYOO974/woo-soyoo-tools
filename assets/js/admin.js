/**
 * Admin Script — Woo SOYOO Tools
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		const searchInput = document.getElementById('wst-feature-search');
		const categoryPills = document.querySelectorAll('.wst-filter-pill');
		const statusPills = document.querySelectorAll('.wst-status-pill');
		const cards = document.querySelectorAll('.wst-feature-card');
		const emptyState = document.getElementById('wst-empty-state');
		const resetBtn = document.getElementById('wst-reset-filters');
		const toast = document.getElementById('wst-toast');
		const toastMsg = document.getElementById('wst-toast-message');
		const statActive = document.getElementById('wst-stat-active');
		const statDisabled = document.getElementById('wst-stat-disabled');

		let activeCategory = 'all';
		let activeStatus = 'all';
		let toastTimer = null;

		/**
		 * Remove any parasitic third-party notices injected via JS into .wst-wrap.
		 */
		function cleanParasiticNotices() {
			const wrap = document.querySelector('.wst-wrap');
			if (!wrap) return;
			const notices = wrap.querySelectorAll('.notice:not(.wst-banner-notice), .updated:not(.wst-banner-notice), .error:not(.wst-banner-notice)');
			notices.forEach(function (el) {
				el.remove();
			});
		}
		cleanParasiticNotices();

		/**
		 * Apply combined search, category, and status filters to feature cards.
		 */
		function applyFilters() {
			const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
			let visibleCount = 0;

			cards.forEach(function (card) {
				const cardCat = card.getAttribute('data-category') || 'general';
				const cardStatus = card.getAttribute('data-status') || 'enabled';
				const searchBlob = card.getAttribute('data-search') || '';

				const matchCat = activeCategory === 'all' || cardCat === activeCategory;
				const matchStatus = activeStatus === 'all' || cardStatus === activeStatus;
				const matchSearch = !query || searchBlob.indexOf(query) !== -1;

				if (matchCat && matchStatus && matchSearch) {
					card.hidden = false;
					visibleCount++;
				} else {
					card.hidden = true;
				}
			});

			if (emptyState) {
				emptyState.hidden = visibleCount > 0;
			}
		}

		if (searchInput) {
			searchInput.addEventListener('input', applyFilters);
		}

		categoryPills.forEach(function (pill) {
			pill.addEventListener('click', function () {
				categoryPills.forEach(function (p) { p.classList.remove('is-active'); });
				pill.classList.add('is-active');
				activeCategory = pill.getAttribute('data-filter-category') || 'all';
				applyFilters();
			});
		});

		statusPills.forEach(function (pill) {
			pill.addEventListener('click', function () {
				statusPills.forEach(function (p) { p.classList.remove('is-active'); });
				pill.classList.add('is-active');
				activeStatus = pill.getAttribute('data-filter-status') || 'all';
				applyFilters();
			});
		});

		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				if (searchInput) searchInput.value = '';
				activeCategory = 'all';
				activeStatus = 'all';
				categoryPills.forEach(function (p) {
					p.classList.toggle('is-active', p.getAttribute('data-filter-category') === 'all');
				});
				statusPills.forEach(function (p) {
					p.classList.toggle('is-active', p.getAttribute('data-filter-status') === 'all');
				});
				applyFilters();
			});
		}

		/**
		 * Technical Details Drawer Toggle.
		 */
		document.querySelectorAll('.wst-details-toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				const card = btn.closest('.wst-feature-card');
				if (!card) return;
				const drawer = card.querySelector('.wst-feature-card__drawer');
				if (!drawer) return;

				const expanded = btn.getAttribute('aria-expanded') === 'true';
				btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
				drawer.hidden = expanded;

				const span = btn.querySelector('span');
				if (span) {
					span.textContent = expanded
						? 'Afficher le contexte technique & les hooks'
						: 'Masquer le contexte technique';
				}
			});
		});

		/**
		 * Show temporary toast feedback.
		 */
		function showToast(message) {
			if (!toast || !toastMsg) return;
			toastMsg.textContent = message;
			toast.hidden = false;

			if (toastTimer) clearTimeout(toastTimer);
			toastTimer = setTimeout(function () {
				toast.hidden = true;
			}, 2800);
		}

		/**
		 * Instant AJAX Toggle on Switch Change.
		 */
		document.querySelectorAll('.wst-switch__input').forEach(function (checkbox) {
			checkbox.addEventListener('change', function () {
				const featureId = checkbox.getAttribute('data-feature-id');
				const isEnabled = checkbox.checked;
				const card = checkbox.closest('.wst-feature-card');
				const stateLabel = document.querySelector('[data-state-label="' + featureId + '"]');

				if (card) {
					card.classList.toggle('is-enabled', isEnabled);
					card.classList.toggle('is-disabled', !isEnabled);
					card.setAttribute('data-status', isEnabled ? 'enabled' : 'disabled');
				}
				if (stateLabel) {
					stateLabel.textContent = isEnabled ? 'Active' : 'Désactivée';
				}

				applyFilters();

				if (typeof wooSoyooToolsAdmin === 'undefined' || !wooSoyooToolsAdmin.ajaxUrl) {
					return;
				}

				const formData = new FormData();
				formData.append('action', 'woo_soyoo_tools_toggle_feature');
				formData.append('nonce', wooSoyooToolsAdmin.nonce);
				formData.append('feature_id', featureId);
				formData.append('enabled', isEnabled ? '1' : '0');

				fetch(wooSoyooToolsAdmin.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res && res.success && res.data) {
							if (statActive && typeof res.data.active_count !== 'undefined') {
								statActive.textContent = res.data.active_count;
							}
							if (statDisabled && typeof res.data.disabled_count !== 'undefined') {
								statDisabled.textContent = res.data.disabled_count;
							}
							showToast(res.data.message || 'Modifications enregistrées.');
						}
					})
					.catch(function () {
						showToast('Utilisez le bouton Enregistrer en bas de page.');
					});
			});
		});
	});
})();
