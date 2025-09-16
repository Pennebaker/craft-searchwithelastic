<?php

namespace pennebaker\searchwithelastic\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;

/**
 * Direct Elasticsearch Service
 *
 * Bypasses Yii2 Elasticsearch library issues by making direct HTTP requests
 *
 * @since 4.0.0
 */
class ElasticsearchDirectService extends Component
{
    /**
     * Perform a direct search request to Elasticsearch
     *
     * @param array $params Search parameters
     * @return array Search results
     * @since 4.0.0
     */
    public static function search(array $params): array
    {
        try {
            $settings = \pennebaker\searchwithelastic\SearchWithElastic::getInstance()->getSettings();

            // Build the URL - parse environment variables
            $endpoint = App::parseEnv($settings->elasticsearchEndpoint);
            $endpoint = rtrim($endpoint, '/');
            $index = $params['index'] ?? '_all';
            $url = "{$endpoint}/{$index}/_search";

            // Build the query body
            $body = [];
            if (isset($params['body']['query'])) {
                $body['query'] = $params['body']['query'];
            } elseif (isset($params['body'])) {
                $body = $params['body'];
            } elseif (isset($params['query'])) {
                // Handle direct query parameter (from searchExtra)
                $body['query'] = $params['query'];
            }

            // Fix match_all queries that have empty arrays instead of empty objects
            if (isset($body['query']['match_all']) && is_array($body['query']['match_all']) && empty($body['query']['match_all'])) {
                $body['query']['match_all'] = new \stdClass();
            }

            // Add highlighting to the body
            if (isset($params['highlight'])) {
                $body['highlight'] = $params['highlight'];
            } else {
                // Default highlighting for content field (as shown in the index)
                $body['highlight'] = [
                    'fields' => [
                        'content' => (object)[],
                        'title' => (object)[]
                    ],
                    'pre_tags' => ['<mark>'],
                    'post_tags' => ['</mark>'],
                    'fragment_size' => 150,
                    'number_of_fragments' => 3
                ];
            }

            // Build URL parameters
            $urlParams = [];
            if (isset($params['size'])) {
                $urlParams['size'] = $params['size'];
            }
            if (!empty($urlParams)) {
                $url .= '?' . http_build_query($urlParams);
            }

            // Make the request
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);

            // Add authentication if needed
            if ($settings->isAuthEnabled && $settings->username && $settings->password) {
                $username = App::parseEnv($settings->username);
                $password = App::parseEnv($settings->password);
                curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                // Debug logging
                Craft::info("Elasticsearch URL: $url", __METHOD__);
                Craft::info("HTTP Code: $httpCode", __METHOD__);
                Craft::error("CURL Error: $curlError", __METHOD__);
                return ['hits' => ['hits' => []]];
            }

            if ($httpCode !== 200) {
                Craft::error("Elasticsearch returned status {$httpCode}: {$response}", __METHOD__);
                return ['hits' => ['hits' => []]];
            }

            $result = json_decode($response, true);

            // Ensure each hit has a highlight field even if empty
            if (isset($result['hits']['hits'])) {
                foreach ($result['hits']['hits'] as &$hit) {
                    if (!isset($hit['highlight'])) {
                        $hit['highlight'] = [];
                    }
                    // Ensure content field exists in highlight
                    if (!isset($hit['highlight']['content'])) {
                        $hit['highlight']['content'] = [];
                    }
                    // Check for searchContent field and copy to content for backward compatibility
                    if (isset($hit['highlight']['searchContent']) && !isset($hit['highlight']['content'])) {
                        $hit['highlight']['content'] = $hit['highlight']['searchContent'];
                    }
                }
            }

            return $result;

        } catch (\Exception $e) {
            Craft::error("Direct Elasticsearch search failed: " . $e->getMessage(), __METHOD__);
            return ['hits' => ['hits' => []]];
        }
    }
}
