# StifLi Flex MCP Tool Catalog

Document for website copy, documentation, and tutorials. It lists the native and modular tools detected in the code, explains how each one is defined, and what it is used for.

## How to read this catalog

Tool types:

- **READ**: queries information and usually does not modify the site.
- **SENSITIVE READ**: queries sensitive information, private data, technical status, or external network data. It requires extra care and usually asks for confirmation.
- **WRITE**: creates, updates, deletes, runs actions, generates media, or changes settings. It requires confirmation and the proper capability.
- **DYNAMIC**: created from Custom Tools or WordPress Abilities. It has no fixed name until the user imports or creates it.

Scope notes:

- The public plugin message talks about **117+ base tools** across WordPress, WooCommerce, and Core. The current code also includes more modules: SEO, forms, snippets, changelog, multimedia, integrations, and dynamic abilities.
- WooCommerce must be active to execute `wc_*` tools. They can still be configured even when WooCommerce is inactive.
- Plugin-specific tools, such as ACF, Yoast, WPForms, Gravity Forms, or Forminator, require the corresponding plugin to be installed and active.
- `ability_*` and `custom_*` tools are dynamic and depend on what the administrator imports or creates.

## Capabilities and security

Tool execution respects WordPress capabilities:

- Posts/pages: `edit_posts`, `delete_posts`, and related caps.
- Media: `upload_files`.
- Options/settings: `manage_options`.
- WooCommerce: `edit_products`, `edit_shop_orders`, `manage_woocommerce`, and related caps.
- Snippets: depend on the snippet provider plugin that is installed.
- Rollback/changelog: typically `manage_options`.

Recommendation for the website:

- Present StifLi Flex MCP as a **least-privilege** system: profiles, toggles, OAuth, confirmations, and rollback help reduce operational risk.

## Core

### `mcp_ping` — READ

Definition: checks connectivity with the MCP server. It returns the current GMT time, basic site information, and optional lightweight diagnostics.

What it is for:

- Verifying that the MCP client is connected.
- Testing authentication and JSON-RPC routing.
- Diagnosing basic problems before using real tools.

Example Use Cases & Sample Prompts

Example: connection check before troubleshooting.
Prompt: "Run an MCP ping with lightweight diagnostics so I can confirm the server is reachable and the REST endpoint is responding."

## WordPress: posts

### `wp_get_posts` — READ

Definition: lists posts with filters and optional enrichments such as author, featured media, taxonomies, and pagination.

What it is for:

- Reviewing recent entries.
- Finding content by status, category, tag, or date.
- Giving an AI agent context before editing or summarizing.

Example Use Cases & Sample Prompts

Example: editorial content discovery.
Prompt: "List the latest 10 published blog posts, include the author and featured image, and show pagination metadata."

### `wp_get_post` — READ

Definition: gets one post by ID, with optional author, featured media, and taxonomy enrichments.

What it is for:

- Reviewing one specific entry.
- Giving the AI full context before optimizing content.

Example Use Cases & Sample Prompts

Example: inspecting a draft before revision.
Prompt: "Get post ID 245 with its author, featured image, and taxonomies so I can review it before making changes."

### `wp_create_post` — WRITE

Definition: creates a post. It accepts title, content, status, type, excerpt, author, featured image, metadata, categories, and taxonomies.

What it is for:

- Creating drafts or published entries from AI.
- Generating editorial or programmatic content.

Example Use Cases & Sample Prompts

Example: AI-written blog post.
Prompt: "Write a 500-word blog post about WordPress security tips, categorize it under Security, and save it as a draft."

### `wp_update_post` — WRITE

Definition: updates a post by ID using fields compatible with `wp_update_post()`. It can also change the featured image.

What it is for:

- Editing title, content, status, excerpt, slug, author, or taxonomies.
- Applying supervised bulk optimizations.

Example Use Cases & Sample Prompts

Example: refreshing an outdated article.
Prompt: "Update post ID 245 with a clearer title, rewrite the introduction for 2026, and keep it in draft for review."

### `wp_delete_post` — WRITE

Definition: deletes a post by ID.

What it is for:

- Moving content to trash or deleting it depending on arguments.
- Cleaning up generated or duplicated content.

Example Use Cases & Sample Prompts

Example: cleaning up duplicate content.
Prompt: "Delete post ID 312 because it is a duplicate of the canonical article."

### `wp_set_featured_image` — WRITE

Definition: assigns or removes the featured image of a post. If `attachment_id` is `0`, it removes the image.

What it is for:

- Automating featured images after uploading or generating media.
- Fixing posts that are missing thumbnails.

Example Use Cases & Sample Prompts

Example: attaching a generated hero image.
Prompt: "Set attachment ID 981 as the featured image for post ID 245."

## WordPress: pages

### `wp_get_pages` — READ

Definition: lists pages with filters for status, search, limit, offset, order, and order field.

What it is for:

- Auditing existing pages.
- Finding a page before updating it.

Example Use Cases & Sample Prompts

Example: locating landing pages.
Prompt: "List all published pages ordered by title so I can find the current landing pages."

### `wp_create_page` — WRITE

Definition: creates a page with title, content, status, author, parent, menu order, and metadata.

What it is for:

- Creating documentation pages, landing pages, or drafts.
- Generating site structure from AI.

Example Use Cases & Sample Prompts

Example: creating a new support page.
Prompt: "Create a draft page titled Shipping Policy with a clear support-friendly structure and add it under the Help parent page."

### `wp_update_page` — WRITE

Definition: updates a page by ID.

What it is for:

- Editing static content.
- Reorganizing page hierarchies or changing `menu_order`.

Example Use Cases & Sample Prompts

Example: updating a legal page.
Prompt: "Update page ID 88 to include our new return policy section and move it higher in the menu order."

### `wp_delete_page` — WRITE

Definition: deletes a page by ID. It can bypass trash with `force=true`.

What it is for:

- Removing temporary or outdated pages.

Example Use Cases & Sample Prompts

Example: removing a campaign page.
Prompt: "Delete page ID 154 because the seasonal campaign has ended."

## WordPress: comments

### `wp_get_comments` — READ

Definition: lists comments with filters by post, status, search, dates, limit, offset, and pagination.

What it is for:

- Moderating comments.
- Summarizing reader or customer feedback.

Example Use Cases & Sample Prompts

Example: moderation queue review.
Prompt: "List the latest 25 pending comments so I can review what needs moderation today."

### `wp_create_comment` — WRITE

Definition: creates a comment. It requires `post_id` and `comment_content`.

What it is for:

- Replying to comments from an authorized agent.
- Creating notes or programmatic replies.

Example Use Cases & Sample Prompts

Example: posting an editorial reply.
Prompt: "Add a comment to post ID 245 thanking readers for their feedback and inviting them to subscribe."

### `wp_update_comment` — WRITE

Definition: updates a comment by `comment_ID` with a fields object.

What it is for:

- Approving, editing, or changing comment status.

Example Use Cases & Sample Prompts

Example: approving a legitimate comment.
Prompt: "Approve comment ID 991 and correct the formatting in the message if needed."

### `wp_delete_comment` — WRITE

Definition: deletes a comment by `comment_ID`, with an optional `force` flag.

What it is for:

- Assisted moderation.
- Removing spam or problematic comments.

Example Use Cases & Sample Prompts

Example: spam cleanup.
Prompt: "Delete comment ID 991 because it is obvious spam."

## WordPress: users and user meta

### `wp_get_users` — READ

Definition: lists users with basic fields: ID, login, display name, and roles. It can optionally include registration date, avatar, post count, and pagination.

What it is for:

- Viewing users and roles.
- Auditing authors or site accounts.

Example Use Cases & Sample Prompts

Example: author audit.
Prompt: "List all editor and author accounts with their display names and post counts."

### `wp_get_user_meta` — SENSITIVE READ

Definition: gets user metadata by `user_id` and optional `meta_key`.

What it is for:

- Diagnosing profile data.
- Reviewing integrations that store preferences or permissions in user meta.

