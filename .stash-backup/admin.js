/**
 * Admin JavaScript for Meta Conversions API
 */
(function($) {
    'use strict';
    
    // Debounce function
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    
    // Initialize page selector
    function initPageSelector() {
        const $searchInput = $('#meta-capi-page-search');
        const $results = $('#meta-capi-page-results');
        const $selected = $('#meta-capi-page-selected');
        const $hiddenInput = $('#meta_capi_exclude_pages_input');
        let searchTimeout;
        
        function updateHiddenInput() {
            const ids = [];
            $selected.find('.meta-capi-tag').each(function() {
                ids.push($(this).data('id'));
            });
            $hiddenInput.val(ids.join(','));
        }
        
        function addPage(page) {
            // Check if already added
            if ($selected.find(`[data-id="${page.id}"]`).length > 0) {
                return;
            }
            
            const $tag = $('<span>', {
                class: 'meta-capi-tag',
                'data-id': page.id
            }).html(
                '<span class="meta-capi-tag-text">' + page.title + '</span>' +
                '<button type="button" class="meta-capi-tag-remove" aria-label="Remove">×</button>'
            );
            
            $tag.find('.meta-capi-tag-remove').on('click', function(e) {
                e.preventDefault();
                $tag.remove();
                updateHiddenInput();
            });
            
            $selected.append($tag);
            updateHiddenInput();
        }
        
        function performSearch(searchTerm) {
            if (!searchTerm || searchTerm.length < 2) {
                $results.hide().empty();
                return;
            }
            
            const excluded = $hiddenInput.val().split(',').filter(Boolean);
            
            $.ajax({
                url: metaCapiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'meta_capi_search_pages',
                    search: searchTerm,
                    excluded: excluded.join(','),
                    nonce: metaCapiAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.results.length > 0) {
                        $results.empty();
                        response.data.results.forEach(function(page) {
                            const $item = $('<div>', {
                                class: 'meta-capi-search-result-item'
                            }).html(
                                '<strong>' + page.title + '</strong> ' +
                                '<span class="description">(ID: ' + page.id + ')</span>'
                            ).on('click', function() {
                                addPage(page);
                                $searchInput.val('');
                                $results.hide().empty();
                            });
                            $results.append($item);
                        });
                        $results.show();
                    } else {
                        $results.html('<div class="meta-capi-search-no-results">' + metaCapiAdmin.strings.noResults + '</div>').show();
                    }
                },
                error: function() {
                    $results.html('<div class="meta-capi-search-error">Error searching pages.</div>').show();
                }
            });
        }
        
        // Debounced search
        const debouncedSearch = debounce(function() {
            const searchTerm = $searchInput.val().trim();
            performSearch(searchTerm);
        }, 300);
        
        $searchInput.on('input', function() {
            debouncedSearch();
        });
        
        // Hide results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.meta-capi-search-box').length) {
                $results.hide();
            }
        });
        
        // Handle Enter key
        $searchInput.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const $firstResult = $results.find('.meta-capi-search-result-item:first');
                if ($firstResult.length) {
                    $firstResult.click();
                }
            }
        });
    }
    
    // Initialize form selector
    function initFormSelector() {
        const $searchInput = $('#meta-capi-form-search');
        const $results = $('#meta-capi-form-results');
        const $selected = $('#meta-capi-form-selected');
        const $hiddenInput = $('#meta_capi_exclude_forms_input');
        const $loadBtn = $('#meta-capi-load-forms-btn');
        const $refreshBtn = $('#meta-capi-refresh-forms-btn');
        const $spinner = $('#meta-capi-forms-spinner');
        const $searchBox = $('.meta-capi-search-box');
        
        // Store forms cache in memory for search.
        let formsCache = [];
        
        // Load/refresh forms function.
        function loadForms() {
            $spinner.css('visibility', 'visible');
            ($loadBtn.length ? $loadBtn : $refreshBtn).prop('disabled', true);
            
            $.ajax({
                url: metaCapiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'meta_capi_load_forms',
                    nonce: metaCapiAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        formsCache = response.data.forms;
                        
                        // Get current selected form IDs.
                        const currentSelected = $hiddenInput.val().split(',').filter(Boolean);
                        
                        // Find missing forms (selected but no longer exist).
                        const existingFormIds = formsCache.map(function(form) { return form.id; });
                        const missingFormIds = currentSelected.filter(function(id) {
                            return existingFormIds.indexOf(id) === -1;
                        });
                        
                        // Remove missing forms from selection.
                        if (missingFormIds.length > 0) {
                            missingFormIds.forEach(function(formId) {
                                $selected.find('[data-id="' + formId + '"]').remove();
                            });
                            updateHiddenInput();
                        }
                        
                        // Show search box if hidden.
                        $searchBox.show();
                        $searchInput.prop('disabled', false);
                        
                        // Hide load button section, show refresh section.
                        $('.meta-capi-load-forms-section').hide();
                        $('.meta-capi-forms-loaded-info').show();
                        
                        // Update count and time, with cleanup message if forms were removed.
                        let timeText = response.data.count + ' forms loaded';
                        let cleanupMessage = '';
                        if (missingFormIds.length > 0) {
                            cleanupMessage = ' <span style="color: #d63638;">(' + missingFormIds.length + ' ' + 
                                (missingFormIds.length === 1 ? 'form was' : 'forms were') + 
                                ' removed - no longer exists)</span>';
                        }
                        
                        $('.meta-capi-forms-loaded-info p').html(
                            '<span class="dashicons dashicons-yes-alt" style="color: #00a32a; vertical-align: middle;"></span> ' +
                            timeText + cleanupMessage + ' ' +
                            '<span class="description" style="margin-left: 8px;">(loaded just now)</span> ' +
                            '<button type="button" id="meta-capi-refresh-forms-btn" class="button button-small" style="margin-left: 10px;">' +
                            '<span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 16px;"></span> ' +
                            metaCapiAdmin.strings.refresh + '</button> ' +
                            '<span class="spinner" id="meta-capi-forms-spinner" style="float: none; margin-left: 10px; visibility: hidden;"></span>'
                        );
                        
                        // Re-bind refresh button.
                        $('#meta-capi-refresh-forms-btn').on('click', loadForms);
                    } else {
                        alert('Error: ' + (response.data.message || 'Failed to load forms.'));
                    }
                },
                error: function() {
                    alert('Error loading forms. Please try again.');
                },
                complete: function() {
                    $spinner.css('visibility', 'hidden');
                    ($loadBtn.length ? $loadBtn : $refreshBtn).prop('disabled', false);
                }
            });
        }
        
        // Bind load/refresh buttons.
        if ($loadBtn.length) {
            $loadBtn.on('click', loadForms);
        }
        if ($refreshBtn.length) {
            $refreshBtn.on('click', loadForms);
        }
        
        function updateHiddenInput() {
            const ids = [];
            $selected.find('.meta-capi-tag').each(function() {
                ids.push($(this).data('id'));
            });
            $hiddenInput.val(ids.join(','));
        }
        
        function addForm(form) {
            // Check if already added
            if ($selected.find(`[data-id="${form.id}"]`).length > 0) {
                return;
            }
            
            const locationText = form.location ? ' - ' + form.location : '';
            const $tag = $('<span>', {
                class: 'meta-capi-tag',
                'data-id': form.id
            }).html(
                '<span class="meta-capi-tag-text">' + form.name + '</span>' +
                '<button type="button" class="meta-capi-tag-remove" aria-label="Remove">×</button>'
            );
            
            $tag.find('.meta-capi-tag-remove').on('click', function(e) {
                e.preventDefault();
                $tag.remove();
                updateHiddenInput();
            });
            
            $selected.append($tag);
            updateHiddenInput();
        }
        
        function performSearch(searchTerm) {
            if (!searchTerm || searchTerm.length < 2) {
                $results.hide().empty();
                return;
            }
            
            // If forms not loaded in memory, use AJAX.
            if (formsCache.length === 0) {
                const excluded = $hiddenInput.val().split(',').filter(Boolean);
                
                $.ajax({
                    url: metaCapiAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'meta_capi_search_forms',
                        search: searchTerm,
                        excluded: excluded.join(','),
                        nonce: metaCapiAdmin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.results && response.data.results.length > 0) {
                                displayResults(response.data.results);
                            } else {
                                $results.html('<div class="meta-capi-search-no-results">' + (response.data.message || metaCapiAdmin.strings.noResults) + '</div>').show();
                            }
                        } else {
                            $results.html('<div class="meta-capi-search-error">' + (response.data.message || 'Error searching forms.') + '</div>').show();
                        }
                    },
                    error: function() {
                        $results.html('<div class="meta-capi-search-error">Error searching forms.</div>').show();
                    }
                });
                return;
            }
            
            // Search in-memory cache (faster).
            const excluded = $hiddenInput.val().split(',').filter(Boolean);
            const searchLower = searchTerm.toLowerCase();
            const filtered = formsCache.filter(function(form) {
                if (excluded.indexOf(form.id) !== -1) {
                    return false;
                }
                const nameMatch = form.name.toLowerCase().indexOf(searchLower) !== -1;
                const locationMatch = (form.location || '').toLowerCase().indexOf(searchLower) !== -1;
                return nameMatch || locationMatch;
            }).slice(0, 20);
            
            displayResults(filtered);
        }
        
        function displayResults(results) {
            if (results.length === 0) {
                $results.html('<div class="meta-capi-search-no-results">' + metaCapiAdmin.strings.noResults + '</div>').show();
                return;
            }
            
            $results.empty();
            results.forEach(function(form) {
                const locationText = form.location ? ' - ' + form.location : '';
                const $item = $('<div>', {
                    class: 'meta-capi-search-result-item'
                }).html(
                    '<strong>' + form.name + '</strong> ' +
                    '<span class="description">(ID: ' + form.id + locationText + ')</span>'
                ).on('click', function() {
                    addForm(form);
                    $searchInput.val('');
                    $results.hide().empty();
                });
                $results.append($item);
            });
            $results.show();
        }
        
        // Load cached forms into memory on page load if available.
        if ($searchBox.is(':visible') && $searchInput.length) {
            const excluded = $hiddenInput.val().split(',').filter(Boolean);
            $.ajax({
                url: metaCapiAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'meta_capi_search_forms',
                    search: '',
                    excluded: excluded.join(','),
                    nonce: metaCapiAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.results) {
                        // Populate cache from all results (empty search returns all forms).
                        formsCache = response.data.results;
                    }
                }
            });
        }
        
        // Debounced search
        const debouncedSearch = debounce(function() {
            const searchTerm = $searchInput.val().trim();
            performSearch(searchTerm);
        }, 300);
        
        $searchInput.on('input', function() {
            debouncedSearch();
        });
        
        // Hide results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.meta-capi-search-box').length) {
                $results.hide();
            }
        });
        
        // Handle Enter key
        $searchInput.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const $firstResult = $results.find('.meta-capi-search-result-item:first');
                if ($firstResult.length) {
                    $firstResult.click();
                }
            }
        });
    }
    
    // Initialize on document ready
    $(document).ready(function() {
        // Verify metaCapiAdmin is available
        if (typeof metaCapiAdmin === 'undefined') {
            console.error('Meta CAPI Admin: metaCapiAdmin object is not defined. Script may not be properly localized.');
            return;
        }
        
        // Verify jQuery is available
        if (typeof jQuery === 'undefined' || typeof $ === 'undefined') {
            console.error('Meta CAPI Admin: jQuery is not available.');
            return;
        }
        
        try {
            initPageSelector();
            initFormSelector();
        } catch (error) {
            console.error('Meta CAPI Admin: Error initializing selectors:', error);
        }
        
        // Handle tag removal for pre-loaded items
        $(document).on('click', '.meta-capi-tag-remove', function(e) {
            e.preventDefault();
            const $tag = $(this).closest('.meta-capi-tag');
            const $container = $tag.closest('.meta-capi-selected-items');
            const $hiddenInput = $container.siblings('.meta-capi-search-box').siblings('input[type="hidden"]');
            
            $tag.remove();
            
            // Update hidden input
            const ids = [];
            $container.find('.meta-capi-tag').each(function() {
                ids.push($(this).data('id'));
            });
            $hiddenInput.val(ids.join(','));
        });
    });
    
})(jQuery);
