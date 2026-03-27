/**
 * StifLi Flex MCP — Copilot Editor Bridge
 *
 * Executes local editor tools (`copilot_*`) in the browser,
 * modifying Gutenberg or Classic Editor state directly.
 * Each change shows a Keep / Undo banner so the user stays in control.
 *
 * @since 2.3.0
 */
(function () {
    'use strict';

    /* =====================================================================
     * Helpers
     * =================================================================== */

    /** Detect current editor type → 'gutenberg' | 'classic' | false */
    function editorType() {
        if (
            typeof wp !== 'undefined' &&
            wp.data &&
            wp.data.select('core/block-editor')
        ) {
            return 'gutenberg';
        }
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
            return 'classic';
        }
        return false;
    }

    /** Gutenberg dispatch shorthand. */
    function gbDispatch(store) {
        return wp.data.dispatch(store);
    }

    /** Gutenberg select shorthand. */
    function gbSelect(store) {
        return wp.data.select(store);
    }

    /** Strip HTML tags (quick helper). */
    function stripTags(s) {
        var d = document.createElement('div');
        d.innerHTML = s;
        return d.textContent || d.innerText || '';
    }

    /** Escape HTML entities to prevent XSS. */
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    /* =====================================================================
     * Highlight & scroll helpers
     * =================================================================== */

    /**
     * Inject the .sflmcp-ai-changed CSS rule into a document (main or iframe)
     * if not already present.
     */
    function ensureHighlightCSS(doc) {
        if (!doc || doc.getElementById('sflmcp-ai-changed-css')) return;
        var style = doc.createElement('style');
        style.id = 'sflmcp-ai-changed-css';
        style.textContent =
            '.sflmcp-ai-changed{' +
            'box-shadow:0 0 0 2px #2ea043 !important;' +
            'border-radius:3px;' +
            'transition:box-shadow .3s ease;' +
            '}';
        (doc.head || doc.documentElement).appendChild(style);
    }

    /**
     * Try to find a DOM element from an array of CSS selectors.
     * Checks the main document first, then any editor iframes.
     *
     * @param {string[]} selectors  CSS selectors to try in order.
     * @returns {Element|null}
     */
    function findElement(selectors) {
        var i, el;
        for (i = 0; i < selectors.length; i++) {
            el = document.querySelector(selectors[i]);
            if (el) return el;
        }
        // Check inside iframes (e.g. Classic Editor TinyMCE iframe).
        var iframes = document.querySelectorAll('iframe');
        for (var f = 0; f < iframes.length; f++) {
            try {
                var idoc = iframes[f].contentDocument || iframes[f].contentWindow.document;
                if (!idoc) continue;
                for (i = 0; i < selectors.length; i++) {
                    el = idoc.querySelector(selectors[i]);
                    if (el) return el;
                }
            } catch (_) { /* cross-origin — skip */ }
        }
        return null;
    }

    /**
     * Find a Gutenberg block element by clientId.
     * Checks inside the editor canvas iframe first (where actual content lives),
     * then falls back to the main document (List View, etc.).
     *
     * @param {string} clientId  Block clientId.
     * @returns {Element|null}
     */
    function findBlockElement(clientId) {
        var selector = '[data-block="' + clientId + '"]';
        // 1. Try editor canvas iframe (modern Gutenberg renders content here).
        var iframe = document.querySelector('iframe[name="editor-canvas"]');
        if (iframe) {
            try {
                var idoc = iframe.contentDocument || iframe.contentWindow.document;
                if (idoc) {
                    var el = idoc.querySelector(selector);
                    if (el) return el;
                }
            } catch (_) { /* cross-origin */ }
        }
        // 2. Fallback: main document (older WP or non-iframe Gutenberg).
        return document.querySelector(selector);
    }

    /**
     * Add a green highlight to an element, auto-remove after a timeout.
     *
     * @param {Element} el        Target element.
     * @param {number}  [ms=5000] Duration in ms.
     */
    function highlightElement(el, ms) {
        if (!el) return;
        ensureHighlightCSS(el.ownerDocument || document);
        el.classList.add('sflmcp-ai-changed');
        setTimeout(function () {
            el.classList.remove('sflmcp-ai-changed');
        }, ms || 5000);
    }

    /**
     * Remove the highlight class from all elements matching an array of selectors.
     *
     * @param {string[]} selectors
     */
    function clearHighlightSelectors(selectors) {
        if (!selectors || !selectors.length) return;
        selectors.forEach(function (sel) {
            try {
                document.querySelectorAll(sel).forEach(function (el) {
                    el.classList.remove('sflmcp-ai-changed');
                });
            } catch (_) { /* invalid selector — skip */ }
        });
    }

    /**
     * Scroll to the first visible element matching any of the given selectors.
     *
     * @param {string[]} selectors
     */
    function scrollToField(selectors) {
        var el = findElement(selectors);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            highlightElement(el);
        }
    }

    /* =====================================================================
     * Keep / Undo banner
     * =================================================================== */

    var bannerCounter = 0;

    /**
     * Show a Keep/Undo banner connected to an undo callback.
     *
     * @param {string}     label      Short text describing the change.
     * @param {Function}   undoFn     Called when user clicks Undo.
     * @param {string[]}   [highlights] CSS selectors whose highlights to clear on dismiss.
     */
    function showKeepUndo(label, undoFn, highlights) {
        bannerCounter++;
        var id = 'sflmcp-keepundo-' + bannerCounter;

        var banner = document.createElement('div');
        banner.id = id;
        banner.className = 'sflmcp-keepundo-banner';
        banner.innerHTML =
            '<span class="sflmcp-keepundo-label">' + escapeHtml(label) + '</span>' +
            '<button type="button" class="sflmcp-keepundo-btn sflmcp-keepundo-keep">✓ Keep</button>' +
            '<button type="button" class="sflmcp-keepundo-btn sflmcp-keepundo-undo">↩ Undo</button>';

        document.body.appendChild(banner);

        // Force reflow then show.
        banner.offsetHeight; // eslint-disable-line no-unused-expressions
        banner.classList.add('sflmcp-keepundo-visible');

        var keep = banner.querySelector('.sflmcp-keepundo-keep');
        var undo = banner.querySelector('.sflmcp-keepundo-undo');

        function remove() {
            clearHighlightSelectors(highlights);
            banner.classList.remove('sflmcp-keepundo-visible');
            setTimeout(function () {
                if (banner.parentNode) banner.parentNode.removeChild(banner);
            }, 300);
        }

        keep.addEventListener('click', function () {
            remove();
        });

        undo.addEventListener('click', function () {
            try { undoFn(); } catch (_) { /* best-effort */ }
            remove();
        });

        // Auto-dismiss after 30 s (keep the change).
        setTimeout(function () {
            if (banner.parentNode) remove();
        }, 30000);
    }

    /* =====================================================================
     * Per-tool implementations
     * =================================================================== */

    var tools = {};

    /* ----- copilot_set_title ------------------------------------------ */

    tools.copilot_set_title = function (args) {
        var title = args.title;
        if (typeof title !== 'string') return { error: 'title is required' };

        var titleSelectors = [
            '.editor-post-title__input',
            '.editor-post-title textarea',
            '#title',
            'h1.wp-block-post-title',
        ];

        var et = editorType();
        if (et === 'gutenberg') {
            var prev = gbSelect('core/editor').getEditedPostAttribute('title');
            gbDispatch('core/editor').editPost({ title: title });
            scrollToField(titleSelectors);
            showKeepUndo('Title changed', function () {
                gbDispatch('core/editor').editPost({ title: prev });
            }, titleSelectors);
            return { success: true, message: 'Title set to: ' + title };
        }
        if (et === 'classic') {
            var el = document.getElementById('title');
            if (!el) return { error: 'Title field not found' };
            var oldVal = el.value;
            el.value = title;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            scrollToField(titleSelectors);
            showKeepUndo('Title changed', function () {
                el.value = oldVal;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }, titleSelectors);
            return { success: true, message: 'Title set to: ' + title };
        }
        return { error: 'No editor detected' };
    };

    /* ----- copilot_set_excerpt ---------------------------------------- */

    tools.copilot_set_excerpt = function (args) {
        var excerpt = args.excerpt;
        if (typeof excerpt !== 'string') return { error: 'excerpt is required' };

        var excerptSelectors = [
            '.editor-post-excerpt textarea',
            '.editor-post-excerpt',
            '#excerpt',
            'textarea[id="excerpt"]',
        ];

        var et = editorType();
        if (et === 'gutenberg') {
            var prev = gbSelect('core/editor').getEditedPostAttribute('excerpt');
            gbDispatch('core/editor').editPost({ excerpt: excerpt });

            // Modern Gutenberg: the excerpt panel has .editor-post-excerpt__dropdown
            // inside a VStack parent — highlight the parent container.
            var excerptDropdown = document.querySelector('.editor-post-excerpt__dropdown');
            if (excerptDropdown && excerptDropdown.parentElement) {
                var excerptContainer = excerptDropdown.parentElement;
                excerptContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                highlightElement(excerptContainer);
            } else {
                scrollToField(excerptSelectors);
            }

            showKeepUndo('Excerpt changed', function () {
                gbDispatch('core/editor').editPost({ excerpt: prev });
            });
            return { success: true, message: 'Excerpt updated' };
        }
        if (et === 'classic') {
            var el = document.getElementById('excerpt');
            if (!el) return { error: 'Excerpt field not found' };
            var oldVal = el.value;
            el.value = excerpt;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            scrollToField(excerptSelectors);
            showKeepUndo('Excerpt changed', function () {
                el.value = oldVal;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }, excerptSelectors);
            return { success: true, message: 'Excerpt updated' };
        }
        return { error: 'No editor detected' };
    };

    /* ----- copilot_set_slug ------------------------------------------- */

    tools.copilot_set_slug = function (args) {
        var slug = args.slug;
        if (typeof slug !== 'string') return { error: 'slug is required' };

        var et = editorType();
        if (et === 'gutenberg') {
            var prev = gbSelect('core/editor').getEditedPostAttribute('slug');
            gbDispatch('core/editor').editPost({ slug: slug });
            showKeepUndo('Slug changed', function () {
                gbDispatch('core/editor').editPost({ slug: prev });
            });
            return { success: true, message: 'Slug set to: ' + slug };
        }
        if (et === 'classic') {
            var el = document.getElementById('new-post-slug');
            if (!el) return { error: 'Slug field not found (save the post first to reveal the slug editor)' };
            var oldVal = el.value;
            el.value = slug;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            showKeepUndo('Slug changed', function () {
                el.value = oldVal;
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
            return { success: true, message: 'Slug set to: ' + slug };
        }
        return { error: 'No editor detected' };
    };

    /* ----- copilot_set_status ----------------------------------------- */

    tools.copilot_set_status = function (args) {
        var status = args.status;
        var valid = ['draft', 'publish', 'pending', 'private', 'future'];
        if (valid.indexOf(status) === -1) {
            return { error: 'status must be one of: ' + valid.join(', ') };
        }

        var et = editorType();
        if (et === 'gutenberg') {
            var prev = gbSelect('core/editor').getEditedPostAttribute('status');
            gbDispatch('core/editor').editPost({ status: status });
            showKeepUndo('Status → ' + status, function () {
                gbDispatch('core/editor').editPost({ status: prev });
            });
            return { success: true, message: 'Status changed to: ' + status };
        }
        if (et === 'classic') {
            var sel = document.getElementById('post_status');
            if (!sel) return { error: 'Status field not found' };
            var oldVal = sel.value;
            sel.value = status;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            showKeepUndo('Status → ' + status, function () {
                sel.value = oldVal;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            });
            return { success: true, message: 'Status changed to: ' + status };
        }
        return { error: 'No editor detected' };
    };

    /* ----- copilot_set_categories ------------------------------------- */

    tools.copilot_set_categories = function (args) {
        var names = args.categories; // array of category names
        if (!Array.isArray(names) || names.length === 0) {
            return { error: 'categories (array of names) is required' };
        }

        var et = editorType();
        if (et === 'gutenberg') {
            // Map names → term IDs via the existing taxonomy store.
            var allCats = gbSelect('core').getEntityRecords('taxonomy', 'category', { per_page: -1 }) || [];
            var ids = [];
            var notFound = [];
            names.forEach(function (name) {
                var lc = name.toLowerCase().trim();
                var match = allCats.find(function (c) { return c.name.toLowerCase() === lc; });
                if (match) ids.push(match.id);
                else notFound.push(name);
            });

            if (ids.length === 0) {
                return { error: 'None of the specified categories were found. Available: ' + allCats.map(function (c) { return c.name; }).join(', ') };
            }

            var prev = gbSelect('core/editor').getEditedPostAttribute('categories') || [];
            gbDispatch('core/editor').editPost({ categories: ids });
            showKeepUndo('Categories updated', function () {
                gbDispatch('core/editor').editPost({ categories: prev });
            });

            var msg = 'Categories set to: ' + names.join(', ');
            if (notFound.length) msg += ' (not found: ' + notFound.join(', ') + ')';
            return { success: true, message: msg };
        }

        if (et === 'classic') {
            // Classic editor: toggle checkboxes in #categorychecklist
            var checklist = document.getElementById('categorychecklist');
            if (!checklist) return { error: 'Category checklist not found' };

            var labels = checklist.querySelectorAll('label');
            var prevChecked = [];
            var boxes = checklist.querySelectorAll('input[type="checkbox"]');
            boxes.forEach(function (cb) {
                if (cb.checked) prevChecked.push(cb.value);
                cb.checked = false;
            });

            var matched = 0;
            names.forEach(function (name) {
                var lc = name.toLowerCase().trim();
                labels.forEach(function (lbl) {
                    if (lbl.textContent.trim().toLowerCase() === lc) {
                        var cb = lbl.querySelector('input[type="checkbox"]');
                        if (cb) { cb.checked = true; matched++; }
                    }
                });
            });

            showKeepUndo('Categories updated', function () {
                boxes.forEach(function (cb) {
                    cb.checked = prevChecked.indexOf(cb.value) !== -1;
                });
            });

            return { success: true, message: 'Matched ' + matched + ' of ' + names.length + ' categories' };
        }

        return { error: 'No editor detected' };
    };

    /* ----- copilot_set_tags ------------------------------------------- */

    tools.copilot_set_tags = function (args) {
        var tags = args.tags; // array of tag names
        if (!Array.isArray(tags) || tags.length === 0) {
            return { error: 'tags (array of names) is required' };
        }

        var et = editorType();
        if (et === 'gutenberg') {
            // Gutenberg tags are async — we must resolve or create IDs.
            // Return a promise-like result; the bridge handles it.
            var allTags = gbSelect('core').getEntityRecords('taxonomy', 'post_tag', { per_page: -1 });
            if (!allTags) {
                allTags = []; // store not hydrated yet; we'll create all as new
            }

            var prev = gbSelect('core/editor').getEditedPostAttribute('tags') || [];
            var ids = prev.slice(); // start from current tags
            var created = [];
            var existing = [];
            var promises = [];

            tags.forEach(function (name) {
                var lc = name.toLowerCase().trim();
                var match = allTags.find(function (t) { return t.name.toLowerCase() === lc; });
                if (match) {
                    if (ids.indexOf(match.id) === -1) ids.push(match.id);
                    existing.push(name);
                } else {
                    // Create the tag via the REST API (Gutenberg data layer).
                    promises.push(
                        wp.data.dispatch('core').saveEntityRecord('taxonomy', 'post_tag', { name: name })
                            .then(function (newTag) {
                                if (newTag && newTag.id) {
                                    ids.push(newTag.id);
                                    created.push(name);
                                }
                            })
                            .catch(function () {
                                // Tag may already exist under different casing; try to find it.
                                var retry = gbSelect('core').getEntityRecords('taxonomy', 'post_tag', { search: name, per_page: 5 });
                                if (retry) {
                                    var found = retry.find(function (t) { return t.name.toLowerCase() === name.toLowerCase().trim(); });
                                    if (found && ids.indexOf(found.id) === -1) {
                                        ids.push(found.id);
                                        existing.push(name);
                                    }
                                }
                            })
                    );
                }
            });

            var tagSelectors = [
                '.editor-post-taxonomies__hierarchical-terms-list',
                '.components-form-token-field',
                '.components-form-token-field__input',
                '.tag-cloud-link',
            ];

            if (promises.length === 0) {
                // All tags existed — apply immediately.
                gbDispatch('core/editor').editPost({ tags: ids });
                scrollToField(tagSelectors);
                showKeepUndo('Tags updated', function () {
                    gbDispatch('core/editor').editPost({ tags: prev });
                }, tagSelectors);
                return { success: true, message: 'Tags set: ' + existing.join(', ') };
            }

            // Some tags need creation — return the result asynchronously.
            var settle = Promise.all
                ? Promise.all(promises.map(function (p) {
                    return p.then(
                        function () { return { ok: true }; },
                        function () { return { ok: false }; }
                    );
                }))
                : Promise.all(promises);

            settle.then(function () {
                gbDispatch('core/editor').editPost({ tags: ids });
                scrollToField(tagSelectors);
                showKeepUndo('Tags updated', function () {
                    gbDispatch('core/editor').editPost({ tags: prev });
                }, tagSelectors);
            });

            var msg = 'Tags being set: ' + tags.join(', ');
            if (created.length) msg += ' (created: ' + created.join(', ') + ')';
            return { success: true, message: msg };
        }

        if (et === 'classic') {
            // Classic editor — type into the tag input and click Add.
            var input = document.getElementById('new-tag-post_tag');
            var addBtn = document.querySelector('.tagadd');
            if (!input || !addBtn) return { error: 'Tag input not found' };

            // Save snapshot of existing tags for undo.
            var cloud = document.querySelector('.tagchecklist');
            var prevHtml = cloud ? cloud.innerHTML : '';

            input.value = tags.join(', ');

            // Trigger input event so WP JS picks it up, then click Add.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            addBtn.click();

            // Scroll to and highlight the tag area.
            var tagBox = document.getElementById('tagsdiv-post_tag') || input.closest('.tagsdiv');
            if (tagBox) {
                tagBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                highlightElement(tagBox);
            }

            showKeepUndo('Tags added: ' + tags.join(', '), function () {
                // Best-effort undo: restore the tag cloud HTML.
                if (cloud) cloud.innerHTML = prevHtml;
            });

            return { success: true, message: 'Tags added: ' + tags.join(', ') };
        }

        return { error: 'No editor detected' };
    };

    /* ----- copilot_replace_content ------------------------------------ */

    tools.copilot_replace_content = function (args) {
        var content = args.content;
        if (typeof content !== 'string') return { error: 'content is required' };

        var et = editorType();
        if (et === 'gutenberg') {
            // Replace entire post content by resetting all blocks.
            var prevBlocks = gbSelect('core/block-editor').getBlocks().map(function (b) {
                return wp.blocks.cloneBlock(b);
            });

            var newBlocks = wp.blocks.parse(content);
            gbDispatch('core/block-editor').resetBlocks(newBlocks);

            // Highlight all new blocks after DOM render.
            setTimeout(function () {
                newBlocks.forEach(function (b) {
                    var el = findBlockElement(b.clientId);
                    highlightElement(el, 3000);
                });
                if (newBlocks.length) {
                    var first = findBlockElement(newBlocks[0].clientId);
                    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 200);

            showKeepUndo('Content replaced', function () {
                gbDispatch('core/block-editor').resetBlocks(prevBlocks);
            });

            return { success: true, message: 'Content replaced (' + newBlocks.length + ' blocks)' };
        }

        if (et === 'classic') {
            var editor = tinyMCE.get('content');
            if (!editor) return { error: 'TinyMCE editor not found' };
            var prevContent = editor.getContent();
            editor.undoManager.add();
            editor.setContent(content);

            showKeepUndo('Content replaced', function () {
                editor.setContent(prevContent);
            });

            return { success: true, message: 'Content replaced' };
        }

        return { error: 'No editor detected' };
    };

    /* ----- copilot_find_replace --------------------------------------- */

    tools.copilot_find_replace = function (args) {
        var search  = args.search;
        var replace = args.replace;
        if (typeof search !== 'string' || typeof replace !== 'string') {
            return { error: 'search and replace are required' };
        }

        var et = editorType();
        if (et === 'gutenberg') {
            var blocks = gbSelect('core/block-editor').getBlocks();
            var count = 0;
            var changedClientIds = [];

            // Snapshot blocks before changes.
            var prevBlocks = blocks.map(function (b) {
                return wp.blocks.cloneBlock(b);
            });

            blocks.forEach(function (block) {
                replaceInBlock(block, search, replace, function () { count++; }, changedClientIds);
            });

            if (count === 0) {
                return { success: false, message: 'Text "' + search + '" not found in any block' };
            }

            // Highlight changed blocks after DOM render.
            setTimeout(function () {
                changedClientIds.forEach(function (cid) {
                    var el = findBlockElement(cid);
                    highlightElement(el, 3000);
                });
                if (changedClientIds.length) {
                    var first = findBlockElement(changedClientIds[0]);
                    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 200);

            showKeepUndo(count + ' replacement(s)', function () {
                gbDispatch('core/block-editor').resetBlocks(prevBlocks);
            });

            return { success: true, message: 'Replaced ' + count + ' occurrence(s)' };
        }

        if (et === 'classic') {
            var editor = tinyMCE.get('content');
            if (!editor) return { error: 'TinyMCE editor not found' };
            var html = editor.getContent();
            var prevHtml = html;

            // Use global string replace.
            var parts = html.split(search);
            var occurrences = parts.length - 1;
            if (occurrences === 0) {
                return { success: false, message: 'Text "' + search + '" not found' };
            }

            editor.undoManager.add();
            editor.setContent(parts.join(replace));

            showKeepUndo(occurrences + ' replacement(s)', function () {
                editor.setContent(prevHtml);
            });

            return { success: true, message: 'Replaced ' + occurrences + ' occurrence(s)' };
        }

        return { error: 'No editor detected' };
    };

    /**
     * Recursively find/replace text in a Gutenberg block and its inner blocks.
     */
    function replaceInBlock(block, search, replace, onHit, changedIds) {
        var changed = false;
        var attrs = {};

        // Common text-holding attributes.
        var textKeys = ['content', 'citation', 'value', 'text', 'caption', 'url', 'alt'];
        textKeys.forEach(function (key) {
            if (typeof block.attributes[key] === 'string' && block.attributes[key].indexOf(search) !== -1) {
                attrs[key] = block.attributes[key].split(search).join(replace);
                changed = true;
                onHit();
            }
        });

        if (changed) {
            gbDispatch('core/block-editor').updateBlockAttributes(block.clientId, attrs);
            if (changedIds) changedIds.push(block.clientId);
        }

        // Recurse into inner blocks.
        if (block.innerBlocks && block.innerBlocks.length) {
            block.innerBlocks.forEach(function (inner) {
                replaceInBlock(inner, search, replace, onHit, changedIds);
            });
        }
    }

    /* ----- copilot_insert_block --------------------------------------- */

    tools.copilot_insert_block = function (args) {
        var blockContent = args.content || '';
        var blockType    = args.block_type || 'core/paragraph';
        var position     = args.position; // optional index

        var et = editorType();
        if (et === 'gutenberg') {
            var blockAttrs = {};
            if (blockType === 'core/paragraph' || blockType === 'core/heading') {
                blockAttrs.content = blockContent;
            } else if (blockType === 'core/image') {
                blockAttrs.url = blockContent;
            } else if (blockType === 'core/list') {
                blockAttrs.values = blockContent;
            } else {
                blockAttrs.content = blockContent;
            }

            if (args.level && blockType === 'core/heading') {
                blockAttrs.level = parseInt(args.level, 10) || 2;
            }

            var newBlock = wp.blocks.createBlock(blockType, blockAttrs);
            var insertAt = typeof position === 'number' ? position : undefined;
            gbDispatch('core/block-editor').insertBlock(newBlock, insertAt);

            // Highlight the new block after DOM render.
            setTimeout(function () {
                var el = findBlockElement(newBlock.clientId);
                if (el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    highlightElement(el, 3000);
                }
            }, 200);

            showKeepUndo('Block inserted', function () {
                gbDispatch('core/block-editor').removeBlock(newBlock.clientId);
            });

            return { success: true, message: 'Inserted ' + blockType + ' block' };
        }

        if (et === 'classic') {
            var editor = tinyMCE.get('content');
            if (!editor) return { error: 'TinyMCE editor not found' };
            var prevContent = editor.getContent();
            editor.undoManager.add();

            // Append at end for Classic.
            var html = '<p>' + blockContent + '</p>';
            editor.setContent(editor.getContent() + html);

            showKeepUndo('Paragraph inserted', function () {
                editor.setContent(prevContent);
            });

            return { success: true, message: 'Paragraph appended' };
        }

        return { error: 'No editor detected' };
    };

    /* ----- copilot_update_block --------------------------------------- */

    tools.copilot_update_block = function (args) {
        var index      = args.block_index;
        var newContent = args.content;

        if (typeof index !== 'number') return { error: 'block_index (number) is required' };
        if (typeof newContent !== 'string') return { error: 'content is required' };

        var et = editorType();
        if (et !== 'gutenberg') {
            return { error: 'copilot_update_block is only available in the Gutenberg editor. Use copilot_find_replace for Classic Editor.' };
        }

        var blocks = gbSelect('core/block-editor').getBlocks();
        if (index < 0 || index >= blocks.length) {
            return { error: 'block_index ' + index + ' out of range (0-' + (blocks.length - 1) + ')' };
        }

        var block = blocks[index];
        var prevBlock = wp.blocks.cloneBlock(block);

        // Determine which attribute holds the text content.
        var contentKey = 'content';
        if (typeof block.attributes.values === 'string') contentKey = 'values';
        if (typeof block.attributes.value === 'string')  contentKey = 'value';
        if (typeof block.attributes.citation === 'string' && typeof block.attributes.content !== 'string') {
            contentKey = 'citation';
        }

        var update = {};
        update[contentKey] = newContent;
        gbDispatch('core/block-editor').updateBlockAttributes(block.clientId, update);

        // Highlight the updated block.
        setTimeout(function () {
            var el = findBlockElement(block.clientId);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                highlightElement(el, 3000);
            }
        }, 200);

        showKeepUndo('Block #' + index + ' updated', function () {
            gbDispatch('core/block-editor').replaceBlock(block.clientId, prevBlock);
        });

        return { success: true, message: 'Block #' + index + ' (' + block.name + ') updated' };
    };

    /* ----- copilot_delete_block --------------------------------------- */

    tools.copilot_delete_block = function (args) {
        var index = args.block_index;
        if (typeof index !== 'number') return { error: 'block_index (number) is required' };

        var et = editorType();
        if (et !== 'gutenberg') {
            return { error: 'copilot_delete_block is only available in the Gutenberg editor' };
        }

        var blocks = gbSelect('core/block-editor').getBlocks();
        if (index < 0 || index >= blocks.length) {
            return { error: 'block_index ' + index + ' out of range (0-' + (blocks.length - 1) + ')' };
        }

        var block = blocks[index];
        var cloned = wp.blocks.cloneBlock(block);

        gbDispatch('core/block-editor').removeBlock(block.clientId);

        showKeepUndo('Block #' + index + ' deleted', function () {
            gbDispatch('core/block-editor').insertBlock(cloned, index);
        });

        return { success: true, message: 'Block #' + index + ' (' + block.name + ') deleted' };
    };

    /* ----- copilot_set_featured_image --------------------------------- */

    tools.copilot_set_featured_image = function (args) {
        var attachmentId = parseInt(args.attachment_id, 10);
        if (!attachmentId) return { error: 'attachment_id (number) is required' };

        var et = editorType();
        if (et === 'gutenberg') {
            var prev = gbSelect('core/editor').getEditedPostAttribute('featured_media') || 0;
            gbDispatch('core/editor').editPost({ featured_media: attachmentId });
            showKeepUndo('Featured image set', function () {
                gbDispatch('core/editor').editPost({ featured_media: prev });
            });
            return { success: true, message: 'Featured image set to attachment #' + attachmentId };
        }

        if (et === 'classic') {
            // Classic editor: set the hidden _thumbnail_id field and update the UI.
            var container = document.getElementById('postimagediv');
            if (!container) return { error: 'Featured image metabox not found' };

            // Use the WP AJAX endpoint to set the thumbnail.
            var postId = document.getElementById('post_ID');
            if (!postId) return { error: 'Post ID not found' };

            jQuery.post(ajaxurl, {
                action: 'set-post-thumbnail',
                post_id: postId.value,
                thumbnail_id: attachmentId,
                _ajax_nonce: document.getElementById('_wpnonce') ? document.getElementById('_wpnonce').value : '',
            }, function (html) {
                if (html && html !== '0') {
                    var inside = container.querySelector('.inside');
                    if (inside) inside.innerHTML = html;
                }
            });

            showKeepUndo('Featured image set', function () {
                jQuery.post(ajaxurl, {
                    action: 'set-post-thumbnail',
                    post_id: postId.value,
                    thumbnail_id: -1,
                    _ajax_nonce: document.getElementById('_wpnonce') ? document.getElementById('_wpnonce').value : '',
                }, function (html) {
                    if (html) {
                        var inside = container.querySelector('.inside');
                        if (inside) inside.innerHTML = html;
                    }
                });
            });

            return { success: true, message: 'Featured image set to attachment #' + attachmentId };
        }

        return { error: 'No editor detected' };
    };

    /* ----- copilot_insert_image_block --------------------------------- */

    tools.copilot_insert_image_block = function (args) {
        var url          = args.url;
        var attachmentId = args.attachment_id ? parseInt(args.attachment_id, 10) : 0;
        var alt          = args.alt || '';
        var caption      = args.caption || '';
        var position     = args.position; // optional block index

        if (!url) return { error: 'url is required' };

        var et = editorType();
        if (et === 'gutenberg') {
            var blockAttrs = {
                url: url,
                alt: alt,
            };
            if (attachmentId) blockAttrs.id = attachmentId;
            if (caption) blockAttrs.caption = caption;

            var newBlock = wp.blocks.createBlock('core/image', blockAttrs);
            var insertAt = typeof position === 'number' ? position : undefined;
            gbDispatch('core/block-editor').insertBlock(newBlock, insertAt);

            showKeepUndo('Image inserted', function () {
                gbDispatch('core/block-editor').removeBlock(newBlock.clientId);
            });

            return { success: true, message: 'Image block inserted' + (attachmentId ? ' (attachment #' + attachmentId + ')' : '') };
        }

        if (et === 'classic') {
            var editor = tinyMCE.get('content');
            if (!editor) return { error: 'TinyMCE editor not found' };
            var prevContent = editor.getContent();
            editor.undoManager.add();

            var imgHtml = '<img src="' + url + '"';
            if (alt) imgHtml += ' alt="' + alt.replace(/"/g, '&quot;') + '"';
            if (attachmentId) imgHtml += ' class="wp-image-' + attachmentId + '"';
            imgHtml += ' />';
            if (caption) imgHtml = '<figure>' + imgHtml + '<figcaption>' + caption + '</figcaption></figure>';

            // Insert at cursor or append at end.
            if (editor.selection && !editor.selection.isCollapsed()) {
                editor.selection.setContent(imgHtml);
            } else {
                editor.setContent(editor.getContent() + imgHtml);
            }

            showKeepUndo('Image inserted', function () {
                editor.setContent(prevContent);
            });

            return { success: true, message: 'Image inserted' };
        }

        return { error: 'No editor detected' };
    };

    /* =====================================================================
     * Public API
     * =================================================================== */

    window.SflmcpCopilotEditor = {
        /**
         * Execute a local editor tool by name.
         *
         * @param {string} name Tool name (e.g. 'copilot_set_title').
         * @param {Object} args Tool arguments.
         * @returns {Object} Result with success/error + message.
         */
        execute: function (name, args) {
            if (typeof tools[name] === 'function') {
                return tools[name](args || {});
            }
            return { error: 'Unknown local tool: ' + name };
        },

        /** Expose editorType for context collectors. */
        editorType: editorType,
    };
})();
