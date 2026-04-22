# Changelog

All notable changes to the Peptide Search AI plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.5.0] - 2026-04-22

### Changed (BREAKING)
- Dropped registration of the `peptide` custom post type and `peptide_category` taxonomy. These are now owned by Peptide Repo Core (PR Core) v0.2.0+, the canonical peptide data layer. Existing posts and term relationships are unaffected — they continue to work with PR Core's registration.
- PSA now has a hard dependency on PR Core ≥ 0.2.0. An admin notice appears if the dependency is not met; PSA features that operate on the `peptide` CPT (meta boxes, admin columns, directory widget, KB renderer, search widget, single-peptide template) degrade gracefully until PR Core is updated.
- `psa_activate()` no longer seeds default `peptide_category` terms — PR Core inherits the 8 existing terms via taxonomy-name keying in `wp_term_taxonomy`.
- `uninstall.php` no longer deletes `peptide` posts or `peptide_category` terms. Those entities are owned by PR Core as of this release; removing them on PSA uninstall would break cross-plugin contracts. PSA uninstall now only removes PSA's own options, transients, and the `wp_psa_api_logs` table.

### Added
- `PSA_Dependency_Check` class (`includes/class-psa-dependency-check.php`): verifies `PR_CORE_VERSION >= 0.2.0`, renders an admin notice when unmet, and is used as a boot-path gate by CPT-dependent PSA classes.
- PHPUnit coverage for `PSA_Dependency_Check::is_satisfied()` across undefined / 0.1.1 / 0.2.0 / 0.3.0 version scenarios plus a regression assertion that `PSA_Post_Type` no longer exposes `register_post_type` or `register_taxonomy` call paths.

### Removed
- `PSA_Post_Type::register_peptide_post_type()` method.
- `PSA_Post_Type::register_taxonomy()` method.
- `PSA_Post_Type::populate_default_categories()` method and its activation hook (seeding is a data-layer concern now owned by PR Core; the 8 existing terms remain in `wp_term_taxonomy`).
- Note: the `PSA_Post_Type::DEFAULT_CATEGORIES` constant is **retained** — PSA still consumes it as a slug→name reference list in `PSA_AI_Content::assign_category_term()` and the admin "Migrate Existing Peptides to Categories" button. Its role changed from "seed list" to "consumer lookup"; the value is unchanged.

### Unchanged
- Meta boxes (`psa_peptide_data`, `psa_extended_data`) and all `psa_*` meta keys on peptide posts.
- Directory shortcode `[peptide_directory]` and its `/wp-json/peptide-search-ai/v1/compounds` REST endpoint.
- Single-peptide template (`PSA_Template`) and KB article renderer (`PSA_KB_Builder`).
- Search widget, `/wp-json/peptides/v1/search` REST endpoint, and AJAX search handler.
- Cost tracker, API logs table schema, and the `psa_db_version` option.

### Migration notes
- No data migration required. The existing `peptide` posts and all `peptide_category` term assignments remain intact and functional — PR Core registers the same post-type name (`peptide`) and taxonomy name (`peptide_category`), so `wp_posts.post_type` and `wp_term_taxonomy.taxonomy` continue to resolve.
- After upgrading, ensure PR Core is at 0.2.0 or later — otherwise PSA surfaces an admin notice and degrades the peptide-dependent features until PR Core is updated.
- Deploy order (coordinated release): PR Core v0.2.0 ships first and restores the peptide detail pages on its own (PSA v4.4.3's existing `post_type_exists( 'peptide' )` guard no-ops its registration once PR Core claims the CPT). PSA v4.5.0 ships second as cleanup — no user-visible change at that point.

## [4.4.3] - 2026-04-14

### Fixed
- **Cost tracker $0.00 bug**: DeepSeek models on OpenRouter omit the `usage` object from API responses, causing all token counts and costs to log as zero. Added character-based token estimation fallback (~3.75 chars/token for scientific English) so spend is always tracked.

### Added
- `token_source` column in `psa_api_logs` table distinguishing measured (`api`) vs. estimated (`estimated`) token counts. Migrated via `dbDelta` on plugin activation.
- DeepSeek and Qwen model pricing in `PSA_Cost_Tracker::estimate_cost()`: `deepseek/deepseek-v3.2`, `deepseek/deepseek-chat`, `deepseek/deepseek-r1`, `qwen/qwen3.6-plus:free`.
- `PSA_Cost_Tracker::get_monthly_estimated_count()` method for querying how many API calls used estimates in a given month.
- Admin UI indicators for estimated data: amber warning icon on monthly summary ("includes estimates"), `~` prefix on estimated token/cost values in the log table, and tooltips explaining the estimation method.
- Diagnostic logging of raw `usage` field from OpenRouter for models that report zero tokens (temporary — remove after confirming).

### Changed
- `PSA_OpenRouter::send_request_once()` now passes `token_source` to cost tracker alongside token counts.
- Version bump to 4.4.3.

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
- Updated 