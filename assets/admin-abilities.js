/**
 * StifLi Flex MCP - Abilities Tab JavaScript
 * WordPress 6.9+ Abilities API Integration
 */

(function($) {
    'use strict';

    var SflmcpAbilities = {
        state: {
            discoveredAbilities: [],
            discoveredCategory: '',
            discoveredSelection: {},
            importedSortKey: 'category',
            importedSortDirection: 'asc'
        },

        init: function() {
            this.bindEvents();
            this.initializeImportedList();
        },

        bindEvents: function() {
            $('#sflmcp-discover-abilities').on('click', this.discoverAbilities.bind(this));

            $(document).on('click', '.sflmcp-import-ability', this.importAbility.bind(this));
            $(document).on('click', '.sflmcp-toggle-ability', this.toggleAbility.bind(this));
            $(document).on('click', '.sflmcp-delete-ability', this.deleteAbility.bind(this));

            $(document).on('click', '.sflmcp-sort-button', this.handleImportedSort.bind(this));
            $(document).on('change', '.sflmcp-select-all-imported', this.toggleAllImportedSelection.bind(this));
            $(document).on('change', '.sflmcp-imported-select-row', this.updateImportedSelectionState.bind(this));
            $(document).on('click', '#sflmcp-apply-imported-bulk-action', this.applyImportedBulkAction.bind(this));

            $(document).on('change', '#sflmcp-discovered-category-filter', this.changeDiscoveredCategory.bind(this));
            $(document).on('change', '.sflmcp-discovered-select-row', this.updateDiscoveredSelectionState.bind(this));
            $(document).on('click', '#sflmcp-select-visible-discovered', this.selectVisibleDiscovered.bind(this));
            $(document).on('click', '#sflmcp-clear-discovered-selection', this.clearDiscoveredSelection.bind(this));
            $(document).on('click', '#sflmcp-import-selected-discovered', this.importSelectedDiscovered.bind(this));
            $(document).on('click', '#sflmcp-import-visible-discovered', this.importVisibleDiscovered.bind(this));
        },

        discoverAbilities: function(e) {
            e.preventDefault();

            var $button = $('#sflmcp-discover-abilities');
            var $container = $('#sflmcp-discovered-abilities');

            $button.prop('disabled', true).text(sflmcpAbilities.i18n.discovering);
            $container.html('<div class="sflmcp-loading"><span class="spinner is-active"></span> ' + sflmcpAbilities.i18n.discovering + '</div>');

            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_discover_abilities',
                    nonce: sflmcpAbilities.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false).text(sflmcpAbilities.i18n.discoverButton);

                    if (!response.success) {
                        $container.html('<div class="sflmcp-notice sflmcp-notice-error">' + SflmcpAbilities.escapeHtml(response.data.message || sflmcpAbilities.i18n.error) + '</div>');
                        return;
                    }

                    SflmcpAbilities.state.discoveredAbilities = Array.isArray(response.data.abilities) ? response.data.abilities : [];
                    SflmcpAbilities.state.discoveredSelection = {};
                    SflmcpAbilities.renderDiscoveredAbilities($container);
                },
                error: function() {
                    $button.prop('disabled', false).text(sflmcpAbilities.i18n.discoverButton);
                    $container.html('<div class="sflmcp-notice sflmcp-notice-error">' + sflmcpAbilities.i18n.error + '</div>');
                }
            });
        },

        renderDiscoveredAbilities: function($container) {
            var abilities = this.getSortedDiscoveredAbilities(this.state.discoveredAbilities);
            var categories = this.getDiscoveredCategories(abilities);
            var visibleAbilities;
            var selectedVisibleCount;
            var importableVisibleCount;
            var html = '';

            if (!abilities.length) {
                $container.html('<div class="sflmcp-empty-state">' + sflmcpAbilities.i18n.noAbilities + '</div>');
                return;
            }

            if (this.state.discoveredCategory && categories.indexOf(this.state.discoveredCategory) === -1) {
                this.state.discoveredCategory = '';
            }

            visibleAbilities = this.getVisibleDiscoveredAbilities();
            selectedVisibleCount = this.getSelectedDiscoveredNames(true).length;
            importableVisibleCount = this.getVisibleImportableAbilityNames().length;

            html += '<div class="sflmcp-discovered-toolbar">';
            html += '<div class="sflmcp-discovered-toolbar-main">';
            html += '<label for="sflmcp-discovered-category-filter" class="screen-reader-text">' + this.escapeHtml(sflmcpAbilities.i18n.allCategories) + '</label>';
            html += '<select id="sflmcp-discovered-category-filter">';
            html += '<option value="">' + this.escapeHtml(sflmcpAbilities.i18n.allCategories) + '</option>';

            categories.forEach(function(category) {
                var selected = category === SflmcpAbilities.state.discoveredCategory ? ' selected' : '';
                html += '<option value="' + SflmcpAbilities.escapeHtml(category) + '"' + selected + '>' + SflmcpAbilities.escapeHtml(category) + '</option>';
            });

            html += '</select>';
            html += '<button type="button" class="button" id="sflmcp-select-visible-discovered">' + this.escapeHtml(sflmcpAbilities.i18n.selectAllVisible) + '</button>';
            html += '<button type="button" class="button" id="sflmcp-clear-discovered-selection">' + this.escapeHtml(sflmcpAbilities.i18n.clearSelection) + '</button>';
            html += '</div>';
            html += '<div class="sflmcp-discovered-toolbar-actions">';
            html += '<button type="button" class="button button-primary" id="sflmcp-import-selected-discovered"' + (selectedVisibleCount ? '' : ' disabled') + '>' + this.escapeHtml(sflmcpAbilities.i18n.importSelected) + '</button>';
            html += '<button type="button" class="button" id="sflmcp-import-visible-discovered"' + (importableVisibleCount ? '' : ' disabled') + '>' + this.escapeHtml(sflmcpAbilities.i18n.importVisible) + '</button>';
            html += '<span class="sflmcp-discovered-summary">' + this.escapeHtml(String(selectedVisibleCount)) + ' ' + this.escapeHtml(sflmcpAbilities.i18n.selectedSuffix) + ' · ' + this.escapeHtml(String(visibleAbilities.length)) + ' ' + this.escapeHtml(sflmcpAbilities.i18n.visibleSuffix) + '</span>';
            html += '</div>';
            html += '</div>';

            if (!visibleAbilities.length) {
                html += '<div class="sflmcp-empty-state">' + this.escapeHtml(sflmcpAbilities.i18n.noAbilities) + '</div>';
                $container.html(html);
                return;
            }

            html += '<div class="sflmcp-discovered-list">';

            visibleAbilities.forEach(function(ability) {
                var importedClass = ability.imported ? ' imported' : '';
                var selected = SflmcpAbilities.state.discoveredSelection[ability.name] ? ' checked' : '';
                var checkboxDisabled = ability.imported ? ' disabled' : '';
                var buttonText = ability.imported ? sflmcpAbilities.i18n.alreadyImported : sflmcpAbilities.i18n.import;
                var buttonDisabled = ability.imported ? ' disabled' : '';
                var buttonClass = ability.imported ? 'button' : 'button button-primary sflmcp-import-ability';

                html += '<div class="sflmcp-discovered-item' + importedClass + '" data-ability-name="' + SflmcpAbilities.escapeHtml(ability.name) + '">';
                html += '<div class="sflmcp-discovered-item-select"><input type="checkbox" class="sflmcp-discovered-select-row" data-ability-name="' + SflmcpAbilities.escapeHtml(ability.name) + '" aria-label="' + SflmcpAbilities.escapeHtml(ability.label) + '"' + selected + checkboxDisabled + '></div>';
                html += '<div class="sflmcp-discovered-item-info">';
                html += '<div class="sflmcp-discovered-item-name">' + SflmcpAbilities.escapeHtml(ability.label) + '</div>';
                html += '<div class="sflmcp-discovered-item-ability-name">' + SflmcpAbilities.escapeHtml(ability.name) + '</div>';
                if (ability.description) {
                    html += '<div class="sflmcp-discovered-item-description">' + SflmcpAbilities.escapeHtml(ability.description) + '</div>';
                }
                if (ability.category) {
                    html += '<span class="sflmcp-discovered-item-category">' + SflmcpAbilities.escapeHtml(ability.category) + '</span>';
                }
                html += '</div>';
                html += '<div class="sflmcp-discovered-item-actions">';
                html += '<button type="button" class="' + buttonClass + '" data-ability="' + SflmcpAbilities.escapeHtml(ability.name) + '"' + buttonDisabled + '>' + SflmcpAbilities.escapeHtml(buttonText) + '</button>';
                html += '</div>';
                html += '</div>';
            });

            html += '</div>';
            $container.html(html);
        },

        importAbility: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var abilityName = $button.data('ability');

            if (!abilityName) {
                return;
            }

            $button.prop('disabled', true).text(sflmcpAbilities.i18n.importing);

            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_import_ability',
                    nonce: sflmcpAbilities.nonce,
                    ability_name: abilityName
                },
                success: function(response) {
                    if (!response.success) {
                        $button.prop('disabled', false).text(sflmcpAbilities.i18n.import);
                        SflmcpAbilities.showPanelNotice('.sflmcp-abilities-discover', 'error', response.data.message || sflmcpAbilities.i18n.error);
                        return;
                    }

                    SflmcpAbilities.setDiscoveredImportedState([abilityName], true);
                    SflmcpAbilities.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
                    SflmcpAbilities.refreshImportedList(function() {
                        SflmcpAbilities.showPanelNotice('.sflmcp-abilities-discover', 'success', response.data.message || sflmcpAbilities.i18n.imported);
                    });
                },
                error: function() {
                    $button.prop('disabled', false).text(sflmcpAbilities.i18n.import);
                    SflmcpAbilities.showPanelNotice('.sflmcp-abilities-discover', 'error', sflmcpAbilities.i18n.error);
                }
            });
        },

        toggleAbility: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var abilityId = $button.data('id');

            $button.prop('disabled', true);

            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_toggle_ability',
                    nonce: sflmcpAbilities.nonce,
                    ability_id: abilityId
                },
                success: function(response) {
                    if (!response.success) {
                        $button.prop('disabled', false);
                        SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'error', response.data.message || sflmcpAbilities.i18n.error);
                        return;
                    }

                    SflmcpAbilities.refreshImportedList(function() {
                        SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'success', response.data.message || sflmcpAbilities.i18n.imported);
                    });
                },
                error: function() {
                    $button.prop('disabled', false);
                    SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'error', sflmcpAbilities.i18n.error);
                }
            });
        },

        deleteAbility: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var abilityId = $button.data('id');
            var abilityName = $button.closest('tr').attr('data-ability-name') || '';

            if (!window.confirm(sflmcpAbilities.i18n.confirmDelete)) {
                return;
            }

            $button.prop('disabled', true);

            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_delete_ability',
                    nonce: sflmcpAbilities.nonce,
                    ability_id: abilityId
                },
                success: function(response) {
                    if (!response.success) {
                        $button.prop('disabled', false);
                        SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'error', response.data.message || sflmcpAbilities.i18n.error);
                        return;
                    }

                    if (abilityName) {
                        SflmcpAbilities.setDiscoveredImportedState([abilityName], false);
                        SflmcpAbilities.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
                    }

                    SflmcpAbilities.refreshImportedList(function() {
                        SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'success', response.data.message || sflmcpAbilities.i18n.deleted);
                    });
                },
                error: function() {
                    $button.prop('disabled', false);
                    SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'error', sflmcpAbilities.i18n.error);
                }
            });
        },

        handleImportedSort: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var sortKey = $button.data('sortKey');

            if (!sortKey) {
                return;
            }

            if (this.state.importedSortKey === sortKey) {
                this.state.importedSortDirection = this.state.importedSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.state.importedSortKey = sortKey;
                this.state.importedSortDirection = sortKey === 'enabled' ? 'desc' : 'asc';
            }

            this.applyImportedSort();
        },

        applyImportedSort: function() {
            var $table = $('#sflmcp-imported-abilities .sflmcp-imported-abilities-table');
            var $tbody = $table.find('tbody');
            var rows;
            var sortKey = this.state.importedSortKey;
            var direction = this.state.importedSortDirection === 'desc' ? -1 : 1;

            if (!$tbody.length) {
                return;
            }

            rows = $tbody.find('tr').get();

            rows.sort(function(rowA, rowB) {
                var valueA = SflmcpAbilities.getImportedSortValue($(rowA), sortKey);
                var valueB = SflmcpAbilities.getImportedSortValue($(rowB), sortKey);

                if (sortKey === 'enabled') {
                    valueA = parseInt(valueA, 10) || 0;
                    valueB = parseInt(valueB, 10) || 0;
                }

                if (valueA < valueB) {
                    return -1 * direction;
                }
                if (valueA > valueB) {
                    return 1 * direction;
                }

                return 0;
            });

            $.each(rows, function(index, row) {
                $tbody.append(row);
            });

            this.updateImportedSortIndicators();
            this.updateImportedSelectionState();
        },

        getImportedSortValue: function($row, sortKey) {
            return ($row.attr('data-sort-' + sortKey) || '').toString().toLowerCase();
        },

        updateImportedSortIndicators: function() {
            $('.sflmcp-sort-button').each(function() {
                var $button = $(this);
                var $icon = $button.find('.dashicons');
                var isActive = $button.data('sortKey') === SflmcpAbilities.state.importedSortKey;

                $button.removeClass('is-active is-desc');
                $icon.removeClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2').addClass('dashicons-sort');

                if (isActive) {
                    $button.addClass('is-active');
                    if (SflmcpAbilities.state.importedSortDirection === 'desc') {
                        $button.addClass('is-desc');
                        $icon.removeClass('dashicons-sort').addClass('dashicons-arrow-down-alt2');
                    } else {
                        $icon.removeClass('dashicons-sort').addClass('dashicons-arrow-up-alt2');
                    }
                }
            });
        },

        toggleAllImportedSelection: function(e) {
            var checked = $(e.currentTarget).is(':checked');
            $('#sflmcp-imported-abilities .sflmcp-imported-select-row').prop('checked', checked);
            this.updateImportedSelectionState();
        },

        updateImportedSelectionState: function() {
            var $rows = $('#sflmcp-imported-abilities .sflmcp-imported-select-row');
            var selectedCount = $rows.filter(':checked').length;
            var totalCount = $rows.length;
            var $selectAll = $('#sflmcp-imported-abilities .sflmcp-select-all-imported');

            $('#sflmcp-imported-selected-count').text(selectedCount);

            if ($selectAll.length) {
                $selectAll.prop('checked', totalCount > 0 && selectedCount === totalCount);
                $selectAll.prop('indeterminate', selectedCount > 0 && selectedCount < totalCount);
            }
        },

        applyImportedBulkAction: function(e) {
            e.preventDefault();

            var actionName = $('#sflmcp-imported-bulk-action').val();
            var ids = this.getSelectedImportedIds();
            var $button = $('#sflmcp-apply-imported-bulk-action');
            var originalText = $button.text();

            if (!actionName) {
                this.showPanelNotice('.sflmcp-abilities-imported', 'error', sflmcpAbilities.i18n.chooseBulkAction);
                return;
            }

            if (!ids.length) {
                this.showPanelNotice('.sflmcp-abilities-imported', 'error', sflmcpAbilities.i18n.noSelection);
                return;
            }

            if (actionName === 'delete' && !window.confirm(sflmcpAbilities.i18n.confirmBulkRemove)) {
                return;
            }

            $button.prop('disabled', true).text(sflmcpAbilities.i18n.applying);
            this.bulkManageAbilities({
                bulk_action: actionName,
                ability_ids: ids
            }, function(response) {
                var deletedNames = [];

                if (actionName === 'delete') {
                    deletedNames = SflmcpAbilities.getSelectedImportedNames();
                    if (deletedNames.length) {
                        SflmcpAbilities.setDiscoveredImportedState(deletedNames, false);
                        SflmcpAbilities.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
                    }
                }

                $('#sflmcp-imported-bulk-action').val('');
                SflmcpAbilities.refreshImportedList(function() {
                    SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'success', response.data.message || sflmcpAbilities.i18n.imported);
                });
            }, function(message) {
                SflmcpAbilities.showPanelNotice('.sflmcp-abilities-imported', 'error', message);
            }, function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

        changeDiscoveredCategory: function(e) {
            this.state.discoveredCategory = $(e.currentTarget).val() || '';
            this.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
        },

        updateDiscoveredSelectionState: function(e) {
            var $checkbox = $(e.currentTarget);
            var abilityName = $checkbox.data('abilityName');

            if (!abilityName) {
                return;
            }

            if ($checkbox.is(':checked')) {
                this.state.discoveredSelection[abilityName] = true;
            } else {
                delete this.state.discoveredSelection[abilityName];
            }

            this.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
        },

        selectVisibleDiscovered: function(e) {
            e.preventDefault();

            this.getVisibleDiscoveredAbilities().forEach(function(ability) {
                if (!ability.imported) {
                    SflmcpAbilities.state.discoveredSelection[ability.name] = true;
                }
            });

            this.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
        },

        clearDiscoveredSelection: function(e) {
            e.preventDefault();
            this.state.discoveredSelection = {};
            this.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
        },

        importSelectedDiscovered: function(e) {
            e.preventDefault();
            this.bulkImportDiscovered(this.getSelectedDiscoveredNames(true));
        },

        importVisibleDiscovered: function(e) {
            e.preventDefault();
            this.bulkImportDiscovered(this.getVisibleImportableAbilityNames());
        },

        bulkImportDiscovered: function(abilityNames) {
            var $selectedButton = $('#sflmcp-import-selected-discovered');
            var $visibleButton = $('#sflmcp-import-visible-discovered');

            if (!abilityNames.length) {
                this.showPanelNotice('.sflmcp-abilities-discover', 'error', sflmcpAbilities.i18n.noSelection);
                return;
            }

            if (!window.confirm(sflmcpAbilities.i18n.confirmBulkImport)) {
                return;
            }

            $selectedButton.prop('disabled', true).text(sflmcpAbilities.i18n.applying);
            $visibleButton.prop('disabled', true);

            this.bulkManageAbilities({
                bulk_action: 'import',
                ability_names: abilityNames
            }, function(response) {
                SflmcpAbilities.setDiscoveredImportedState(response.data.processed_names || abilityNames, true);
                SflmcpAbilities.renderDiscoveredAbilities($('#sflmcp-discovered-abilities'));
                SflmcpAbilities.refreshImportedList(function() {
                    SflmcpAbilities.showPanelNotice('.sflmcp-abilities-discover', 'success', response.data.message || sflmcpAbilities.i18n.imported);
                });
            }, function(message) {
                SflmcpAbilities.showPanelNotice('.sflmcp-abilities-discover', 'error', message);
            }, function() {
                $selectedButton.prop('disabled', false).text(sflmcpAbilities.i18n.importSelected);
                $visibleButton.prop('disabled', false);
            });
        },

        bulkManageAbilities: function(payload, onSuccess, onError, onComplete) {
            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: $.extend({
                    action: 'sflmcp_bulk_manage_abilities',
                    nonce: sflmcpAbilities.nonce
                }, payload),
                success: function(response) {
                    if (response.success) {
                        if (typeof onSuccess === 'function') {
                            onSuccess(response);
                        }
                    } else if (typeof onError === 'function') {
                        onError(response.data && response.data.message ? response.data.message : sflmcpAbilities.i18n.error);
                    }

                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                },
                error: function() {
                    if (typeof onError === 'function') {
                        onError(sflmcpAbilities.i18n.error);
                    }
                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                }
            });
        },

        refreshImportedList: function(callback) {
            var $container = $('#sflmcp-imported-abilities');

            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_get_imported_abilities',
                    nonce: sflmcpAbilities.nonce
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $container.html(response.data.html);
                        SflmcpAbilities.initializeImportedList();
                    }

                    if (typeof callback === 'function') {
                        callback(response);
                    }
                }
            });
        },

        initializeImportedList: function() {
            this.applyImportedSort();
            this.updateImportedSelectionState();
        },

        getSelectedImportedIds: function() {
            return $('#sflmcp-imported-abilities .sflmcp-imported-select-row:checked').map(function() {
                return parseInt($(this).val(), 10);
            }).get().filter(function(id) {
                return !!id;
            });
        },

        getSelectedImportedNames: function() {
            return $('#sflmcp-imported-abilities .sflmcp-imported-select-row:checked').map(function() {
                return $(this).closest('tr').attr('data-ability-name') || '';
            }).get().filter(function(name) {
                return !!name;
            });
        },

        getSortedDiscoveredAbilities: function(abilities) {
            return (abilities || []).slice().sort(function(abilityA, abilityB) {
                var categoryA = (abilityA.category || '').toLowerCase();
                var categoryB = (abilityB.category || '').toLowerCase();
                var labelA = (abilityA.label || '').toLowerCase();
                var labelB = (abilityB.label || '').toLowerCase();

                if (categoryA < categoryB) {
                    return -1;
                }
                if (categoryA > categoryB) {
                    return 1;
                }
                if (labelA < labelB) {
                    return -1;
                }
                if (labelA > labelB) {
                    return 1;
                }
                return 0;
            });
        },

        getDiscoveredCategories: function(abilities) {
            var categories = {};

            (abilities || []).forEach(function(ability) {
                if (ability.category) {
                    categories[ability.category] = true;
                }
            });

            return Object.keys(categories).sort(function(categoryA, categoryB) {
                return categoryA.localeCompare(categoryB);
            });
        },

        getVisibleDiscoveredAbilities: function() {
            var selectedCategory = this.state.discoveredCategory;

            return this.getSortedDiscoveredAbilities(this.state.discoveredAbilities).filter(function(ability) {
                if (!selectedCategory) {
                    return true;
                }

                return ability.category === selectedCategory;
            });
        },

        getVisibleImportableAbilityNames: function() {
            return this.getVisibleDiscoveredAbilities().filter(function(ability) {
                return !ability.imported;
            }).map(function(ability) {
                return ability.name;
            });
        },

        getSelectedDiscoveredNames: function(visibleOnly) {
            var visibleMap = {};
            var selectedNames = [];

            if (visibleOnly) {
                this.getVisibleDiscoveredAbilities().forEach(function(ability) {
                    visibleMap[ability.name] = true;
                });
            }

            this.getSortedDiscoveredAbilities(this.state.discoveredAbilities).forEach(function(ability) {
                if (ability.imported || !SflmcpAbilities.state.discoveredSelection[ability.name]) {
                    return;
                }

                if (visibleOnly && !visibleMap[ability.name]) {
                    return;
                }

                selectedNames.push(ability.name);
            });

            return selectedNames;
        },

        setDiscoveredImportedState: function(abilityNames, importedState) {
            var abilityMap = {};

            (abilityNames || []).forEach(function(name) {
                if (name) {
                    abilityMap[name] = true;
                }
            });

            this.state.discoveredAbilities = this.state.discoveredAbilities.map(function(ability) {
                if (abilityMap[ability.name]) {
                    ability.imported = importedState;
                    if (importedState) {
                        delete SflmcpAbilities.state.discoveredSelection[ability.name];
                    }
                }
                return ability;
            });
        },

        showPanelNotice: function(panelSelector, type, message) {
            var safeMessage = this.escapeHtml(message || '');
            var $panel = $(panelSelector);
            var noticeClass = type === 'success' ? 'sflmcp-notice-success' : 'sflmcp-notice-error';

            if (!$panel.length) {
                return;
            }

            $panel.find('.sflmcp-panel-notice').remove();
            $panel.prepend('<div class="sflmcp-notice sflmcp-panel-notice ' + noticeClass + '">' + safeMessage + '</div>');
        },

        escapeHtml: function(text) {
            if (!text) {
                return '';
            }

            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SflmcpAbilities.init();
    });

})(jQuery);
