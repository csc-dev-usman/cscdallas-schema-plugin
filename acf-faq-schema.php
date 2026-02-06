<?php
/**
 * Plugin Name: ACF FAQs JSON-LD Schema (Strip HTML)
 * Description: Reads stored schema JSON-LD and configured ACF FAQ repeater fields, then prints a single merged JSON-LD graph into the header on singular content.
 * Version:     1.4
 * Author:      Automated Assistant
 * Text Domain: acf-faq-schema
 *
 * Requirements:
 * - schema (JSON-LD string/array/object) [optional]
 * - Configurable FAQ repeater mappings in Settings > ACF FAQ Schema
 *
 * This plugin strips HTML, shortcodes, and decodes entities for the FAQ JSON-LD output.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-acf-faq-schema-breadcrumbs.php';

class ACF_FAQ_Schema_StripHTML {

    const OPTION_MAPPINGS = 'acf_faq_schema_mappings';
    const OPTION_BREADCRUMB_PAGE_IDS = 'acf_faq_schema_breadcrumb_page_ids';

    protected $breadcrumbs;

    public function __construct() {
        $this->breadcrumbs = new ACF_FAQ_Schema_Breadcrumbs();

        add_action( 'wp_head', array( $this, 'maybe_print_schema' ), 1 );
        add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function maybe_print_schema() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! is_object( $post ) ) {
            return;
        }

        $post_id = $post->ID;
        $graph   = $this->get_schema_graph_nodes( $post_id );
        $faq     = $this->build_faq_schema_node( $post_id );
        $crumbs  = $this->build_breadcrumb_schema_node( $post );

        if ( ! empty( $faq ) ) {
            $graph[] = $faq;
        }

        if ( ! empty( $crumbs ) && ! $this->graph_has_breadcrumb( $graph ) ) {
            $graph[] = $crumbs;
        }

        if ( empty( $graph ) ) {
            return;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@graph'   => array_values( $graph ),
        );

        $json_flags = apply_filters( 'acf_faqs_schema_json_options', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $json       = wp_json_encode( $schema, $json_flags );

        if ( false === $json ) {
            return;
        }

        printf( "<script type=\"application/ld+json\">%s</script>\n", $json );
    }

    public function register_admin_page() {
        add_options_page(
            'ACF FAQ Schema',
            'ACF FAQ Schema',
            'manage_options',
            'acf-faq-schema',
            array( $this, 'render_admin_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'acf_faq_schema_settings',
            self::OPTION_MAPPINGS,
            array( $this, 'sanitize_mappings_option' )
        );

        register_setting(
            'acf_faq_schema_settings',
            self::OPTION_BREADCRUMB_PAGE_IDS,
            array( $this, 'sanitize_breadcrumb_pages_option' )
        );
    }

    public function sanitize_mappings_option( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $value as $mapping ) {
            if ( ! is_array( $mapping ) ) {
                continue;
            }

            $item = array(
                'repeater_field'      => isset( $mapping['repeater_field'] ) ? sanitize_key( $mapping['repeater_field'] ) : '',
                'question_field'      => isset( $mapping['question_field'] ) ? sanitize_key( $mapping['question_field'] ) : '',
                'answer_field'        => isset( $mapping['answer_field'] ) ? sanitize_key( $mapping['answer_field'] ) : '',
                'section_title_field' => isset( $mapping['section_title_field'] ) ? sanitize_key( $mapping['section_title_field'] ) : '',
            );

            if ( '' === $item['repeater_field'] || '' === $item['question_field'] || '' === $item['answer_field'] ) {
                continue;
            }

            $sanitized[] = $item;
        }

        return $sanitized;
    }

    public function sanitize_breadcrumb_pages_option( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $page_ids = array_map( 'absint', $value );
        $page_ids = array_filter( $page_ids );

        return array_values( array_unique( $page_ids ) );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $mappings                  = $this->get_faq_mappings();
        $selected_breadcrumb_pages = $this->get_breadcrumb_page_ids();
        $pages                     = get_pages(
            array(
                'sort_column' => 'menu_order,post_title',
            )
        );
        ?>
        <div class="wrap">
            <h1>ACF FAQ Schema</h1>
            <p>Configure which ACF repeater fields should be treated as FAQ sources for JSON-LD output.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'acf_faq_schema_settings' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Breadcrumb Pages</th>
                            <td>
                                <div id="acf-faq-schema-breadcrumb-picker" class="acf-faq-schema-picker">
                                    <input
                                        type="text"
                                        id="acf-faq-schema-breadcrumb-search"
                                        class="regular-text"
                                        placeholder="Search pages..."
                                        autocomplete="off"
                                    />
                                    <div id="acf-faq-schema-breadcrumb-selected" class="acf-faq-schema-selected"></div>
                                    <div id="acf-faq-schema-breadcrumb-options" class="acf-faq-schema-options">
                                        <?php foreach ( $pages as $page ) : ?>
                                            <?php $page_title = $page->post_title ? $page->post_title : '(no title)'; ?>
                                            <label class="acf-faq-schema-option" data-label="<?php echo esc_attr( strtolower( $page_title ) ); ?>">
                                                <input
                                                    type="checkbox"
                                                    name="<?php echo esc_attr( self::OPTION_BREADCRUMB_PAGE_IDS ); ?>[]"
                                                    value="<?php echo esc_attr( $page->ID ); ?>"
                                                    <?php checked( in_array( $page->ID, $selected_breadcrumb_pages, true ) ); ?>
                                                />
                                                <span><?php echo esc_html( $page_title ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <p class="description">Breadcrumb schema will be added only on the selected pages.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table class="widefat striped" id="acf-faq-schema-mappings-table">
                    <thead>
                        <tr>
                            <th>Repeater Field</th>
                            <th>Question Subfield</th>
                            <th>Answer Subfield</th>
                            <th>Section Title Field</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $mappings as $index => $mapping ) : ?>
                            <tr>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_MAPPINGS . '[' . $index . '][repeater_field]' ); ?>" value="<?php echo esc_attr( $mapping['repeater_field'] ); ?>" placeholder="faqs_list" /></td>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_MAPPINGS . '[' . $index . '][question_field]' ); ?>" value="<?php echo esc_attr( $mapping['question_field'] ); ?>" placeholder="faqs_list_title" /></td>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_MAPPINGS . '[' . $index . '][answer_field]' ); ?>" value="<?php echo esc_attr( $mapping['answer_field'] ); ?>" placeholder="faqs_list_description" /></td>
                                <td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_MAPPINGS . '[' . $index . '][section_title_field]' ); ?>" value="<?php echo esc_attr( $mapping['section_title_field'] ); ?>" placeholder="faqs_section_title" /></td>
                                <td><button type="button" class="button-link-delete acf-faq-schema-remove-row">Remove</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="acf-faq-schema-add-row">Add FAQ Mapping</button>
                </p>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        (function() {
            var tableBody = document.querySelector('#acf-faq-schema-mappings-table tbody');
            var addButton = document.getElementById('acf-faq-schema-add-row');
            var breadcrumbPicker = document.getElementById('acf-faq-schema-breadcrumb-picker');
            var breadcrumbSearch = document.getElementById('acf-faq-schema-breadcrumb-search');
            var breadcrumbSelected = document.getElementById('acf-faq-schema-breadcrumb-selected');
            var breadcrumbOptions = document.getElementById('acf-faq-schema-breadcrumb-options');

            if (!tableBody || !addButton) {
                return;
            }

            function nextIndex() {
                return tableBody.querySelectorAll('tr').length;
            }

            function rowHtml(index) {
                var prefix = '<?php echo esc_js( self::OPTION_MAPPINGS ); ?>[' + index + ']';

                return '<tr>'
                    + '<td><input type="text" class="regular-text" name="' + prefix + '[repeater_field]" placeholder="faqs_list" /></td>'
                    + '<td><input type="text" class="regular-text" name="' + prefix + '[question_field]" placeholder="faqs_list_title" /></td>'
                    + '<td><input type="text" class="regular-text" name="' + prefix + '[answer_field]" placeholder="faqs_list_description" /></td>'
                    + '<td><input type="text" class="regular-text" name="' + prefix + '[section_title_field]" placeholder="faqs_section_title" /></td>'
                    + '<td><button type="button" class="button-link-delete acf-faq-schema-remove-row">Remove</button></td>'
                    + '</tr>';
            }

            addButton.addEventListener('click', function() {
                tableBody.insertAdjacentHTML('beforeend', rowHtml(nextIndex()));
            });

            tableBody.addEventListener('click', function(event) {
                if (!event.target.classList.contains('acf-faq-schema-remove-row')) {
                    return;
                }

                var row = event.target.closest('tr');
                if (row) {
                    row.remove();
                }
            });

            if (breadcrumbPicker && breadcrumbSearch && breadcrumbSelected && breadcrumbOptions) {
                var optionLabels = Array.prototype.slice.call(
                    breadcrumbOptions.querySelectorAll('.acf-faq-schema-option')
                );

                function renderSelectedPages() {
                    var checked = optionLabels.filter(function(label) {
                        var input = label.querySelector('input');
                        return input && input.checked;
                    });

                    if (!checked.length) {
                        breadcrumbSelected.innerHTML = '<span class="acf-faq-schema-empty">No pages selected</span>';
                        return;
                    }

                    breadcrumbSelected.innerHTML = checked.map(function(label) {
                        var input = label.querySelector('input');
                        var text = label.querySelector('span');
                        var pageId = input ? input.value : '';
                        var pageName = text ? text.textContent : '';

                        return '<button type="button" class="acf-faq-schema-chip" data-page-id="' + pageId + '">' + pageName + ' <span aria-hidden="true">x</span></button>';
                    }).join('');
                }

                function filterPages() {
                    var query = breadcrumbSearch.value.toLowerCase().trim();

                    optionLabels.forEach(function(label) {
                        var haystack = label.getAttribute('data-label') || '';
                        label.style.display = !query || haystack.indexOf(query) !== -1 ? '' : 'none';
                    });
                }

                breadcrumbSearch.addEventListener('input', filterPages);

                breadcrumbOptions.addEventListener('change', function(event) {
                    if (event.target && event.target.type === 'checkbox') {
                        renderSelectedPages();
                    }
                });

                breadcrumbSelected.addEventListener('click', function(event) {
                    var chip = event.target.closest('.acf-faq-schema-chip');
                    if (!chip) {
                        return;
                    }

                    var pageId = chip.getAttribute('data-page-id');
                    var input = breadcrumbOptions.querySelector('input[value="' + pageId + '"]');
                    if (input) {
                        input.checked = false;
                        renderSelectedPages();
                    }
                });

                renderSelectedPages();
                filterPages();
            }
        })();
        </script>
        <style>
            .acf-faq-schema-picker {
                max-width: 520px;
            }

            .acf-faq-schema-selected {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin: 12px 0;
            }

            .acf-faq-schema-chip {
                border: 1px solid #ccd0d4;
                background: #fff;
                border-radius: 999px;
                padding: 4px 10px;
                cursor: pointer;
            }

            .acf-faq-schema-empty {
                color: #646970;
            }

            .acf-faq-schema-options {
                max-height: 260px;
                overflow: auto;
                border: 1px solid #ccd0d4;
                background: #fff;
                padding: 8px 10px;
            }

            .acf-faq-schema-option {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 0;
            }
        </style>
        <?php
    }

    protected function get_schema_graph_nodes( $post_id ) {
        $raw_schema = $this->get_field_value( 'schema', $post_id );

        if ( '' === trim( (string) $raw_schema ) && ! is_array( $raw_schema ) && ! is_object( $raw_schema ) ) {
            return array();
        }

        $decoded = $this->decode_schema_value( $raw_schema );

        if ( empty( $decoded ) || ! is_array( $decoded ) ) {
            return array();
        }

        return $this->normalize_schema_to_graph_nodes( $decoded );
    }

    protected function decode_schema_value( $raw_schema ) {
        if ( is_object( $raw_schema ) || is_array( $raw_schema ) ) {
            $normalized = json_decode( wp_json_encode( $raw_schema ), true );

            return is_array( $normalized ) ? $normalized : array();
        }

        if ( ! is_string( $raw_schema ) ) {
            return array();
        }

        $raw_schema = trim( $raw_schema );

        if ( '' === $raw_schema ) {
            return array();
        }

        $decoded = json_decode( $raw_schema, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return array();
        }

        return $decoded;
    }

    protected function normalize_schema_to_graph_nodes( $schema ) {
        if ( isset( $schema['@graph'] ) ) {
            return $this->normalize_graph_entries( $schema['@graph'] );
        }

        if ( $this->is_assoc_array( $schema ) ) {
            return array( $schema );
        }

        return $this->normalize_graph_entries( $schema );
    }

    protected function normalize_graph_entries( $entries ) {
        if ( is_object( $entries ) ) {
            $entries = json_decode( wp_json_encode( $entries ), true );
        }

        if ( ! is_array( $entries ) ) {
            return array();
        }

        if ( $this->is_assoc_array( $entries ) ) {
            return array( $entries );
        }

        $graph = array();

        foreach ( $entries as $entry ) {
            if ( is_object( $entry ) ) {
                $entry = json_decode( wp_json_encode( $entry ), true );
            }

            if ( is_array( $entry ) && ! empty( $entry ) ) {
                $graph[] = $entry;
            }
        }

        return $graph;
    }

    protected function build_faq_schema_node( $post_id ) {
        $mappings       = $this->get_faq_mappings();
        $all_questions  = array();
        $section_title  = '';

        foreach ( $mappings as $mapping ) {
            $repeater = $this->get_field_value( $mapping['repeater_field'], $post_id );

            if ( ! is_array( $repeater ) || empty( $repeater ) ) {
                continue;
            }

            $questions = $this->build_questions_from_mapping( $repeater, $mapping );

            if ( empty( $questions ) ) {
                continue;
            }

            $all_questions = array_merge( $all_questions, $questions );

            if ( '' === $section_title && ! empty( $mapping['section_title_field'] ) ) {
                $raw_title = $this->get_field_value( $mapping['section_title_field'], $post_id );
                $title     = $this->sanitize_text_for_schema( $raw_title );

                if ( '' !== $title ) {
                    $section_title = $title;
                }
            }
        }

        if ( empty( $all_questions ) ) {
            return array();
        }

        $schema = array(
            '@type'      => 'FAQPage',
			'@id'        => get_permalink( $post_id ) . '#faq',
            'mainEntity' => $all_questions,
        );

        if ( '' !== $section_title ) {
            $schema['name'] = $section_title;
        }

        return $schema;
    }

    protected function build_questions_from_mapping( $repeater, $mapping ) {
        $out = array();

        foreach ( $repeater as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $question_raw = isset( $row[ $mapping['question_field'] ] ) ? $row[ $mapping['question_field'] ] : '';
            $answer_raw   = isset( $row[ $mapping['answer_field'] ] ) ? $row[ $mapping['answer_field'] ] : '';
            $question     = $this->sanitize_text_for_schema( $question_raw );
            $answer       = $this->sanitize_text_for_schema( $answer_raw );

            if ( '' === $question || '' === $answer ) {
                continue;
            }

            $out[] = array(
                '@type'          => 'Question',
                'name'           => $question,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $answer,
                ),
            );
        }

        return $out;
    }

    protected function get_faq_mappings() {
        $mappings = get_option( self::OPTION_MAPPINGS, array() );

        if ( ! is_array( $mappings ) || empty( $mappings ) ) {
            return array(
                array(
                    'repeater_field'      => 'faqs_list',
                    'question_field'      => 'faqs_list_title',
                    'answer_field'        => 'faqs_list_description',
                    'section_title_field' => 'faqs_section_title',
                ),
            );
        }

        return $mappings;
    }

    protected function get_field_value( $key, $post_id = null ) {
        if ( function_exists( 'get_field' ) ) {
            $value = get_field( $key, $post_id );

            if ( null !== $value && false !== $value ) {
                return $value;
            }
        }

        if ( $post_id ) {
            return get_post_meta( $post_id, $key, true );
        }

        return null;
    }

    protected function build_breadcrumb_schema_node( $post ) {
        if ( ! $this->breadcrumbs_enabled_for_post( $post ) ) {
            return array();
        }

        if ( ! $this->breadcrumbs instanceof ACF_FAQ_Schema_Breadcrumbs ) {
            return array();
        }

        return $this->breadcrumbs->build_for_post( $post );
    }

    /**
     * Strip shortcodes, HTML tags, decode entities, remove extra whitespace.
     */
    protected function sanitize_text_for_schema( $raw ) {
        if ( is_array( $raw ) ) {
            $raw = implode( ' ', $raw );
        }

        $raw = (string) $raw;

        $raw = strip_shortcodes( $raw );
        $raw = preg_replace( '#<(br|br\s*/|/p|p\s*/?)>#i', "\n", $raw );

        $text = wp_strip_all_tags( $raw );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        $text = str_replace(
            array(
                "\xC2\xA0",
                "\xE2\x80\x8B",
                "\xE2\x80\x8C",
                "\xE2\x80\x8D",
                "\xEF\xBB\xBF",
            ),
            ' ',
            $text
        );

        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( $text );
    }

    protected function is_assoc_array( $value ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        if ( array() === $value ) {
            return false;
        }

        return array_keys( $value ) !== range( 0, count( $value ) - 1 );
    }

    protected function graph_has_breadcrumb( $graph ) {
        if ( ! is_array( $graph ) ) {
            return false;
        }

        foreach ( $graph as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            if ( isset( $node['@type'] ) ) {
                $types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );

                if ( in_array( 'BreadcrumbList', $types, true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function breadcrumbs_enabled_for_post( $post ) {
        if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
            return false;
        }

        return in_array( (int) $post->ID, $this->get_breadcrumb_page_ids(), true );
    }

    protected function get_breadcrumb_page_ids() {
        $page_ids = get_option( self::OPTION_BREADCRUMB_PAGE_IDS, array() );

        if ( ! is_array( $page_ids ) ) {
            return array();
        }

        $page_ids = array_map( 'absint', $page_ids );
        $page_ids = array_filter( $page_ids );

        return array_values( array_unique( $page_ids ) );
    }
}

new ACF_FAQ_Schema_StripHTML();
