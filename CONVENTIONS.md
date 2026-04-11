# Peptide Search AI — Code Conventions

## Step-by-Step Guides

### Adding a New AI Provider

If you want to support a different AI service (e.g., Claude API, LLaMA Cloud, Azure OpenAI) instead of or alongside OpenRouter:

1. **Create a new provider class:** `includes/class-psa-[provider]-ai.php`
   ```php
   <?php
   if ( ! defined( 'ABSPATH' ) ) { exit; }
   
   class PSA_[Provider]_AI {
       const API_URL = 'https://...';
       
       public static function validate_peptide_name( $name ) {
           // Similar signature to PSA_AI_Generator::validate_peptide_name()
           // Return: array { is_valid: bool, canonical_name: string, reason: string }
       }
       
       public static function generate_peptide_content( $peptide_name ) {
           // Similar signature to PSA_AI_Generator::generate_peptide_content()
           // Return: array { name, overview, sequence, ... } or WP_Error
       }
   }
   ```

2. **Update admin settings:** In `class-psa-admin.php`, add new field to select provider:
   ```php
   add_settings_field(
       'ai_provider',
       __( 'AI Provider', 'peptide-search-ai' ),
       array( __CLASS__, 'render_provider_field' ),
       'peptide-search-ai',
       'psa_ai_section'
   );
   ```

3. **Update PSA_AI_Generator:** Modify `get_settings()` and both validation/generation methods to use the selected provider:
   ```php
   private static function get_settings() {
       $settings = wp_parse_args( get_option( 'psa_settings', array() ), $defaults );
       $provider = $settings['ai_provider'] ?? 'openrouter';
       return apply_filters( 'psa_ai_settings', $settings, $provider );
   }
   ```

4. **Add fallback logic:** If provider fails, optionally retry with OpenRouter as fallback:
   ```php
   $response = match( $provider ) {
       'claude' => PSA_Claude_AI::generate_peptide_content( $name ),
       'openrouter' => PSA_AI_Generator::generate_peptide_content( $name ),
       default => new WP_Error( 'unknown_provider', "Provider '$provider' not supported" ),
   };
   ```

5. **Update settings sanitizer:** In `PSA_Admin::sanitize_settings()`, add provider field:
   ```php
   'ai_provider' => sanitize_text_field( $input['ai_provider'] ?? 'openrouter' ),
   ```

6. **Document settings:** Update `readme.txt` with new provider option.

### Adding a New Setting

To add a new admin configurable setting (e.g., "Prompt language", "Max retries"):

1. **Define constant in PSA_Config:** `includes/class-psa-config.php`
   ```php
   const MY_NEW_SETTING_DEFAULT = 'some_value';
   ```

2. **Add to settings sanitizer:** `includes/class-psa-admin.php`
   ```php
   public static function sanitize_settings( $input ) {
       return array(
           // ... existing fields
           'my_new_setting' => sanitize_text_field( $input['my_new_setting'] ?? '' ),
       );
   }
   ```

3. **Register settings field:** In `PSA_Admin::register_settings()`
   ```php
   add_settings_field(
       'my_new_setting',
       __( 'My New Setting', 'peptide-search-ai' ),
       array( __CLASS__, 'render_my_new_setting_field' ),
       'peptide-search-ai',
       'psa_behavior_section' // or 'psa_ai_section'
   );
   ```

4. **Create field renderer:** Add method to `PSA_Admin` class
   ```php
   public static function render_my_new_setting_field() {
       $value = self::get_setting( 'my_new_setting', '' );
       ?>
       <input type="text" name="psa_settings[my_new_setting]" value="<?php echo esc_attr( $value ); ?>" />
       <p class="description"><?php esc_html_e( 'Description here.', 'peptide-search-ai' ); ?></p>
       <?php
   }
   ```

5. **Use in your code:** Retrieve via `PSA_AI_Generator::get_settings()` (or create similar getter if needed):
   ```php
   $options = self::get_settings();
   $my_setting = $options['my_new_setting'] ?? 'default';
   ```

