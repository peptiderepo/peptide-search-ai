/**
 * Peptide Search AI — Frontend JavaScript
 *
 * Supports multiple shortcode instances on the same page by using
 * class-based selectors scoped to each .psa-search-wrap container.
 *
 * Flow:
 * 1. User searches -> AJAX call to backend
 * 2. If found -> show results
 * 3. If not found -> backend automatically validates + queues generation
 *    -> user sees "pending" page
 * 4. If invalid peptide name -> show "not recognized" message
 */
(function ($) {
    'use strict';

    /**
     * Initialize a single search instance scoped to its .psa-search-wrap container.
     */
    function initSearchInstance($wrap) {
        var $form     = $wrap.find('.psa-search-form');
        var $input    = $wrap.find('.psa-search-input');
        var $results  = $wrap.find('.psa-search-results');
        var $checking = $wrap.find('.psa-checking');
        var $pending  = $wrap.find('.psa-pending');
        var $invalid  = $wrap.find('.psa-invalid');
        var $error    = $wrap.find('.psa-error');

        function debounce(fn, delay) {
            var timer;
            return function () {
                var args = arguments, ctx = this;
                clearTimeout(timer);
                timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
            };
        }

        function hideAll() {
            $results.hide();
            $results.attr('aria-busy', 'false');
            $checking.hide();
            $pending.hide();
            $invalid.hide();
            $error.hide();
        }

        function showError(msg) {
            hideAll();
            $error.html('<strong>Error:</strong> ' + esc(msg)).show();
            $error.attr('role', 'alert');
        }

        function esc(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(str));
            return d.innerHTML;
        }

        function sourceBadge(source) {
            var map = {
                'pubchem': { label: 'Verified', cls: 'psa-badge-verified' },
                'manual':  { label: 'Curated',  cls: 'psa-badge-manual' }
            };
            var b = map[source];
            if (!b) return '';
            return '<span class="psa-result-badge ' + b.cls + '">' + b.label + '</span>';
        }

        /**
         * Render search results.
         */
        function renderResults(data) {
            hideAll();

            var html = '<p class="psa-result-count">' + data.total + ' peptide' +
                       (data.total !== 1 ? 's' : '') + ' found</p>';

            data.results.forEach(function (item) {
                html += '<a href="' + esc(item.url) + '" class="psa-result-item">';
                html += '<h4 class="psa-result-title">' + esc(item.title);
                if (item.source) html += sourceBadge(item.source);
                html += '</h4>';

                var meta = [];
                if (item.molecular_weight)  meta.push('<span>MW: ' + esc(item.molecular_weight) + '</span>');
                if (item.molecular_formula) meta.push('<span>Formula: ' + esc(item.molecular_formula) + '</span>');
                if (item.sequence && item.sequence.length <= 40) meta.push('<span>Seq: <code>' + esc(item.sequence) + '</code></span>');
                if (meta.length) html += '<div class="psa-result-meta">' + meta.join('') + '</div>';

                if (item.excerpt) html += '<p class="psa-result-excerpt">' + esc(item.excerpt) + '</p>';
                html += '</a>';
            });

            $results.html(html).show();
        }

        /**
         * Show the pending/in-progress message.
         */
        function showPending(name) {
            hideAll();
            $pending.find('.psa-pending-inner p').html(
                '<strong>' + esc(name) + '</strong> is currently being added to our database. Please check back again later.'
            );
            $pending.show();
        }

        /**
         * Show the invalid peptide message.
         */
        function showInvalid(name, reason) {
            hideAll();
            $invalid.find('.psa-invalid-text').html(
                '<strong>' + esc(name) + '</strong> does not appear to be a recognized peptide. Please check the spelling or try a different search term.'
            );
            $invalid.show();
        }

        /**
         * Perform the search. The backend handles everything:
         * - If found: returns results
         * - If not found + valid peptide: validates, creates placeholder, queues generation
         * - If not found + invalid: returns invalid status
         */
        function doSearch(query) {
            if (query.length < 2) {
                hideAll();
                return;
            }

            hideAll();
            $checking.show();
            $results.attr('aria-busy', 'true');

            $.ajax({
                url: psaAjax.ajaxurl,
                method: 'GET',
                data: {
                    action: 'psa_search',
                    nonce:  psaAjax.nonce,
                    q:      query
                },
                timeout: 30000,
                success: function (response) {
                    $results.attr('aria-busy', 'false');
                    if (!response.success) {
                        showError(response.data || 'Search failed.');
                        return;
                    }
                    var d = response.data;
                    switch (d.status) {
                        case 'found':
                            renderResults(d.data);
                            break;
                        case 'pending':
                            showPending(d.peptide_name);
                            break;
                        case 'invalid':
                            showInvalid(query, d.message);
                            break;
                        case 'rate_limited':
                            showError(d.message || 'Too many requests. Please try again later.');
                            break;
                        default:
                            showError('Unexpected response.');
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    $results.attr('aria-busy', 'false');
                    var errorMsg = 'Could not connect to the server. Please try again.';
                    if (textStatus === 'timeout') {
                        errorMsg = 'Request timed out. The server took too long to respond. Please try again.';
                    } else if (textStatus === 'error') {
                        if (jqXHR.status === 0) {
                            errorMsg = 'Network error. Please check your connection and try again.';
                        } else if (jqXHR.status === 500) {
                            errorMsg = 'Server error. Please try again later.';
                        } else if (jqXHR.status === 503) {
                            errorMsg = 'Server is temporarily unavailable. Please try again later.';
                        }
                    }
                    showError(errorMsg);
                }
            });
        }

        // --- Event Handlers (scoped to this instance) ---

        $form.on('submit', function (e) {
            e.preventDefault();
            doSearch($input.val().trim());
        });

        var debouncedSearch = debounce(function () {
            var q = $input.val().trim();
            if (q.length >= 2) {
                doSearch(q);
            } else {
                hideAll();
            }
        }, 400);
 
        $input.on('input', debouncedSearch);
    }

    // --- Initialize all search instances on page ---

    $(document).ready(function () {
        $('.psa-search-wrap').each(function () {
            initSearchInstance($(this));
        });
    });

})(jQuery);
