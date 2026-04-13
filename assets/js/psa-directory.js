/**
 * Peptide Directory — Frontend JavaScript
 *
 * Renders the browsable directory grid with category filters, search,
 * pagination, and detail modal. Uses vanilla JS (no build step required).
 *
 * Data source: REST API at /wp-json/peptide-search-ai/v1/compounds
 * Config: psaDirectory global (localized from class-psa-directory.php)
 *
 * @see includes/class-psa-directory.php — shortcode + REST endpoint
 * @see assets/css/psa-directory.css     — all .psa-dir-* styles
 */
(function () {
	'use strict';

	var config = window.psaDirectory;
	if (!config) return;

	var root       = document.getElementById('psa-directory-root');
	if (!root) return;

	var state = {
		compounds: [],
		page: 1,
		totalPages: 0,
		total: 0,
		category: '',
		search: '',
		loading: false,
		activeTab: 'single',
		modalData: null
	};

	var i18n = config.i18n;

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function esc(str) {
		if (!str) return '';
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(str));
		return d.innerHTML;
	}

	function debounce(fn, delay) {
		var timer;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
		};
	}

	// -------------------------------------------------------------------------
	// API
	// -------------------------------------------------------------------------

	function fetchCompounds(append) {
		if (state.loading) return;
		state.loading = true;
		updateLoadMoreBtn();

		var url = config.restUrl +
			'?page=' + state.page +
			'&per_page=' + config.perPage;

		if (state.search) url += '&search=' + encodeURIComponent(state.search);
		if (state.category) url += '&category=' + encodeURIComponent(state.category);

		var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);
		xhr.setRequestHeader('X-WP-Nonce', config.nonce);
		xhr.onload = function () {
			state.loading = false;
			if (xhr.status === 200) {
				var data = JSON.parse(xhr.responseText);
				if (append) {
					state.compounds = state.compounds.concat(data.compounds);
				} else {
					state.compounds = data.compounds;
				}
				state.total = data.total;
				state.totalPages = data.total_pages;
				renderGrid();
			} else {
				renderError();
			}
		};
		xhr.onerror = function () {
			state.loading = false;
			renderError();
		};
		xhr.send();
	}

	function fetchCompoundDetail(id, callback) {
		var url = config.restUrl + '?search=&per_page=1&page=1&fields=full';
		/* Find by iterating existing data first to avoid extra API call. */
		var existing = null;
		for (var i = 0; i < state.compounds.length; i++) {
			if (state.compounds[i].id === id) {
				existing = state.compounds[i];
				break;
			}
		}
		if (existing && existing.sequence !== undefined) {
			callback(existing);
			return;
		}
		/* Fallback: fetch from API (shouldn't normally be needed for full fields). */
		callback(existing);
	}

	// -------------------------------------------------------------------------
	// Render — Shell
	// -------------------------------------------------------------------------

	function renderShell() {
		root.innerHTML =
			'<div class="psa-dir-header">' +
				'<h2 class="psa-dir-title">' + esc(i18n.title) + '</h2>' +
				'<p class="psa-dir-subtitle">' + esc(i18n.subtitle) + '</p>' +
			'</div>' +
			'<div class="psa-dir-search-wrap">' +
				'<input type="text" class="psa-dir-search" placeholder="' + esc(i18n.searchPlaceholder) + '" aria-label="' + esc(i18n.searchPlaceholder) + '" />' +
			'</div>' +
			'<div class="psa-dir-tabs" role="tablist">' +
				'<button class="psa-dir-tab psa-dir-tab--active" data-tab="single" role="tab" aria-selected="true">' + esc(i18n.singleReagents) + '</button>' +
				'<button class="psa-dir-tab" data-tab="protocols" role="tab" aria-selected="false">' + esc(i18n.protocolModels) + '</button>' +
			'</div>' +
			'<div class="psa-dir-filters" role="group" aria-label="Category filter"></div>' +
			'<div class="psa-dir-content">' +
				'<div class="psa-dir-grid" role="list"></div>' +
				'<div class="psa-dir-coming-soon" style="display:none;">' +
					'<p>' + esc(i18n.comingSoon) + '</p>' +
				'</div>' +
				'<div class="psa-dir-empty" style="display:none;">' +
					'<p>' + esc(i18n.noResults) + '</p>' +
				'</div>' +
				'<div class="psa-dir-loader" style="display:none;">' +
					'<div class="psa-spinner"></div>' +
					'<p>' + esc(i18n.loading) + '</p>' +
				'</div>' +
			'</div>' +
			'<div class="psa-dir-load-more-wrap" style="display:none;">' +
				'<button class="psa-dir-load-more">' + esc(i18n.loadMore) + '</button>' +
			'</div>' +
			'<div class="psa-dir-modal" role="dialog" aria-modal="true" aria-label="Peptide details" style="display:none;">' +
				'<div class="psa-dir-modal__backdrop"></div>' +
				'<div class="psa-dir-modal__content" tabindex="-1"></div>' +
			'</div>';

		renderFilters();
		bindEvents();
	}

	// -------------------------------------------------------------------------
	// Render — Filters
	// -------------------------------------------------------------------------

	function renderFilters() {
		var wrap = root.querySelector('.psa-dir-filters');
		var html = '<button class="psa-dir-filter psa-dir-filter--active" data-category="">' + esc(i18n.showAll) + '</button>';
		config.categories.forEach(function (cat) {
			html += '<button class="psa-dir-filter" data-category="' + esc(cat.slug) + '">' + esc(cat.name) + '</button>';
		});
		wrap.innerHTML = html;
	}

	// -------------------------------------------------------------------------
	// Render — Grid
	// -------------------------------------------------------------------------

	function renderGrid() {
		var grid    = root.querySelector('.psa-dir-grid');
		var empty   = root.querySelector('.psa-dir-empty');
		var loader  = root.querySelector('.psa-dir-loader');
		var coming  = root.querySelector('.psa-dir-coming-soon');

		loader.style.display = 'none';
		coming.style.display = 'none';

		if (state.activeTab === 'protocols') {
			grid.style.display  = 'none';
			empty.style.display = 'none';
			coming.style.display = 'block';
			updateLoadMoreBtn();
			return;
		}

		grid.style.display = '';

		if (state.compounds.length === 0 && !state.loading) {
			empty.style.display = 'block';
			grid.innerHTML = '';
			updateLoadMoreBtn();
			return;
		}

		empty.style.display = 'none';

		var html = '';
		state.compounds.forEach(function (c) {
			var catBadge = '';
			if (c.categories && c.categories.length > 0) {
				catBadge = '<span class="psa-dir-card__badge psa-dir-badge--' + esc(c.categories[0].slug) + '">' + esc(c.categories[0].name) + '</span>';
			}

			var metaHtml = '';
			if (c.half_life) {
				metaHtml += '<div class="psa-dir-card__meta-item"><span class="psa-dir-card__meta-label">' + esc(i18n.halfLife) + '</span><span class="psa-dir-card__meta-value">' + esc(c.half_life) + '</span></div>';
			}
			if (c.stability) {
				metaHtml += '<div class="psa-dir-card__meta-item"><span class="psa-dir-card__meta-label">' + esc(i18n.stability) + '</span><span class="psa-dir-card__meta-value">' + esc(c.stability) + '</span></div>';
			}

			var extrasHtml = c.extras ? c.extras : '';

			html += '<div class="psa-dir-card" role="listitem" data-id="' + c.id + '">' +
				'<div class="psa-dir-card__header">' +
					'<h3 class="psa-dir-card__title">' + esc(c.name) + '</h3>' +
					catBadge +
				'</div>' +
				(metaHtml ? '<div class="psa-dir-card__meta">' + metaHtml + '</div>' : '') +
				extrasHtml +
				'<button class="psa-dir-card__btn" data-id="' + c.id + '">' + esc(i18n.viewDetails) + '</button>' +
			'</div>';
		});

		grid.innerHTML = html;
		updateLoadMoreBtn();
	}

	function updateLoadMoreBtn() {
		var wrap = root.querySelector('.psa-dir-load-more-wrap');
		var btn  = root.querySelector('.psa-dir-load-more');

		if (state.activeTab === 'protocols' || state.page >= state.totalPages || state.compounds.length === 0) {
			wrap.style.display = 'none';
		} else {
			wrap.style.display = 'block';
			btn.textContent = state.loading ? i18n.loading : i18n.loadMore;
			btn.disabled = state.loading;
		}
	}

	function renderError() {
		state.loading = false;
		var grid = root.querySelector('.psa-dir-grid');
		grid.innerHTML = '<p class="psa-dir-error">Failed to load directory. Please try again.</p>';
		root.querySelector('.psa-dir-loader').style.display = 'none';
		updateLoadMoreBtn();
	}

	// -------------------------------------------------------------------------
	// Render — Modal
	// -------------------------------------------------------------------------

	function openModal(compound) {
		state.modalData = compound;
		var modal   = root.querySelector('.psa-dir-modal');
		var content = root.querySelector('.psa-dir-modal__content');

		var catBadges = '';
		if (compound.categories) {
			compound.categories.forEach(function (cat) {
				catBadges += '<span class="psa-dir-card__badge psa-dir-badge--' + esc(cat.slug) + '">' + esc(cat.name) + '</span> ';
			});
		}

		var dataGrid = '';
		var fields = [
			{ label: i18n.solubility, value: compound.solubility },
			{ label: i18n.vialSize, value: compound.vial_size_mg ? compound.vial_size_mg + ' mg' : '' },
			{ label: i18n.storageLyo, value: compound.storage_lyophilized },
			{ label: i18n.storageRecon, value: compound.storage_reconstituted },
			{ label: i18n.halfLife, value: compound.half_life },
			{ label: i18n.stability, value: compound.stability },
			{ label: i18n.molecularWeight, value: compound.molecular_weight },
			{ label: i18n.formula, value: compound.molecular_formula }
		];
		fields.forEach(function (f) {
			if (f.value) {
				dataGrid += '<div class="psa-dir-modal__data-item">' +
					'<span class="psa-dir-modal__data-label">' + esc(f.label) + '</span>' +
					'<span class="psa-dir-modal__data-value">' + esc(f.value) + '</span>' +
				'</div>';
			}
		});

		var researchHtml = '';
		if (compound.typical_dose_mcg || compound.cycle_parameters) {
			researchHtml = '<div class="psa-dir-modal__section">' +
				'<h4>' + esc(i18n.researchParams) + '</h4>';
			if (compound.typical_dose_mcg) {
				researchHtml += '<p><strong>' + esc(i18n.typicalDose) + ':</strong> ' + esc(compound.typical_dose_mcg) + '</p>';
			}
			if (compound.cycle_parameters) {
				researchHtml += '<p><strong>' + esc(i18n.cycleParams) + ':</strong> ' + esc(compound.cycle_parameters) + '</p>';
			}
			researchHtml += '</div>';
		}

		var pubchemHtml = '';
		if (compound.pubchem_cid) {
			pubchemHtml = '<a class="psa-dir-modal__pubchem" href="https://pubchem.ncbi.nlm.nih.gov/compound/' + parseInt(compound.pubchem_cid, 10) + '" target="_blank" rel="noopener">' + esc(i18n.pubchemLink) + '</a>';
		}

		var sequenceHtml = '';
		if (compound.sequence) {
			sequenceHtml = '<div class="psa-dir-modal__section">' +
				'<h4>' + esc(i18n.sequence) + '</h4>' +
				'<div class="psa-dir-modal__sequence-wrap">' +
					'<code class="psa-dir-modal__sequence">' + esc(compound.sequence) + '</code>' +
					'<button class="psa-dir-modal__copy" data-seq="' + esc(compound.sequence) + '">' + esc(i18n.copySequence) + '</button>' +
				'</div>' +
			'</div>';
		}

		var mechanismHtml = '';
		if (compound.mechanism) {
			mechanismHtml = '<div class="psa-dir-modal__section">' +
				'<h4>Mechanism of Action</h4>' +
				'<p>' + esc(compound.mechanism).replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>') + '</p>' + // Safe: esc() HTML-encodes all user content before structural tags are inserted.

			'</div>';
		}

		content.innerHTML =
			'<button class="psa-dir-modal__close" aria-label="' + esc(i18n.close) + '">&times;</button>' +
			'<h2 class="psa-dir-modal__title">' + esc(compound.name) + '</h2>' +
			'<div class="psa-dir-modal__badges">' + catBadges + '</div>' +
			(compound.description ? '<p class="psa-dir-modal__desc">' + esc(compound.description) + '</p>' : '') +
			sequenceHtml +
			(dataGrid ? '<div class="psa-dir-modal__data-grid">' + dataGrid + '</div>' : '') +
			researchHtml +
			mechanismHtml +
			pubchemHtml +
			'<a class="psa-dir-modal__full-link" href="' + esc(compound.url) + '">' + esc(i18n.viewFullPage) + '</a>';

		modal.style.display = 'flex';
		content.focus();

		// Update URL hash for direct linking.
		if (compound.slug) {
			history.replaceState(null, '', '#' + compound.slug);
		}

		// Fire integration hook for Peptide Community.
		document.dispatchEvent(new CustomEvent('psa_after_peptide_detail', {
			detail: { postId: compound.id }
		}));
	}

	function closeModal() {
		var modal = root.querySelector('.psa-dir-modal');
		modal.style.display = 'none';
		state.modalData = null;
		history.replaceState(null, '', window.location.pathname + window.location.search);
	}

	// -------------------------------------------------------------------------
	// Events
	// -------------------------------------------------------------------------

	function bindEvents() {
		// Search input.
		var searchInput = root.querySelector('.psa-dir-search');
		var debouncedSearch = debounce(function () {
			state.search = searchInput.value.trim();
			state.page = 1;
			showLoader();
			fetchCompounds(false);
		}, 400);
		searchInput.addEventListener('input', debouncedSearch);

		// Category filter buttons.
		root.querySelector('.psa-dir-filters').addEventListener('click', function (e) {
			var btn = e.target.closest('.psa-dir-filter');
			if (!btn) return;

			root.querySelectorAll('.psa-dir-filter').forEach(function (b) {
				b.classList.remove('psa-dir-filter--active');
			});
			btn.classList.add('psa-dir-filter--active');

			state.category = btn.getAttribute('data-category');
			state.page = 1;
			showLoader();
			fetchCompounds(false);
		});

		// Tab buttons.
		root.querySelector('.psa-dir-tabs').addEventListener('click', function (e) {
			var btn = e.target.closest('.psa-dir-tab');
			if (!btn) return;

			root.querySelectorAll('.psa-dir-tab').forEach(function (b) {
				b.classList.remove('psa-dir-tab--active');
				b.setAttribute('aria-selected', 'false');
			});
			btn.classList.add('psa-dir-tab--active');
			btn.setAttribute('aria-selected', 'true');

			state.activeTab = btn.getAttribute('data-tab');
			renderGrid();
		});

		// Load More button.
		root.querySelector('.psa-dir-load-more').addEventListener('click', function () {
			state.page++;
			fetchCompounds(true);
		});

		// Card "View Research Details" button — delegate on grid.
		root.querySelector('.psa-dir-grid').addEventListener('click', function (e) {
			var btn = e.target.closest('.psa-dir-card__btn');
			if (!btn) return;
			var id = parseInt(btn.getAttribute('data-id'), 10);
			fetchCompoundDetail(id, function (compound) {
				if (compound) openModal(compound);
			});
		});

		// Modal close: X button, backdrop click, Escape key.
		root.querySelector('.psa-dir-modal').addEventListener('click', function (e) {
			if (e.target.classList.contains('psa-dir-modal__backdrop') || e.target.classList.contains('psa-dir-modal__close')) {
				closeModal();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && state.modalData) {
				closeModal();
			}
		});

		// Copy sequence button.
		root.addEventListener('click', function (e) {
			var copyBtn = e.target.closest('.psa-dir-modal__copy');
			if (!copyBtn) return;
			var seq = copyBtn.getAttribute('data-seq');
			navigator.clipboard.writeText(seq).then(function () {
				copyBtn.textContent = i18n.copied;
				setTimeout(function () { copyBtn.textContent = i18n.copySequence; }, 2000);
			});
		});

		// Check URL hash on load for direct modal linking.
		checkUrlHash();
	}

	function showLoader() {
		var grid   = root.querySelector('.psa-dir-grid');
		var loader = root.querySelector('.psa-dir-loader');
		var empty  = root.querySelector('.psa-dir-empty');
		grid.innerHTML = '';
		empty.style.display = 'none';
		loader.style.display = 'block';
	}

	/**
	 * If URL has a hash like #bpc-157, find the matching compound and open modal.
	 */
	function checkUrlHash() {
		var hash = window.location.hash.replace('#', '');
		if (!hash) return;
		/* Wait for initial load, then look for matching slug. */
		var checkInterval = setInterval(function () {
			if (state.loading) return;
			clearInterval(checkInterval);
			for (var i = 0; i < state.compounds.length; i++) {
				if (state.compounds[i].slug === hash) {
					openModal(state.compounds[i]);
					return;
				}
			}
		}, 200);
		/* Safety: stop checking after 5 seconds. */
		setTimeout(function () { clearInterval(checkInterval); }, 5000);
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		renderShell();
		showLoader();
		fetchCompounds(false);
	}

})();