6. **Allow filtering:** Apply `psa_ai_settings` filter so extensions can modify:
   ```php
   $settings = apply_filters( 'psa_ai_settings', $settings );
   ```

### Adding a New Meta Field

To store additional data on peptide posts (e.g., "IUPAC Name", "Clinical Trials Info"):

1. **Define in PSA_Post_Type::META_FIELDS:** `includes/class-psa-post-type.php`
   ```php
   const META_FIELDS = array(
       // ... existing fields
       'psa_iupac_name'    => 'IUPAC Name',
       'psa_clinical_data' => 'Clinical Trials',
   );
   ```

2. **Update meta box renderer:** In `PSA_Post_Type::render_meta_box()`
   ```php
   $textarea_fields = array(
       // ... existing fields
       'psa_clinical_data', // Add to textarea list if long text
   );
   ```

3. **Populate during generation:** In `PSA_AI_Generator::save_peptide_meta()`
   ```php
   private static function save_peptide_meta( $post_id, $ai_data, $pubchem_data ) {
       $meta = array(
           // ... existing fields
           'psa_iupac_name'    => $ai_data['iupac_name'] ?? '',
           'psa_clinical_data' => $ai_data['clinical_trials'] ?? '',
       );
   }
   ```

4. **Include in generation prompt:** Update `PSA_AI_Generator::build_generation_prompt()` to request the field in AI response:
   ```php
   $prompt = "... Return JSON with: { name, sequence, iupac_name, clinical_trials, ... }";
   ```

5. **Display on single page:** In `PSA_Template::append_peptide_data()`
   ```php
   $detail_fields = array(
       'psa_iupac_name'    => 'IUPAC Name',
       'psa_clinical_data' => 'Clinical Trials',
       // ... existing fields
   );
   ```

6. **Optionally export via REST:** Update `PSA_Search::format_peptide()` to include in REST response:
   ```php
   return array(
       // ... existing fields
       'iupac_name'    => get_post_meta( $post_id, 'psa_iupac_name', true ),
       'clinical_data' => get_post_meta( $post_id, 'psa_clinical_data', true ),
   );
   ```

### Adding a New Peptide Category

To organize peptides into categories (e.g., "Hormones", "Growth Factors", "Neuropeptides"):

1. **Register custom taxonomy:** In main `peptide-search-ai.php` or new helper file
   ```php
   function psa_register_peptide_category_taxonomy() {
       register_taxonomy(
           'peptide_category',
           'peptide',
           array(
               'label'        => 'Peptide Categories',
               'hierarchical' => true,
               'show_in_rest' => true,
               'rewrite'      => array( 'slug' => 'peptide-category' ),
           )
       );
   }
   add_action( 'init', 'psa_register_peptide_category_taxonomy' );
   ```

2. **Add category field to AI generation prompt:** Update `PSA_AI_Generator::build_generation_prompt()`
   ```php
   $prompt = "Determine the category of {$peptide_name}. 
              Return JSON including: { ..., category: 'hormone'|'growth_factor'|'neuropeptide'|'other' }";
   ```

3. **Assign category during generation:** In `PSA_AI_Generator::background_generate()`
   ```php
   if ( ! empty( $result['category'] ) ) {
       wp_set_object_terms( $post_id, $result['category'], 'peptide_category' );
   }
   ```

4. **Display category on single page:** In `PSA_Template::append_peptide_data()`
   ```php
   $categories = wp_get_object_terms( $post_id, 'peptide_category' );
   if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
       echo '<p>Category: ' . esc_html( $categories[0]->name ) . '</p>';
   }
   ```

5. **Filter search by category:** Extend `PSA_Search::search_peptides()` to accept optional `category` parameter:
   ```php
   public static function search_peptides( $query, $category = null ) {
       // ... existing search logic
       if ( $category ) {
           // JOIN with taxonomy terms
       }
   }
   ```

6. **Allow REST API filtering:** Update REST route in `PSA_Search::register_rest_routes()`
   ```php
   'args' => array(
       'q'        => array( ... ),
       'category' => array(
           'sanitize_callback' => 'sanitize_text_field',
       ),
   ),
   ```

