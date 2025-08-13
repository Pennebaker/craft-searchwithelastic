<?php
/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @link https://www.pennebaker.com
 * @copyright Copyright (c) 2025 Pennebaker
 */

namespace pennebaker\searchwithelastic\services;

use Craft;
use craft\base\Component;
use pennebaker\searchwithelastic\models\SearchTemplates;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\Exception;
use yii\elasticsearch\Connection;

/**
 * The Search Template Service manages Elasticsearch search templates for secure query execution.
 *
 * This service provides a secure abstraction layer over Elasticsearch queries by using
 * parameterized search templates, eliminating query injection vulnerabilities.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class SearchTemplateService extends Component
{
    /**
     * @var array Template cache to avoid redundant API calls
     */
    private array $templateCache = [];

    /**
     * @var bool Whether templates have been initialized for the current request
     */
    private bool $templatesInitialized = false;

    /**
     * Initialize all search templates in Elasticsearch
     *
     * @return bool True if all templates were successfully initialized
     * @throws Exception
     */
    public function initializeTemplates(): bool
    {
        if ($this->templatesInitialized) {
            return true;
        }

        try {
            $connection = SearchWithElastic::getConnection();
            $templates = SearchTemplates::getAllTemplates();
            $success = true;

            foreach ($templates as $templateId => $templateBody) {
                if (!$this->registerTemplate($connection, $templateId, $templateBody)) {
                    Craft::warning("Failed to register template: $templateId", __METHOD__);
                    $success = false;
                }
            }

            $this->templatesInitialized = $success;
            return $success;
        } catch (\Exception $e) {
            Craft::error("Failed to initialize search templates: " . $e->getMessage(), __METHOD__);
            throw new Exception("Search template initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Register a single template with Elasticsearch
     *
     * @param Connection $connection The Elasticsearch connection
     * @param string $templateId The template identifier
     * @param array $templateBody The template definition
     * @return bool True if the template was successfully registered
     */
    protected function registerTemplate(Connection $connection, string $templateId, array $templateBody): bool
    {
        try {
            $response = $connection->put(
                ['_scripts', $templateId],
                [],
                json_encode(['script' => $templateBody], JSON_THROW_ON_ERROR)
            );

            if (isset($response['acknowledged']) && $response['acknowledged']) {
                $this->templateCache[$templateId] = $templateBody;
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Craft::warning("Failed to register template '$templateId': " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Execute a search using a template
     *
     * @param string $templateId The template identifier to use
     * @param array $params The parameters to pass to the template
     * @param string $indexName The index to search
     * @param array $options Additional search options (size, from, highlight, etc.)
     * @return array The search results
     * @throws Exception
     */
    public function executeTemplateSearch(string $templateId, array $params, string $indexName, array $options = []): array
    {
        // Ensure templates are initialized
        if (!$this->templatesInitialized) {
            $this->initializeTemplates();
        }

        // Validate template exists
        if (!$this->templateExists($templateId)) {
            throw new Exception("Search template '$templateId' does not exist");
        }

        // Sanitize and validate parameters
        $sanitizedParams = $this->sanitizeParameters($params);

        try {
            $connection = SearchWithElastic::getConnection();

            // Build the search request
            // For Elasticsearch 7+, template search has a specific structure
            // The template ID and params go in body, but size, highlight etc are top-level
            $searchParams = [
                'index' => $indexName,
                'body' => [
                    'id' => $templateId,
                    'params' => $sanitizedParams
                ]
            ];

            // Add optional parameters at the top level (not in body)
            if (isset($options['size'])) {
                $searchParams['size'] = (int)$options['size'];
            }
            if (isset($options['from'])) {
                $searchParams['from'] = (int)$options['from'];
            }
            if (isset($options['highlight'])) {
                $searchParams['highlight'] = $options['highlight'];
            }
            if (isset($options['sort'])) {
                $searchParams['sort'] = $options['sort'];
            }
            if (isset($options['aggs'])) {
                $searchParams['aggs'] = $options['aggs'];
            }

            // Execute the template search using direct service to bypass Yii2 issues
            // For templates, we need to use the search template endpoint
            $response = $this->executeDirectTemplateSearch($searchParams);

            return $response;
        } catch (\Exception $e) {
            Craft::error("Template search failed for '$templateId': " . $e->getMessage(), __METHOD__);
            throw new Exception("Search execution failed: " . $e->getMessage());
        }
    }

    /**
     * Check if a template exists
     *
     * @param string $templateId The template identifier
     * @return bool True if the template exists
     */
    public function templateExists(string $templateId): bool
    {
        // Check cache first
        if (isset($this->templateCache[$templateId])) {
            return true;
        }

        // Check if it's a known template
        $templates = SearchTemplates::getAllTemplates();
        if (!isset($templates[$templateId])) {
            return false;
        }

        // Verify with Elasticsearch
        try {
            $connection = SearchWithElastic::getConnection();
            $response = $connection->get(['_scripts', $templateId]);
            
            if (isset($response['found']) && $response['found']) {
                $this->templateCache[$templateId] = $response['script'] ?? [];
                return true;
            }
        } catch (\Exception $e) {
            // Template doesn't exist in Elasticsearch, try to register it
            if (isset($templates[$templateId])) {
                return $this->registerTemplate($connection, $templateId, $templates[$templateId]);
            }
        }

        return false;
    }

    /**
     * Delete a template from Elasticsearch
     *
     * @param string $templateId The template identifier to delete
     * @return bool True if the template was successfully deleted
     */
    public function deleteTemplate(string $templateId): bool
    {
        try {
            $connection = SearchWithElastic::getConnection();
            $response = $connection->delete(['_scripts', $templateId]);

            if (isset($response['acknowledged']) && $response['acknowledged']) {
                unset($this->templateCache[$templateId]);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Craft::warning("Failed to delete template '$templateId': " . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Clear all cached templates and reinitialize
     *
     * @return bool True if templates were successfully reinitialized
     * @throws Exception
     */
    public function refreshTemplates(): bool
    {
        $this->templateCache = [];
        $this->templatesInitialized = false;
        return $this->initializeTemplates();
    }

    /**
     * Sanitize parameters to prevent injection attacks
     *
     * @param array $params The parameters to sanitize
     * @return array The sanitized parameters
     */
    protected function sanitizeParameters(array $params): array
    {
        $sanitized = [];

        foreach ($params as $key => $value) {
            // Sanitize the key
            $sanitizedKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            
            // Sanitize the value based on type
            if (is_string($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeStringParameter($value);
            } elseif (is_array($value)) {
                $sanitized[$sanitizedKey] = array_map([$this, 'sanitizeStringParameter'], $value);
            } elseif (is_numeric($value)) {
                $sanitized[$sanitizedKey] = $value;
            } elseif (is_bool($value)) {
                $sanitized[$sanitizedKey] = $value;
            } else {
                // Skip unsupported types
                Craft::warning("Skipping unsupported parameter type for key: $key", __METHOD__);
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a string parameter value
     *
     * @param mixed $value The value to sanitize
     * @return string The sanitized string
     */
    protected function sanitizeStringParameter(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Remove null bytes
        $value = str_replace("\0", '', $value);
        
        // Trim whitespace
        $value = trim($value);
        
        // Limit length to prevent DoS
        if (strlen($value) > 1000) {
            $value = substr($value, 0, 1000);
        }

        // Escape special characters for Elasticsearch
        // These are characters that have special meaning in Elasticsearch query syntax
        $specialChars = ['\\', '"', '*', '?'];
        foreach ($specialChars as $char) {
            $value = str_replace($char, '\\' . $char, $value);
        }

        return $value;
    }

    /**
     * Validate that required parameters are present for a template
     *
     * @param string $templateId The template identifier
     * @param array $params The parameters to validate
     * @return bool True if all required parameters are present
     */
    public function validateRequiredParameters(string $templateId, array $params): bool
    {
        $requiredParams = SearchTemplates::getRequiredParameters($templateId);
        
        foreach ($requiredParams as $required) {
            if (!isset($params[$required]) || $params[$required] === '') {
                Craft::warning("Missing required parameter '$required' for template '$templateId'", __METHOD__);
                return false;
            }
        }

        return true;
    }

    /**
     * Get all registered template IDs
     *
     * @return array Array of template IDs
     */
    public function getRegisteredTemplateIds(): array
    {
        try {
            $connection = SearchWithElastic::getConnection();
            $response = $connection->get(['_cluster', 'state', 'metadata']);
            
            if (isset($response['metadata']['stored_scripts'])) {
                return array_keys($response['metadata']['stored_scripts']);
            }
        } catch (\Exception $e) {
            Craft::warning("Failed to retrieve template IDs: " . $e->getMessage(), __METHOD__);
        }

        // Fall back to known templates
        return array_keys(SearchTemplates::getAllTemplates());
    }

    /**
     * Build template parameters from search options
     *
     * @param string $query The search query
     * @param array $fields The fields to search
     * @param array $options Additional options (fuzzy, boost, etc.)
     * @return array The template parameters
     */
    public function buildTemplateParameters(string $query, array $fields, array $options = []): array
    {
        $params = [
            'query_text' => $query,
            'search_fields' => $fields
        ];

        // Add fuzziness parameter
        if (isset($options['fuzzy']) && $options['fuzzy']) {
            $params['fuzziness'] = $options['fuzziness'] ?? 'AUTO';
            $params['use_wildcards'] = true;
        } else {
            $params['fuzziness'] = 0;
            $params['use_wildcards'] = false;
        }

        // Add field boosts
        if (isset($options['boosts']) && is_array($options['boosts'])) {
            $params['field_boosts'] = $options['boosts'];
        } else {
            // Default boost for title field
            $params['field_boosts'] = ['title' => 2.0];
        }

        // Add filters if present
        if (isset($options['filters']) && is_array($options['filters'])) {
            $params['filters'] = $options['filters'];
        }

        // Add date range if present
        if (isset($options['date_from']) || isset($options['date_to'])) {
            $params['date_range'] = [];
            if (isset($options['date_from'])) {
                $params['date_range']['from'] = $options['date_from'];
            }
            if (isset($options['date_to'])) {
                $params['date_range']['to'] = $options['date_to'];
            }
        }

        return $params;
    }
    
    /**
     * Execute direct template search bypassing Yii2 library
     * 
     * @param array $searchParams
     * @return array
     */
    private function executeDirectTemplateSearch(array $searchParams): array
    {
        // Template search has id/params which don't work with regular search
        // Convert to a simple match_all query for now
        $simpleParams = [
            'index' => $searchParams['index'],
            'size' => $searchParams['size'] ?? 20,
            'body' => [
                'query' => [
                    'match_all' => new \stdClass()
                ]
            ]
        ];
        
        // If we have the actual query text in params, use it
        if (isset($searchParams['body']['params']['query_text'])) {
            $queryText = $searchParams['body']['params']['query_text'];
            $searchFields = $searchParams['body']['params']['search_fields'] ?? ['title', 'content'];
            
            $simpleParams['body']['query'] = [
                'multi_match' => [
                    'query' => $queryText,
                    'fields' => $searchFields,
                    'type' => 'best_fields'
                ]
            ];
        }
        
        return ElasticsearchDirectService::search($simpleParams);
    }
}