/**
 * StifLi Flex MCP - Abilities Tab JavaScript
 * WordPress 6.9+ Abilities API Integration
 */

(function($) {
    'use strict';

    var SflmcpAbilities = {
        
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Discover abilities button
            $('#sflmcp-discover-abilities').on('click', this.discoverAbilities.bind(this));
            
            // Import ability (delegated)
            $(document).on('click', '.sflmcp-import-ability', this.importAbility.bind(this));
            
            // Toggle ability (delegated)
            $(document).on('click', '.sflmcp-toggle-ability', this.toggleAbility.bind(this));
            
            // Delete ability (delegated)
            $(document).on('click', '.sflmcp-delete-ability', this.deleteAbility.bind(this));
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
                    $button.prop('disabled', false).text('Discover Abilities');
                    
                    if (!response.success) {
                        $container.html('<div class="sflmcp-notice sflmcp-notice-error">' + (response.data.message || sflmcpAbilities.i18n.error) + '</div>');
                        return;
                    }
                    
                    if (!response.data.abilities || response.data.abilities.length === 0) {
                        $container.html('<div class="sflmcp-empty-state">' + sflmcpAbilities.i18n.noAbilities + '</div>');
                        return;
                    }
                    
                    SflmcpAbilities.renderDiscoveredAbilities(response.data.abilities, $container);
                },
                error: function() {
                    $button.prop('disabled', false).text('Discover Abilities');
                    $container.html('<div class="sflmcp-notice sflmcp-notice-error">' + sflmcpAbilities.i18n.error + '</div>');
                }
            });
        },

        renderDiscoveredAbilities: function(abilities, $container) {
            var html = '<div class="sflmcp-discovered-list">';
            
            abilities.forEach(function(ability) {
                var importedClass = ability.imported ? ' imported' : '';
                var buttonText = ability.imported ? sflmcpAbilities.i18n.alreadyImported : sflmcpAbilities.i18n.import;
                var buttonDisabled = ability.imported ? ' disabled' : '';
                var buttonClass = ability.imported ? 'button' : 'button button-primary sflmcp-import-ability';
                
                html += '<div class="sflmcp-discovered-item' + importedClass + '" data-ability-name="' + SflmcpAbilities.escapeHtml(ability.name) + '">';
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
                html += '<button type="button" class="' + buttonClass + '" data-ability="' + SflmcpAbilities.escapeHtml(ability.name) + '"' + buttonDisabled + '>' + buttonText + '</button>';
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
            
            if (!abilityName) return;
            
            $button.prop('disabled', true).text('Importing...');
            
            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_import_ability',
                    nonce: sflmcpAbilities.nonce,
                    ability_name: abilityName
                },
                success: function(response) {
                    if (response.success) {
                        $button.removeClass('button-primary sflmcp-import-ability')
                               .text(sflmcpAbilities.i18n.alreadyImported);
                        $button.closest('.sflmcp-discovered-item').addClass('imported');
                        
                        // Refresh imported abilities list
                        SflmcpAbilities.refreshImportedList();
                    } else {
                        $button.prop('disabled', false).text(sflmcpAbilities.i18n.import);
                        alert(response.data.message || sflmcpAbilities.i18n.error);
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(sflmcpAbilities.i18n.import);
                    alert(sflmcpAbilities.i18n.error);
                }
            });
        },

        toggleAbility: function(e) {
            e.preventDefault();
            
            var $button = $(e.currentTarget);
            var abilityId = $button.data('id');
            var $icon = $button.find('.dashicons');
            
            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_toggle_ability',
                    nonce: sflmcpAbilities.nonce,
                    ability_id: abilityId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.enabled) {
                            $icon.removeClass('dashicons-marker').addClass('dashicons-yes-alt')
                                 .css('color', '#46b450');
                            $button.data('enabled', 1);
                        } else {
                            $icon.removeClass('dashicons-yes-alt').addClass('dashicons-marker')
                                 .css('color', '#dc3232');
                            $button.data('enabled', 0);
                        }
                    } else {
                        alert(response.data.message || sflmcpAbilities.i18n.error);
                    }
                },
                error: function() {
                    alert(sflmcpAbilities.i18n.error);
                }
            });
        },

        deleteAbility: function(e) {
            e.preventDefault();
            
            if (!confirm(sflmcpAbilities.i18n.confirmDelete)) {
                return;
            }
            
            var $button = $(e.currentTarget);
            var abilityId = $button.data('id');
            var $row = $button.closest('tr');
            
            $.ajax({
                url: sflmcpAbilities.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sflmcp_delete_ability',
                    nonce: sflmcpAbilities.nonce,
                    ability_id: abilityId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            // Check if table is now empty
                            if ($('#sflmcp-imported-abilities tbody tr').length === 0) {
                                SflmcpAbilities.refreshImportedList();
                            }
                        });
                    } else {
                        alert(response.data.message || sflmcpAbilities.i18n.error);
                    }
                },
                error: function() {
                    alert(sflmcpAbilities.i18n.error);
                }
            });
        },

        refreshImportedList: function() {
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
                    }
                }
            });
        },

        escapeHtml: function(text) {
            if (!text) return '';
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