Example Use Cases & Sample Prompts

Example: diagnosing a profile issue.
Prompt: "Get the user meta for user ID 14 so I can inspect the profile-related fields that might be causing a login preference issue."

### `wp_update_user_meta` — WRITE

Definition: updates a user meta value by `user_id`, `meta_key`, and `meta_value`.

What it is for:

- Adjusting user preferences or custom fields.

Example Use Cases & Sample Prompts

Example: updating an internal profile flag.
Prompt: "Set user meta preferred_language to en_US for user ID 14."

### `wp_delete_user_meta` — WRITE

Definition: deletes user metadata by `user_id` and `meta_key`.

What it is for:

- Cleaning up obsolete or incorrect user data.

Example Use Cases & Sample Prompts

Example: removing stale settings.
Prompt: "Delete the onboarding_completed meta key for user ID 14 because we are resetting the onboarding flow."

Compliance note:

- The tools for creating, updating, or deleting users were removed for WordPress.org compliance.

## WordPress: plugins and themes

### `wp_list_plugins` — READ

Definition: lists installed plugins with name and version.

What it is for:

- Diagnosing the environment.
- Seeing dependencies before using integration tools.

Example Use Cases & Sample Prompts

Example: environment inventory.
Prompt: "List all installed plugins with their versions so I can verify which integrations are available."

### `wp_get_themes` — READ

Definition: lists installed themes.

What it is for:

- Diagnosing the visual and technical state of the site.
- Preparing support or audits.

Example Use Cases & Sample Prompts

Example: theme audit.
Prompt: "List installed themes and identify which one appears to be the active production theme."

Compliance note:

- The tools for installing, activating, or deactivating plugins and themes were removed.

## WordPress: media

### `wp_get_media` — READ

Definition: lists media library attachments with limit and offset.

What it is for:

- Finding existing images or files.
- Preparing asset selection for posts.

Example Use Cases & Sample Prompts

Example: image library review.
Prompt: "List the latest 20 media items so I can choose a banner image for the homepage."

### `wp_get_media_item` — READ

Definition: gets media item details by ID.

What it is for:

- Viewing the URL, metadata, and attachment data.

Example Use Cases & Sample Prompts

Example: checking an attachment before reuse.
Prompt: "Get media item ID 981 so I can inspect its URL and metadata before attaching it to a post."

### `wp_upload_image_from_url` — WRITE

Definition: downloads an image from a public URL and creates a media attachment.

What it is for:

- Importing external images.
- Preparing featured images from a remote source.

Example Use Cases & Sample Prompts

Example: importing a partner logo.
Prompt: "Download this public image URL into the media library and keep the attachment ready for a new partner page."

### `wp_upload_image` — WRITE

Definition: uploads an image from base64 or a data URL and creates a media attachment.

What it is for:

- Saving AI-generated images.
- Integrating workflows where the image arrives as base64.

Example Use Cases & Sample Prompts

Example: storing a generated asset.
Prompt: "Upload this base64 image to the media library and title it Summer Campaign Hero."

### `wp_update_media_item` — WRITE

Definition: updates attachment metadata such as title, content, and excerpt.

What it is for:

- Improving titles, captions, descriptions, and alt text.

Example Use Cases & Sample Prompts

Example: improving accessibility metadata.
Prompt: "Update media item ID 981 with a clearer title, caption, and descriptive alt text for accessibility."

### `wp_delete_media_item` — WRITE

Definition: deletes a media item by ID. It can permanently delete it with `force=true`.

What it is for:

- Cleaning unused files.
- Reverting incorrect generations or uploads.

Example Use Cases & Sample Prompts

Example: removing an incorrect upload.
Prompt: "Delete media item ID 981 because it was uploaded by mistake."

## WordPress: taxonomies, categories, and tags

### `wp_get_taxonomies` — READ

Definition: lists registered taxonomies and returns `slug`, `name`, and `label` for each item.

What it is for:

- Discovering custom taxonomies.
- Preparing term operations.

Example Use Cases & Sample Prompts

Example: plugin schema discovery.
Prompt: "List all registered taxonomies and include slug, name, and label so I can see which custom content structures are available."

### `wp_get_terms` — READ

Definition: lists terms from a taxonomy.

What it is for:

- Querying categories, tags, or custom taxonomies.

Example Use Cases & Sample Prompts

Example: browsing a custom taxonomy.
Prompt: "List all terms in the product_brand taxonomy so I can review the brand structure."

### `wp_create_term` — WRITE

Definition: creates a term in any registered taxonomy.

What it is for:

- Creating category, tag, or custom taxonomy terms.

Example Use Cases & Sample Prompts

Example: adding a new taxonomy option.
Prompt: "Create a new term called Enterprise in the customer_segment taxonomy."

### `wp_update_term` — WRITE

Definition: updates a term in any taxonomy.

What it is for:

- Renaming or changing slug, description, or hierarchy.

Example Use Cases & Sample Prompts

Example: correcting a taxonomy label.
Prompt: "Rename term ID 77 in the category taxonomy to Newsroom and update its description."

### `wp_delete_term` — WRITE

Definition: deletes a term by `term_id` and taxonomy.

What it is for:

- Cleaning duplicated or obsolete terms.

Example Use Cases & Sample Prompts

Example: removing an obsolete category.
Prompt: "Delete term ID 77 from the category taxonomy because we no longer use it."

### `wp_get_term_meta` — SENSITIVE READ

Definition: gets term metadata and redacts values that look like secrets. Output is structured as `{term_id, key, value}` when `meta_key` is provided, or `{term_id, meta}` when it is not.

What it is for:

- Diagnosing taxonomies enriched by plugins.

Example Use Cases & Sample Prompts

Example: debugging custom term fields.
Prompt: "Get term meta for term ID 77 and return both the structured output and redacted values so I can inspect plugin data safely."

### `wp_update_term_meta` — WRITE

Definition: updates term metadata.

What it is for:

- Adjusting extra fields on categories or custom taxonomies.

Example Use Cases & Sample Prompts

Example: updating a category color.
Prompt: "Set the color meta value to #0057b8 for term ID 77 in the category taxonomy."

### `wp_delete_term_meta` — WRITE

Definition: deletes term metadata.

What it is for:

- Cleaning data attached to terms.

Example Use Cases & Sample Prompts

Example: removing deprecated metadata.
Prompt: "Delete the legacy_icon meta key from category term ID 77."

### `wp_get_categories` — READ

Definition: lists categories with empty, search, and limit filters.

What it is for:

- Reviewing editorial structure.

Example Use Cases & Sample Prompts

Example: category planning.
Prompt: "List all categories, including empty ones, so I can review the editorial taxonomy."

### `wp_create_category` — WRITE

Definition: creates a category.

What it is for:

- Adding new editorial sections.

Example Use Cases & Sample Prompts

Example: creating a new content pillar.
Prompt: "Create a new category called Case Studies with a short description for B2B content."

### `wp_update_category` — WRITE

Definition: updates a category by `term_id`.

What it is for:

- Renaming or reorganizing categories.

Example Use Cases & Sample Prompts

Example: category cleanup.
Prompt: "Update category term ID 12 and rename it from Tips to Expert Tips."

### `wp_delete_category` — WRITE

Definition: deletes a category.

What it is for:

- Cleaning old categories.

Example Use Cases & Sample Prompts

Example: retiring an old category.
Prompt: "Delete category term ID 12 because we merged it into another editorial section."

### `wp_get_tags` — READ

Definition: lists tags with filters.

What it is for:

- Reviewing the tag system.

Example Use Cases & Sample Prompts

Example: tag audit.
Prompt: "List all tags that match the word security so I can consolidate duplicates."

### `wp_create_tag` — WRITE

Definition: creates a tag.

What it is for:

- Adding tags for new content.

Example Use Cases & Sample Prompts

Example: campaign tagging.
Prompt: "Create a new tag called Black Friday 2026."

### `wp_update_tag` — WRITE

Definition: updates a tag by `term_id`.