## Naming Conventions

### CSS Classes (`.psa-*`)

All CSS classes use lowercase with hyphens, prefixed `.psa-`:

```css
/* Search form */
.psa-search-wrap          /* Container for entire search UI */
.psa-search-form          /* Form element */
.psa-search-input-wrap    /* Flex container for input + button */
.psa-search-input         /* Text input field */
.psa-search-btn           /* Submit button */
.psa-search-icon          /* Icon inside button */

/* Results */
.psa-search-results       /* Results container */
.psa-result-count         /* "N peptides found" text */
.psa-result-item          /* Single result link */
.psa-result-title         /* Result title heading */
.psa-result-meta          /* Metadata row (MW, formula, etc.) */
.psa-result-excerpt       /* Preview text */
.psa-result-badge         /* Source badge on result */

/* States */
.psa-checking             /* "Searching..." loading state */
.psa-checking-inner       /* Inner content of checking state */
.psa-spinner              /* Animated spinner */
.psa-pending              /* "Currently being added" message */
.psa-pending-inner        /* Inner content of pending state */
.psa-pending-icon         /* Icon inside pending state */
.psa-invalid              /* "Not found" message */
.psa-invalid-inner        /* Inner content of invalid state */
.psa-invalid-text         /* Text inside invalid state */
.psa-error                /* Error message */

/* Single page */
.psa-peptide-data         /* Container for all peptide data on single page */
.psa-quick-facts          /* Quick facts box */
.psa-facts-table          /* Table inside quick facts */
.psa-sequence             /* Amino acid sequence code block */
.psa-section              /* Detail section (Mechanism, Safety, etc.) */
.psa-section-content      /* Rich text content inside section */
.psa-source-badge         /* Source attribution badge at bottom */

/* Badge variants */
.psa-badge-ai             /* AI-generated badge (yellow) */
.psa-badge-verified       /* PubChem verified badge (green) */
.psa-badge-manual         /* Manually curated badge (blue) */

/* KB page override */
.psa-kb-search-wrap       /* Search container on KB page */
```

**BEM-inspired structure:**
- Block: `.psa-search-wrap` (independent component)
- Element: `.psa-search-input` (part of block, `__` replaces with `-`)
- Modifier: `.psa-badge-ai` (variant of `.psa-badge`, `-` separates type)

### PHP Naming (`psa_*`, `PSA_*`)

#### Functions (Global, Snake Case)

```php
// Plugin-level utility functions
psa_init()                          /* Main init hook callback */
psa_admin_init()                    /* Admin init hook callback */
psa_activate()                      /* Plugin activation hook */
psa_deactivate()                    /* Plugin deactivation hook */
psa_maybe_enqueue_assets()          /* Conditional asset loading */
psa_enqueue_frontend_assets()       /* Shared asset enqueueing */
psa_replace_kb_search()             /* KB page search injection */
psa_is_kb_page()                    /* Check if current page is KB */
psa_get_client_ip()                 /* Get visitor IP for rate limiting */
psa_get_admin_user_id()             /* Get first admin user ID */
```

#### Classes (PascalCase, PSA_ Prefix)

```php
PSA_Config                          /* Configuration constants */
PSA_Error                           /* Error handling [deprecated] */
PSA_Post_Type                       /* CPT registration & meta */
PSA_Search                          /* Search, AJAX, REST API, shortcode */
PSA_AI_Generator                    /* OpenRouter integration */
PSA_PubChem                         /* PubChem API integration */
PSA_Admin                           /* Settings pages & fields */
PSA_Template                        /* Single page rendering */
```

#### Class Methods (camelCase)

```php
// Static utility methods (all methods in Peptide Search AI are static)
PSA_Search::init()
PSA_Search::render_search_form()
PSA_Search::ajax_search()
PSA_Search::rest_search()
PSA_Search::search_peptides()
PSA_Search::format_peptide()

// Public methods: descriptive action verbs
PSA_AI_Generator::validate_peptide_name()
PSA_AI_Generator::generate_peptide_content()
PSA_AI_Generator::background_generate()
PSA_PubChem::lookup()

// Private methods: prefixed with underscore
PSA_Search::find_pending_peptide()
PSA_AI_Generator::parse_ai_response()
PSA_AI_Generator::get_settings()
```

