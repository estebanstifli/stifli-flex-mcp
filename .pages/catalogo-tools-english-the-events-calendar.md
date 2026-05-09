# StifLi Flex MCP - The Events Calendar Tools

This document includes only the The Events Calendar tools available in StifLi Flex MCP.

## WordPress: events (The Events Calendar)

This area requires The Events Calendar plugin to be installed and active.

### `wp_tec_list_events` — READ

Definition: lists events from The Events Calendar with filters such as search text, dates, status, venue, organizer, categories, tags, and pagination.

What it is for:

- Building event listings for editorial, support, or operations tasks.
- Filtering events by date ranges, publication state, or linked entities.

Example Use Cases & Sample Prompts

Example: listing upcoming events.
Prompt: "List events in the next 30 days, filter to published events, and include pagination metadata."

### `wp_tec_get_event` — READ

Definition: gets one event by ID with full details, including schedule, venue, organizers, taxonomies, and public links.

What it is for:

- Reviewing a specific event before editing or publishing changes.
- Verifying venue/organizer links and date details.

Example Use Cases & Sample Prompts

Example: validating a published event.
Prompt: "Get event ID 2466 and show all details so I can verify title, schedule, venue, organizers, and URL."

### `wp_tec_save_event` — WRITE

Definition: creates or updates an event in The Events Calendar. It supports title, description, dates, all-day mode, venue/organizer references, cost, URL, status, featured flag, and taxonomy references.

What it is for:

- Creating new events from AI workflows.
- Updating event content, schedule, and visibility.

Example Use Cases & Sample Prompts

Example: creating a campaign event.
Prompt: "Create a new event for June 15, 2026 from 18:00 to 20:00, set it as published, and attach the specified venue and organizer IDs."

### `wp_tec_list_entities` — READ

Definition: lists or retrieves The Events Calendar entities (`venue` or `organizer`) with search and pagination support.

What it is for:

- Discovering existing venues and organizers before assigning them to events.
- Looking up one specific venue or organizer by ID.

Example Use Cases & Sample Prompts

Example: preparing linked entities for event creation.
Prompt: "List organizers that match 'Maria' and return their IDs so I can link the right organizer to a new event."

### `wp_tec_save_entity` — WRITE

Definition: creates or updates a The Events Calendar entity (`venue` or `organizer`) with the fields relevant to each type.

What it is for:

- Creating missing venues or organizers during an event workflow.
- Maintaining venue and organizer records over time.

Example Use Cases & Sample Prompts

Example: creating a venue before event publishing.
Prompt: "Create a new venue called City Hall Auditorium with full address details and publish it."

### `wp_tec_trash_event` — WRITE

Definition: moves an event to trash, or permanently deletes it when force deletion is requested.

What it is for:

- Safely removing outdated events.
- Handling accidental publications with auditable rollback support.

Example Use Cases & Sample Prompts

Example: retiring an event.
Prompt: "Move event ID 2466 to trash because the campaign has ended."