What it is for:

- Correcting names, slugs, or descriptions.

Example Use Cases & Sample Prompts

Example: normalizing a tag name.
Prompt: "Rename tag term ID 34 from e-commerce to ecommerce and update its slug accordingly."

### `wp_delete_tag` — WRITE

Definition: deletes a tag.

What it is for:

- Cleaning duplicated or obsolete tags.

Example Use Cases & Sample Prompts

Example: removing a duplicate tag.
Prompt: "Delete tag term ID 34 because it duplicates an existing normalized tag."

## WordPress: menus

### `wp_get_nav_menus` — READ

Definition: lists navigation menus.

What it is for:

- Seeing available menus before editing them.

Example Use Cases & Sample Prompts

Example: navigation audit.
Prompt: "List all navigation menus so I can identify which one controls the main header."

### `wp_get_menus` — READ

Definition: alias of `wp_get_nav_menus`.

What it is for:

- Compatibility with prompts or clients that ask for menus.

Example Use Cases & Sample Prompts

Example: compatibility lookup.
Prompt: "Get all menus available on the site."

### `wp_get_menu` — READ

Definition: gets a specific menu with its items, by `menu_id` or `menu_location`.

What it is for:

- Auditing navigation structure.
- Preparing a reorder.

Example Use Cases & Sample Prompts

Example: header review.
Prompt: "Get the menu assigned to the primary location so I can review its current items and order."

### `wp_create_nav_menu` — WRITE

Definition: creates a navigation menu.

What it is for:

- Creating new menus from AI.

Example Use Cases & Sample Prompts

Example: building a seasonal menu.
Prompt: "Create a new navigation menu called Holiday Campaign Links."

### `wp_add_nav_menu_item` — WRITE

Definition: adds an item to a menu. It supports `post_type`, `custom`, and `taxonomy` item types.

What it is for:

- Adding links to pages, posts, categories, or external URLs.

Example Use Cases & Sample Prompts

Example: adding a landing page to the menu.
Prompt: "Add the About Us page to menu ID 6 and place it near the top."

### `wp_update_nav_menu_item` — WRITE

Definition: updates a navigation item.

What it is for:

- Changing title, destination, parent, or order of an item.

Example Use Cases & Sample Prompts

Example: renaming a navigation label.
Prompt: "Update menu item ID 244 and change its label from Blog to Resources."

### `wp_delete_nav_menu_item` — WRITE

Definition: deletes a menu item.

What it is for:

- Cleaning navigation.

Example Use Cases & Sample Prompts

Example: removing an outdated link.
Prompt: "Delete menu item ID 244 because it points to a retired campaign page."

### `wp_delete_nav_menu` — WRITE

Definition: deletes a menu by `menu_id`.

What it is for:

- Removing old or duplicate menus.

Example Use Cases & Sample Prompts

Example: menu cleanup.
Prompt: "Delete menu ID 6 because it was only used for a finished microsite."

### `wp_reorder_menu_items` — WRITE

Definition: reorders menu items in one operation. It records the previous order for rollback.

What it is for:

- Reorganizing navigation in an auditable way.

Example Use Cases & Sample Prompts

Example: prioritizing a sales page.
Prompt: "Reorder the main navigation menu so Pricing appears before Blog and keep the existing hierarchy intact."

## WordPress: options, settings, and metadata

### `wp_get_option` — SENSITIVE READ

Definition: gets the value of a WordPress option.

What it is for:

- Diagnosing configuration.
- Reviewing specific options without going into the database.

Example Use Cases & Sample Prompts

Example: checking a site setting.
Prompt: "Get the value of the blogname option so I can confirm the current site title."

### `wp_get_plugin_settings` — SENSITIVE READ

Definition: inspects plugin-related options by slug or prefixes and recursively redacts secrets.

What it is for:

- Plugin support.
- Auditing settings without exposing keys.

Example Use Cases & Sample Prompts

Example: support review for a plugin.
Prompt: "Inspect the settings related to the WooCommerce plugin and redact any secret keys in the output."

### `wp_update_option` — WRITE

Definition: updates a WordPress option.

What it is for:

- Changing global settings with elevated permissions.

Example Use Cases & Sample Prompts

Example: updating a general site setting.
Prompt: "Update the blogdescription option to our new company tagline."

### `wp_get_post_meta` — SENSITIVE READ

Definition: gets post metadata.

What it is for:

- Diagnosing custom fields.
- Reading plugin data attached to a post.

Example Use Cases & Sample Prompts

Example: checking SEO-related fields.
Prompt: "Get the post meta for post ID 245 so I can inspect custom SEO and workflow fields."

### `wp_update_post_meta` — WRITE

Definition: updates post metadata.

What it is for:

- Adjusting custom fields, SEO fields, flags, or technical data.

Example Use Cases & Sample Prompts

Example: setting a workflow flag.
Prompt: "Update the editorial_reviewed meta key to yes for post ID 245."

### `wp_delete_post_meta` — WRITE

Definition: deletes post metadata.

What it is for:

- Cleaning incorrect or obsolete data.

Example Use Cases & Sample Prompts

Example: removing legacy meta.
Prompt: "Delete the old_campaign_code meta key from post ID 245."

### `wp_get_settings` — SENSITIVE READ

Definition: gets WordPress settings. It can receive a `keys` array to limit the query.

What it is for:

- Diagnosing general configuration.

Example Use Cases & Sample Prompts

Example: targeted settings audit.
Prompt: "Get the WordPress settings for blogname, admin_email, timezone_string, and posts_per_page."

### `wp_update_settings` — WRITE

Definition: updates multiple WordPress settings.

What it is for:

- Controlled administrative changes.

Example Use Cases & Sample Prompts

Example: updating core site preferences.
Prompt: "Update the site timezone, date format, and time format to match our new editorial standards."

## WordPress: revisions, post types, and health

### `wp_get_post_revisions` — READ

Definition: gets revisions for a post.

What it is for:

- Comparing content history.
- Preparing a restore.

Example Use Cases & Sample Prompts

Example: reviewing content history.
Prompt: "List the revisions for post ID 245 so I can compare the last few changes."

### `wp_restore_post_revision` — WRITE

Definition: restores a post to a revision by `revision_id`.

What it is for:

- Recovering previous content.

Example Use Cases & Sample Prompts

Example: undoing a bad edit.
Prompt: "Restore post ID 245 to revision ID 14502 because the latest update removed key content."

### `wp_get_post_types` — READ

Definition: lists registered post types with labels, capabilities, and visibility.

What it is for:

- Discovering custom post types.
- Preparing automations for custom content types.

Example Use Cases & Sample Prompts

Example: custom content discovery.
Prompt: "List all registered post types with their labels so I can see what custom content models exist on this site."

### `wp_get_site_health` — SENSITIVE READ

Definition: runs a site audit with depth levels 0, 1, or 2.

What it is for:

- Technical diagnostics.
- Support and development.

Example Use Cases & Sample Prompts

Example: pre-launch technical audit.
Prompt: "Run a site health audit at depth 2 and summarize the most important warnings."

## WordPress: utilities, search, and network

### `search` — READ

Definition: searches posts with filters for type, author, category, tag, status, date, order, and pagination.

What it is for:

- Finding content from natural-language intent.
- Preparing editorial operations.

Example Use Cases & Sample Prompts

Example: topic search across content.
Prompt: "Search published posts related to WooCommerce shipping and return the most relevant matches."

### `fetch` — SENSITIVE READ

Definition: fetches a URL using the WordPress HTTP API. It supports method, query params, headers, timeout, redirects, HEAD mode, text extraction, and byte limits.

What it is for:

- Querying external APIs.
- Checking a URL or reading remote content.

Example Use Cases & Sample Prompts

Example: checking an external endpoint.
Prompt: "Fetch the headers for this public API endpoint and tell me whether it responds successfully."

### `wp_generate_image` — WRITE

Definition: generates an image with AI and saves it as a WordPress attachment. It uses the provider configured in Multimedia.

