<?php

namespace WPGraphQL\Extensions\Polylang;

use GraphQLRelay\Relay;

class TermObject
{
    function __construct()
    {
        add_action('graphql_register_types', [$this, 'register'], 10, 0);
    }

    function register()
    {
        foreach (\WPGraphQL::get_allowed_taxonomies() as $taxonomy) {
            $this->add_taxonomy_fields(get_taxonomy($taxonomy));
        }

        /**
         * Pass language input field to insert args
         */
        add_filter(
            'graphql_term_object_insert_term_args',
            function ($insert_args, $input) {
                if (isset($input['language'])) {
                    $insert_args['language'] = $input['language'];
                }

                return $insert_args;
            },
            10,
            2
        );
    }

    function add_taxonomy_fields(\WP_Taxonomy $taxonomy)
    {
        if (!pll_is_translated_taxonomy($taxonomy->name)) {
            return;
        }

        $type = ucfirst($taxonomy->graphql_single_name);

        register_graphql_fields("RootQueryTo${type}ConnectionWhereArgs", [
            'language' => [
                'type' => 'LanguageCodeEnum',
                'description' => "Filter by ${type}s by language code (Polylang)",
            ],
        ]);

        register_graphql_fields("Create${type}Input", [
            'language' => [
                'type' => 'LanguageCodeEnum',
            ],
        ]);
        register_graphql_fields("Update${type}Input", [
            'language' => [
                'type' => 'LanguageCodeEnum',
            ],
        ]);

        /**
         * Handle language arg for term inserts
         */
        add_action(
            "graphql_insert_{$taxonomy->name}",
            function ($term_id, $args) {
                if (isset($args['language'])) {
                    pll_set_term_language($term_id, $args['language']);
                }
            },
            10,
            2
        );

        /**
         * Handle language arg for term updates
         */
        add_action(
            "graphql_update_{$taxonomy->name}",
            function ($term_id, $args) {
                if (isset($args['language'])) {
                    pll_set_term_language($term_id, $args['language']);
                }
            },
            10,
            2
        );

        register_graphql_field($type, 'language', [
            'type' => 'Language',
            'description' => __(
                'List available translations for this post',
                'wpnext'
            ),
            'resolve' => function (\WP_Term $term, $args, $context, $info) {
                $fields = $info->getFieldSelection();
                $language = [];

                if (usesSlugBasedField($fields)) {
                    $language['code'] = pll_get_term_language(
                        $term->term_id,
                        'slug'
                    );
                    $language['slug'] = $language['code'];
                    $language['id'] = Relay::toGlobalId(
                        'Language',
                        $language['code']
                    );
                }

                if (isset($fields['name'])) {
                    $language['name'] = pll_get_term_language(
                        $term->term_id,
                        'name'
                    );
                }

                if (isset($fields['locale'])) {
                    $language['locale'] = pll_get_term_language(
                        $term->term_id,
                        'locale'
                    );
                }

                return $language;
            },
        ]);

        register_graphql_field($type, 'translations', [
            'type' => [
                'list_of' => $type,
            ],
            'description' => __(
                'List all translated versions of this term',
                'wp-graphql-polylang'
            ),
            'resolve' => function (\WP_Term $term) {
                $terms = [];

                foreach (
                    pll_get_term_translations($term->term_id)
                    as $lang => $term_id
                ) {
                    if ($term_id === $term->term_id) {
                        continue;
                    }

                    $translation = get_term($term_id);

                    if (!$translation) {
                        continue;
                    }

                    if (is_wp_error($translation)) {
                        continue;
                    }

                    $terms[] = $translation;
                }

                return $terms;
            },
        ]);
    }
}