#### Post Meta Keys (`psa_*` prefix)

All lowercase, hyphen-separated (converted to underscore in database):

```php
psa_sequence              /* Amino acid sequence */
psa_molecular_weight      /* Molecular weight (Da) */
psa_molecular_formula     /* Molecular formula (e.g., C12H20N2O3) */
psa_aliases               /* Alternative names */
psa_mechanism             /* Mechanism of action */
psa_research_apps         /* Research applications */
psa_safety_profile        /* Safety & side effects */
psa_dosage_info           /* Dosage information */
psa_references            /* References & citations */
psa_source                /* Source: ai-generated, pubchem, manual, pending, failed */
psa_pubchem_cid           /* PubChem Compound ID */

/* Tracking fields (temporary, deleted on completion) */
psa_generation_started    /* Unix timestamp when generation began */
psa_generation_attempts   /* Retry counter */
psa_generation_error      /* Error message if generation failed */
psa_generation_completed  /* Unix timestamp when generation finished */
```

#### Options Keys (`psa_*` prefix)

Stored in `wp_options`:

```php
psa_settings              /* Serialized array of all plugin settings */
```

#### Constants (`PSA_*` uppercase)

```php
/* Core plugin constants (defined in main file) */
PSA_VERSION               /* Plugin version (e.g., '4.0.0') */
PSA_PLUGIN_DIR            /* Absolute filesystem path to plugin directory */
PSA_PLUGIN_URL            /* Absolute URL to plugin directory */
PSA_PLUGIN_FILE           /* Absolute path to main plugin file */

/* Security constant (optional, in wp-config.php) */
PSA_OPENROUTER_KEY        /* OpenRouter API key (preferred over database) */

/* Configuration constants (PSA_Config class) */
PSA_Config::MIN_QUERY_LENGTH      /* Minimum search query length */
PSA_Config::MAX_QUERY_LENGTH      /* Maximum search query length */
PSA_Config::AJAX_RATE_LIMIT       /* Requests per IP per hour (AJAX) */
PSA_Config::REST_RATE_LIMIT       /* Requests per IP per hour (REST) */
PSA_Config::DAILY_GENERATION_CAP  /* Max new peptides per day */
PSA_Config::PENDING_TIMEOUT       /* Timeout for "pending" status (seconds) */
PSA_Config::VALIDATION_MAX_TOKENS /* Max tokens for validation AI call */
PSA_Config::GENERATION_MAX_TOKENS /* Max tokens for full generation */
```

### Hooks and Filters

#### Action Hooks (Format: `psa_{before|after}_{action}`)

Executed at key lifecycle points. Plugins can hook with `add_action()`:

```php
/* WordPress integration hooks */
init                                      /* Standard WordPress init hook */
admin_init                                /* Standard WordPress admin init */
wp_enqueue_scripts                        /* Standard WordPress asset loading */
wp_footer                                 /* Standard WordPress footer hook */
add_meta_boxes                            /* Standard WordPress meta box hook */
save_post_peptide                         /* Standard WordPress post save hook */
wp_ajax_psa_search                        /* AJAX handler for search */
wp_ajax_nopriv_psa_search                 /* AJAX handler (unauthenticated) */
rest_api_init                             /* REST API route registration */

/* Plugin-specific actions */
psa_peptide_created                       /* Fired when placeholder post is created */
do_action( 'psa_peptide_created', $post_id, $peptide_name );

psa_before_generation                     /* Fired before AI generation starts */
do_action( 'psa_before_generation', $post_id, $peptide_name );

psa_after_generation                      /* Fired after generation completes */
do_action( 'psa_after_generation', $post_id, $result, $peptide_name );
```

#### Filter Hooks (Format: `psa_{noun}_{context}`)

Allow plugins to modify data. Plugins can hook with `add_filter()`:

