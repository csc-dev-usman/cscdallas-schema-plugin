<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ACF_FAQ_Schema_Breadcrumbs {

    public function build_for_post( $post ) {
        if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
            return array();
        }

        $items      = array();
        $position   = 1;
        $items[]    = array(
            '@type'    => 'ListItem',
            'position' => $position++,
            'name'     => $this->sanitize_label( get_bloginfo( 'name' ) ),
            'item'     => home_url( '/' ),
        );

        $ancestors = array_reverse( get_post_ancestors( $post ) );

        foreach ( $ancestors as $ancestor_id ) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $this->get_schema_title( $ancestor_id ),
                'item'     => get_permalink( $ancestor_id ),
            );
        }

        $current_title       = $this->get_schema_title( $post->ID );
        $current_description = $this->get_schema_description( $post->ID );

        $items[] = array(
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => $current_title,
            'item'     => get_permalink( $post ),
        );

        $schema = array(
            '@type'           => 'BreadcrumbList',
            '@id'             => trailingslashit( get_permalink( $post ) ) . '#breadcrumb',
            'name'            => $current_title,
            'itemListElement' => $items,
        );

        if ( '' !== $current_description ) {
            $schema['description'] = $current_description;
        }

        return $schema;
    }

    protected function get_schema_title( $post_id ) {
        $rank_math_title = get_post_meta( $post_id, 'rank_math_title', true );

        if ( is_string( $rank_math_title ) && '' !== trim( $rank_math_title ) ) {
            return $this->sanitize_label( $rank_math_title );
        }

        return $this->sanitize_label( get_the_title( $post_id ) );
    }

    protected function get_schema_description( $post_id ) {
        $rank_math_description = get_post_meta( $post_id, 'rank_math_description', true );

        if ( is_string( $rank_math_description ) && '' !== trim( $rank_math_description ) ) {
            return $this->sanitize_label( $rank_math_description );
        }

        return '';
    }

    protected function sanitize_label( $label ) {
        $label = wp_strip_all_tags( (string) $label );
        $label = html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $label = preg_replace( '/\s+/u', ' ', $label );

        return trim( $label );
    }
}
