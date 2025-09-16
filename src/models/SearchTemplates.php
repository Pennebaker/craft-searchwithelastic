<?php
/**
 * Search w/Elastic plugin for Craft CMS 5.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\models;

use craft\base\Model;

/**
 * Search Templates Model
 *
 * Defines all Elasticsearch search templates used for secure query execution.
 * Templates use Mustache syntax for parameter substitution.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class SearchTemplates extends Model
{
    /**
     * Template ID constants for type safety
     * @since 4.0.0
     */
    public const TEMPLATE_BASIC_SEARCH = 'craft_basic_search';
    /** @since 4.0.0 */
    public const TEMPLATE_FUZZY_SEARCH = 'craft_fuzzy_search';
    /** @since 4.0.0 */
    public const TEMPLATE_EXACT_SEARCH = 'craft_exact_search';
    /** @since 4.0.0 */
    public const TEMPLATE_WILDCARD_SEARCH = 'craft_wildcard_search';
    /** @since 4.0.0 */
    public const TEMPLATE_PHRASE_SEARCH = 'craft_phrase_search';
    /** @since 4.0.0 */
    public const TEMPLATE_FILTERED_SEARCH = 'craft_filtered_search';
    /** @since 4.0.0 */
    public const TEMPLATE_RANGE_SEARCH = 'craft_range_search';
    /** @since 4.0.0 */
    public const TEMPLATE_AGGREGATION_SEARCH = 'craft_aggregation_search';
    /** @since 4.0.0 */
    public const TEMPLATE_MULTI_FIELD_SEARCH = 'craft_multi_field_search';
    /** @since 4.0.0 */
    public const TEMPLATE_BOOSTED_SEARCH = 'craft_boosted_search';

    /**
     * Get all search templates
     *
     * @return array Array of template definitions keyed by template ID
     * @since 4.0.0
     */
    public static function getAllTemplates(): array
    {
        return [
            self::TEMPLATE_BASIC_SEARCH => self::getBasicSearchTemplate(),
            self::TEMPLATE_FUZZY_SEARCH => self::getFuzzySearchTemplate(),
            self::TEMPLATE_EXACT_SEARCH => self::getExactSearchTemplate(),
            self::TEMPLATE_WILDCARD_SEARCH => self::getWildcardSearchTemplate(),
            self::TEMPLATE_PHRASE_SEARCH => self::getPhraseSearchTemplate(),
            self::TEMPLATE_FILTERED_SEARCH => self::getFilteredSearchTemplate(),
            self::TEMPLATE_RANGE_SEARCH => self::getRangeSearchTemplate(),
            self::TEMPLATE_AGGREGATION_SEARCH => self::getAggregationSearchTemplate(),
            self::TEMPLATE_MULTI_FIELD_SEARCH => self::getMultiFieldSearchTemplate(),
            self::TEMPLATE_BOOSTED_SEARCH => self::getBoostedSearchTemplate(),
        ];
    }

    /**
     * Get required parameters for a specific template
     *
     * @param string $templateId The template ID
     * @return array Array of required parameter names
     * @since 4.0.0
     */
    public static function getRequiredParameters(string $templateId): array
    {
        $requirements = [
            self::TEMPLATE_BASIC_SEARCH => ['query_text', 'search_fields'],
            self::TEMPLATE_FUZZY_SEARCH => ['query_text', 'search_fields'],
            self::TEMPLATE_EXACT_SEARCH => ['query_text', 'search_fields'],
            self::TEMPLATE_WILDCARD_SEARCH => ['query_text', 'search_fields'],
            self::TEMPLATE_PHRASE_SEARCH => ['query_text', 'search_fields'],
            self::TEMPLATE_FILTERED_SEARCH => ['query_text', 'search_fields', 'filters'],
            self::TEMPLATE_RANGE_SEARCH => ['field_name'],
            self::TEMPLATE_AGGREGATION_SEARCH => ['aggregations'],
            self::TEMPLATE_MULTI_FIELD_SEARCH => ['query_text', 'search_fields'],
            self::TEMPLATE_BOOSTED_SEARCH => ['query_text', 'search_fields', 'field_boosts'],
        ];

        return $requirements[$templateId] ?? [];
    }

    /**
     * Basic search template for simple text matching
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getBasicSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'bool' => [
                        'should' => [
                            '{{#search_fields}}' => [
                                'match' => [
                                    '{{.}}' => '{{query_text}}'
                                ]
                            ],
                            '{{/search_fields}}' => null
                        ],
                        'minimum_should_match' => 1
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Fuzzy search template with wildcard and fuzzy matching
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getFuzzySearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'multi_match' => [
                                    'query' => '{{query_text}}',
                                    'fields' => '{{#search_fields}}{{.}} {{/search_fields}}',
                                    'fuzziness' => '{{#fuzziness}}{{fuzziness}}{{/fuzziness}}{{^fuzziness}}AUTO{{/fuzziness}}',
                                    'prefix_length' => 2,
                                    'max_expansions' => 50
                                ]
                            ],
                            '{{#use_wildcards}}' => [
                                '{{#search_fields}}' => [
                                    'wildcard' => [
                                        '{{.}}' => [
                                            'value' => '*{{query_text}}*',
                                            'case_insensitive' => true
                                        ]
                                    ]
                                ],
                                '{{/search_fields}}' => null
                            ],
                            '{{/use_wildcards}}' => null
                        ]
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Exact search template for precise matching
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getExactSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'bool' => [
                        'should' => [
                            '{{#search_fields}}' => [
                                'term' => [
                                    '{{.}}.keyword' => '{{query_text}}'
                                ]
                            ],
                            '{{/search_fields}}' => null
                        ],
                        'minimum_should_match' => 1
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Wildcard search template
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getWildcardSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'bool' => [
                        'should' => [
                            '{{#search_fields}}' => [
                                'wildcard' => [
                                    '{{.}}' => [
                                        'value' => '{{query_text}}',
                                        'case_insensitive' => true
                                    ]
                                ]
                            ],
                            '{{/search_fields}}' => null
                        ]
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Phrase search template for exact phrase matching
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getPhraseSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'multi_match' => [
                        'query' => '{{query_text}}',
                        'fields' => '{{#search_fields}}{{.}} {{/search_fields}}',
                        'type' => 'phrase',
                        'slop' => '{{#slop}}{{slop}}{{/slop}}{{^slop}}0{{/slop}}'
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Filtered search template with additional filter clauses
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getFilteredSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'multi_match' => [
                                    'query' => '{{query_text}}',
                                    'fields' => '{{#search_fields}}{{.}} {{/search_fields}}'
                                ]
                            ]
                        ],
                        'filter' => [
                            '{{#filters}}' => [
                                'term' => [
                                    '{{field}}' => '{{value}}'
                                ]
                            ],
                            '{{/filters}}' => null
                        ]
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Range search template for date and numeric ranges
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getRangeSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'range' => [
                        '{{field_name}}' => [
                            '{{#gte}}gte{{/gte}}{{^gte}}{{#gt}}gt{{/gt}}{{/gte}}' => '{{#gte}}{{gte}}{{/gte}}{{^gte}}{{#gt}}{{gt}}{{/gt}}{{/gte}}',
                            '{{#lte}}lte{{/lte}}{{^lte}}{{#lt}}lt{{/lt}}{{/lte}}' => '{{#lte}}{{lte}}{{/lte}}{{^lte}}{{#lt}}{{lt}}{{/lt}}{{/lte}}'
                        ]
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Aggregation search template for faceted search
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getAggregationSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'size' => '{{#size}}{{size}}{{/size}}{{^size}}20{{/size}}',
                '{{#query_text}}' => [
                    'query' => [
                        'multi_match' => [
                            'query' => '{{query_text}}',
                            'fields' => '{{#search_fields}}{{.}} {{/search_fields}}{{^search_fields}}title content{{/search_fields}}',
                            'type' => 'best_fields'
                        ]
                    ]
                ],
                '{{/query_text}}' => null,
                'aggs' => [
                    '{{#aggregations}}' => [
                        '{{name}}' => [
                            '{{type}}' => [
                                'field' => '{{field}}',
                                '{{#size}}size{{/size}}' => '{{#size}}{{size}}{{/size}}'
                            ]
                        ]
                    ],
                    '{{/aggregations}}' => null
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Multi-field search template with field-specific options
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getMultiFieldSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'bool' => [
                        'should' => [
                            [
                                'multi_match' => [
                                    'query' => '{{query_text}}',
                                    'fields' => '{{#search_fields}}{{.}} {{/search_fields}}',
                                    'type' => '{{#match_type}}{{match_type}}{{/match_type}}{{^match_type}}best_fields{{/match_type}}',
                                    'tie_breaker' => '{{#tie_breaker}}{{tie_breaker}}{{/tie_breaker}}{{^tie_breaker}}0.3{{/tie_breaker}}',
                                    'minimum_should_match' => '{{#minimum_should_match}}{{minimum_should_match}}{{/minimum_should_match}}{{^minimum_should_match}}1{{/minimum_should_match}}'
                                ]
                            ]
                        ]
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Boosted search template with field-specific boost values
     *
     * @return array The template definition
     * @since 4.0.0
     */
    protected static function getBoostedSearchTemplate(): array
    {
        return [
            'lang' => 'mustache',
            'source' => json_encode([
                'query' => [
                    'multi_match' => [
                        'query' => '{{query_text}}',
                        'fields' => [
                            '{{#search_fields}}',
                            '{{.}}^{{#field_boosts.}}{{.}}{{/field_boosts.}}{{^field_boosts.}}1{{/field_boosts.}}',
                            '{{/search_fields}}'
                        ],
                        'type' => 'best_fields',
                        'operator' => 'or',
                        'minimum_should_match' => '30%'
                    ]
                ]
            ], JSON_THROW_ON_ERROR)
        ];
    }

    /**
     * Get template by search type
     *
     * @param string $searchType The type of search (basic, fuzzy, exact, etc.)
     * @return string The template ID for the search type
     * @since 4.0.0
     */
    public static function getTemplateForSearchType(string $searchType): string
    {
        $mapping = [
            'basic' => self::TEMPLATE_BASIC_SEARCH,
            'fuzzy' => self::TEMPLATE_FUZZY_SEARCH,
            'exact' => self::TEMPLATE_EXACT_SEARCH,
            'wildcard' => self::TEMPLATE_WILDCARD_SEARCH,
            'phrase' => self::TEMPLATE_PHRASE_SEARCH,
            'filtered' => self::TEMPLATE_FILTERED_SEARCH,
            'range' => self::TEMPLATE_RANGE_SEARCH,
            'aggregation' => self::TEMPLATE_AGGREGATION_SEARCH,
            'multi_field' => self::TEMPLATE_MULTI_FIELD_SEARCH,
            'boosted' => self::TEMPLATE_BOOSTED_SEARCH,
        ];

        return $mapping[$searchType] ?? self::TEMPLATE_BASIC_SEARCH;
    }

    /**
     * Validate template structure
     *
     * @param array $template The template to validate
     * @return bool True if the template is valid
     * @since 4.0.0
     */
    public static function validateTemplate(array $template): bool
    {
        // Check required fields
        if (!isset($template['lang']) || !isset($template['source'])) {
            return false;
        }

        // Validate language
        if (!in_array($template['lang'], ['mustache', 'painless'], true)) {
            return false;
        }

        // Validate source is valid JSON
        if (is_string($template['source'])) {
            try {
                json_decode($template['source'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get template description for documentation
     *
     * @param string $templateId The template ID
     * @return string The template description
     * @since 4.0.0
     */
    public static function getTemplateDescription(string $templateId): string
    {
        $descriptions = [
            self::TEMPLATE_BASIC_SEARCH => 'Basic text search across specified fields',
            self::TEMPLATE_FUZZY_SEARCH => 'Fuzzy search with tolerance for typos and wildcards',
            self::TEMPLATE_EXACT_SEARCH => 'Exact term matching using keyword fields',
            self::TEMPLATE_WILDCARD_SEARCH => 'Pattern matching with wildcards (* and ?)',
            self::TEMPLATE_PHRASE_SEARCH => 'Exact phrase matching with configurable slop',
            self::TEMPLATE_FILTERED_SEARCH => 'Search with additional filter constraints',
            self::TEMPLATE_RANGE_SEARCH => 'Range queries for dates and numeric values',
            self::TEMPLATE_AGGREGATION_SEARCH => 'Aggregation queries for faceted search',
            self::TEMPLATE_MULTI_FIELD_SEARCH => 'Advanced multi-field search with custom options',
            self::TEMPLATE_BOOSTED_SEARCH => 'Search with field-specific boost values',
        ];

        return $descriptions[$templateId] ?? 'Unknown template';
    }
}
