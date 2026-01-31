jQuery(document).ready(function($) {
    const modal = $('#sflmcp_tool_editor_modal');
    const tableBody = $('#sflmcp_custom_tools_table tbody');
    let argsCount = 0;

    // --- Dynamic UI for Method Type ---
    function updateEndpointUI() {
        const method = $('#tool_method').val();
        if (method === 'ACTION') {
            $('#endpoint_label').text('Action Hook Name');
            $('#tool_endpoint').attr('placeholder', 'flush_rewrite_rules');
            $('#endpoint_help').text('Any WordPress/plugin action hook. Examples: flush_rewrite_rules, woocommerce_cancel_unpaid_orders, w3tc_flush_all');
            $('#sflmcp_test_tool').hide();
        } else {
            $('#endpoint_label').text('Webhook URL / Endpoint');
            $('#tool_endpoint').attr('placeholder', 'https://hook.eu1.make.com/...');
            $('#endpoint_help').text('Full URL to the external API endpoint.');
            $('#sflmcp_test_tool').show();
        }
    }
    
    $('#tool_method').on('change', updateEndpointUI);

    // --- Data Loading ---
    function loadTools() {
        $.post(sflmcpCustom.ajaxUrl, {
            action: 'sflmcp_get_custom_tools',
            nonce: sflmcpCustom.nonce
        }, function(response) {
            if (response.success) {
                renderTable(response.data);
            } else {
                tableBody.html('<tr><td colspan="5">Error loading tools</td></tr>');
            }
        });
    }

    function renderTable(tools) {
        tableBody.empty();
        if (tools.length === 0) {
            tableBody.html('<tr><td colspan="5" style="text-align:center;">No custom tools found. Click "Add New Tool" to create one.</td></tr>');
            return;
        }

        tools.forEach(function(tool) {
            const isEnabled = tool.enabled == 1;
            const statusToggle = isEnabled 
                ? '<button type="button" class="button button-small sflmcp-toggle-tool" data-id="' + tool.id + '" data-enabled="1" title="Click to disable"><span class="dashicons dashicons-yes" style="color:green;vertical-align:middle;"></span> Enabled</button>' 
                : '<button type="button" class="button button-small sflmcp-toggle-tool" data-id="' + tool.id + '" data-enabled="0" title="Click to enable"><span class="dashicons dashicons-no" style="color:#999;vertical-align:middle;"></span> Disabled</button>';
            
            const methodBadge = tool.method === 'ACTION' 
                ? '<span class="badge badge-action" style="background:#9b59b6;color:#fff;padding:2px 6px;border-radius:3px;">ACTION</span>'
                : '<span class="badge badge-method" style="background:#3498db;color:#fff;padding:2px 6px;border-radius:3px;">' + esc(tool.method) + '</span>';
                
            const row = `
                <tr>
                    <td><strong>${esc(tool.tool_name)}</strong></td>
                    <td>${esc(tool.tool_description)}</td>
                    <td>${methodBadge}</td>
                    <td>${statusToggle}</td>
                    <td>
                        <button type="button" class="button button-small sflmcp-edit-tool" data-tool='${JSON.stringify(tool)}'>Edit</button>
                        <button type="button" class="button button-small button-link-delete sflmcp-delete-tool" data-id="${tool.id}">Delete</button>
                    </td>
                </tr>
            `;
            tableBody.append(row);
        });
    }

    // --- Modal Handling ---
    $('#sflmcp_add_custom_tool').on('click', function() {
        openModal();
    });

    $('.sflmcp-modal-close').on('click', function() {
        modal.hide();
    });

    $(window).on('click', function(e) {
        if ($(e.target).is(modal)) {
            modal.hide();
        }
    });

    function openModal(tool = null) {
        // Reset form
        $('#sflmcp_tool_form')[0].reset();
        $('#sflmcp_args_table tbody').empty();
        $('#sflmcp_advanced_settings').hide();
        argsCount = 0;

        if (tool) {
            // Edit Mode
            $('#sflmcp_editor_title').text('Edit Tool: ' + tool.tool_name);
            $('#tool_id').val(tool.id);
            $('#tool_name').val(tool.tool_name).prop('readonly', true); // Name immutable on edit
            $('#tool_description').val(tool.tool_description);
            $('#tool_method').val(tool.method);
            $('#tool_endpoint').val(tool.endpoint);
            $('#tool_headers').val(tool.headers);
            $('#tool_enabled').prop('checked', tool.enabled == 1);

            // Populate Arguments Builder from Schema
            try {
                const schema = JSON.parse(tool.arguments);
                if (schema.properties) {
                    const required = schema.required || [];
                    Object.keys(schema.properties).forEach(key => {
                        const prop = schema.properties[key];
                        addArgRow({
                            name: key,
                            type: prop.type,
                            desc: prop.description || '',
                            required: required.includes(key)
                        });
                    });
                }
            } catch (e) {
                console.error("Error parsing schema", e);
            }
        } else {
            // Create Mode
            $('#sflmcp_editor_title').text('Create New Custom Tool');
            $('#tool_id').val('0');
            $('#tool_name').val('').prop('readonly', false);
            $('#tool_enabled').prop('checked', true);
        }
        
        // Update UI based on method type
        updateEndpointUI();
        
        modal.show();
    }

    // --- Arguments Builder Logic ---
    $('#sflmcp_add_arg_row').on('click', function() {
        addArgRow();
    });

    function addArgRow(data = {name: '', type: 'string', desc: '', required: false}) {
        argsCount++;
        const rowId = 'arg_row_' + argsCount;
        const checked = data.required ? 'checked' : '';
        
        const html = `
            <tr id="${rowId}">
                <td><input type="text" class="arg-name" value="${esc(data.name)}" placeholder="e.g. title" required></td>
                <td>
                    <select class="arg-type">
                        <option value="string" ${data.type==='string'?'selected':''}>String</option>
                        <option value="integer" ${data.type==='integer'?'selected':''}>Integer</option>
                        <option value="boolean" ${data.type==='boolean'?'selected':''}>Boolean</option>
                        <option value="number" ${data.type==='number'?'selected':''}>Number</option>
                    </select>
                </td>
                <td><input type="text" class="arg-desc" value="${esc(data.desc)}" placeholder="Description for AI"></td>
                <td style="text-align:center;"><input type="checkbox" class="arg-req" ${checked}></td>
                <td><span class="dashicons dashicons-trash sflmcp-remove-arg" onclick="jQuery('#${rowId}').remove()"></span></td>
            </tr>
        `;
        $('#sflmcp_args_table tbody').append(html);
    }

    // --- Saving ---
    $('#sflmcp_save_tool').on('click', function() {
        const btn = $(this);
        const name = $('#tool_name').val().trim();
        
        if (!name) { alert('Tool name is required'); return; }
        if (!name.startsWith('custom_')) { alert('Tool name must start with "custom_"'); return; }
        
        // Compile JSON Schema from builder
        const schema = {
            type: "object",
            properties: {},
            required: []
        };
        
        let isValid = true;
        $('#sflmcp_args_table tbody tr').each(function() {
            const tr = $(this);
            const key = tr.find('.arg-name').val().trim();
            if (!key) return; // Skip empty rows
            
            // Basic validation for duplicate keys could go here
            if (schema.properties[key]) {
                alert('Duplicate parameter name: ' + key);
                isValid = false;
                return false;
            }

            schema.properties[key] = {
                type: tr.find('.arg-type').val(),
                description: tr.find('.arg-desc').val()
            };
            
            if (tr.find('.arg-req').is(':checked')) {
                schema.required.push(key);
            }
        });
        
        if (!isValid) return;

        const data = {
            action: 'sflmcp_save_custom_tool',
            nonce: sflmcpCustom.nonce,
            id: $('#tool_id').val(),
            tool_name: name,
            tool_description: $('#tool_description').val(),
            method: $('#tool_method').val(),
            endpoint: $('#tool_endpoint').val(),
            arguments: JSON.stringify(schema),
            headers: $('#tool_headers').val(),
            enabled: $('#tool_enabled').is(':checked') ? 1 : 0
        };

        btn.prop('disabled', true).text(sflmcpCustom.i18n.loading); // Reusing 'loading' string

        $.post(sflmcpCustom.ajaxUrl, data, function(res) {
            btn.prop('disabled', false).text('Save Tool');
            if (res.success) {
                modal.hide();
                loadTools();
                alert(sflmcpCustom.i18n.saved);
            } else {
                alert(res.data.message || sflmcpCustom.i18n.errorSaving);
            }
        });
    });

    // --- Actions ---
    $(document).on('click', '.sflmcp-edit-tool', function() {
        const tool = $(this).data('tool');
        openModal(tool);
    });

    $(document).on('click', '.sflmcp-delete-tool', function() {
        if (!confirm(sflmcpCustom.i18n.confirmDelete)) return;
        
        const id = $(this).data('id');
        $.post(sflmcpCustom.ajaxUrl, {
            action: 'sflmcp_delete_custom_tool',
            nonce: sflmcpCustom.nonce,
            id: id
        }, function(res) {
            loadTools();
        });
    });

    // --- Toggle Status ---
    $(document).on('click', '.sflmcp-toggle-tool', function() {
        const btn = $(this);
        const id = btn.data('id');
        
        btn.prop('disabled', true);
        
        $.post(sflmcpCustom.ajaxUrl, {
            action: 'sflmcp_toggle_custom_tool',
            nonce: sflmcpCustom.nonce,
            id: id
        }, function(res) {
            btn.prop('disabled', false);
            if (res.success) {
                // Update button appearance without full reload
                const newEnabled = res.data.enabled;
                // Use attr() instead of data() to ensure it persists correctly
                btn.attr('data-enabled', newEnabled);
                if (newEnabled == 1) {
                    btn.html('<span class="dashicons dashicons-yes" style="color:green;vertical-align:middle;"></span> Enabled');
                    btn.attr('title', 'Click to disable');
                } else {
                    btn.html('<span class="dashicons dashicons-no" style="color:#999;vertical-align:middle;"></span> Disabled');
                    btn.attr('title', 'Click to enable');
                }
            } else {
                alert(res.data && res.data.message ? res.data.message : 'Error toggling tool');
            }
        }).fail(function() {
            btn.prop('disabled', false);
            alert('Network error');
        });
    });
    
    // --- Testing ---
    $('#sflmcp_test_tool').on('click', function() {
        const endpoint = $('#tool_endpoint').val();
        if (!endpoint) { alert('Endpoint URL required for testing'); return; }
        
        const btn = $(this);
        const originalText = btn.text();
        btn.prop('disabled', true).text(sflmcpCustom.i18n.testing);
        
        $.post(sflmcpCustom.ajaxUrl, {
            action: 'sflmcp_test_custom_tool',
            nonce: sflmcpCustom.nonce,
            endpoint: endpoint,
            method: $('#tool_method').val(),
            headers: $('#tool_headers').val()
        }, function(res) {
            btn.prop('disabled', false).text(originalText);
            if (res.success) {
                alert("Status: " + res.data.code + "\n\nResponse:\n" + res.data.body);
            } else {
                alert("Error: " + res.data.message);
            }
        });
    });

    // --- UI Toggles ---
    $('#sflmcp_toggle_advanced').on('click', function(e) {
        e.preventDefault();
        $('#sflmcp_advanced_settings').slideToggle();
    });

    // Helper
    function esc(str) {
        if (!str) return '';
        return $('<div>').text(str).html();
    }

    // Init
    loadTools();
});