What it is for:

- Creating featured images.
- Generating assets for posts, products, or campaigns.

Example Use Cases & Sample Prompts

Example: creating a blog hero image.
Prompt: "Generate a clean illustrated hero image for an article about WordPress backups and save it to the media library."

### `wp_generate_video` — WRITE

Definition: generates a video with AI using Google Veo or OpenAI Sora and saves it as a media attachment. It is asynchronous and can take several minutes.

What it is for:

- Creating videos for content, ecommerce, or social channels.
- Automating multimedia from prompts.

Example Use Cases & Sample Prompts

Example: producing a product teaser.
Prompt: "Generate a short promotional video for our new product launch and save it in the media library for review."

## WordPress: SEO and editorial integrations

### `wp_rm_get_head` — SENSITIVE READ

Definition: gets the rendered SEO head HTML for a URL through Rank Math Headless CMS Support.

What it is for:

- Auditing real SEO output.
- Reviewing generated meta tags.

Example Use Cases & Sample Prompts

Example: SEO output audit.
Prompt: "Get the rendered SEO head for the /pricing page so I can inspect the title, description, and canonical tags."

### `wp_rm_get_post_seo` — SENSITIVE READ

Definition: gets Rank Math SEO fields for a post.

What it is for:

- Reviewing title, description, and SEO metadata.

Example Use Cases & Sample Prompts

Example: reviewing current SEO values.
Prompt: "Get the Rank Math SEO data for post ID 245 so I can see the current title and meta description."

### `wp_rm_update_post_seo` — WRITE

Definition: updates Rank Math SEO fields for a post.

What it is for:

- Optimizing SEO with AI.

Example Use Cases & Sample Prompts

Example: improving search snippets.
Prompt: "Update the Rank Math title and meta description for post ID 245 to improve click-through rate."

### `yoast_get_meta` — SENSITIVE READ

Definition: gets Yoast SEO metadata for a post: title, description, focus keyword, canonical, robots, Open Graph, and Twitter values.

What it is for:

- Auditing Yoast SEO data.

Example Use Cases & Sample Prompts

Example: Yoast metadata review.
Prompt: "Get the Yoast SEO metadata for post ID 245 and summarize the current optimization status."

### `yoast_set_meta` — WRITE

Definition: sets Yoast SEO metadata for a post.

What it is for:

- Generating SEO titles and descriptions.
- Adjusting indexing or social previews.

Example Use Cases & Sample Prompts

Example: updating social previews.
Prompt: "Set a stronger SEO title, meta description, and Open Graph description for post ID 245."

### `yoast_reindex` — SENSITIVE READ

Definition: clears Yoast indexables cache for one post or for all posts.

What it is for:

- Forcing SEO data to rebuild.

Example Use Cases & Sample Prompts

Example: rebuilding stale SEO data.
Prompt: "Reindex Yoast data for post ID 245 after its SEO metadata was updated."

### `acf_get_field_groups` — SENSITIVE READ

Definition: lists ACF field groups with keys, titles, and location rules.

What it is for:

- Understanding custom field structure.

Example Use Cases & Sample Prompts

Example: mapping a content model.
Prompt: "List all ACF field groups and show where each group is assigned."

### `acf_get_fields` — SENSITIVE READ

Definition: gets ACF values for a post, including keys, names, types, and values.

What it is for:

- Reading structured content.

Example Use Cases & Sample Prompts

Example: inspecting a structured page.
Prompt: "Get all ACF fields for post ID 245 so I can inspect its structured content sections."

### `acf_update_field` — WRITE

Definition: updates an ACF field by name or key on a post.

What it is for:

- Filling structured content with AI.

Example Use Cases & Sample Prompts

Example: updating a hero block field.
Prompt: "Update the hero_subtitle ACF field for post ID 245 with a clearer benefit-driven message."

## WordPress: forms

### `wpforms_list_forms` — SENSITIVE READ

Definition: lists WPForms forms with ID, title, status, and creation date.

What it is for:

- Discovering available forms.

Example Use Cases & Sample Prompts

Example: form inventory.
Prompt: "List all WPForms forms so I can identify which contact and lead forms are active."

### `wpforms_get_entries` — SENSITIVE READ

Definition: gets entries from a WPForms form.

What it is for:

- Analyzing leads or requests.

Example Use Cases & Sample Prompts

Example: reviewing recent inquiries.
Prompt: "Get the latest entries from WPForms form ID 3 and summarize the most common support requests."

### `gf_list_forms` — SENSITIVE READ

Definition: lists Gravity Forms forms with ID, title, description, and entry count.

What it is for:

- Discovering Gravity Forms assets.

Example Use Cases & Sample Prompts

Example: Gravity Forms inventory.
Prompt: "List all Gravity Forms forms with their entry counts so I can see which forms are active."

### `gf_get_entries` — SENSITIVE READ

Definition: gets entries from a Gravity Forms form.

What it is for:

- Reviewing submissions and customer data.

Example Use Cases & Sample Prompts

Example: lead analysis.
Prompt: "Get the latest entries from Gravity Form ID 7 and summarize the top lead sources."

### `gf_update_entry` — WRITE

Definition: updates a Gravity Forms entry: status, read flag, starred flag, or field values.

What it is for:

- Marking leads as processed.
- Correcting data or classifications.

Example Use Cases & Sample Prompts

Example: processing a sales lead.
Prompt: "Mark Gravity Forms entry ID 1024 as read and starred because the sales team is already handling it."

### `forminator_list_forms` — SENSITIVE READ

Definition: lists Forminator forms, polls, and quizzes.

What it is for:

- Discovering Forminator assets.

Example Use Cases & Sample Prompts

Example: Forminator audit.
Prompt: "List all Forminator forms, polls, and quizzes so I can review what is currently published."

### `forminator_get_entries` — SENSITIVE READ

Definition: gets entries from a Forminator form.

What it is for:

- Analyzing data captured by forms.

Example Use Cases & Sample Prompts

Example: response review.
Prompt: "Get the latest entries from Forminator form ID 5 and summarize the most common answers."

## Snippets

This area requires WPCode, Code Snippets, or Woody Code Snippets.

### `snippet_list` — SENSITIVE READ

Definition: lists snippets with limit and offset. It returns ID, title, active state, code type, and location.

What it is for:

- Auditing installed snippets.
- Seeing what custom code exists.

Example Use Cases & Sample Prompts

Example: custom code audit.
Prompt: "List all snippets with their active status so I can review what custom code is running on the site."

### `snippet_get` — SENSITIVE READ

Definition: gets a complete snippet by ID, including the code.

What it is for:

- Reviewing code before modifying it.

Example Use Cases & Sample Prompts

Example: reviewing a risky snippet.
Prompt: "Get snippet ID 19 so I can inspect the full code before changing anything."

### `snippet_create` — WRITE

Definition: creates a new snippet, inactive by default. It does not execute the code; it only stores it.

What it is for:

- Preparing snippets for human review.

Example Use Cases & Sample Prompts

Example: staging a safe code change.
Prompt: "Create a new inactive PHP snippet that redirects logged-in users to the dashboard after login."

### `snippet_update` — WRITE

Definition: updates an existing snippet. It only modifies the provided fields and does not execute code.

What it is for:

- Correcting existing snippets.

Example Use Cases & Sample Prompts

Example: adjusting custom code safely.
Prompt: "Update snippet ID 19 to change the admin notice text and leave it inactive for review."

### `snippet_delete` — WRITE

Definition: deletes a snippet by ID.

What it is for:

- Cleaning obsolete snippets.

Example Use Cases & Sample Prompts

Example: removing dead code.
Prompt: "Delete snippet ID 19 because it is no longer used and has been replaced elsewhere."

### `snippet_activate` — WRITE

Definition: activates a snippet by ID.

What it is for:

- Turning on an automation or custom code adjustment.

Example Use Cases & Sample Prompts

Example: enabling reviewed code.
Prompt: "Activate snippet ID 19 now that the code review has been completed."