```php
/* Search & retrieval */
psa_search_results                        /* Modify search results before return */
apply_filters( 'psa_search_results', $response, $query );

/* AI & validation */
psa_validation_prompt                     /* Modify validation prompt before API call */
apply_filters( 'psa_validation_prompt', $prompt, $peptide_name );

psa_generation_prompt                     /* Modify generation prompt before API call */
apply_filters( 'psa_generation_prompt', $prompt, $peptide_name );

psa_ai_settings                           /* Modify settings before use in AI code */
apply_filters( 'psa_ai_settings', $settings );

/* Data storage */
psa_peptide_meta                          /* Modify meta fields before saving */
apply_filters( 'psa_peptide_meta', $meta, $post_id, $ai_data, $pubchem_data );

psa_template_data                         /* Modify template data before single-page rendering */
apply_filters( 'psa_template_data', $data, $post_id );
```

## Error Handling Patterns

### Returning WP_Error

Use `new WP_Error( $code, $message )` for recoverable failures:

```php
public static function validate_peptide_name( $name ) {
    $options = self::get_settings();
    $api_key = $options['api_key'];
    
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', 'OpenRouter API key is not configured.' );
    }
    
    // ... call API ...
    
    if ( is_wp_error( $response ) ) {
        return $response; // Propagate error up
    }
    
    // Success: return array
    return array( 'is_valid' => true, 'canonical_name' => $canonical_name );
}
```

### Checking for WP_Error

Before using a result, check if it's an error:

```php
$validation = PSA_AI_Generator::validate_peptide_name( $query );

if ( is_wp_error( $validation ) ) {
    error_log( 'PSA: Validation error: ' . $validation->get_error_message() );
    wp_send_json_success(
        array( 'status' => 'invalid', 'message' => 'Could not verify this peptide name.' )
    );
    return;
}

// Safe to access $validation as array
if ( empty( $validation['is_valid'] ) ) {
    // ... handle invalid result
}
```

### Logging Errors

Use PHP's `error_log()` for debugging; logs to wp-content/debug.log if `WP_DEBUG_LOG` is true:

```php
error_log( 'PSA: Generated content for "' . $peptide_name . '" (post ' . $post_id . ')' );
error_log( 'PSA: Generation FAILED: ' . $error_message );
```

### User-Facing Errors

For AJAX/REST responses, return JSON errors via `wp_send_json_error()` or `wp_send_json_success()` with error status:

```php
// AJAX error response
wp_send_json_error( 'Search query is too long.' );

// REST error response
return new WP_REST_Response(
    array( 'error' => 'Rate limit exceeded.' ),
    429
);

// AJAX success but with error status
wp_send_json_success(
    array(
        'status'  => 'invalid',
        'message' => 'This does not appear to be a recognized peptide.',
    )
);
```

## Code Style Rules

### PHP Formatting (WordPress Coding Standards)

1. **Indentation:** Tabs (not spaces). Set editor to use tabs.

2. **Yoda Conditions:** Place constants/variables on left side of comparison:
   ```php
   // Good
   if ( 'pending' === $source ) { ... }
   if ( true === $is_valid ) { ... }
   
   // Avoid
   if ( $source === 'pending' ) { ... }
   ```

3. **String Escaping:** Always escape output to the browser:
   ```php
   // Good
   echo esc_html( $peptide_name );
   echo esc_attr( $class_name );
   echo wp_kses_post( $post_content );
   echo esc_url( $permalink );
   
   // Avoid
   echo $peptide_name;
   ```

4. **Sanitization:** Always sanitize user input:
   ```php
   // Good
   $query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
   $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
   
   // Avoid
   $query = $_GET['q'];
   ```

5. **Spacing:**
   - No spaces inside parentheses: `if ( $condition )` not `if( $condition )`
   - Space after keywords: `if`, `foreach`, `function`, `return`
   - Space around operators: `$a = $b + $c` not `$a=$b+$c`

6. **Line length:** Keep lines under 120 characters (configure in `.phpcs.xml.dist`).

