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

namespace pennebaker\searchwithelastic\services;

use craft\base\Component;
use pennebaker\searchwithelastic\models\SearchTemplates;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * The Elasticsearch Query Builder service provides specialized query construction functionality.
 *
 * This service handles the building of complex Elasticsearch queries using secure templates,
 * parameter preparation, and optimization of search parameters for different types of searches.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ElasticsearchQueryBuilder extends Component
{
    /**
     * @var SearchTemplateService|null The template service instance
     * @since 4.0.0
     */
    private ?SearchTemplateService $templateService = null;

    /**
     * Get the template service instance
     *
     * @return SearchTemplateService
     * @since 4.0.0
     */
    protected function getTemplateService(): SearchTemplateService
    {
        if ($this->templateService === null) {
            $this->templateService = SearchWithElastic::getInstance()->searchTemplates;
        }
        return $this->templateService;
    }
    /**
     * Build search query based on search type and fields
     * This method now returns template parameters instead of raw queries for security
     *
     * @param string $query The search query
     * @param array $fields The fields to search in
     * @param bool $fuzzy Whether to use fuzzy matching
     * @return array Array with 'template_id' and 'params' for template execution
     * @since 4.0.0
     */
    public function buildSearchQuery(string $query, array $fields, bool $fuzzy): array
    {
        // Handle empty query - return match_all
        if (empty(trim($query))) {
            return ['match_all' => (object)[]];
        }

        // Prepare template parameters
        $templateService = $this->getTemplateService();
        
        if ($fuzzy) {
            $templateId = SearchTemplates::TEMPLATE_FUZZY_SEARCH;
            $params = $templateService->buildTemplateParameters($query, $fields, [
                'fuzzy' => true,
                'fuzziness' => 'AUTO',
                'boosts' => ['title' => 2.0]
            ]);
        } else {
            $templateId = SearchTemplates::TEMPLATE_BOOSTED_SEARCH;
            $params = $templateService->buildTemplateParameters($query, $fields, [
                'fuzzy' => false,
                'boosts' => ['title' => 2.0]
            ]);
        }

        return [
            'template_id' => $templateId,
            'params' => $params
        ];
    }

    /**
     * Build fuzzy search template parameters
     *
     * @param string $query The search query
     * @param array $fields The fields to search in
     * @return array Template parameters for fuzzy search
     * @since 4.0.0
     */
    protected function buildFuzzyTemplateParams(string $query, array $fields): array
    {
        $templateService = $this->getTemplateService();
        
        return $templateService->buildTemplateParameters($query, $fields, [
            'fuzzy' => true,
            'fuzziness' => 'AUTO',
            'use_wildcards' => true,
            'boosts' => $this->getFieldBoosts($fields)
        ]);
    }

    /**
     * Build exact search template parameters
     *
     * @param string $query The search query
     * @param array $fields The fields to search in
     * @return array Template parameters for exact search
     * @since 4.0.0
     */
    protected function buildExactTemplateParams(string $query, array $fields): array
    {
        $templateService = $this->getTemplateService();
        
        return $templateService->buildTemplateParameters($query, $fields, [
            'fuzzy' => false,
            'boosts' => $this->getFieldBoosts($fields)
        ]);
    }

    /**
     * Build aggregation template parameters for faceted search
     *
     * @param array $facets The facets to aggregate on
     * @return array Template parameters for aggregation
     * @since 4.0.0
     */
    public function buildAggregationTemplateParams(array $facets): array
    {
        $aggregations = [];
        
        foreach ($facets as $name => $config) {
            $aggregations[] = [
                'name' => is_string($name) ? $name : 'agg_' . $config['field'],
                'type' => 'terms',
                'field' => $config['field'],
                'size' => $config['size'] ?? 10
            ];
        }
        
        return [
            'aggregations' => $aggregations
        ];
    }

    /**
     * Build filter template parameters for advanced search filtering
     *
     * @param array $filters The filters to apply
     * @return array Template parameters for filtered search
     * @since 4.0.0
     */
    public function buildFilterTemplateParams(array $filters): array
    {
        $filterParams = [];
        
        foreach ($filters as $field => $value) {
            if (is_array($value)) {
                // Multiple values - add each as separate filter
                foreach ($value as $val) {
                    $filterParams[] = [
                        'field' => $field,
                        'value' => $val
                    ];
                }
            } else {
                // Single value
                $filterParams[] = [
                    'field' => $field,
                    'value' => $value
                ];
            }
        }
        
        return [
            'filters' => $filterParams
        ];
    }

    /**
     * Build range template parameters for date/numeric filtering
     *
     * @param string $field The field to filter on
     * @param mixed|null $from The minimum value (optional)
     * @param mixed|null $to The maximum value (optional)
     * @return array Template parameters for range search
     * @since 4.0.0
     */
    public function buildRangeTemplateParams(string $field, mixed $from = null, mixed $to = null): array
    {
        $params = [
            'field_name' => $field
        ];
        
        if ($from !== null) {
            $params['gte'] = $from;
        }
        
        if ($to !== null) {
            $params['lte'] = $to;
        }
        
        return $params;
    }

    /**
     * Get field boosts based on field names
     *
     * @param array $fields The fields to get boosts for
     * @return array Field boost mapping
     * @since 4.0.0
     */
    protected function getFieldBoosts(array $fields): array
    {
        $boosts = [];
        
        foreach ($fields as $field) {
            // Title fields get higher boost
            $boosts[$field] = $field === 'title' ? 2.0 : 1.0;
        }
        
        return $boosts;
    }

    /**
     * Execute a template-based search
     *
     * @param string $indexName The index to search
     * @param string $templateId The template ID to use
     * @param array $params The template parameters
     * @param array $options Additional search options
     * @return array The search results
     * @throws \Exception
     * @since 4.0.0
     */
    public function executeTemplateSearch(string $indexName, string $templateId, array $params, array $options = []): array
    {
        $templateService = $this->getTemplateService();
        
        // Validate required parameters
        if (!$templateService->validateRequiredParameters($templateId, $params)) {
            throw new \Exception("Missing required parameters for template: $templateId");
        }
        
        // Execute the search
        return $templateService->executeTemplateSearch($templateId, $params, $indexName, $options);
    }

    /**
     * Build a complex query combining multiple search types
     *
     * @param array $queryParts Array of query parts with types and parameters
     * @return array Combined query structure
     * @since 4.0.0
     */
    public function buildComplexQuery(array $queryParts): array
    {
        $boolQuery = [
            'must' => [],
            'should' => [],
            'filter' => [],
            'must_not' => []
        ];
        
        foreach ($queryParts as $part) {
            $clause = $part['clause'] ?? 'must';
            $type = $part['type'] ?? 'match';
            $params = $part['params'] ?? [];
            
            switch ($type) {
                case 'range':
                    $rangeParams = $this->buildRangeTemplateParams(
                        $params['field'],
                        $params['from'] ?? null,
                        $params['to'] ?? null
                    );
                    if (!empty($rangeParams)) {
                        $boolQuery[$clause][] = ['range' => [$params['field'] => $rangeParams]];
                    }
                    break;
                    
                case 'filter':
                    $filterParams = $this->buildFilterTemplateParams($params['filters'] ?? []);
                    if (!empty($filterParams['filters'])) {
                        foreach ($filterParams['filters'] as $filter) {
                            $boolQuery[$clause][] = ['term' => [$filter['field'] => $filter['value']]];
                        }
                    }
                    break;
                    
                default:
                    // Handle other query types
                    break;
            }
        }
        
        // Clean up empty clauses
        $boolQuery = array_filter($boolQuery);
        
        return empty($boolQuery) ? ['match_all' => (object)[]] : ['bool' => $boolQuery];
    }
}