### `snippet_deactivate` — WRITE

Definition: deactivates a snippet by ID.

What it is for:

- Turning off custom code without deleting it.

Example Use Cases & Sample Prompts

Example: emergency rollback.
Prompt: "Deactivate snippet ID 19 immediately because it is causing an issue on the frontend."

## Changelog and rollback

### `mcp_get_changelog` — SENSITIVE READ

Definition: gets the MCP changelog or audit log with filters and pagination.

What it is for:

- Auditing actions performed by AI.
- Viewing changes by tool, operation, object, date, or status.

Example Use Cases & Sample Prompts

Example: reviewing recent AI activity.
Prompt: "Get the latest changelog entries from today and group them by tool so I can audit what happened."

### `mcp_get_change_detail` — SENSITIVE READ

Definition: gets complete detail for one changelog entry, including before and after state plus arguments.

What it is for:

- Investigating exactly what changed.

Example Use Cases & Sample Prompts

Example: inspecting a suspicious change.
Prompt: "Get the full detail for changelog entry 455 so I can compare the before and after state."

### `mcp_rollback_change` — WRITE

Definition: reverts a specific entry back to its previous state.

What it is for:

- Undoing an individual change.

Example Use Cases & Sample Prompts

Example: undoing a bad edit.
Prompt: "Rollback changelog entry 455 because the content update was incorrect."

### `mcp_redo_change` — WRITE

Definition: reapplies an entry that was previously rolled back.

What it is for:

- Re-doing changes when a rollback was unnecessary.

Example Use Cases & Sample Prompts

Example: restoring an approved update.
Prompt: "Redo changelog entry 455 because the rollback was done by mistake."

### `mcp_rollback_session` — WRITE

Definition: reverts every change from a session in reverse order.

What it is for:

- Undoing a whole conversation or automation run.

Example Use Cases & Sample Prompts

Example: reverting a full automation run.
Prompt: "Rollback the entire MCP session from this morning because the bulk update touched the wrong content."

## WooCommerce: products and variations

### `wc_get_products` — READ

Definition: lists products with filters for status, category, tag, search, pagination, order, and type. It can include images, categories, attributes, variation counts, and pagination metadata.

What it is for:

- Querying the catalog.
- Finding products by status, category, type, or search.

Example Use Cases & Sample Prompts

Example: catalog audit.
Prompt: "List the latest 20 published products, include images and categories, and show pagination info."

### `wc_create_product` — WRITE

Definition: creates a WooCommerce product with name, type, prices, description, SKU, stock, categories, tags, images, and status.

What it is for:

- Creating simple, variable, grouped, or external products.

Example Use Cases & Sample Prompts

Example: adding a new catalog item.
Prompt: "Create a draft simple product called Wireless Desk Lamp with price, SKU, stock quantity, and a short description."

### `wc_update_product` — WRITE

Definition: updates a product by ID.

What it is for:

- Changing price, stock, description, SKU, categories, tags, or status.

Example Use Cases & Sample Prompts

Example: correcting a product record.
Prompt: "Update product ID 410 to change the regular price, improve the short description, and set the stock quantity to 45."

### `wc_delete_product` — WRITE

Definition: deletes a product by ID. `force=true` permanently deletes it.

What it is for:

- Removing old or incorrect products.

Example Use Cases & Sample Prompts

Example: removing a test product.
Prompt: "Delete product ID 410 because it was only created for internal testing."

### `wc_batch_update_products` — WRITE

Definition: updates multiple products in a batch.

What it is for:

- Applying bulk changes to stock, prices, or status.

Example Use Cases & Sample Prompts

Example: seasonal catalog update.
Prompt: "Batch update these product IDs to set their sale price and mark them as featured for the campaign."

### `wc_get_product_variations` — READ

Definition: gets variations for a variable product and returns normalized rows with `id`, `product_id`, SKU, pricing, stock, stock status, and attributes.

What it is for:

- Reviewing sizes, colors, SKUs, or prices per variation.
- Verifying variation-to-parent product mapping.

Example Use Cases & Sample Prompts

Example: variation review.
Prompt: "Get all variations for product ID 410 and show each SKU, attribute set, price, and stock level."

### `wc_get_variation` — READ

Definition: gets one variation by `product_id` and `variation_id` with ownership validation.

What it is for:

- Inspecting one variation quickly without listing all variations.
- Verifying that a variation belongs to the expected variable product.

Example Use Cases & Sample Prompts

Example: single variation validation.
Prompt: "Get variation ID 9901 for product ID 410 and confirm its parent, attributes, price, and stock status."

### `wc_create_product_variation` — WRITE

Definition: creates a product variation.

What it is for:

- Adding new combinations to a variable product.

Example Use Cases & Sample Prompts

Example: adding a new size.
Prompt: "Create a new variation for product ID 410 with size XL, SKU LAMP-XL, and its own price and stock."

### `wc_update_product_variation` — WRITE

Definition: updates a variation using `product_id` and `variation_id`, with ownership validation before saving changes.

What it is for:

- Changing variation price, attributes, or stock.
- Preventing accidental cross-product variation updates.

Example Use Cases & Sample Prompts

Example: correcting variation inventory.
Prompt: "Update variation ID 9901 for product ID 410 and set stock quantity to 12 with sale price 24.99."

### `wc_batch_update_variations` — WRITE

Definition: batch updates multiple variations of one variable product in a single operation.

What it is for:

- Applying bulk price or stock changes across many variations.
- Running controlled updates and later rollback with a second batch payload.

Example Use Cases & Sample Prompts

Example: seasonal size update.
Prompt: "Batch update these variation IDs for product ID 410 to adjust sale prices and stock quantities for the campaign."

### `wc_delete_product_variation` — WRITE

Definition: deletes a variation using `product_id` and `variation_id`, with ownership validation.

What it is for:

- Cleaning obsolete variations.
- Avoiding deletion of variations linked to a different parent product.

Example Use Cases & Sample Prompts

Example: removing a discontinued option.
Prompt: "Delete variation ID 9901 for product ID 410 because that color-size combination is discontinued."

### `wc_get_product_attributes` — READ

Definition: lists global WooCommerce product attributes (attribute taxonomies).

What it is for:

- Auditing global attribute definitions such as color, size, or material.
- Discovering attribute IDs before assigning them to products.

Example Use Cases & Sample Prompts

Example: attribute catalog audit.
Prompt: "List global WooCommerce product attributes with their taxonomy names and IDs."

### `wc_get_attribute_terms` — READ

Definition: lists terms for a WooCommerce product attribute taxonomy using `attribute_id` or `attribute_slug`.

What it is for:

- Reviewing existing values for a global attribute.
- Preparing product attribute assignment workflows.

Example Use Cases & Sample Prompts

Example: size term review.
Prompt: "List terms for the size attribute using attribute_slug pa_size so I can review available options."

### `wc_create_product_attribute` — WRITE

Definition: creates a global WooCommerce product attribute taxonomy.

What it is for:

- Adding new global attributes for catalog structure.
- Standardizing product data before creating many variations.

Example Use Cases & Sample Prompts

Example: creating a new attribute family.
Prompt: "Create a global product attribute called Finish with slug finish, type select, and archives enabled."

### `wc_set_product_attributes` — WRITE

Definition: sets product attributes on a product, supporting both global taxonomy attributes and local/custom attributes.

What it is for:

- Assigning variation-ready attribute sets to variable products.
- Updating attribute visibility and variation flags in one action.

Example Use Cases & Sample Prompts

Example: assigning color options.
Prompt: "Set product ID 410 attributes to include global Color options Red and Blue as variation-enabled and visible."

## WooCommerce: categories, tags, and reviews

### `wc_get_product_categories` — READ

Definition: lists product categories.

What it is for:

- Reviewing the catalog structure.

Example Use Cases & Sample Prompts

Example: taxonomy review.
Prompt: "List all product categories so I can review the current ecommerce catalog structure."

### `wc_create_product_category` — WRITE

Definition: creates a product category.

What it is for:

