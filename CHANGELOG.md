# Changelog

All notable changes to the Peptide Search AI plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.3.0] - Unreleased

### Added
- **Browsable Peptide Directory**: `[peptide_directory]` shortcode renders a card-based grid with category filtering, search, pagination, and detail modal.
- **Category Taxonomy**: `peptide_category` taxonomy on the peptide CPT with 8 pre-populated terms (Tissue Repair, Lipid Metabolism, Aging Research, Dermatological, Metabolic, Growth Hormone, Immunology, Endocrine).
- **9 Extended Meta Fields**: half-life, stability, solubility, vial size, storage (lyophilized/reconstituted), typical dose, cycle parameters, amino acid count.
- **REST API Endpoint**: `GET /wp-json/peptide-search-ai/v1/compounds` with search, category, pagination, and basic/full field sets for cross-plugin data access.
- **Detail Modal**: Accessible overlay triggered from directory cards showing full compound data, copyable sequence, PubChem link, and "View Full Page" link.
- **Integration Hooks**: `psa_after_peptide_detail` action, `psa_directory_card_extras` and `psa_rest_compound_data` filters for Peptide Community plugin.
- **Category Auto-Assignment**: AI generator now assigns peptide_category taxonomy terms during content generation.
- **Admin Data Tools**: "Migrate Existing Peptides to Categories" button and "Re-enrich Existing Peptides" button with background job queuing.
- **Dark Mode Support**: Directory styles work with `data-theme="dark"` via CSS custom properties.
- **Category Badge Colors**: Exported as CSS custom properties (`--psa-cat-*`) for reuse by other plugins.
- New file: `includes/class-psa-directory.php` — directory REST endpoint and shortcode.
- New file: `assets/js/psa-directory.js` — directory grid, filters, modal (vanilla JS).
- New file: `assets/css/psa-directory.css` — directory styles with responsive breakpoints.
- PHPUnit tests for PostType and Directory classes.

### Changed
- Updated AI generation prompt to request extended fields (half-life, stability, storage, etc.).
- `class-psa-post-type.php` now includes taxonomy registration and EXTENDED_META_FIELDS constant.
- `class-psa-template.php` refactored into smaller private methods; shows extended research data on single peptide pages.
- `class-psa-admin.php` includes Data Tools section with migration and re-enrichment actions.
- `peptide-search-ai.php` registers new directory class, taxonomy, and conditional asset loading.
- Version bump to 4.3.0.

## [4.2.0] - Unreleased

### Added
- PHPUnit test execution in CI pipeline across PHP 7.4, 8.1, 8.3.
- HTTP health check and REST API smoke test after deployment.
- `declare(strict_types=1)` and typed parameters/return types on all class files.
- Consistent 3-question preamble docblocks on all classes (what / who calls / dependencies).
- `@see` cross-references at top of related class files.
- New `PSA_OpenRouter` class — extracted API communication layer from `PSA_AI_Generator`.
- New `PSA_KB_Builder` class — extracted KB article builder from `PSA_AI_Generator`.
- Internationalization (i18n) for all frontend JS strings via `wp_localize_script`.
- This CHANGELOG file.

### Changed
- `PSA_AI_Generator` now delegates to `PSA_OpenRouter` and `PSA_KB_Builder` (reduced from 827 to ~470 lines).
- Updated test suite to call `PSA_OpenRouter::parse_response()` instead of removed private method.
- Updated `ARCHITECTURE.md` and `CONVENTIONS.md` to reflect new class structure.
- Bumped minimum PHP recommendation to 7.4+ with strict type enforcement.

### Removed
- Deprecated `PSA_Error` class and `class-psa-error.php` (unused, no callers).

## [4.1.0] - 2026-04-10

### Added
- AES-256-CBC encryption for API keys stored in the database (`PSA_Encryption` class).
- Cost/token tracking with `wp_psa_api_logs` custom table (`PSA_Cost_Tracker` class).
- Monthly budget system with hard-stop enforcement.
- Dry-run mode for cost estimation before API calls.
- Admin UI for API usage summary and recent call logs.
- Monthly budget configuration field in settings.

### Fixed
- PHPCS `PreparedSQL` errors in cost tracker queries.

## [4.0.0] - 2026-04-10

### Security
- **SEC-001 CRITICAL:** Removed spoofable `X-Forwarded-For` / `X-Real-IP` from IP detection; now trusts only `CF-Connecting-IP` + `REMOTE_ADDR`.
- **SEC-004 HIGH:** Added peptide name input validation with character whitelist and prompt injection blocking.
- **SEC-006 HIGH:** Added missing `return` after `wp_send_json_success` in pending retry max-retries block.
- **SEC-007 MEDIUM:** KB articles now respect the `auto_publish` setting instead of always publishing.
- **SEC-009 MEDIUM:** Replaced hardcoded page ID 73 with configurable `kb_page_id` setting.
- **SEC-010 MEDIUM:** Fixed cache invalidation transient name pattern (added underscore prefix).
- **SEC-011 MEDIUM:** Skipped `sleep()` during WP-Cron to avoid blocking PHP workers on rate limit retry.
- **SEC-012 MEDIUM:** Added global daily generation cap (50/day) to prevent cost overruns.
- **SEC-013 LOW:** Added `@deprecated` notice to unused `PSA_Error` class.

### Added
- PHPUnit test infrastructure with `bootstrap.php` and 20 unit tests across 2 test files.
- `ARCHITECTURE.md` and `CONVENTIONS.md` documentation.

### Fixed
- JavaScript typos breaking form submit and network error handling.
- CSS missing semicolons.
- PHPCS compliance: short ternaries, array formatting, Yoda conditions, tab indentation.

## [3.0.0] - 2026-04-09

### Added
- Dual-layer rate limiting (transient + object cache) for AJAX and REST endpoints.
- Cache generation counter for O(1) cache invalidation instead of expensive DELETE queries.
- PubChem data enrichment with molecular weight, formula, SMILES, and InChI.
- Background generation via WP-Cron (non-blocking).
- Retry logic with configurable max attempts for stale pending peptides.
- REST API endpoint (`/wp-json/peptides/v1/search`) for headless access.
- Multiple shortcode instances on the same page via `data-psa-instance` attribute.

### Changed
- Search results cached with generation counter for fast invalidation.
- Batch post/meta priming to avoid N+1 queries in search results.

## [2.0.0] - 2026-04-08

### Added
- AI-powered peptide name validation (lightweight model before full generation).
- OpenRouter API integration for multi-model support.
- Custom Post Type (`peptide`) with 15+ meta fields.
- Admin settings page for API key, model selection, and behavior configuration.
- Single peptide page template with quick-facts table and source badges.
- Echo Knowledge Base article auto-creation from generated content.
- CSS design system with `.psa-*` namespace and BEM-inspired structure.

## [1.0.0] - 2026-04-07

### Added
- Initial release.
- Basic peptide search shortcode.
- AJAX-powered search with debounced input.
- Responsive mobile layout (600px breakpoint).
