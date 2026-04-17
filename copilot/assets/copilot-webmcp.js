/**
 * StifLi Flex MCP — WebMCP Bridge
 *
 * Registers copilot_* editor tools with the browser's native
 * navigator.modelContext API (WebMCP) so Chrome's built-in AI
 * (or any WebMCP-compatible agent) can discover and call them
 * — no API key needed, zero cost.
 *
 * Falls back silently when WebMCP is not available.
 *
 * @since 2.5.0
 */
(function () {
	'use strict';

	/* =====================================================================
	 * Wait for navigator.modelContext — it may load asynchronously
	 * =================================================================== */

	var MAX_WAIT  = 5000; // ms
	var POLL_INTERVAL = 200;

	/** Debug logging — only output when WP_DEBUG / SFLMCP_DEBUG is active. */
	var _debug = (typeof window.sflmcpCopilot !== 'undefined' && window.sflmcpCopilot.debug);
	function dbg() {
		if (_debug && typeof console !== 'undefined') {
			console.log.apply(console, arguments);
		}
	}

	function waitForModelContext(cb) {
		if (typeof navigator !== 'undefined' && navigator.modelContext) {
			cb(navigator.modelContext);
			return;
		}
		var elapsed = 0;
		var timer = setInterval(function () {
			elapsed += POLL_INTERVAL;
			if (typeof navigator !== 'undefined' && navigator.modelContext) {
				clearInterval(timer);
				cb(navigator.modelContext);
			} else if (elapsed >= MAX_WAIT) {
				clearInterval(timer);
				// WebMCP not available — nothing to do.
			}
		}, POLL_INTERVAL);
	}

	/* =====================================================================
	 * Tool definitions — mirrors copilot-editor.js local tools
	 * =================================================================== */

	/**
	 * Build the tool list based on the current editor context.
	 * Only returns tools relevant to the detected editor type.
	 */
	function buildToolDefinitions() {
		var isEditor = !!(
			window.SflmcpCopilotEditor &&
			window.SflmcpCopilotEditor.editorType &&
			window.SflmcpCopilotEditor.editorType()
		);

		if (!isEditor) return [];

		var et = window.SflmcpCopilotEditor.editorType();
		var isGutenberg = (et === 'gutenberg');

		var defs = [];

		defs.push({
			name: 'copilot_set_title',
			description: 'Set the post/page title in the editor. The change is visual and immediate.',
			input: {
				type: 'object',
				properties: {
					title: { type: 'string', description: 'The new title' },
				},
				required: ['title'],
			},
		});

		defs.push({
			name: 'copilot_set_excerpt',
			description: 'Set the post/page excerpt in the editor.',
			input: {
				type: 'object',
				properties: {
					excerpt: { type: 'string', description: 'The new excerpt text' },
				},
				required: ['excerpt'],
			},
		});

		defs.push({
			name: 'copilot_set_slug',
			description: 'Set the post/page URL slug in the editor.',
			input: {
				type: 'object',
				properties: {
					slug: { type: 'string', description: 'The new slug (URL-safe)' },
				},
				required: ['slug'],
			},
		});

		defs.push({
			name: 'copilot_set_status',
			description: 'Change the post status (draft, publish, pending, private).',
			input: {
				type: 'object',
				properties: {
					status: { type: 'string', description: 'The new status: draft, publish, pending, or private' },
				},
				required: ['status'],
			},
		});

		defs.push({
			name: 'copilot_set_categories',
			description: 'Set the post categories by name. Replaces current selection.',
			input: {
				type: 'object',
				properties: {
					categories: { type: 'array', items: { type: 'string' }, description: 'Array of category names' },
				},
				required: ['categories'],
			},
		});

		defs.push({
			name: 'copilot_set_tags',
			description: 'Set the post tags by name.',
			input: {
				type: 'object',
				properties: {
					tags: { type: 'array', items: { type: 'string' }, description: 'Array of tag names' },
				},
				required: ['tags'],
			},
		});

		defs.push({
			name: 'copilot_replace_content',
			description: 'Replace the entire post content. Provide full HTML or block markup.',
			input: {
				type: 'object',
				properties: {
					content: { type: 'string', description: 'The new HTML content' },
				},
				required: ['content'],
			},
		});

		defs.push({
			name: 'copilot_find_replace',
			description: 'Find and replace text in the post content. Works across all blocks (Gutenberg) or the full HTML (Classic). Case-sensitive.',
			input: {
				type: 'object',
				properties: {
					search:  { type: 'string', description: 'The text to find' },
					replace: { type: 'string', description: 'The replacement text' },
				},
				required: ['search', 'replace'],
			},
		});

		defs.push({
			name: 'copilot_insert_block',
			description: 'Insert a new block at a given position. In Classic Editor, appends a paragraph.',
			input: {
				type: 'object',
				properties: {
					content:    { type: 'string', description: 'Block text/HTML content' },
					block_type: { type: 'string', description: 'Block type (e.g. core/paragraph, core/heading). Default: core/paragraph' },
					position:   { type: 'number', description: 'Insert at this 0-based block index. Omit to append.' },
					level:      { type: 'number', description: 'Heading level (2-6) — only for core/heading' },
				},
				required: ['content'],
			},
		});

		if (isGutenberg) {
			defs.push({
				name: 'copilot_update_block',
				description: 'Update the text content of a specific block by its 0-based index. Gutenberg only.',
				input: {
					type: 'object',
					properties: {
						block_index: { type: 'number', description: 'The 0-based block index' },
						content:     { type: 'string', description: 'The new text/HTML content for the block' },
					},
					required: ['block_index', 'content'],
				},
			});

			defs.push({
				name: 'copilot_delete_block',
				description: 'Delete a specific block by its 0-based index. Gutenberg only.',
				input: {
					type: 'object',
					properties: {
						block_index: { type: 'number', description: 'The 0-based block index to delete' },
					},
					required: ['block_index'],
				},
			});
		}

		defs.push({
			name: 'copilot_set_featured_image',
			description: 'Set the featured image from a WordPress media attachment ID.',
			input: {
				type: 'object',
				properties: {
					attachment_id: { type: 'number', description: 'The WordPress media attachment ID' },
				},
				required: ['attachment_id'],
			},
		});

		defs.push({
			name: 'copilot_insert_image_block',
			description: 'Insert an image into the post content at a specific position.',
			input: {
				type: 'object',
				properties: {
					url:           { type: 'string', description: 'The image URL' },
					attachment_id: { type: 'number', description: 'Optional attachment ID' },
					alt:           { type: 'string', description: 'Alt text for the image' },
					caption:       { type: 'string', description: 'Optional image caption' },
					position:      { type: 'number', description: 'Block index to insert at (0-based). Omit to append.' },
				},
				required: ['url'],
			},
		});

		// Context tool — lets the agent read current editor state.
		defs.push({
			name: 'copilot_get_context',
			description: 'Get the current post editor context: title, excerpt, slug, status, categories, tags, and block list with content. Use this to understand what the user is editing before making changes.',
			input: {
				type: 'object',
				properties: {},
			},
		});

		return defs;
	}

	/**
	 * Collect the current editor context for the copilot_get_context tool.
	 * This gives the AI full visibility into the editor state without needing
	 * the page_context AJAX path.
	 */
	function collectEditorContext() {
		var ctx = {};

		var et = window.SflmcpCopilotEditor
			? window.SflmcpCopilotEditor.editorType()
			: false;

		if (!et) return { text: 'No editor detected on this page.' };

		ctx.editor_type = et;

		if (et === 'gutenberg' && typeof wp !== 'undefined' && wp.data) {
			var editor = wp.data.select('core/editor');
			var blockEditor = wp.data.select('core/block-editor');

			if (editor) {
				ctx.title   = editor.getEditedPostAttribute('title') || '';
				ctx.excerpt = editor.getEditedPostAttribute('excerpt') || '';
				ctx.slug    = editor.getEditedPostAttribute('slug') || '';
				ctx.status  = editor.getEditedPostAttribute('status') || '';
				ctx.post_type = editor.getCurrentPostType() || '';
			}

			if (blockEditor) {
				var blocks = blockEditor.getBlocks() || [];
				ctx.block_count = blocks.length;
				ctx.blocks = [];
				for (var i = 0; i < blocks.length; i++) {
					var b = blocks[i];
					var info = {
						index: i,
						type:  b.name || 'unknown',
					};

					// Extract text content from common attributes.
					var attrs = b.attributes || {};
					if (attrs.content) info.content = stripTags(attrs.content);
					else if (attrs.value) info.content = stripTags(attrs.value);
					else if (attrs.citation) info.content = stripTags(attrs.citation);
					else if (attrs.url) info.content = attrs.url;

					if (attrs.level) info.level = attrs.level;
					if (attrs.alt) info.alt = attrs.alt;
					if (b.innerBlocks && b.innerBlocks.length) {
						info.inner_blocks = b.innerBlocks.length;
					}

					ctx.blocks.push(info);
				}
			}
		} else if (et === 'classic') {
			var titleEl = document.getElementById('title');
			var excerptEl = document.getElementById('excerpt');
			ctx.title   = titleEl ? titleEl.value : '';
			ctx.excerpt = excerptEl ? excerptEl.value : '';

			if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
				var raw = tinyMCE.get('content').getContent();
				ctx.content_length = raw.length;
				// Provide a trimmed preview to keep token use manageable.
				var plain = raw.replace(/<[^>]+>/g, '').trim();
				ctx.content_preview = plain.length > 2000 ? plain.substring(0, 2000) + '…' : plain;
			}
		}

		return ctx;
	}

	/** Minimal HTML-strip for context collection. */
	function stripTags(s) {
		if (typeof s !== 'string') return '';
		// Replace HTML tags with empty string, then decode common entities.
		return s.replace(/<[^>]*>/g, '')
			.replace(/&amp;/g, '&')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"')
			.replace(/&#039;/g, "'")
			.replace(/&nbsp;/g, ' ')
			.trim();
	}

	/* =====================================================================
	 * Register tools with navigator.modelContext
	 * =================================================================== */

	function registerTools(mc) {
		var defs = buildToolDefinitions();

		// Filter out disabled tools from settings.
		var disabled = (window.sflmcpCopilot && window.sflmcpCopilot.webmcpDisabledTools) || [];
		if (disabled.length) {
			defs = defs.filter(function (d) {
				return disabled.indexOf(d.name) === -1;
			});
		}

		if (!defs.length) return;

		var registered = 0;

		for (var i = 0; i < defs.length; i++) {
			(function (def) {
				try {
					// ModelContextTool is a single dictionary argument with name inside.
					mc.registerTool({
						name: def.name,
						description: def.description,
						inputSchema: def.input,
						execute: async function (args) {
							// Special case: context tool.
							if (def.name === 'copilot_get_context') {
								var ctx = collectEditorContext();
								return {
									content: [{ type: 'text', text: JSON.stringify(ctx, null, 2) }],
								};
							}

							// Execute via copilot-editor.js bridge.
							if (!window.SflmcpCopilotEditor) {
								return {
									content: [{ type: 'text', text: JSON.stringify({ error: 'Editor bridge not loaded' }) }],
								};
							}

							var result = window.SflmcpCopilotEditor.execute(def.name, args || {});
							return {
								content: [{ type: 'text', text: JSON.stringify(result) }],
							};
						},
					});
					registered++;
				} catch (e) {
					// Tool registration failed — likely duplicate or API changed.
					if (typeof console !== 'undefined' && console.warn) {
						console.warn('[StifLi WebMCP] Failed to register tool "' + def.name + '":', e);
					}
				}
			})(defs[i]);
		}

		if (registered > 0) {
			dbg('[StifLi WebMCP] Registered ' + registered + ' editor tools with navigator.modelContext');
		}

		// Activate visual indicators in the Copilot widget.
		if (registered > 0) {
			activateIndicators(registered);
		}

		// Dispatch event so other scripts can react.
		try {
			document.dispatchEvent(new CustomEvent('sflmcp-webmcp-ready', {
				detail: { toolCount: registered },
			}));
		} catch (_) { /* old browsers */ }
	}

	/**
	 * Show the WebMCP badge in the header and the green dot on the toggle bubble.
	 *
	 * @param {number} toolCount Number of tools registered.
	 */
	function activateIndicators(toolCount) {
		// Green dot on toggle bubble.
		var dot = document.getElementById('sflmcp-copilot-webmcp-dot');
		if (dot) {
			dot.classList.remove('sflmcp-hidden');
			dot.title = 'WebMCP active (' + toolCount + ' tools)';
		}

		// Badge in panel header.
		var badge = document.getElementById('sflmcp-copilot-badge-webmcp');
		if (badge) {
			badge.classList.remove('sflmcp-hidden');
			badge.title = 'WebMCP — Browser AI (' + toolCount + ' tools, free)';
		}

		// Mark widget for CSS targeting.
		var widget = document.getElementById('sflmcp-copilot-widget');
		if (widget) {
			widget.classList.add('sflmcp-copilot--webmcp-active');
		}
	}

	/* =====================================================================
	 * Init — wait for both the editor bridge and modelContext
	 * =================================================================== */

	/**
	 * Expose a global interface for copilot.js to use for local chat
	 * via Chrome's Prompt API (LanguageModel).
	 *
	 * window.SflmcpWebMCP.available - true when Prompt API is detected
	 * window.SflmcpWebMCP.chat(msg, pageContext, conversation) → Promise<{text, tool_calls}>
	 */
	var webmcpApi = {
		available: false,
		toolsRegistered: 0,
		_session: null,
		_toolDefs: [],

		/**
		 * Build the system prompt including available tools and editor context.
		 */
		/**
		 * Format page context as human-readable text (mirrors PHP format_page_context).
		 */
		_formatPageContext: function (ctx) {
			if (!ctx) { return ''; }
			var lines = ['--- CURRENT ADMIN PAGE CONTEXT ---'];
			lines.push('Screen: ' + (ctx.screen || 'unknown'));

			if (ctx.post) {
				var p = ctx.post;
				lines.push('Editing ' + (p.post_type || 'post') + ' (#' + (p.id || '?') + '): "' + (p.title || '') + '"  [editor: ' + (p.editor_type || 'unknown') + ']');
				if (p.status)         { lines.push('Status: ' + p.status); }
				if (p.slug)           { lines.push('Slug: ' + p.slug); }
				if (p.excerpt)        { lines.push('Excerpt: ' + p.excerpt); }
				if (p.categories)     { lines.push('Categories: ' + p.categories); }
				if (p.tags)           { lines.push('Tags: ' + p.tags); }
				if (p.featured_image) { lines.push('Featured image: ' + p.featured_image); }

				// Block-level content (Gutenberg).
				if (p.blocks && p.blocks.length) {
					lines.push('');
					lines.push('BLOCKS (' + p.blocks.length + ' total):');
					for (var i = 0; i < p.blocks.length; i++) {
						var b = p.blocks[i];
						var text = b.content || b.value || b.citation || b.url || '';
						// Strip HTML tags for readability.
						text = text.replace(/<[^>]+>/g, '');
						var extra = '';
						if (b.level) { extra += ' H' + b.level; }
						if (b.alt)   { extra += ' alt="' + b.alt + '"'; }
						if (b.innerBlockCount) { extra += ' (' + b.innerBlockCount + ' inner blocks)'; }
						lines.push('  [' + (b.index || i) + '] ' + (b.type || 'unknown') + extra + ': ' + text);
					}
				} else if (p.content) {
					// Classic editor fallback.
					var plainContent = p.content.replace(/<[^>]+>/g, '');
					lines.push('Content: ' + plainContent);
				}
			}

			if (ctx.product) {
				var pr = ctx.product;
				lines.push('WooCommerce Product (#' + (pr.id || '?') + '): "' + (pr.name || '') + '"');
				if (pr.price)        { lines.push('Price: ' + pr.price); }
				if (pr.sku)          { lines.push('SKU: ' + pr.sku); }
				if (pr.stock_status) { lines.push('Stock: ' + pr.stock_status); }
			}

			if (ctx.order) {
				var o = ctx.order;
				lines.push('WooCommerce Order (#' + (o.id || '?') + ') — Status: ' + (o.status || ''));
				if (o.total) { lines.push('Total: ' + o.total); }
			}

			return lines.join('\n');
		},

		_buildSystemPrompt: function () {
			// If the user provided a custom system prompt in settings, use it.
			var custom = (window.sflmcpCopilot && window.sflmcpCopilot.webmcpSystemPrompt) || '';
			if (custom.trim()) {
				// Append the dynamic tool list so the model knows what's available.
				var toolLines = [];
				if (this._toolDefs.length) {
					toolLines.push('');
					toolLines.push('AVAILABLE TOOLS:');
					for (var t = 0; t < this._toolDefs.length; t++) {
						var td = this._toolDefs[t];
						toolLines.push('- ' + td.name + ': ' + td.description);
					}
				}
				return custom.trim() + '\n' + toolLines.join('\n');
			}

			var parts = [];

			// ── 1. Identity & Context ──
			parts.push('You are AI Copilot, a WordPress editing assistant embedded in the browser.');
			parts.push('');
			parts.push('CONTEXT YOU RECEIVE:');
			parts.push('With every message you receive the full context of the page the user is editing:');
			parts.push('- Post title, excerpt, slug, status, categories, tags');
			parts.push('- A numbered list of all content blocks with their index [0], [1], etc. and text');
			parts.push('Use this context to understand what the user is working on. You do NOT need to ask the user to paste content — you already have it.');
			parts.push('');

			// ── 2. Tools ──
			if (this._toolDefs.length) {
				parts.push('YOUR TOOLS:');
				parts.push('You have the following tools to modify the post directly in the editor. Changes are instant and visual.');
				parts.push('');
				for (var i = 0; i < this._toolDefs.length; i++) {
					var t = this._toolDefs[i];
					var paramParts = [];
					if (t.input && t.input.properties) {
						for (var k in t.input.properties) {
							if (t.input.properties.hasOwnProperty(k)) {
								var p = t.input.properties[k];
								var req = (t.input.required && t.input.required.indexOf(k) !== -1) ? ' (required)' : '';
								paramParts.push('    ' + k + ': ' + (p.type || 'string') + req + ' — ' + (p.description || ''));
							}
						}
					}
					parts.push('- ' + t.name + ': ' + t.description);
					if (paramParts.length) {
						parts.push(paramParts.join('\n'));
					}
				}
				parts.push('');
			}

			// ── 3. How to call tools ──
			parts.push('HOW TO CALL A TOOL:');
			parts.push('Respond with a fenced block using the tag tool_call containing a JSON object:');
			parts.push('');
			parts.push('```tool_call');
			parts.push('{"name": "copilot_set_tags", "arguments": {"tags": "tag1, tag2, tag3"}}');
			parts.push('```');
			parts.push('');
			parts.push('Rules:');
			parts.push('- The fence MUST be ```tool_call (not ```tool_code, ```json, or anything else).');
			parts.push('- The body MUST be valid JSON with "name" and "arguments" keys.');
			parts.push('- You may include multiple ```tool_call blocks in one response to call several tools.');
			parts.push('');

			// ── 4. When to use tools ──
			parts.push('WHEN TO USE TOOLS:');
			parts.push('- If the user asks to CHANGE, TRANSLATE, REWRITE, OPTIMIZE, SET, ADD, or DELETE anything → use the appropriate tool. Do NOT describe the change — APPLY it.');
			parts.push('- To translate or rewrite the ENTIRE content → use copilot_replace_content with the full new HTML.');
			parts.push('- To translate or edit a SPECIFIC block (e.g. "the second paragraph") → use copilot_update_block with the block_index from the context.');
			parts.push('- To set title, excerpt, tags, categories, etc. → use the corresponding copilot_set_* tool.');
			parts.push('- To answer a QUESTION (e.g. "suggest titles", "what is this about?") → respond with plain text, no tool_call needed.');
			parts.push('- After calling tools, write a brief confirmation of what was done.');
			parts.push('');

			return parts.join('\n');
		},

		/**
		 * Send a message through Chrome's Prompt API.
		 * Returns { text: string, tool_calls: array|null }
		 */
		chat: async function (msg, pageContext, conversation) {
			if (typeof LanguageModel === 'undefined') {
				throw new Error('Chrome Prompt API (LanguageModel) not available');
			}

			// Create or re-use session.
			if (!this._session) {
				var lang = (window.sflmcpCopilot && window.sflmcpCopilot.webmcpLanguage) || 'en';
				var langOpts = { expectedInputLanguages: [lang], expectedOutputLanguages: [lang] };

				var avail = await LanguageModel.availability(langOpts);
				if (avail === 'unavailable') {
					throw new Error('Gemini Nano is not available on this device');
				}

				this._session = await LanguageModel.create({
					systemPrompt: this._buildSystemPrompt(),
					expectedInputLanguages: [lang],
					expectedOutputLanguages: [lang],
				});
			}

			// Build the prompt: context + conversation history + user message.
			var prompt = '';

			// Inject page context into every prompt so the model always has it.
			var formattedContext = this._formatPageContext(pageContext);
			if (formattedContext) {
				prompt += formattedContext + '\n\n';
			}

			if (conversation && conversation.length) {
				var maxHistory = Math.min(conversation.length, 6);
				var start = conversation.length - maxHistory;
				for (var i = start; i < conversation.length; i++) {
					var turn = conversation[i];
					if (turn.role === 'user') {
						prompt += 'User: ' + turn.content + '\n';
					} else if (turn.role === 'assistant') {
						prompt += 'Assistant: ' + turn.content + '\n';
					}
				}
			}
			prompt += 'User: ' + msg;

			dbg('[StifLi WebMCP] Prompt sent to Gemini Nano:', prompt);

			var response = await this._session.prompt(prompt);

			dbg('[StifLi WebMCP] Raw response from Gemini Nano:', response);

			// Parse tool calls from the response.
			// Supports multiple formats Gemini Nano may produce:
			// 1) ```tool_call\n{JSON}\n```  (preferred)
			// 2) ```tool_code\nfunc_name(args)\n```  (fallback)
			// 3) ```tool_call\nfunc_name(args)\n```  (hybrid)
			var toolCalls = [];
			var cleanText = response;

			// Match any code block labeled tool_call or tool_code.
			var blockRegex = /```(?:tool_call|tool_code)\s*\n([\s\S]*?)\n```/g;
			var match;
			while ((match = blockRegex.exec(response)) !== null) {
				var body = match[1].trim();
				var call = this._parseToolCallBody(body);
				if (call) {
					call.id = 'webmcp-' + Date.now() + '-' + toolCalls.length;
					toolCalls.push(call);
				}
				cleanText = cleanText.replace(match[0], '');
			}

			// Fallback: look for bare function calls outside code blocks
			// e.g. copilot_set_tags(tags=["a","b"])
			if (!toolCalls.length) {
				var bareFnRegex = /(copilot_\w+)\(([^)]*?)\)/g;
				var bareMatch;
				while ((bareMatch = bareFnRegex.exec(response)) !== null) {
					var call2 = this._parseFunctionCall(bareMatch[1], bareMatch[2]);
					if (call2) {
						call2.id = 'webmcp-' + Date.now() + '-' + toolCalls.length;
						toolCalls.push(call2);
					}
					cleanText = cleanText.replace(bareMatch[0], '');
				}
			}

			cleanText = cleanText.trim();

			// ── Smart fallback: Nano often modifies content as plain text instead
			// of emitting a tool_call. Detect echoed blocks and auto-apply:
			//   ≥3 blocks → copilot_replace_content (full rewrite)
			//   1-2 blocks → copilot_update_block / copilot_insert_block (partial edit) ──
			if (!toolCalls.length && cleanText) {
				var echoBlocks = this._parseBlockEcho(cleanText);

				if (echoBlocks.length >= 3) {
					// Full content rewrite.
					var html = this._blocksToHtml(echoBlocks);
					if (html) {
						toolCalls.push({
							id: 'webmcp-auto-' + Date.now(),
							name: 'copilot_replace_content',
							arguments: { content: html },
						});
						dbg('[StifLi WebMCP] Auto-detected full content echo (' + echoBlocks.length + ' blocks), created copilot_replace_content');
						cleanText = null;
					}
				} else if (echoBlocks.length >= 1) {
					// Partial edit: 1-2 blocks. Determine the total block count
					// from the context we injected into the prompt.
					var totalBlocks = this._getCurrentBlockCount();

					for (var bi = 0; bi < echoBlocks.length; bi++) {
						var eb = echoBlocks[bi];
						var blockContent = this._mdToHtml(eb.content);
						// Strip code fences.
						blockContent = blockContent.replace(/^```\s*/, '').replace(/```\s*$/, '').trim();
						if (!blockContent) continue;
						// Wrap in <p> if no HTML tags.
						if (blockContent.indexOf('<') === -1) {
							blockContent = '<p>' + blockContent + '</p>';
						}

						if (eb.index >= totalBlocks) {
							// New block — insert at the end.
							toolCalls.push({
								id: 'webmcp-auto-' + Date.now() + '-' + bi,
								name: 'copilot_insert_block',
								arguments: { content: blockContent },
							});
							dbg('[StifLi WebMCP] Auto-detected new block [' + eb.index + '], created copilot_insert_block');
						} else {
							// Existing block — update in place.
							toolCalls.push({
								id: 'webmcp-auto-' + Date.now() + '-' + bi,
								name: 'copilot_update_block',
								arguments: { block_index: eb.index, content: blockContent },
							});
							dbg('[StifLi WebMCP] Auto-detected modified block [' + eb.index + '], created copilot_update_block');
						}
					}
					if (toolCalls.length) { cleanText = null; }
				}
			}

			dbg('[StifLi WebMCP] Parsed result — text:', cleanText, '| tool_calls:', toolCalls);

			return {
				text: cleanText || null,
				tool_calls: toolCalls.length ? toolCalls : null,
			};
		},

		/**
		 * Parse the body of a tool_call/tool_code block.
		 * Handles JSON format: {"name": "x", "arguments": {...}}
		 * and function-call format: tool_name(key=val, ...)
		 */
		_parseToolCallBody: function (body) {
			// Try JSON first (preferred format).
			try {
				var parsed = JSON.parse(body);
				if (parsed.name) {
					return { name: parsed.name, arguments: parsed.arguments || {} };
				}
			} catch (_) {
				// Not valid JSON — try function call syntax.
			}

			// Function call: copilot_set_tags(tags=["a","b"])
			var fnMatch = body.match(/^(\w+)\((.*)?\)$/s);
			if (fnMatch) {
				return this._parseFunctionCall(fnMatch[1], fnMatch[2] || '');
			}

			return null;
		},

		/**
		 * Parse Python-style function call arguments into {name, arguments}.
		 * e.g. name="copilot_set_tags", argsStr='tags=["a","b","c"]'
		 */
		_parseFunctionCall: function (name, argsStr) {
			if (!name) { return null; }

			var args = {};
			argsStr = (argsStr || '').trim();
			if (!argsStr) {
				return { name: name, arguments: args };
			}

			// Try to parse as key=value pairs.
			// Split on commas that are NOT inside brackets/quotes.
			var pairs = [];
			var depth = 0;
			var inStr = false;
			var strCh = '';
			var current = '';
			for (var i = 0; i < argsStr.length; i++) {
				var c = argsStr[i];
				if (inStr) {
					current += c;
					if (c === strCh && argsStr[i - 1] !== '\\') { inStr = false; }
				} else if (c === '"' || c === "'") {
					inStr = true;
					strCh = c;
					current += c;
				} else if (c === '[' || c === '{' || c === '(') {
					depth++;
					current += c;
				} else if (c === ']' || c === '}' || c === ')') {
					depth--;
					current += c;
				} else if (c === ',' && depth === 0) {
					pairs.push(current.trim());
					current = '';
				} else {
					current += c;
				}
			}
			if (current.trim()) { pairs.push(current.trim()); }

			for (var j = 0; j < pairs.length; j++) {
				var eqIdx = pairs[j].indexOf('=');
				if (eqIdx > 0) {
					var key = pairs[j].substring(0, eqIdx).trim();
					var val = pairs[j].substring(eqIdx + 1).trim();
					// Try to parse the value as JSON (arrays, numbers, booleans).
					try {
						args[key] = JSON.parse(val);
					} catch (_) {
						// Strip surrounding quotes if present.
						if ((val.charAt(0) === '"' && val.charAt(val.length - 1) === '"') ||
							(val.charAt(0) === "'" && val.charAt(val.length - 1) === "'")) {
							val = val.substring(1, val.length - 1);
						}
						args[key] = val;
					}
				}
			}

			return { name: name, arguments: args };
		},

		/**
		 * Parse [N] core/TYPE: content lines from Nano's echoed response.
		 */
		_parseBlockEcho: function (text) {
			var blocks = [];
			var lines = text.split('\n');
			for (var i = 0; i < lines.length; i++) {
				// Match both plain "[6] core/paragraph:" and bold "**[6] core/paragraph:**"
				var m = lines[i].match(/^\s*\*{0,2}\[(\d+)\]\s*core\/(\w+):\*{0,2}\s*(.*)/);
				if (m) {
					blocks.push({ index: parseInt(m[1], 10), type: m[2], content: m[3].trim() });
				}
			}
			return blocks;
		},

		/**
		 * Get the current block count from the Gutenberg editor.
		 * Used to determine if a block index is existing or new.
		 */
		_getCurrentBlockCount: function () {
			try {
				if (typeof wp !== 'undefined' && wp.data) {
					var blockEditor = wp.data.select('core/block-editor');
					if (blockEditor) {
						var blocks = blockEditor.getBlocks();
						if (blocks) return blocks.length;
					}
				}
			} catch (_) {}
			return 0;
		},

		/**
		 * Reconstruct HTML from parsed blocks.
		 */
		_blocksToHtml: function (blocks) {
			var parts = [];
			for (var i = 0; i < blocks.length; i++) {
				var raw = blocks[i].content;
				if (!raw) continue;

				// Strip code fences that the context may have included.
				raw = raw.replace(/^```\s*/, '').replace(/```\s*$/, '').trim();
				if (!raw) continue;

				// Skip excerpt references.
				if (/^\[Excerpt:/.test(raw)) continue;

				// Horizontal rule.
				if (raw === '---') { parts.push('<hr>'); continue; }

				// Convert markdown formatting.
				raw = this._mdToHtml(raw);

				// Headings: # … through ######.
				var hMatch = raw.match(/^(#{1,6})\s+(.*)/);
				if (hMatch) {
					var lvl = hMatch[1].length;
					parts.push('<h' + lvl + '>' + hMatch[2] + '</h' + lvl + '>');
					continue;
				}

				// List items: "*   item1*   item2" on one line (context format).
				if (/^\*\s{1,}/.test(raw)) {
					var items = raw.split(/\*\s{2,}/);
					parts.push('<ul>');
					for (var j = 0; j < items.length; j++) {
						var item = items[j].replace(/^\*\s*/, '').trim();
						if (item) parts.push('<li>' + item + '</li>');
					}
					parts.push('</ul>');
					continue;
				}

				// Regular paragraph.
				parts.push('<p>' + raw + '</p>');
			}
			return parts.length ? parts.join('\n') : null;
		},

		/**
		 * Convert basic Markdown formatting to HTML.
		 * Handles **bold** → <strong>, keeping it simple and safe.
		 */
		_mdToHtml: function (text) {
			// Bold: **text** → <strong>text</strong>
			text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
			return text;
		},

		/**
		 * Reset the session (e.g. when context changes).
		 */
		resetSession: function () {
			if (this._session) {
				try { this._session.destroy(); } catch (_) {}
				this._session = null;
			}
		},
	};

	// Detect Prompt API availability.
	if (typeof LanguageModel !== 'undefined') {
		webmcpApi.available = true;
	}

	window.SflmcpWebMCP = webmcpApi;

	/**
	 * Build tool definitions filtered by settings.
	 */
	function buildFilteredToolDefs() {
		var defs = buildToolDefinitions();
		var disabled = (window.sflmcpCopilot && window.sflmcpCopilot.webmcpDisabledTools) || [];
		if (disabled.length) {
			defs = defs.filter(function (d) {
				return disabled.indexOf(d.name) === -1;
			});
		}
		return defs;
	}

	function init() {
		waitForModelContext(function (mc) {
			// Editor bridge may not be ready yet (Gutenberg lazy-loads).
			if (window.SflmcpCopilotEditor && window.SflmcpCopilotEditor.editorType()) {
				registerTools(mc);
				webmcpApi._toolDefs = buildFilteredToolDefs();
			} else {
				// Wait for Gutenberg to initialize.
				var editorWait = setInterval(function () {
					if (window.SflmcpCopilotEditor && window.SflmcpCopilotEditor.editorType()) {
						clearInterval(editorWait);
						registerTools(mc);
						webmcpApi._toolDefs = buildFilteredToolDefs();
					}
				}, 300);

				// Give up after 15 s.
				setTimeout(function () {
					clearInterval(editorWait);
				}, 15000);
			}
		});
	}

	// Fire on DOMContentLoaded if not already loaded.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
