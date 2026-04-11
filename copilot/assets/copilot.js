/**
 * StifLi Flex MCP — AI Copilot Widget
 *
 * Floating contextual assistant for the WordPress admin.
 * Detects the current page, collects relevant context from the DOM,
 * and sends it alongside the user message to the AI provider.
 *
 * @package StifliFlexMcp
 * @since 2.3.0
 */

/* global jQuery, sflmcpCopilot, wp */
(function ($) {
    'use strict';

    if (typeof sflmcpCopilot === 'undefined') {
        return;
    }

    /* -------------------------------------------------------
     * State
     * ------------------------------------------------------- */
    var state = {
        open: false,
        busy: false,
        conversation: [],
        xhr: null,
        sessionId: 'copilot-' + Date.now().toString(36) + '-' + Math.random().toString(36).substring(2, 10),
    };

    /* -------------------------------------------------------
     * DOM cache (populated on ready)
     * ------------------------------------------------------- */
    var $widget, $toggle, $panel, $close, $messages, $input, $send, $actions;

    /* -------------------------------------------------------
     * Context Collectors
     *
     * Each function returns a plain object (or null) with
     * contextual data scraped from the current admin page.
     * The data is sent as page_context and injected into the
     * system prompt so the AI always has full context.
     * ------------------------------------------------------- */
    var ContextCollectors = {

        /**
         * Detect editor type: 'gutenberg', 'classic', or false.
         */
        editorType: function () {
            if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.select('core/editor')) {
                return 'gutenberg';
            }
            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                return 'classic';
            }
            if ($('#title').length && $('#content').length) {
                return 'classic';
            }
            return false;
        },

        /**
         * Post / Page / CPT editor — enriched with block-level detail.
         */
        postEditor: function () {
            if (sflmcpCopilot.screen.base !== 'post') {
                return null;
            }

            var editorType = this.editorType();
            if (!editorType) { return null; }

            var data = {
                editor_type: editorType,
                post_type: sflmcpCopilot.screen.postType || 'post',
                id: '',
                title: '',
                content: '',
                excerpt: '',
                status: '',
                slug: '',
                categories: '',
                tags: '',
                blocks: null,
                featured_image: '',
            };

            if (editorType === 'gutenberg') {
                var editor = wp.data.select('core/editor');
                var blockEditor = wp.data.select('core/block-editor');
                var post = editor.getCurrentPost() || {};

                data.id      = post.id || '';
                data.title   = editor.getEditedPostAttribute('title') || '';
                data.content = editor.getEditedPostContent() || '';
                data.excerpt = editor.getEditedPostAttribute('excerpt') || '';
                data.status  = editor.getEditedPostAttribute('status') || '';
                data.slug    = editor.getEditedPostAttribute('slug') || '';

                // Resolve category names.
                var catIds = editor.getEditedPostAttribute('categories') || [];
                if (wp.data.select('core') && catIds.length) {
                    var cats = [];
                    catIds.forEach(function (id) {
                        var term = wp.data.select('core').getEntityRecord('taxonomy', 'category', id);
                        if (term) { cats.push(term.name); }
                    });
                    data.categories = cats.join(', ');
                }

                // Resolve tag names.
                var tagIds = editor.getEditedPostAttribute('tags') || [];
                if (wp.data.select('core') && tagIds.length) {
                    var tags = [];
                    tagIds.forEach(function (id) {
                        var term = wp.data.select('core').getEntityRecord('taxonomy', 'post_tag', id);
                        if (term) { tags.push(term.name); }
                    });
                    data.tags = tags.join(', ');
                }

                // Featured image.
                var featId = editor.getEditedPostAttribute('featured_media');
                if (featId) {
                    var media = wp.data.select('core').getMedia(featId);
                    data.featured_image = media ? (media.source_url || '') : '(ID: ' + featId + ')';
                }

                // Block-level detail for the AI to reference by index.
                if (blockEditor) {
                    var rawBlocks = blockEditor.getBlocks() || [];
                    data.blocks = rawBlocks.map(function (b, i) {
                        var info = {
                            index: i,
                            type: b.name || 'unknown',
                        };
                        // Extract text content from common block attributes.
                        if (b.attributes) {
                            if (b.attributes.content) {
                                info.content = b.attributes.content;
                            }
                            if (b.attributes.citation) {
                                info.citation = b.attributes.citation;
                            }
                            if (b.attributes.value) {
                                info.value = b.attributes.value;
                            }
                            if (b.attributes.url) {
                                info.url = b.attributes.url;
                            }
                            if (b.attributes.alt) {
                                info.alt = b.attributes.alt;
                            }
                            if (b.attributes.caption) {
                                info.caption = b.attributes.caption;
                            }
                            if (b.attributes.level) {
                                info.level = b.attributes.level; // heading level h1-h6
                            }
                            if (b.attributes.values) {
                                info.values = b.attributes.values; // list items
                            }
                        }
                        // Inner blocks (columns, groups, etc.) — first level only.
                        if (b.innerBlocks && b.innerBlocks.length) {
                            info.innerBlockCount = b.innerBlocks.length;
                        }
                        return info;
                    });
                }

                return data;
            }

            // Classic Editor.
            var $title = $('#title');
            if (!$title.length) { return null; }

            data.id    = $('#post_ID').val() || '';
            data.title = $title.val() || '';
            data.slug  = $('#editable-post-name-full').text() || '';

            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
                data.content = tinyMCE.get('content').getContent();
            } else {
                data.content = $('#content').val() || '';
            }
            data.excerpt = $('#excerpt').val() || '';

            var cats = [];
            $('#categorychecklist input:checked').each(function () {
                cats.push($(this).parent().text().trim());
            });
            data.categories = cats.join(', ');

            data.tags = $('.tagchecklist span').map(function () {
                return $(this).text().replace(/\s*X\s*$/, '').trim();
            }).get().join(', ');

            data.status = $('#post_status').val() || ($('#original_post_status').val() || '');

            return data;
        },

        /**
         * WooCommerce Product editor.
         */
        wcProduct: function () {
            if (sflmcpCopilot.screen.base !== 'post' || sflmcpCopilot.screen.postType !== 'product') {
                return null;
            }

            var data = {
                id: $('#post_ID').val() || '',
                name: '',
                regular_price: '',
                sale_price: '',
                sku: '',
                stock: '',
                stock_status: '',
                weight: '',
                length: '',
                width: '',
                height: '',
                short_description: '',
                product_type: '',
                categories: '',
                tags: '',
            };

            var editorType = ContextCollectors.editorType();
            if (editorType === 'gutenberg') {
                var editor = wp.data.select('core/editor');
                data.name = editor.getEditedPostAttribute('title') || '';
            } else {
                data.name = $('#title').val() || '';
            }

            data.regular_price    = $('#_regular_price').val() || '';
            data.sale_price       = $('#_sale_price').val() || '';
            data.sku              = $('#_sku').val() || '';
            data.stock            = $('#_stock').val() || '';
            data.stock_status     = $('#_stock_status').val() || '';
            data.weight           = $('#_weight').val() || '';
            data.length           = $('#_length').val() || '';
            data.width            = $('#_width').val() || '';
            data.height           = $('#_height').val() || '';
            data.short_description = $('#excerpt').val() || '';
            data.product_type     = $('#product-type').val() || '';

            // Product categories.
            var cats = [];
            $('#product_catchecklist input:checked, #taxonomy-product_cat input:checked').each(function () {
                cats.push($(this).parent().text().trim());
            });
            data.categories = cats.join(', ');

            // Product tags.
            var tagEl = $('.tagchecklist[id*="product_tag"] span, #product_tag .tagchecklist span');
            data.tags = tagEl.map(function () {
                return $(this).text().replace(/\s*X\s*$/, '').trim();
            }).get().join(', ');

            if (!data.name && !data.regular_price) { return null; }
            return data;
        },

        /**
         * WooCommerce Order detail page.
         */
        wcOrder: function () {
            var screenId = sflmcpCopilot.screen.id;
            if (screenId !== 'woocommerce_page_wc-orders' && screenId !== 'shop_order') {
                return null;
            }
            var data = {
                id: '',
                status: '',
                total: '',
                items: '',
            };

            data.id     = $('#post_ID').val() || $('input[name="id"]').val() || '';
            data.status = $('#order_status').val() || $('select[name="order_status"]').val() || '';
            data.total  = $('#_order_total').val() || $('.woocommerce-order-data__meta .order_number').text().trim() || '';

            var items = [];
            $('.woocommerce_order_items .order_item .wc-order-item-name, #order_line_items .name a').each(function () {
                items.push($(this).text().trim());
            });
            data.items = items.join(', ');

            if (!data.id) { return null; }
            return data;
        },

        /**
         * Media Library.
         */
        mediaLibrary: function () {
            if (sflmcpCopilot.screen.id !== 'upload') {
                return null;
            }
            var count = $('.attachments .attachment.selected, .wp-list-table .check-column input:checked').length;
            return { count: count || 0 };
        },

        /**
         * Comments list.
         */
        comments: function () {
            if (sflmcpCopilot.screen.base !== 'edit-comments') {
                return null;
            }
            var pending = parseInt($('.pending-count').first().text(), 10) || 0;
            return { pending: pending };
        },

        /**
         * Plugins list.
         */
        plugins: function () {
            if (sflmcpCopilot.screen.base !== 'plugins') {
                return null;
            }
            var active   = $('.plugins .active').length;
            var inactive = $('.plugins .inactive').length;
            return { active: active, inactive: inactive };
        },
    };

    /**
     * Gather full page context by running every collector.
     */
    function collectPageContext() {
        var ctx = {
            screen: sflmcpCopilot.screen.id,
        };

        var post = ContextCollectors.postEditor();
        if (post) { ctx.post = post; }

        var product = ContextCollectors.wcProduct();
        if (product) { ctx.product = product; }

        var order = ContextCollectors.wcOrder();
        if (order) { ctx.order = order; }

        var media = ContextCollectors.mediaLibrary();
        if (media) { ctx.media = media; }

        var comments = ContextCollectors.comments();
        if (comments) { ctx.comments = comments; }

        var plugins = ContextCollectors.plugins();
        if (plugins) { ctx.plugins = plugins; }

        return ctx;
    }

    /* -------------------------------------------------------
     * Quick Action Chips
     *
     * Contextual one-click prompts that appear at the top of
     * the panel depending on which admin page the user is on.
     * ------------------------------------------------------- */

    var QuickActions = {
        'post': [
            { label: '⚡ Optimize content',                prompt: 'Optimize the design and structure of this post to make it more attractive and professional. Improve visual hierarchy using headings, lists, bold text, and separators where appropriate. Reorganize the content so it flows better and is easier to read. Apply the changes directly.' },
            { label: '🏷️ Generate tags',                  prompt: 'Analyze the content of this post, generate 5-8 relevant tags and set them directly using the copilot_set_tags tool.' },
            { label: '📝 Write excerpt',                   prompt: 'Write a compelling excerpt (under 160 chars) for this post optimized for SEO and set it directly using the copilot_set_excerpt tool.' },
            { label: '💡 Suggest titles',                  prompt: 'Suggest 3 alternative, more engaging titles for this post.' },
            { label: '♿ Alt text for images',             prompt: 'Generate descriptive alt text for all images in this post to improve accessibility.' },
            { label: '📋 Summarize',                       prompt: 'Summarize this post content into a short, digestible overview (3-4 sentences).' },
            { label: '🔍 SEO review',                      prompt: 'Review this post for SEO best practices and suggest improvements.' },
            { label: '🖼️ Generate image',                  prompt: 'Generate an image that matches the topic of this post and set it as the featured image.' },
        ],
        'product': [
            { label: '✍️ Improve description',             prompt: 'Rewrite the short description of this product to be more compelling and conversion-oriented.' },
            { label: '🏷️ Suggest tags/categories',         prompt: 'Suggest relevant product categories and tags for this product.' },
            { label: '💰 Price analysis',                  prompt: 'Based on the product description, does the price seem appropriate? Give a brief analysis.' },
            { label: '📋 Generate bullet points',          prompt: 'Generate 5 concise bullet points highlighting the key features and benefits of this product.' },
            { label: '🔍 SEO optimize',                    prompt: 'Suggest an SEO-optimized product title and meta description for this product.' },
            { label: '🖼️ Generate product image',          prompt: 'Generate a professional product image based on this product description and set it as the featured image.' },
        ],
        'order': [
            { label: '📊 Order summary',                   prompt: 'Summarize this order: items, totals, and current status.' },
            { label: '✉️ Draft customer email',            prompt: 'Draft a professional follow-up email to the customer about this order.' },
        ],
        'upload': [
            { label: '♿ Bulk alt text',                   prompt: 'Help me generate descriptive alt text for images in my media library. What do you need to get started?' },
            { label: '🧹 Cleanup suggestions',             prompt: 'What strategies can I use to clean up my WordPress media library?' },
        ],
        'edit-comments': [
            { label: '🛡️ Spam check',                     prompt: 'Show me the pending comments so I can check if any look like spam.' },
            { label: '💬 Draft replies',                   prompt: 'Help me draft professional replies for the pending comments.' },
        ],
        'plugins': [
            { label: '🔍 Plugin audit',                    prompt: 'List all active plugins and tell me if any seem redundant or outdated.' },
            { label: '⚡ Performance tips',                prompt: 'Which of my active plugins are likely impacting performance the most?' },
        ],
        'dashboard': [
            { label: '📊 Site summary',                    prompt: 'Give me a quick summary of my site: recent posts, comments, and overall status.' },
            { label: '✅ What needs attention?',           prompt: 'Is there anything on my WordPress site that needs urgent attention?' },
        ],
    };

    /**
     * Render quick action chips based on the current screen.
     */
    function renderQuickActions() {
        if (!$actions || !$actions.length) { return; }
        $actions.empty();

        var screenBase = sflmcpCopilot.screen.base || '';
        var screenId   = sflmcpCopilot.screen.id || '';
        var postType   = sflmcpCopilot.screen.postType || '';
        var key        = '';

        // Determine which action set to show.
        if (screenBase === 'post' && postType === 'product') {
            key = 'product';
        } else if (screenBase === 'post') {
            key = 'post';
        } else if (screenId === 'woocommerce_page_wc-orders' || screenId === 'shop_order') {
            key = 'order';
        } else if (screenId === 'upload') {
            key = 'upload';
        } else if (screenBase === 'edit-comments') {
            key = 'edit-comments';
        } else if (screenBase === 'plugins') {
            key = 'plugins';
        } else if (screenBase === 'dashboard') {
            key = 'dashboard';
        }

        var items = QuickActions[key];
        if (!items || !items.length) { return; }

        items.forEach(function (item) {
            var $chip = $('<button type="button" class="sflmcp-copilot-chip"></button>')
                .text(item.label)
                .on('click', function () {
                    $input.val(item.prompt);
                    sendMessage();
                });
            $actions.append($chip);
        });
    }

    /* -------------------------------------------------------
     * UI Helpers
     * ------------------------------------------------------- */

    function addMessage(role, html) {
        var cls = 'sflmcp-copilot-msg sflmcp-copilot-msg-' + role;
        var $msg = $('<div class="' + cls + '"></div>').html(html);
        $messages.append($msg);
        $messages.scrollTop($messages[0].scrollHeight);
    }

    function setThinking(on) {
        if (on) {
            addMessage('assistant', '<em>' + sflmcpCopilot.i18n.thinking + '</em>');
        } else {
            $messages.find('.sflmcp-copilot-msg-assistant:last em').closest('.sflmcp-copilot-msg').remove();
        }
    }

    function togglePanel(open) {
        state.open = open;
        if (open) {
            $panel.addClass('sflmcp-copilot-panel--open');
            $toggle.addClass('sflmcp-copilot-toggle--active');
            $input.focus();
        } else {
            $panel.removeClass('sflmcp-copilot-panel--open');
            $toggle.removeClass('sflmcp-copilot-toggle--active');
        }
    }

    /* -------------------------------------------------------
     * Chat logic
     * ------------------------------------------------------- */

    function sendMessage() {
        var msg = $.trim($input.val());
        if (!msg || state.busy) { return; }

        state.busy = true;
        $send.prop('disabled', true);
        $input.val('');
        autoResize();

        addMessage('user', $('<span>').text(msg).html());
        setThinking(true);

        // Collect fresh context right before sending.
        var pageContext = collectPageContext();

        state.xhr = $.ajax({
            url: sflmcpCopilot.ajaxUrl,
            method: 'POST',
            data: {
                action:       'sflmcp_copilot_chat',
                nonce:        sflmcpCopilot.nonce,
                message:      msg,
                page_context: JSON.stringify(pageContext),
                conversation: JSON.stringify(state.conversation),
            },
            success: function (res) {
                setThinking(false);
                if (res.success && res.data) {
                    handleResponse(res.data);
                } else {
                    addMessage('assistant', '<span class="sflmcp-copilot-error">' + escapeHtml(sflmcpCopilot.i18n.error + ' ' + (res.data && res.data.message ? res.data.message : 'Unknown error')) + '</span>');
                }
            },
            error: function (xhr, status) {
                setThinking(false);
                if (status !== 'abort') {
                    addMessage('assistant', '<span class="sflmcp-copilot-error">' + sflmcpCopilot.i18n.error + ' Network error</span>');
                }
            },
            complete: function () {
                state.busy = false;
                $send.prop('disabled', false);
                state.xhr = null;
            },
        });
    }

    /**
     * Process the AI response — show text, handle tool calls.
     */
    function handleResponse(data) {
        // Store conversation for multi-turn.
        if (data.conversation) {
            state.conversation = data.conversation;
        }

        // Show text.
        if (data.text) {
            addMessage('assistant', formatMarkdown(data.text));
        }

        // Handle tool calls — execute ALL of them, then feed results back together.
        // Claude requires tool_result blocks for every tool_use in the assistant message.
        if (data.tool_calls && data.tool_calls.length) {
            executeAllTools(data.tool_calls);
        }
    }

    /**
     * Execute all tool calls sequentially, then feed all results back at once.
     * Local tools (copilot_*) run in the browser; remote tools go via AJAX.
     */
    function executeAllTools(toolCalls) {
        var results = [];
        var index = 0;
        state.busy = true;
        $send.prop('disabled', true);

        function pushResult(tc, name, output) {
            results.push({
                tool_use_id: tc.tool_use_id || tc.id || '',
                call_id:     tc.call_id || tc.id || '',
                name:        name,
                output:      output,
            });
        }

        function next() {
            if (index >= toolCalls.length) {
                // All tools executed — feed results back to AI.
                feedAllToolResults(results);
                return;
            }

            var tc = toolCalls[index];
            var name = tc.name || tc.function || '';

            // ── Local tool: execute in browser via copilot-editor bridge ──
            if (name.indexOf('copilot_') === 0 && window.SflmcpCopilotEditor) {
                addMessage('tool', '✏️ <em>' + escapeHtml(name) + '</em>');
                try {
                    var localResult = window.SflmcpCopilotEditor.execute(name, tc.arguments || {});
                    pushResult(tc, name, localResult);
                } catch (err) {
                    pushResult(tc, name, { error: err.message || 'Local tool failed' });
                }
                index++;
                next();
                return;
            }

            // ── Remote tool: execute via AJAX ──
            addMessage('tool', '🔧 <em>' + escapeHtml(name) + '…</em>');

            $.ajax({
                url: sflmcpCopilot.ajaxUrl,
                method: 'POST',
                data: {
                    action:     'sflmcp_copilot_execute_tool',
                    nonce:      sflmcpCopilot.nonce,
                    tool_name:  name,
                    arguments:  JSON.stringify(tc.arguments || {}),
                    session_id: state.sessionId,
                },
                success: function (res) {
                    var output;
                    if (res.success && res.data) {
                        output = res.data.content || res.data;
                    } else {
                        output = { error: (res.data && res.data.message) || 'Tool execution failed' };
                    }
                    pushResult(tc, name, output);
                    index++;
                    next();
                },
                error: function () {
                    pushResult(tc, name, { error: 'Network error executing tool' });
                    index++;
                    next();
                },
            });
        }

        next();
    }

    /**
     * Send ALL tool results back to the AI in a single request.
     */
    function feedAllToolResults(results) {
        setThinking(true);

        $.ajax({
            url: sflmcpCopilot.ajaxUrl,
            method: 'POST',
            data: {
                action:       'sflmcp_copilot_chat',
                nonce:        sflmcpCopilot.nonce,
                message:      '',
                page_context: JSON.stringify(collectPageContext()),
                conversation: JSON.stringify(state.conversation),
                tool_result:  JSON.stringify(results),
            },
            success: function (res) {
                setThinking(false);
                if (res.success && res.data) {
                    handleResponse(res.data);
                } else {
                    addMessage('assistant', '<span class="sflmcp-copilot-error">' + escapeHtml(res.data && res.data.message || 'Error') + '</span>');
                }
            },
            error: function () {
                setThinking(false);
                addMessage('assistant', '<span class="sflmcp-copilot-error">Network error</span>');
            },
            complete: function () {
                state.busy = false;
                $send.prop('disabled', false);
            },
        });
    }

    /* -------------------------------------------------------
     * Utilities
     * ------------------------------------------------------- */

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /**
     * Very light markdown: **bold**, `code`, newlines.
     */
    function formatMarkdown(text) {
        return escapeHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\n/g, '<br>');
    }

    /**
     * Auto-resize textarea to fit content (max 4 rows).
     */
    function autoResize() {
        var el = $input[0];
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 96) + 'px'; // ~4 rows.
    }

    /* -------------------------------------------------------
     * Init
     * ------------------------------------------------------- */

    $(function () {
        $widget   = $('#sflmcp-copilot-widget');
        $toggle   = $('#sflmcp-copilot-toggle');
        $panel    = $('#sflmcp-copilot-panel');
        $close    = $('#sflmcp-copilot-close');
        $messages = $('#sflmcp-copilot-messages');
        $input    = $('#sflmcp-copilot-input');
        $send     = $('#sflmcp-copilot-send');
        $actions  = $('#sflmcp-copilot-actions');

        if (!$widget.length) { return; }
        $widget.show();

        // Events.
        $toggle.on('click', function () { togglePanel(!state.open); });
        $close.on('click', function () { togglePanel(false); });

        $send.on('click', sendMessage);
        $input.on('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        $input.on('input', autoResize);

        // Render context-aware quick actions.
        renderQuickActions();

        // ── Drag support — drag by the header bar ──
        (function () {
            var $header = $panel.find('.sflmcp-copilot-header');
            var dragging = false;
            var startX, startY, origLeft, origTop;

            $header.on('mousedown', function (e) {
                // Ignore clicks on the close button.
                if ($(e.target).closest('.sflmcp-copilot-close').length) return;

                dragging = true;
                $header.addClass('sflmcp-dragging');

                // Switch widget to position-based layout on first drag.
                var offset = $widget[0].getBoundingClientRect();
                $widget.css({
                    right: 'auto',
                    bottom: 'auto',
                    left: offset.left + 'px',
                    top: offset.top + 'px',
                });

                startX = e.clientX;
                startY = e.clientY;
                origLeft = offset.left;
                origTop  = offset.top;

                e.preventDefault();
            });

            $(document).on('mousemove', function (e) {
                if (!dragging) return;
                var dx = e.clientX - startX;
                var dy = e.clientY - startY;
                $widget.css({
                    left: Math.max(0, origLeft + dx) + 'px',
                    top:  Math.max(0, origTop + dy) + 'px',
                });
            });

            $(document).on('mouseup', function () {
                if (!dragging) return;
                dragging = false;
                $header.removeClass('sflmcp-dragging');
            });
        })();
    });

})(jQuery);
