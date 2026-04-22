=== Peptide Search AI ===
Contributors: terence
Tags: peptide, database, search, AI, science, research, openrouter
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 4.1.0
License: GPLv2 or later

Searchable peptide database with AI-powered auto-population. Visitors search for peptides — if one doesn't exist, it's automatically validated and created with comprehensive AI-generated scientific content in the background.

== Description ==

Peptide Search AI turns your WordPress site into an intelligent, self-growing peptide database.

**How it works:**

1. Visitors search for a peptide using the search form.
2. If found, results appear instantly with molecular data.
3. If not found, the plugin automatically validates the name using a lightweight AI call, creates a placeholder, and queues comprehensive content generation in the background via WP-Cron.
4. The visitor sees a "currently being added" message and can check back shortly.
5. Generated entries include: amino acid sequence, molecular weight/formula, mechanism of action, research applications, safety profile, dosage info, and references.
6. PubChem cross-referencing validates molecular data when available.

**Features:**

* AJAX search with instant results
* Automatic peptide name validation (lightweight AI check before full generation)
* Background content generation via WP-Cron (non-blocking)
* Custom Post Type with structured scientific fields
* AI content generation via OpenRouter — access hundreds of models (Gemini, Llama, DeepSeek, Mistral, Qwen, and more)
* Separate model configuration for validation (cheap/fast) and generation (full)
* PubChem PUG REST API integration for verified molecular data
* Rate limiting (10 requests/IP/hour) to prevent abuse
* Transient-based caching for validation and PubChem lookups
* Admin settings for API key, models, auto-publish behavior, PubChem toggle
* REST API endpoint for headless/external access (read-only)
* Conditional asset loading (only on pages with the shortcode or peptide singles)
* Source badges (AI-Generated / PubChem Verified / Manually Curated)
* Mobile-responsive design

== Installation ==

1. Upload the `peptide-search-ai` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings > Peptide Search AI
4. Enter your OpenRouter API key (get one at openrouter.ai/settings/keys)
5. Optionally set separate models for validation and generation
6. Configure auto-publish and PubChem settings
7. Add `[peptide_search]` to any page or post

== Shortcode ==

`[peptide_search]` — Displays the peptide search form.

Optional attributes:
* `placeholder` — Custom placeholder text for the search input.

Example: `[peptide_search placeholder="Search our peptide database..."]`

== REST API ==

Search endpoint: `GET /wp-json/peptides/v1/search?q=BPC-157`

Returns JSON with matching published peptides, their molecular data, and permalink URLs. This endpoint is read-only and does not trigger auto-generation.