- Organizing the ecommerce catalog.

Example Use Cases & Sample Prompts

Example: adding a new collection.
Prompt: "Create a new product category called Home Office Essentials with a short description."

### `wc_update_product_category` — WRITE

Definition: updates a product category.

What it is for:

- Renaming or changing description or slug.

Example Use Cases & Sample Prompts

Example: category rename.
Prompt: "Update product category ID 22 and rename it from Lighting to Smart Lighting."

### `wc_delete_product_category` — WRITE

Definition: deletes a product category.

What it is for:

- Cleaning old categories.

Example Use Cases & Sample Prompts

Example: removing an unused category.
Prompt: "Delete product category ID 22 because it has been merged into another category."

### `wc_get_product_tags` — READ

Definition: lists product tags.

What it is for:

- Reviewing catalog classification.

Example Use Cases & Sample Prompts

Example: product tag audit.
Prompt: "List all product tags that contain the word summer so I can consolidate campaign tags."

### `wc_create_product_tag` — WRITE

Definition: creates a product tag.

What it is for:

- Tagging products for campaigns, attributes, or collections.

Example Use Cases & Sample Prompts

Example: campaign launch tagging.
Prompt: "Create a new product tag called New Arrival 2026."

### `wc_update_product_tag` — WRITE

Definition: updates a product tag.

What it is for:

- Correcting names or slugs.

Example Use Cases & Sample Prompts

Example: tag normalization.
Prompt: "Rename product tag ID 64 from eco-friendly to eco friendly and adjust the slug."

### `wc_delete_product_tag` — WRITE

Definition: deletes a product tag.

What it is for:

- Cleaning unused tags.

Example Use Cases & Sample Prompts

Example: removing duplicate classification.
Prompt: "Delete product tag ID 64 because it duplicates an existing catalog tag."

### `wc_get_product_reviews` — READ

Definition: lists product reviews, optionally filtered by `product_id`.

What it is for:

- Analyzing reviews and satisfaction.

Example Use Cases & Sample Prompts

Example: review analysis.
Prompt: "Get recent reviews for product ID 410 and summarize the most common praise and complaints."

### `wc_create_product_review` — WRITE

Definition: creates a product review.

What it is for:

- Importing reviews or creating controlled internal review data.

Example Use Cases & Sample Prompts

Example: importing trusted review content.
Prompt: "Create a 5-star review for product ID 410 using this approved testimonial text from our migration file."

### `wc_update_product_review` — WRITE

Definition: updates a product review.

What it is for:

- Moderating content, status, or rating.

Example Use Cases & Sample Prompts

Example: correcting an imported review.
Prompt: "Update review ID 830 and fix the rating and approved status to match our moderation policy."

### `wc_delete_product_review` — WRITE

Definition: deletes a product review.

What it is for:

- Moderating reviews.

Example Use Cases & Sample Prompts

Example: removing abusive content.
Prompt: "Delete review ID 830 because it contains abusive language and violates moderation rules."

## WooCommerce: stock

### `wc_update_stock` — WRITE

Definition: updates the stock quantity of a product with `set`, `increase`, or `decrease` operations.

What it is for:

- Adjusting inventory from AI or automations.

Example Use Cases & Sample Prompts

Example: receiving new inventory.
Prompt: "Increase the stock of product ID 410 by 25 units after the latest warehouse delivery."

### `wc_get_low_stock_products` — READ

Definition: gets products below a stock threshold.

What it is for:

- Inventory alerts.
- Restocking reports.

Example Use Cases & Sample Prompts

Example: replenishment planning.
Prompt: "List all products with stock below 5 units so I can prepare a restocking plan."

### `wc_set_stock_status` — WRITE

Definition: changes stock status to `instock`, `outofstock`, or `onbackorder`.

What it is for:

- Marking products as sold out or available.

Example Use Cases & Sample Prompts

Example: forcing backorder availability.
Prompt: "Set product ID 410 to onbackorder because the next shipment is already scheduled."

## WooCommerce: orders, notes, and refunds

### `wc_get_orders` — SENSITIVE READ

Definition: lists orders with filters for status, customer, product, dates, pagination, and optional enrichments for items, totals, and shipping.

What it is for:

- Reviewing sales.
- Creating reports and analysis.
- Preparing actions on orders.

Example Use Cases & Sample Prompts

Example: operations review.
Prompt: "List the latest 20 processing orders and include their items, totals, and shipping data."

### `wc_create_order` — WRITE

Definition: creates an order with customer, billing, shipping, line items, shipping lines, fees, coupons, status, and payment method.

What it is for:

- Creating manual orders from an authorized AI workflow.

Example Use Cases & Sample Prompts

Example: internal sales order.
Prompt: "Create a manual WooCommerce order for this customer with the listed products, shipping details, and bank transfer as the payment method."

### `wc_update_order` — WRITE

Definition: updates an order by ID.

What it is for:

- Changing status, billing or shipping data, or line items.

Example Use Cases & Sample Prompts

Example: correcting shipping details.
Prompt: "Update order ID 5501 with the corrected shipping address and keep the order in processing status."

### `wc_delete_order` — WRITE

Definition: deletes an order by ID. `force=true` permanently deletes it.

What it is for:

- Cleaning test or incorrect orders.

Example Use Cases & Sample Prompts

Example: removing a test transaction.
Prompt: "Delete order ID 5501 because it was created for QA testing only."

### `wc_batch_update_orders` — WRITE

Definition: updates multiple orders in a batch.

What it is for:

- Applying bulk status or metadata changes.

Example Use Cases & Sample Prompts

Example: marking a batch as completed.
Prompt: "Batch update these order IDs and set them all to completed after shipment confirmation."

### `wc_get_order_notes` — SENSITIVE READ

Definition: gets notes for an order.

What it is for:

- Reviewing order operational history or communication.

Example Use Cases & Sample Prompts

Example: support investigation.
Prompt: "Get all notes for order ID 5501 so I can review the support and fulfillment history."

### `wc_create_order_note` — WRITE

Definition: creates a note on an order. It can be a customer note or an internal note.

What it is for:

- Recording actions or messages.

Example Use Cases & Sample Prompts

Example: leaving a fulfillment note.
Prompt: "Add an internal note to order ID 5501 saying the replacement item was shipped today."

### `wc_delete_order_note` — WRITE

Definition: deletes an order note.

What it is for:

- Cleaning incorrect notes.

Example Use Cases & Sample Prompts

Example: removing an accidental note.
Prompt: "Delete order note ID 889 for order ID 5501 because it was added by mistake."

### `wc_create_refund` — WRITE

Definition: creates a refund for an order, with amount, reason, lines, and optional inventory handling.

What it is for:

- Managing returns with traceability.

Example Use Cases & Sample Prompts

Example: partial customer refund.
Prompt: "Create a partial refund for order ID 5501 for the damaged item and restock the returned quantity."

### `wc_get_refunds` — READ

Definition: gets refunds for one order or all refunds.

What it is for:

- Auditing reimbursements.

Example Use Cases & Sample Prompts

Example: refund review.
Prompt: "List all refunds from the last 30 days so I can analyze refund volume and reasons."

### `wc_delete_refund` — WRITE

Definition: deletes a refund by ID.

What it is for:

- Correcting refunds that were created by mistake.

Example Use Cases & Sample Prompts

Example: refund correction.
Prompt: "Delete refund ID 211 because it was created on the wrong order."

## WooCommerce: coupons

### `wc_get_coupons` — READ

Definition: lists coupons with filters for code, status, limit, offset, and order.

What it is for:

- Auditing active promotions.
- Auditing draft, scheduled, private, or trashed coupons.

Example Use Cases & Sample Prompts

Example: promotion inventory.
Prompt: "List coupons with status any ordered by date so I can review active, draft, and trashed promotions."

### `wc_get_coupon` — READ

Definition: gets one WooCommerce coupon by ID.

What it is for:

- Inspecting one coupon before updating or deleting it.
- Validating discount type, amount, expiry, and status.

