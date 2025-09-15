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

namespace pennebaker\searchwithelastic\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\commerce\elements\Product;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\App;
use Exception;
use pennebaker\searchwithelastic\helpers\validation\ValidationHelper;
use pennebaker\searchwithelastic\SearchWithElastic;
use pennebaker\searchwithelastic\validators\IndexValidator;
use yii\base\InvalidConfigException;

/**
 * Settings model for the Search with Elastic plugin
 *
 * This model defines all configuration options for the plugin including
 * Elasticsearch connection settings, indexing preferences, element type
 * configurations, and frontend content fetching options.
 *
 * Supports environment variable configuration for sensitive settings like
 * endpoint URLs and authentication credentials using Craft's built-in parsing.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class SettingsModel extends Model
{
    /**
     * Returns the list of behaviors attached to this model
     *
     * Configures environment variable parsing for sensitive settings
     * like endpoint URLs and authentication credentials.
     *
     * @return array<string, mixed> The behavior configurations
     * @since 4.0.0
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['elasticsearchEndpoint', 'username', 'password'],
            ],
        ];
    }

    /** @var string The Elasticsearch instance endpoint - accepts full URL (https://host:port) or hostname:port format */
    public string $elasticsearchEndpoint = 'elasticsearch:9200';

    /** @var bool A boolean indicating whether authentication to the Elasticsearch server is required */
    public bool $isAuthEnabled = false;

    /** @var string [optional] The username used to connect to the Elasticsearch server */
    public string $username = '';

    /** @var string [optional] The password used to connect to the Elasticsearch server */
    public string $password = '';

    /** @var string Base prefix for all index names (e.g., craft-) */
    public string $indexPrefix = 'craft-';

    /** @var string Fallback index name suffix (after prefix, before site ID) */
    public string $fallbackIndexName = 'elements';

    /** @var array Element type specific index name overrides (just the suffix part) */
    public array $elementTypeIndexNames = [
        Entry::class => '',
        Asset::class => '',
        Category::class => '',
        Product::class => '',
        DigitalProduct::class => '',
    ];

    /** @var string|string[]|null A list of asset kinds to be indexed */
    public string|array|null $assetKinds = ['pdf'];

    /** @var array A list of handles of entries types that should not be indexed */
    public array $excludedEntryTypes = [];

    /** @var array A list of handles of asset volumes that should not be indexed */
    public array $excludedAssetVolumes = [];

    /** @var array A list of entry statuses that should be indexed */
    public array $indexableEntryStatuses = ['pending', 'live'];

    /** @var array A list of product statuses that should be indexed */
    public array $indexableProductStatuses = ['pending', 'live'];

    /** @var array A list of digital product statuses that should be indexed */
    public array $indexableDigitalProductStatuses = ['pending', 'live'];

    /** @var array A list of category statuses that should be indexed */
    public array $indexableCategoryStatuses = ['enabled'];

    /** @var array A list of handles of category groups that should not be indexed */
    public array $excludedCategoryGroups = [];

    /** @var array A list of handles of product types that should not be indexed */
    public array $excludedProductTypes = [];

    /** @var array A list of handles of digital product types that should not be indexed */
    public array $excludedDigitalProductTypes = [];

    /** @var bool A boolean indicating whether frontend content fetching via HTTP requests is enabled */
    public bool $enableFrontendFetching = false;
    
    /** @var bool A boolean indicating whether to use CraftCMS searchable fields for indexing */
    public bool $useSearchableFields = true;
    
    /** @var bool A boolean indicating whether to fallback to frontend fetching if searchable fields extraction fails */
    public bool $fallbackToFrontendFetching = true;
    
    /** @var string The Elasticsearch field name for searchable content */
    public string $searchableContentFieldName = 'content';
    
    /** @var string The Elasticsearch field name for frontend fetched content */
    public string $frontendContentFieldName = 'content_fetch';
    
    /** @var array A list of handles of entry types that should NOT use searchable content indexing */
    public array $excludedSearchableContentEntryTypes = [];
    
    /** @var array A list of handles of category groups that should NOT use searchable content indexing */
    public array $excludedSearchableContentCategoryGroups = [];
    
    /** @var array A list of handles of product types that should NOT use searchable content indexing */
    public array $excludedSearchableContentProductTypes = [];
    
    /** @var array A list of handles of digital product types that should NOT use searchable content indexing */
    public array $excludedSearchableContentDigitalProductTypes = [];
    
    /** @var array A list of handles of asset volumes that should NOT use searchable content indexing */
    public array $excludedSearchableContentAssetVolumes = [];

    /** @var array A list of handles of entry types that should NOT use frontend content fetching */
    public array $excludedFrontendFetchingEntryTypes = [];

    /** @var array A list of handles of asset volumes that should NOT use frontend content fetching */
    public array $excludedFrontendFetchingAssetVolumes = [];

    /** @var array A list of handles of category groups that should NOT use frontend content fetching */
    public array $excludedFrontendFetchingCategoryGroups = [];

    /** @var array A list of handles of product types that should NOT use frontend content fetching */
    public array $excludedFrontendFetchingProductTypes = [];

    /** @var array A list of handles of digital product types that should NOT use frontend content fetching */
    public array $excludedFrontendFetchingDigitalProductTypes = [];

    /** @var bool A boolean indicating whether elements without URLs should still be indexed */
    public bool $indexElementsWithoutUrls = true;

    /** @var array A list of asset kinds that should use frontend content fetching */
    public array $frontendFetchingAssetKinds = ['text', 'json', 'xml', 'javascript', 'html'];

    /** @var bool A boolean indicating whether to include detailed debugging information in frontend fetching responses */
    public bool $enableFrontendFetchDebug = false;

    /**
     * @var callable A callback used to extract the indexable content from a page source code.
     *               The only argument is the page source code (rendered template) and it is expected to return a string.
     */
    public $contentExtractorCallback;

    /**
     * @var callable A callback used to get the HTML content of the element to index.
     *               If null, the default Guzzle Client implementation will be used instead to get the content.
     */
    public $elementContentCallback;

    /**
     * @var callable A callback used to prepare and format the Elasticsearch result object in order to be used by the results twig view.
     *               Expect two arguments: first, an array represented initial formatted results, the second, an Elasticsearch record result object.
     */
    public $resultFormatterCallback;

    /** @var array The tags inserted before and after the search term to highlight in search results */
    public array $highlight = [
        'pre_tags'  => '',
        'post_tags' => '',
    ];

    /** @var bool Whether rate limiting is enabled for search endpoints */
    public bool $rateLimitingEnabled = false;

    /** @var int Maximum number of requests allowed per minute */
    public int $rateLimitRequestsPerMinute = 60;

    /** @var int Burst size allowance for rate limiting (allows temporary spikes) */
    public int $rateLimitBurstSize = 10;

    /** @var string Method for tracking rate limits: 'ip' or 'user' */
    public string $rateLimitTrackingMethod = 'ip';

    /** @var array List of IP addresses exempt from rate limiting */
    public array $rateLimitExemptIps = [];

    /**
     * @var array An associative array passed to the yii2-elasticsearch component Connection class constructor.
     * @note If this is set, the $elasticsearchEndpoint, $username, $password and $isAuthEnabled properties will be ignored.
     * @see  https://www.yiiframework.com/extension/yiisoft/yii2-elasticsearch/doc/api/2.1/yii-elasticsearch-connection#properties
     */
    public array|null $elasticsearchComponentConfig = null;

    /**
     * @var array An associative array defining additional fields to be indexed along with the defaults one.
     * Each additional field should be declared as the name of the attribute as the key and an associative array for the value
     * in which the keys can be:
     * - `mapping`: an array providing the elasticsearch mapping definition for the field. For example:
     *   ```php
     *   [
     *        'type'  => 'text',
     *        'store' => true
     *   ]
     *   ```
     * - `highlighter` : an object defining the elasticsearch highlighter behavior for the field. For example: `(object)[]`
     * - `value` : either a string or a callable function taking one argument of \craft\base\Element type and returning the value of the field, for example:
     *   ```php
     *   function (\craft\base\Element $element) {
     *       return ArrayHelper::getValue($element, 'color.hex');
     *   }
     *   ```
     */
    public array $extraFields = [];

    /**
     * Returns the validation rules for attributes
     *
     * Defines comprehensive validation rules for all settings including
     * required fields, data types, format validation, and allowed values.
     *
     * @return array The validation rule configurations
     */
    public function rules(): array
    {
        return [
            ['elasticsearchEndpoint', 'required', 'message' => Craft::t(SearchWithElastic::PLUGIN_HANDLE, 'Endpoint URL is required')],
            ['elasticsearchEndpoint', 'string'],
            ['elasticsearchEndpoint', 'validateElasticsearchEndpoint'],
            ['elasticsearchEndpoint', 'default', 'value' => 'elasticsearch:9200'],
            ['isAuthEnabled', 'boolean'],
            [['username', 'password'], 'string'],
            [['username', 'password'], 'trim'],
            [['excludedEntryTypes', 'highlight'], 'safe'],
            [['indexableEntryStatuses', 'indexableProductStatuses', 'indexableDigitalProductStatuses'], 'each', 'rule' => ['in', 'range' => ['pending', 'live', 'expired', 'disabled']]],
            [['indexableEntryStatuses', 'indexableProductStatuses', 'indexableDigitalProductStatuses'], 'required'],
            [['indexableCategoryStatuses'], 'each', 'rule' => ['in', 'range' => ['enabled', 'disabled']]],
            [['indexableCategoryStatuses'], 'required'],
            [['excludedCategoryGroups', 'excludedProductTypes', 'excludedDigitalProductTypes'], 'safe'],
            ['enableFrontendFetching', 'boolean'],
            ['enableFrontendFetchDebug', 'boolean'],
            ['indexElementsWithoutUrls', 'boolean'],
            ['useSearchableFields', 'boolean'],
            ['fallbackToFrontendFetching', 'boolean'],
            ['searchableContentFieldName', 'string'],
            ['searchableContentFieldName', 'default', 'value' => 'content'],
            ['frontendContentFieldName', 'string'],
            ['frontendContentFieldName', 'default', 'value' => 'content_fetch'],
            [['excludedSearchableContentEntryTypes', 'excludedSearchableContentAssetVolumes', 'excludedSearchableContentCategoryGroups', 'excludedSearchableContentProductTypes', 'excludedSearchableContentDigitalProductTypes'], 'safe'],
            [['excludedFrontendFetchingEntryTypes', 'excludedFrontendFetchingAssetVolumes', 'excludedFrontendFetchingCategoryGroups', 'excludedFrontendFetchingProductTypes', 'excludedFrontendFetchingDigitalProductTypes'], 'safe'],
            [['assetKinds', 'frontendFetchingAssetKinds'], 'safe'],
            ['indexPrefix', 'string'],
            ['indexPrefix', 'validateIndexPrefix'],
            ['fallbackIndexName', 'string'],
            ['fallbackIndexName', 'validateFallbackIndexName'],
            [['elementTypeIndexNames'], 'safe'],
            ['elementTypeIndexNames', 'validateElementTypeIndexNames'],
            ['rateLimitingEnabled', 'boolean'],
            ['rateLimitRequestsPerMinute', 'integer', 'min' => 1, 'max' => 10000],
            ['rateLimitRequestsPerMinute', 'default', 'value' => 60],
            ['rateLimitBurstSize', 'integer', 'min' => 0, 'max' => 100],
            ['rateLimitBurstSize', 'default', 'value' => 10],
            ['rateLimitTrackingMethod', 'in', 'range' => ['ip', 'user']],
            ['rateLimitTrackingMethod', 'default', 'value' => 'ip'],
            ['rateLimitExemptIps', 'each', 'rule' => ['ip', 'subnet' => null]],
            ['rateLimitExemptIps', 'default', 'value' => []],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            // Connection Settings
            'elasticsearchEndpoint' => Craft::t('search-with-elastic', 'Elasticsearch Endpoint'),
            'isAuthEnabled' => Craft::t('search-with-elastic', 'Enable Authentication'),
            'username' => Craft::t('search-with-elastic', 'Username'),
            'password' => Craft::t('search-with-elastic', 'Password'),

            // Index Configuration
            'indexPrefix' => Craft::t('search-with-elastic', 'Index Prefix'),
            'fallbackIndexName' => Craft::t('search-with-elastic', 'Fallback Index Name'),
            'elementTypeIndexNames' => Craft::t('search-with-elastic', 'Element Type Index Names'),

            // Element Type Configuration
            'assetKinds' => Craft::t('search-with-elastic', 'Asset Kinds'),
            'excludedEntryTypes' => Craft::t('search-with-elastic', 'Excluded Entry Types'),
            'excludedAssetVolumes' => Craft::t('search-with-elastic', 'Excluded Asset Volumes'),
            'indexableEntryStatuses' => Craft::t('search-with-elastic', 'Indexable Entry Statuses'),
            'indexableProductStatuses' => Craft::t('search-with-elastic', 'Indexable Product Statuses'),
            'indexableDigitalProductStatuses' => Craft::t('search-with-elastic', 'Indexable Digital Product Statuses'),
            'indexableCategoryStatuses' => Craft::t('search-with-elastic', 'Indexable Category Statuses'),
            'excludedCategoryGroups' => Craft::t('search-with-elastic', 'Excluded Category Groups'),
            'excludedProductTypes' => Craft::t('search-with-elastic', 'Excluded Product Types'),
            'excludedDigitalProductTypes' => Craft::t('search-with-elastic', 'Excluded Digital Product Types'),

            // Frontend Fetching Settings
            'enableFrontendFetching' => Craft::t('search-with-elastic', 'Enable Frontend Fetching'),
            'enableFrontendFetchDebug' => Craft::t('search-with-elastic', 'Enable Frontend Fetch Debug'),
            'excludedFrontendFetchingEntryTypes' => Craft::t('search-with-elastic', 'Excluded Frontend Fetching Entry Types'),
            'excludedFrontendFetchingAssetVolumes' => Craft::t('search-with-elastic', 'Excluded Frontend Fetching Asset Volumes'),
            'excludedFrontendFetchingCategoryGroups' => Craft::t('search-with-elastic', 'Excluded Frontend Fetching Category Groups'),
            'excludedFrontendFetchingProductTypes' => Craft::t('search-with-elastic', 'Excluded Frontend Fetching Product Types'),
            'excludedFrontendFetchingDigitalProductTypes' => Craft::t('search-with-elastic', 'Excluded Frontend Fetching Digital Product Types'),
            'indexElementsWithoutUrls' => Craft::t('search-with-elastic', 'Index Elements Without URLs'),
            'frontendFetchingAssetKinds' => Craft::t('search-with-elastic', 'Frontend Fetching Asset Kinds'),

            // Advanced Configuration
            'contentExtractorCallback' => Craft::t('search-with-elastic', 'Content Extractor Callback'),
            'elementContentCallback' => Craft::t('search-with-elastic', 'Element Content Callback'),
            'resultFormatterCallback' => Craft::t('search-with-elastic', 'Result Formatter Callback'),
            'highlight' => Craft::t('search-with-elastic', 'Highlight Tags'),
            'elasticsearchComponentConfig' => Craft::t('search-with-elastic', 'Elasticsearch Component Config'),
            'extraFields' => Craft::t('search-with-elastic', 'Extra Fields'),

            // Rate Limiting Configuration
            'rateLimitingEnabled' => Craft::t('search-with-elastic', 'Enable Rate Limiting'),
            'rateLimitRequestsPerMinute' => Craft::t('search-with-elastic', 'Requests Per Minute'),
            'rateLimitBurstSize' => Craft::t('search-with-elastic', 'Burst Size'),
            'rateLimitTrackingMethod' => Craft::t('search-with-elastic', 'Tracking Method'),
            'rateLimitExemptIps' => Craft::t('search-with-elastic', 'Exempt IP Addresses'),
        ];
    }

    /**
     * Validates the Elasticsearch endpoint format
     *
     * Accepts both full URLs (http://host:port, https://host:port) and
     * traditional hostname:port format for backward compatibility.
     *
     * @param string $attribute The attribute being validated
     * @return void
     */
    public function validateElasticsearchEndpoint(string $attribute): void
    {
        // Parse environment variables first
        $value = App::parseEnv($this->$attribute);

        if (empty($value)) {
            return; // Required validation will handle empty values
        }

        // Check if it's a full URL with protocol
        if (preg_match('#^https?://#i', $value)) {
            // Parse the URL to validate its components
            $parsed = parse_url($value);

            if (!$parsed || !isset($parsed['host'])) {
                $this->addError($attribute, Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Invalid URL format. Please enter a valid Elasticsearch endpoint (e.g., https://elasticsearch:9200)'
                ));
                return;
            }

            // Port is optional in URL format (defaults to 80/443)
            return;
        }

        // For hostname:port format, port is required
        if (!preg_match('/^[a-zA-Z0-9\-._]+:\d+$/', $value)) {
            $this->addError($attribute, Craft::t(
                SearchWithElastic::PLUGIN_HANDLE,
                'Please enter a valid Elasticsearch endpoint. Use either a full URL (https://elasticsearch:9200) or hostname:port format (elasticsearch:9200)'
            ));
        }
    }

    /**
     * Validates the index prefix using Elasticsearch naming rules
     *
     * @param string $attribute The attribute being validated
     * @return void
     */
    public function validateIndexPrefix(string $attribute): void
    {
        $value = $this->$attribute;

        // Empty prefix is allowed
        if ($value === '') {
            return;
        }

        $errors = IndexValidator::validateIndexName($value);
        foreach ($errors as $error) {
            $this->addError($attribute, $error);
        }
    }

    /**
     * Validates the fallback index name using Elasticsearch naming rules
     *
     * @param string $attribute The attribute being validated
     * @return void
     */
    public function validateFallbackIndexName(string $attribute): void
    {
        $value = $this->$attribute;

        $errors = IndexValidator::validateIndexName($value);
        foreach ($errors as $error) {
            $this->addError($attribute, $error);
        }
    }

    /**
     * Validates element type index name suffixes
     *
     * Ensures that all custom index name suffixes follow Elasticsearch naming conventions.
     * Empty strings are allowed to use the fallback index name.
     *
     * @param string $attribute The attribute being validated
     * @return void
     */
    public function validateElementTypeIndexNames(string $attribute): void
    {
        if (!is_array($this->$attribute)) {
            return;
        }

        foreach ($this->$attribute as $elementType => $indexSuffix) {
            // Empty strings are allowed (will use fallback)
            if ($indexSuffix === '') {
                continue;
            }

            // Validate the suffix using the comprehensive validator
            $errors = IndexValidator::validateIndexName($indexSuffix);
            foreach ($errors as $error) {
                $this->addError(
                    $attribute,
                    Craft::t(
                        'search-with-elastic',
                        'Index name suffix for {elementType}: {error}',
                        ['elementType' => $elementType, 'error' => $error]
                    )
                );
            }
        }
    }

    /**
     * Performs additional validation after the standard validation rules
     *
     * Tests Elasticsearch connectivity, validates status arrays using ValidationHelper,
     * and cleans up exclude arrays. Also handles validation of custom component
     * configurations and entry type formats.
     *
     * @return void
     * @throws InvalidConfigException
     */
    public function afterValidate(): void
    {
        // Always validate status arrays and cleanup exclude arrays, even with custom config
        $this->validateStatusArraysAndCleanupExcludes();

        if ($this->elasticsearchComponentConfig !== null) {
            // Validate that elasticsearchComponentConfig is an array
            if (!is_array($this->elasticsearchComponentConfig)) {
                $this->addError('elasticsearchComponentConfig', Craft::t('search-with-elastic', 'Elasticsearch component config must be an array.'));
            }
            parent::afterValidate();
            return;
        }

        // Save the current Elasticsearch connector
        $previousElasticConnector = Craft::$app->get(SearchWithElastic::PLUGIN_HANDLE);

        // Create a new instance of the Elasticsearch connector with the freshly-submitted url and auth settings
        $elasticsearchPlugin = SearchWithElastic::getInstance();
        assert($elasticsearchPlugin !== null, "SearchWithElastic::getInstance() should always return the plugin instance when called from the plugin's code.");

        try {
            $elasticsearchPlugin->initializeElasticConnector($this);

            // Run the actual validation
            if (!$elasticsearchPlugin->elasticsearch->testConnection()) {
                throw new InvalidConfigException('Could not connect to the Elasticsearch server.');
            }
        } catch (InvalidConfigException) {
            $parsedEndpoint = App::parseEnv($this->elasticsearchEndpoint);
            $this->addError(
                'global',
                Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Could not connect to the Elasticsearch instance at {elasticsearchEndpoint}. Please check the endpoint URL and authentication settings.',
                    ['elasticsearchEndpoint' => $parsedEndpoint]
                )
            );
        } finally {
            // Restore the previous Elasticsearch connector
            try {
                Craft::$app->set(SearchWithElastic::PLUGIN_HANDLE, $previousElasticConnector);
            } catch (Exception $e) {
                // Log the error but don't throw - we need to complete validation gracefully
                Craft::error(
                    'Failed to restore Elasticsearch connector after validation: ' . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        parent::afterValidate();
    }


    /**
     * Validates status arrays and cleans up exclude arrays
     *
     * This method is extracted to ensure it runs regardless of whether
     * a custom elasticsearchComponentConfig is provided.
     *
     * @return void
     */
    protected function validateStatusArraysAndCleanupExcludes(): void
    {
        // Validate status arrays using ValidationHelper
        $statusValidationErrors = ValidationHelper::validateMultipleStatusArrays([
            'indexableEntryStatuses' => [$this->indexableEntryStatuses, 'entry'],
            'indexableProductStatuses' => [$this->indexableProductStatuses, 'product'],
            'indexableDigitalProductStatuses' => [$this->indexableDigitalProductStatuses, 'digital product'],
            'indexableCategoryStatuses' => [$this->indexableCategoryStatuses, 'category'],
        ]);

        foreach ($statusValidationErrors as $field => $error) {
            $this->addError($field, Craft::t('search-with-elastic', $error));
        }

        // Cleanup exclude and enabled arrays using ValidationHelper
        $cleanedArrays = ValidationHelper::cleanupExcludeArrays([
            'excludedEntryTypes' => $this->excludedEntryTypes,
            'excludedCategoryGroups' => $this->excludedCategoryGroups,
            'excludedProductTypes' => $this->excludedProductTypes,
            'excludedDigitalProductTypes' => $this->excludedDigitalProductTypes,
            'excludedSearchableContentEntryTypes' => $this->excludedSearchableContentEntryTypes,
            'excludedSearchableContentAssetVolumes' => $this->excludedSearchableContentAssetVolumes,
            'excludedSearchableContentCategoryGroups' => $this->excludedSearchableContentCategoryGroups,
            'excludedSearchableContentProductTypes' => $this->excludedSearchableContentProductTypes,
            'excludedSearchableContentDigitalProductTypes' => $this->excludedSearchableContentDigitalProductTypes,
            'excludedFrontendFetchingEntryTypes' => $this->excludedFrontendFetchingEntryTypes,
            'excludedFrontendFetchingAssetVolumes' => $this->excludedFrontendFetchingAssetVolumes,
            'excludedFrontendFetchingCategoryGroups' => $this->excludedFrontendFetchingCategoryGroups,
            'excludedFrontendFetchingProductTypes' => $this->excludedFrontendFetchingProductTypes,
            'excludedFrontendFetchingDigitalProductTypes' => $this->excludedFrontendFetchingDigitalProductTypes,
            'assetKinds' => $this->assetKinds,
            'frontendFetchingAssetKinds' => $this->frontendFetchingAssetKinds,
        ]);

        // Apply cleaned arrays back to properties
        foreach ($cleanedArrays as $property => $cleanedArray) {
            $this->$property = $cleanedArray;
        }

        // Validate entry type format (prepare for future section:entryType format)
        $entryTypeFormatErrors = ValidationHelper::validateEntryTypeFormat($this->excludedEntryTypes);
        foreach ($entryTypeFormatErrors as $error) {
            // For now, just log warnings instead of errors to maintain backward compatibility
            Craft::warning($error, __METHOD__);
        }
    }
}