7. **Function documentation:** Use PHPDoc for all functions/methods:
   ```php
   /**
    * Brief description of what this does.
    *
    * Longer description if needed. Can span multiple lines.
    *
    * @param string $name The peptide name to validate.
    * @param int    $post_id Optional post ID (default 0).
    * @return array|WP_Error Result array or error.
    */
   public static function validate_peptide_name( $name, $post_id = 0 ) {
       // ...
   }
   ```

8. **No shorthand PHP tags:** Use `<?php` not `<?`

9. **No closing PHP tag:** At end of PHP-only files, omit `?>` to prevent accidental whitespace.

### JavaScript Formatting

1. **Quote style:** Use single quotes for strings (consistent with WordPress JS standards).

2. **Semicolons:** Always end statements with `;`

3. **Indentation:** 4 spaces (or match project setting).

4. **Immediately Invoked Function Expression (IIFE):** Wrap in `(function ($) { ... })(jQuery)` to avoid global scope pollution.

5. **Comment blocks:**
   ```javascript
   /**
    * Render search results.
    */
   function renderResults(data) {
       // ... implementation
   }
   ```

6. **jQuery document-ready:** Use `$(document).ready()` only for initialization, not for every event handler.

7. **Error handling:** Always handle AJAX errors:
   ```javascript
   $.ajax({
       success: function (response) { ... },
       error: function (jqXHR, textStatus, errorThrown) { ... }
   });
   ```

### CSS Formatting

1. **Class naming:** Lowercase, hyphens, `.psa-` prefix (see CSS Naming Conventions section).

2. **Property order:** Use logical grouping:
   ```css
   .psa-button {
       /* Layout */
       display: inline-block;
       width: 100%;
       
       /* Spacing */
       padding: 10px;
       margin: 5px 0;
       
       /* Colors */
       background: #2563eb;
       color: #fff;
       
       /* Typography */
       font-size: 16px;
       font-weight: 600;
       
       /* Transitions */
       transition: background 0.2s;
   }
   ```

3. **Media queries:** Place at end of component or in separate section.

4. **No IDs:** Use classes only (except for single shortcode instances managed by JS).

5. **Scoped to plugin:** Never style global elements; always scope via `.psa-` classes.

### Composer & Dependencies

1. **composer.json:** Declared dependencies in `require` and `require-dev`.
   ```json
   "require": {
       "php": ">=7.4"
   },
   "require-dev": {
       "phpunit/phpunit": "^9.0 || ^10.0",
       "squizlabs/php_codesniffer": "^3.7",
       "wp-coding-standards/wpcs": "^3.0"
   }
   ```

2. **PSR-4 Autoload:** Classes in `includes/` can be autoloaded via `PSA\` namespace (not currently used, but available).

3. **PHPCS:** Run `composer run lint` before committing:
   ```bash
   composer install
   composer run lint
   composer run lint:fix  # Auto-fix formatting issues
   ```

## Summary Table

| Aspect | Pattern | Example |
|--------|---------|---------|
| **CSS Classes** | `.psa-{name}` | `.psa-search-wrap`, `.psa-badge-verified` |
| **PHP Functions** | `psa_{action}()` | `psa_init()`, `psa_enqueue_frontend_assets()` |
| **PHP Classes** | `PSA_{Name}` | `PSA_Search`, `PSA_AI_Generator` |
| **Class Methods** | `method_name()` | `validate_peptide_name()`, `render_search_form()` |
| **Post Meta** | `psa_{field}` | `psa_sequence`, `psa_molecular_weight` |
| **Constants** | `PSA_{NAME}` | `PSA_VERSION`, `PSA_CONFIG::MAX_TOKENS` |
| **Action Hooks** | `psa_{event}` | `psa_peptide_created`, `psa_after_generation` |
| **Filter Hooks** | `psa_{noun}_{context}` | `psa_search_results`, `psa_peptide_meta` |
| **Indentation** | Tabs | 1 tab = indentation level |
| **Line Length** | <120 chars | Configure in `.phpcs.xml.dist` |
| **Escaping** | `esc_*()` always | `esc_html()`, `esc_attr()`, `wp_kses_post()` |
| **Sanitizing** | `sanitize_*()` always | `sanitize_text_field()`, `wp_unslash()` |