Example Use Cases & Sample Prompts

Example: coupon inspection.
Prompt: "Get coupon ID 73 and show its code, discount type, amount, expiry date, and status."

### `wc_get_coupon_count` — READ

Definition: counts coupons by status (`publish`, `draft`, `pending`, `private`, `future`, `trash`) or returns totals for all statuses.

What it is for:

- Monitoring promotion inventory by lifecycle state.
- Verifying trash behavior after logical deletes.

Example Use Cases & Sample Prompts

Example: trash verification.
Prompt: "Count coupons with status trash so I can confirm whether a logical delete moved the coupon to trash."

### `wc_create_coupon` — WRITE

Definition: creates a coupon with code, discount type, amount, expiration, usage limit, individual use, and included or excluded products.

What it is for:

- Creating promotional campaigns.

Example Use Cases & Sample Prompts

Example: launching a seasonal discount.
Prompt: "Create a coupon code SPRING15 for 15 percent off, expiring in 30 days, for individual use only."

### `wc_update_coupon` — WRITE

Definition: updates a coupon by ID.

What it is for:

- Changing discounts, expiration, or limits.

Example Use Cases & Sample Prompts

Example: extending a campaign.
Prompt: "Update coupon ID 73 and extend its expiration date by two more weeks."

### `wc_delete_coupon` — WRITE

Definition: deletes a coupon by ID. With `force=false`, it attempts to move the coupon to trash first; with `force=true`, it permanently deletes it.

What it is for:

- Retiring promotions with optional trash-first behavior.
- Permanently deleting coupons when required.

Example Use Cases & Sample Prompts

Example: retiring an old coupon.
Prompt: "Delete coupon ID 73 with force=false so it is moved to trash when available."

### `wc_empty_coupon_trash` — WRITE

Definition: permanently deletes all coupons currently in trash.

What it is for:

- Performing final cleanup after review or retention periods.
- Keeping the coupon table free of obsolete trashed entries.

Example Use Cases & Sample Prompts

Example: coupon trash cleanup.
Prompt: "Empty WooCommerce coupon trash and return how many trashed coupons were permanently removed."

Compliance note:

- WooCommerce customer tools (`wc_get_customers`, `wc_create_customer`, `wc_update_customer`, `wc_delete_customer`) were removed.

## WooCommerce: reports

### `wc_get_sales_report` — READ

Definition: gets a sales report for a date range.

What it is for:

- Daily, weekly, or monthly summaries.

Example Use Cases & Sample Prompts

Example: weekly business review.
Prompt: "Get the sales report for the last 7 days and summarize revenue, order count, and average order value."

### `wc_get_top_sellers_report` — READ

Definition: gets the top-selling products.

What it is for:

- Analyzing the catalog and trends.

Example Use Cases & Sample Prompts

Example: product performance review.
Prompt: "Show me the top-selling WooCommerce products for the last 30 days."

## WooCommerce: taxes

### `wc_get_tax_classes` — READ

Definition: lists tax classes.

What it is for:

- Reviewing tax configuration.

Example Use Cases & Sample Prompts

Example: fiscal setup audit.
Prompt: "List all WooCommerce tax classes so I can review our current tax setup."

### `wc_get_tax_rates` — READ

Definition: lists tax rates, optionally filtered by class.

What it is for:

- Auditing rates by country, state, or class.

Example Use Cases & Sample Prompts

Example: regional tax review.
Prompt: "List tax rates for the reduced-rate class so I can verify the current percentages."

### `wc_create_tax_rate` — WRITE

Definition: creates a tax rate.

What it is for:

- Configuring taxes programmatically.

Example Use Cases & Sample Prompts

Example: adding a new region.
Prompt: "Create a new 8.25 percent tax rate for the target region in WooCommerce."

### `wc_update_tax_rate` — WRITE

Definition: updates a tax rate by ID.

What it is for:

- Correcting percentage, name, or priority.

Example Use Cases & Sample Prompts

Example: adjusting a tax percentage.
Prompt: "Update tax rate ID 14 and change it to 8.5 percent effective immediately."

### `wc_delete_tax_rate` — WRITE

Definition: deletes a tax rate by ID.

What it is for:

- Cleaning old fiscal configuration.

Example Use Cases & Sample Prompts

Example: removing obsolete tax logic.
Prompt: "Delete tax rate ID 14 because that jurisdiction is no longer configured in the store."

## WooCommerce: shipping

### `wc_get_shipping_zones` — READ

Definition: lists shipping zones.

What it is for:

- Auditing coverage and regions.

Example Use Cases & Sample Prompts

Example: shipping coverage review.
Prompt: "List all shipping zones so I can review the current regional delivery setup."

### `wc_get_shipping_zone_methods` — READ

Definition: gets the shipping methods for a zone.

What it is for:

- Reviewing active methods by zone.

Example Use Cases & Sample Prompts

Example: operational shipping check.
Prompt: "Get the shipping methods for zone ID 3 and show which methods are enabled."

### `wc_create_shipping_zone` — WRITE

Definition: creates a shipping zone.

What it is for:

- Configuring logistics.

Example Use Cases & Sample Prompts

Example: adding a new delivery region.
Prompt: "Create a new shipping zone for Canada East with the relevant regions included."

### `wc_update_shipping_zone` — WRITE

Definition: updates a zone.

What it is for:

- Changing zone name or order.

Example Use Cases & Sample Prompts

Example: renaming a shipping region.
Prompt: "Update shipping zone ID 3 and rename it to EU Priority Shipping."

### `wc_delete_shipping_zone` — WRITE

Definition: deletes a shipping zone.

What it is for:

- Cleaning unused zones.

Example Use Cases & Sample Prompts

Example: retiring an old region.
Prompt: "Delete shipping zone ID 3 because that region is no longer served."

## WooCommerce: gateways, system, settings, and webhooks

### `wc_get_payment_gateways` — READ

Definition: lists payment gateways.

What it is for:

- Reviewing available and active payment methods.

Example Use Cases & Sample Prompts

Example: payments audit.
Prompt: "List all WooCommerce payment gateways and show which ones are currently enabled."

### `wc_update_payment_gateway` — WRITE

Definition: updates a gateway's settings by ID.

What it is for:

- Activating, deactivating, or changing the title or settings of a payment method.

Example Use Cases & Sample Prompts

Example: renaming a checkout method.
Prompt: "Update the bank transfer gateway title so it appears as Secure Bank Transfer at checkout."

### `wc_get_system_status` — SENSITIVE READ

Definition: gets WooCommerce technical status: environment, versions, database information, and active plugins.

What it is for:

- Technical support and diagnostics.

Example Use Cases & Sample Prompts

Example: ecommerce support check.
Prompt: "Get the WooCommerce system status and summarize any environment issues or outdated components."

### `wc_run_system_status_tool` — WRITE

Definition: runs system tools, such as clearing transients or removing orphaned variations.

What it is for:

- WooCommerce maintenance.

Example Use Cases & Sample Prompts

Example: maintenance cleanup.
Prompt: "Run the WooCommerce tool to clear expired transients and report what was cleaned."

### `wc_get_settings` — SENSITIVE READ

Definition: gets WooCommerce settings by group: general, products, tax, shipping, checkout, or account.

What it is for:

- Auditing store configuration.

Example Use Cases & Sample Prompts

Example: store configuration review.
Prompt: "Get the WooCommerce shipping settings so I can review the current delivery configuration."

### `wc_update_setting_option` — WRITE

Definition: updates a WooCommerce settings option.

What it is for:

- Making administrative store changes.

Example Use Cases & Sample Prompts

Example: updating store email settings.
Prompt: "Update the WooCommerce setting that controls the store notice text to match our current promotion."

### `wc_get_webhooks` — READ

Definition: lists WooCommerce webhooks.

What it is for:

- Seeing configured outbound integrations.

Example Use Cases & Sample Prompts

Example: integration inventory.
Prompt: "List all WooCommerce webhooks so I can review which external systems receive order events."

### `wc_create_webhook` — WRITE

