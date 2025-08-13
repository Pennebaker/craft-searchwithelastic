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

use pennebaker\searchwithelastic\helpers\ElasticsearchHelper;

/**
 * Search w/Elastic Configuration Template
 *
 * Configuration template for the Search w/Elastic plugin. Copy this file to
 * 'craft/config' as 'search-with-elastic.php' and customize the settings for
 * your environment. Supports multi-environment configuration like general.php.
 */

return [
    // Elasticsearch connection settings
    'elasticsearchEndpoint'        => 'elasticsearch:9200',
    'isAuthEnabled'                => false, // IMPORTANT: Default is false - set to true when using authentication
    'username'                     => '', // Required when isAuthEnabled is true
    'password'                     => '', // Required when isAuthEnabled is true
    
    // SECURITY BEST PRACTICE:
    // Use environment variables for credentials:
    // 'elasticsearchEndpoint' => '$ELASTICSEARCH_ENDPOINT',
    // 'username'              => '$ELASTICSEARCH_USERNAME',
    // 'password'              => '$ELASTICSEARCH_PASSWORD',

    // Index naming configuration
    'indexPrefix' => 'craft-', // Base prefix for all index names (e.g., 'craft-elements_1')
    // 'indexPrefix' => 'mysite-', // Custom prefix for multiple Craft instances
    // 'indexPrefix' => '', // No prefix

    'fallbackIndexName' => 'elements', // Default index name suffix when no element-specific override is set
    // 'fallbackIndexName' => 'content', // Alternative fallback name

    // Element type specific index name overrides (just the suffix part)
    'elementTypeIndexNames' => [
        'craft\\elements\\Entry' => '', // Default: uses fallback ('elements')
        'craft\\elements\\Asset' => '', // Default: uses fallback ('elements')
        'craft\\elements\\Category' => '', // Default: uses fallback ('elements')
        'craft\\commerce\\elements\\Product' => '', // Default: uses fallback ('elements')
        'craft\\digitalproducts\\elements\\Product' => '', // Default: uses fallback ('elements')
    ],
    // 'elementTypeIndexNames' => [
    //     'craft\\elements\\Entry' => 'entries', // Results in: 'craft-entries_1'
    //     'craft\\elements\\Asset' => 'assets', // Results in: 'craft-assets_1'
    //     'craft\\elements\\Category' => 'categories', // Results in: 'craft-categories_1'
    //     'craft\\commerce\\elements\\Product' => 'products', // Results in: 'craft-products_1'
    //     'craft\\digitalproducts\\elements\\Product' => 'digital-products', // Results in: 'craft-digital-products_1'
    // ],

    // Search result highlighting configuration
    'highlight' => [
        'pre_tags'  => '',
        'post_tags' => '',
    ],
    // 'highlight' => [
    //     'pre_tags'  => '<mark>',
    //     'post_tags' => '</mark>',
    // ],

    // Asset kinds configuration - which asset types should be indexed
    'assetKinds' => ['pdf'], // Default: only index PDF assets
    // 'assetKinds' => ['pdf', 'text', 'html', 'json', 'xml'], // Index text-based assets
    // 'assetKinds' => ['pdf', 'text', 'html', 'json', 'xml', 'word', 'excel', 'powerpoint'], // Index document assets
    // 'assetKinds' => ['image', 'video', 'audio'], // Index media assets (metadata only)
    // Available kinds: 'access', 'audio', 'captions_subtitles', 'compressed', 'excel', 'flash', 'html',
    //                  'illustrator', 'image', 'javascript', 'json', 'pdf', 'photoshop', 'php',
    //                  'powerpoint', 'text', 'video', 'word', 'xml', 'unknown'

    // Element type excludes - exclude specific element types from indexing entirely
    'excludedEntryTypes' => [], // Default: index all entry types
    // 'excludedEntryTypes' => ['internalPages', 'adminNotes'], // Exclude specific entry types by handle

    'excludedAssetVolumes' => [], // Default: index all asset volumes
    // 'excludedAssetVolumes' => ['privateFiles', 'adminAssets'], // Exclude specific asset volumes by handle

    // Asset kind-specific frontend fetching configuration
    'frontendFetchingAssetKinds' => [],
    // 'frontendFetchingAssetKinds' => ['text', 'html', 'json', 'xml'], // Enable frontend fetching for text-based assets
    // 'frontendFetchingAssetKinds' => ['text', 'html', 'json', 'xml', 'javascript', 'php'], // Enable all supported text-based asset kinds
    // 'frontendFetchingAssetKinds' => [], // Disable frontend fetching for all asset kinds

    // Frontend content fetching configuration
    'enableFrontendFetching' => true, // Default: enable frontend content fetching via HTTP requests
    // 'enableFrontendFetching' => false, // Disable frontend fetching globally - elements will be indexed with metadata only

    'indexElementsWithoutUrls' => true, // Default: index elements without URLs using their metadata
    // 'indexElementsWithoutUrls' => false, // Skip indexing elements that don't have URLs

    // Frontend fetching excludes - disable frontend content fetching for specific element types
    // (elements will still be indexed with their metadata)
    'excludedFrontendFetchingEntryTypes' => [], // Default: fetch frontend content for all entry types
    // 'excludedFrontendFetchingEntryTypes' => ['news', 'internalPages'], // Skip frontend fetching for specific entry types

    'excludedFrontendFetchingAssetVolumes' => [], // Default: fetch frontend content for all asset volumes
    // 'excludedFrontendFetchingAssetVolumes' => ['privateFiles', 'internalAssets'], // Skip frontend fetching for specific volumes

    'excludedFrontendFetchingCategoryGroups' => [], // Default: fetch frontend content for all category groups
    // 'excludedFrontendFetchingCategoryGroups' => ['internalCategories', 'adminCategories'], // Skip frontend fetching for specific category groups

    'excludedFrontendFetchingProductTypes' => [], // Default: fetch frontend content for all product types (Commerce)
    // 'excludedFrontendFetchingProductTypes' => ['internalProducts', 'adminProducts'], // Skip frontend fetching for specific product types

    'excludedFrontendFetchingDigitalProductTypes' => [], // Default: fetch frontend content for all digital product types (Digital Products)
    // 'excludedFrontendFetchingDigitalProductTypes' => ['internalDigitalProducts'], // Skip frontend fetching for specific digital product types

    // Indexable element statuses configuration
    // Choose which element statuses should be indexed in Elasticsearch
    'indexableEntryStatuses' => ['pending', 'live'], // Default: index both pending and live entries
    // 'indexableEntryStatuses' => ['live'], // Only index live entries
    // 'indexableEntryStatuses' => ['pending', 'live', 'expired'], // Include expired entries for search history
    // Available statuses: 'pending' (Post Date in future or not set), 'live' (Post Date in past), 'expired' (Expiry Date set and in past), 'disabled' (Element is disabled)

    'indexableProductStatuses' => ['pending', 'live'], // Default: index both pending and live products (Commerce)
    // 'indexableProductStatuses' => ['live'], // Only index live products

    'indexableDigitalProductStatuses' => ['pending', 'live'], // Default: index both pending and live digital products (Digital Products)
    // 'indexableDigitalProductStatuses' => ['live'], // Only index live digital products

    'indexableCategoryStatuses' => ['enabled'], // Default: index only enabled categories
    // 'indexableCategoryStatuses' => ['enabled', 'disabled'], // Index both enabled and disabled categories
    // Available statuses: 'enabled' (Category is enabled), 'disabled' (Category is disabled)

    'excludedCategoryGroups' => [], // Default: index all category groups
    // 'excludedCategoryGroups' => ['internalCategories', 'adminOnly'], // Exclude specific category groups by handle

    'excludedProductTypes' => [], // Default: index all product types (Commerce)
    // 'excludedProductTypes' => ['internalProducts', 'adminOnly'], // Exclude specific product types by handle

    'excludedDigitalProductTypes' => [], // Default: index all digital product types (Digital Products)
    // 'excludedDigitalProductTypes' => ['internalDigitalProducts', 'adminOnly'], // Exclude specific digital product types by handle

    // Optional callbacks for advanced content processing

    // Extract specific content from fetched HTML before indexing
    // 'contentExtractorCallback' => function (string $entryContent) {
    //     if (preg_match('/<!-- BEGIN elasticsearch indexed content -->(.*)<!-- END elasticsearch indexed content -->/s', $entryContent, $body)) {
    //         $entryContent = '<!DOCTYPE html>' . trim($body[1]);
    //
    //         // Now lets strip out any HTML and just keep the text content
    //         $entryContent = strip_tags($entryContent);
    //     }
    //     return $entryContent;
    // },

    // Custom element content provider (overrides default frontend fetching)
    // 'elementContentCallback' => function (\craft\base\ElementInterface $element) {
    //      // Return custom HTML content for this element
    //     return '<span>Custom content for: ' . $element->title . '</span>';
    // },

    // Format search results before returning to templates
    // 'resultFormatterCallback' => function (array $formattedResult, $result) {
    //     // Modify the formatted result array
    //     $formattedResult['customField'] = 'custom value';
    //     return $formattedResult;
    // },


    // Advanced Elasticsearch configuration (overrides basic connection settings above)
    // The `elasticsearchEndpoint`, `username`, `password` and `isAuthEnabled` settings are ignored if this is set
    // Uncomment to use advanced cluster configuration
    // 'elasticsearchComponentConfig' => [
    //     'autodetectCluster' => false,
    //     'defaultProtocol'   => 'https', // Use HTTPS for production
    //
    //     'nodes' => [
    //         [
    //             'protocol'     => 'https',
    //             'http_address' => 'elasticsearch:9200',
    //         ],
    //         // Additional nodes for cluster setup
    //         // [
    //         //     'protocol'     => 'https',
    //         //     'http_address' => 'elasticsearch-node-2:9200',
    //         // ],
    //     ],
    //
    //     // SECURITY: Use environment variables for credentials:
    //     'auth' => [
    //         'username' => '$ELASTICSEARCH_USERNAME', // Environment variable
    //         'password' => '$ELASTICSEARCH_PASSWORD', // Environment variable
    //         // 'apiKey' => '$ELASTICSEARCH_API_KEY',  // For API key authentication
    //         // 'token'  => '$ELASTICSEARCH_TOKEN',   // For token-based authentication
    //     ],
    //
    //     'connectionTimeout' => 10,
    //     'dataTimeout'       => 30,
    //     'verifySsl'         => true, // Verify SSL certificates in production
    // ],

    // Additional custom fields to index beyond the default ones
    'extraFields' => [], // Default: no extra fields

    // Rate Limiting Configuration - Prevent DoS attacks on search endpoints
    'rateLimitingEnabled' => false, // Default: disabled - enable to protect search endpoints
    // 'rateLimitingEnabled' => true, // Enable rate limiting in production

    'rateLimitRequestsPerMinute' => 60, // Default: 60 requests per minute
    // 'rateLimitRequestsPerMinute' => 30, // More restrictive for public sites
    // 'rateLimitRequestsPerMinute' => 120, // Less restrictive for authenticated users

    'rateLimitBurstSize' => 10, // Default: allow 10 extra requests above the limit temporarily
    // 'rateLimitBurstSize' => 5, // Stricter burst control
    // 'rateLimitBurstSize' => 20, // More lenient for legitimate traffic spikes

    'rateLimitTrackingMethod' => 'ip', // Default: track by IP address
    // 'rateLimitTrackingMethod' => 'user', // Track by authenticated user ID (requires login)

    'rateLimitExemptIps' => [], // Default: no exempt IPs
    // 'rateLimitExemptIps' => ['127.0.0.1', '::1'], // Exempt localhost
    // 'rateLimitExemptIps' => ['192.168.1.0/24'], // Exempt internal network
    // 'rateLimitExemptIps' => ['$TRUSTED_IP_1', '$TRUSTED_IP_2'], // Use environment variables
    
    // SECURITY NOTES:
    // - Always use environment variables for sensitive credentials
    // - Environment variables are parsed using Craft's built-in parsing
    // - Always use HTTPS in production environments
    // - Regularly rotate your credentials
    // - Enable rate limiting in production to prevent abuse

    // Manual configuration examples (traditional approach):
    // 'extraFields' => [
    //     'customField' => [
    //         'mapping' => [
    //             'type' => 'text',
    //             'analyzer' => 'standard',
    //             'store' => true
    //         ],
    //         'highlighter' => (object)['type' => 'plain'],
    //         'value' => function (\craft\base\ElementInterface $element) {
    //             // Return custom field value for indexing
    //             return $element->customFieldHandle ?? '';
    //         }
    //     ],
    //     'computedField' => [
    //         'mapping' => [
    //             'type' => 'keyword'
    //         ],
    //         'value' => function (\craft\base\ElementInterface $element) {
    //             // Return computed value based on element properties
    //             return strtoupper($element->title ?? '');
    //         }
    //     ]
    // ],

    // Helper method examples (recommended approach):
    // Uncomment the following line to use the helper methods:
    // use pennebaker\searchwithelastic\helpers\ElasticsearchHelper;
    // 'extraFields' => [
    //     // Element type name with default keyword mapping
    //     '__typename' => ElasticsearchHelper::createTypeNameField(),
    //
    //     // Element order/position with default keyword mapping
    //     'order' => ElasticsearchHelper::createOrderField(),
    //
    //     // Simple field value accessors with default keyword mapping
    //     'uri' => ElasticsearchHelper::createFieldValueAccessor('uri'),
    //     'headline' => ElasticsearchHelper::createFieldValueAccessor('headline'),
    //     'text' => ElasticsearchHelper::createFieldValueAccessor('text'),
    //     'bio' => ElasticsearchHelper::createFieldValueAccessor('bio'),
    //     'basicCopy' => ElasticsearchHelper::createFieldValueAccessor('basicCopy'),
    //     'copy' => ElasticsearchHelper::createFieldValueAccessor('copy'),
    //
    //     // Handle accessors (for sections, types, etc.)
    //     'sectionType' => ElasticsearchHelper::createFieldHandleAccessor('section'),
    //     'entryType' => ElasticsearchHelper::createFieldHandleAccessor('type'),
    //
    //     // Image field with no mapping (complex object data)
    //     'image' => ElasticsearchHelper::createImageField('image'),
    //
    //     // Example with custom text mapping for searchable content
    //     'searchableHeadline' => ElasticsearchHelper::createFieldValueAccessor('headline', [
    //         'type' => 'text',
    //         'analyzer' => 'english',
    //         'store' => true
    //     ]),
    //
    //     // Date field with proper date mapping
    //     'publishedDate' => ElasticsearchHelper::createFormattedDateField('postDate'),
    //
    //     // Year extraction with 10-year grouping limit
    //     'publishYear' => ElasticsearchHelper::createYearField('postDate', 10),
    //
    //     // Category relations
    //     'categoryTitles' => ElasticsearchHelper::createRelationTitlesField('categories'),
    //     'categoryParents' => ElasticsearchHelper::createCategoryParentField('categories'),
    //     'categoryChildren' => ElasticsearchHelper::createCategoryChildField('categories'),
    //
    //     // Assets with no mapping (complex object data)
    //     'attachments' => ElasticsearchHelper::createAssetField('assets'),
    //
    //     // Custom field with completely custom mapping
    //     'customSearchField' => ElasticsearchHelper::createFieldValueAccessor('customField', [
    //         'type' => 'text',
    //         'analyzer' => 'standard',
    //         'fields' => [
    //             'keyword' => [
    //                 'type' => 'keyword',
    //                 'ignore_above' => 256
    //             ]
    //         ]
    //     ]),
    // ]
];