Definition: creates a WooCommerce webhook with name, status, topic, and delivery URL.

What it is for:

- Integrating WooCommerce with external systems.

Example Use Cases & Sample Prompts

Example: connecting an order automation.
Prompt: "Create a WooCommerce webhook for order.created events that sends payloads to our integration endpoint."

### `wc_update_webhook` — WRITE

Definition: updates a webhook.

What it is for:

- Changing the URL, status, or name of an integration.

Example Use Cases & Sample Prompts

Example: moving an integration endpoint.
Prompt: "Update webhook ID 18 so it sends events to the new delivery URL and remains active."

### `wc_delete_webhook` — WRITE

Definition: deletes a webhook.

What it is for:

- Retiring old integrations.

Example Use Cases & Sample Prompts

Example: shutting down a legacy integration.
Prompt: "Delete webhook ID 18 because the old automation endpoint has been decommissioned."

## Plugin integrations

These tools are grouped under the Plugins tab when the corresponding plugin is installed and active, or when they belong to the integration catalog.

### All Sources Images — DYNAMIC

Expected tools: `ability_allsi_*`.

Definition: abilities discovered from the All Sources Images plugin.

What it is for:

- Searching stock images.
- Generating AI images.
- Inserting featured or inline images in posts.

Example Use Cases & Sample Prompts

Example: sourcing an article image from a plugin ability.
Prompt: "Use the All Sources Images ability to find a royalty-free hero image for a post about remote work."

### AiPatch Security Scanner — DYNAMIC

Expected tools: `ability_aipatch_*`.

Definition: abilities from the AiPatch security scanner.

What it is for:

- Security auditing.
- Vulnerability detection.
- AI-assisted remediation guidance.

Example Use Cases & Sample Prompts

Example: scanning for risk before deployment.
Prompt: "Run the AiPatch security scanning ability and summarize the most important vulnerabilities."

### Notification for Telegram — DYNAMIC

Expected tools: `ability_notification_for_telegram_*`.

Definition: abilities for sending notifications through Telegram.

What it is for:

- Alerts from automations.
- Notifications for orders, errors, or completed tasks.

Example Use Cases & Sample Prompts

Example: sending an operational alert.
Prompt: "Use the Telegram notification ability to send an alert when low-stock products are detected."

### WPCode, Code Snippets, and Woody Snippets

Related tools:

- `snippet_list`.
- `snippet_get`.
- `snippet_create`.
- `snippet_update`.
- `snippet_delete`.
- `snippet_activate`.
- `snippet_deactivate`.

What it is for:

- Managing custom code from AI with controls and confirmation.

Example Use Cases & Sample Prompts

Example: safe code lifecycle management.
Prompt: "Create a new inactive snippet for a custom redirect rule, then show me the code before activation."

### ACF

Related tools:

- `acf_get_field_groups`.
- `acf_get_fields`.
- `acf_update_field`.

What it is for:

- Reading and updating structured custom fields.

Example Use Cases & Sample Prompts

Example: updating structured landing page content.
Prompt: "Get the ACF fields for our homepage and update the hero subtitle to reflect the new campaign message."

### Yoast SEO

Related tools:

- `yoast_get_meta`.
- `yoast_set_meta`.
- `yoast_reindex`.

What it is for:

- Optimizing on-page SEO from AI.

Example Use Cases & Sample Prompts

Example: on-page SEO refresh.
Prompt: "Review the Yoast metadata for post ID 245, propose improvements, and then update the SEO title and description."

### Rank Math

Related tools:

- `wp_rm_get_head`.
- `wp_rm_get_post_seo`.
- `wp_rm_update_post_seo`.

What it is for:

- Reading and updating Rank Math SEO values.

Example Use Cases & Sample Prompts

Example: validating live SEO output.
Prompt: "Get the Rank Math SEO head for the product page and then update the post SEO fields if the metadata is weak."

### WPForms, Gravity Forms, and Forminator

Related tools:

- `wpforms_list_forms`.
- `wpforms_get_entries`.
- `gf_list_forms`.
- `gf_get_entries`.
- `gf_update_entry`.
- `forminator_list_forms`.
- `forminator_get_entries`.

What it is for:

- Analyzing leads, submissions, and forms.
- Marking entries or editing Gravity Forms data when appropriate.

Example Use Cases & Sample Prompts

Example: lead triage workflow.
Prompt: "List recent form submissions across our forms and mark the highest-priority sales leads in Gravity Forms."

## Dynamic tools: `custom_*`

Definition: tools created by the administrator from Custom Tools.

Required prefix:

- `custom_`

Types:

- HTTP `GET`, `POST`, `PUT`, `DELETE`.
- ACTION to execute WordPress hooks with `do_action()`.

What it is for:

- Connecting Zapier, Make, n8n, Slack, Discord, Jira, Notion, Twilio, CRMs, or private APIs.
- Exposing plugin actions without writing native code.
- Prototyping integrations.

Example Use Cases & Sample Prompts

Example: sending data to an external system.
Prompt: "Use the custom_send_slack_message tool to post a deployment notification to our operations channel."

Conceptual examples:

- `custom_create_jira_ticket`.
- `custom_send_slack_message`.
- `custom_clear_cache`.
- `custom_run_crm_webhook`.

## Dynamic tools: `ability_*`

Definition: tools imported from the WordPress Abilities API.

Format:

- One ability like `vendor/action-name` becomes `ability_vendor_action_name`.

What it is for:

- Exposing capabilities from modern plugins as MCP tools.
- Keeping descriptions and schemas provided by the original plugin.
- Building an extensible ecosystem without adding code to the StifLi Flex MCP core.

Example Use Cases & Sample Prompts

Example: using a plugin-provided capability.
Prompt: "Run the imported ability for the Telegram plugin to send a summary of today's completed orders."

## Tools removed for compliance

The code keeps notes about tools removed to comply with WordPress.org expectations.

Removed in WordPress:

- Create user.
- Update user.
- Delete user.
- Install plugin.
- Activate plugin.
- Deactivate plugin.
- Install theme.
- Switch theme.
- Direct option deletion tool `wp_delete_option`.

Removed in WooCommerce:

- `wc_get_customers`.
- `wc_create_customer`.
- `wc_update_customer`.
- `wc_delete_customer`.

Recommended message for public documentation:

- The plugin avoids sensitive user-management operations, plugin and theme installation, and direct option deletion in order to align with WordPress.org best practices and policies.

## Summary by use case

### Editorial content

Main tools:

- `wp_get_posts`, `wp_get_post`, `wp_create_post`, `wp_update_post`, `wp_delete_post`.
- `wp_get_pages`, `wp_create_page`, `wp_update_page`.
- `wp_set_featured_image`.
- `wp_generate_image`.
- SEO: `yoast_*`, `wp_rm_*`.

### Ecommerce

Main tools:

- `wc_get_products`, `wc_create_product`, `wc_update_product`.
- `wc_get_product_variations`, `wc_get_variation`, `wc_batch_update_variations`.
- `wc_get_product_attributes`, `wc_get_attribute_terms`, `wc_set_product_attributes`.
- `wc_get_orders`, `wc_update_order`, `wc_create_order_note`.
- `wc_update_stock`, `wc_get_low_stock_products`.
- `wc_get_coupons`, `wc_get_coupon_count`, `wc_create_coupon`, `wc_empty_coupon_trash`, `wc_get_sales_report`.

### Support and maintenance

Main tools:

- `mcp_ping`.
- `wp_get_site_health`.
- `wp_list_plugins`.
- `wp_get_themes`.
- `wc_get_system_status`.
- `fetch`.
- `mcp_get_changelog` and rollback.

### Automation and integrations

Main tools:

- `custom_*`.
- `ability_*`.
- WooCommerce webhooks.
- Notification for Telegram abilities.
- Forms and snippets.

### Operational security

Main tools:

- Profiles.
- READ and WRITE confirmations.
- Changelog.
- Individual rollback.
- Session rollback.
- Debug log.