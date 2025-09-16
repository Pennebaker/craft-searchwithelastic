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

namespace pennebaker\searchwithelastic\records;

use Craft;
use craft\base\Element;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use DateTime;
use pennebaker\searchwithelastic\events\search\SearchEvent;
use pennebaker\searchwithelastic\helpers\ElasticsearchHelper;
use pennebaker\searchwithelastic\models\SettingsModel;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\StaleObjectException;
use yii\elasticsearch\ActiveRecord;
use yii\elasticsearch\Connection;
use yii\elasticsearch\Exception;
use yii\helpers\Json;
use yii\helpers\VarDumper;

/**
 * Elasticsearch Active Record for indexing Craft elements
 *
 * Represents a document in the Elasticsearch index with support for
 * attachment processing, custom field mappings, and search operations.
 *
 * @property string $title Element title
 * @property string $url Element URL
 * @property string $elementHandle Element type handle
 * @property object|array $content Element content
 * @property string $postDate Element post date
 * @property boolean $noPostDate Whether element has no post date
 * @property string $updateDate Element update date
 * @property boolean $noUpdateDate Whether element has no update date
 * @property string $expiryDate Element expiry date
 * @property Element $element
 * @property array|string[] $searchFields
 * @property-write array $queryParams
 * @property null|array $highlightParams
 * @property-read SettingsModel $pluginSettings
 * @property array $schema
 * @property boolean $noExpiryDate Whether element has no expiry date
 * 
 * @since 4.0.0
 */
class ElasticsearchRecord extends ActiveRecord
{
    /**
     * Event triggered before creating an Elasticsearch index
     * 
     * @since 4.0.0
     */
    public const EVENT_BEFORE_CREATE_INDEX = 'beforeCreateIndex';

    /**
     * Event triggered before saving a document to Elasticsearch
     * 
     * @since 4.0.0
     */
    public const EVENT_BEFORE_SAVE = 'beforeSave';

    /**
     * Event triggered before performing a search
     * 
     * @since 4.0.0
     */
    public const EVENT_BEFORE_SEARCH = 'beforeSearch';

    /**
     * @var int|null The current site ID for index operations
     * @since 4.0.0
     */
    public static ?int $siteId;

    /**
     * @var array The Elasticsearch index schema
     * @since 4.0.0
     */
    private array $_schema;

    /**
     * @var array Default document attributes
     * @since 4.0.0
     */
    private array $_attributes = ['title', 'url', 'elementHandle', 'content', 'postDate', 'updateDate', 'expiryDate', 'noPostDate', 'noUpdateDate', 'noExpiryDate'];

    /**
     * @var Element|null The Craft element associated with this record
     * @since 4.0.0
     */
    private ?Element $_element;

    /**
     * @var array|null Search query parameters
     * @since 4.0.0
     */
    private ?array $_queryParams;

    /**
     * @var array|null Search highlighting parameters
     * @since 4.0.0
     */
    private ?array $_highlightParams;

    /**
     * @var array Default fields to search in
     * @since 4.0.0
     */
    private array $_searchFields = ['attachment.content', 'title'];

    /**
     * Get the Elasticsearch document type
     *
     * @return string The document type
     * @since 4.0.0
     */
    public static function type(): string
    {
        return '_doc';
    }

    /**
     * Get the list of document attributes
     *
     * @return array The attribute names
     * @since 4.0.0
     */
    public function attributes(): array
    {
        return $this->_attributes;
    }

    /**
     * Initialize the record and process extra fields
     *
     * @return void
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function init(): void
    {
        parent::init();
        $this->processExtraFieldsForInit();
    }

    /**
     * Save the document to Elasticsearch
     *
     * Creates the index if it doesn't exist, processes extra fields,
     * and uses the attachment pipeline for document processing.
     *
     * @param bool $runValidation Whether to run validation
     * @param array|null $attributeNames Specific attributes to save
     * @return bool Whether the save was successful
     * @throws InvalidConfigException
     * @throws \yii\db\Exception
     * @throws StaleObjectException
     * @throws Exception
     * @since 4.0.0
     */
    public function save($runValidation = true, $attributeNames = null): bool
    {
        if (!self::indexExists()) {
            $this->createESIndex();
        }

        $this->processExtraFieldsForSave();

        $this->trigger(self::EVENT_BEFORE_SAVE, new Event());
        if (!$this->getIsNewRecord()) {
            $this->delete(); // Attachment pipeline is not supported by Document Update API
        }
        return $this->insert($runValidation, $attributeNames, ['pipeline' => 'attachment']);
    }

    /**
     * Get the Elasticsearch database connection
     *
     * @return Connection The Elasticsearch connection
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public static function getDb(): Connection
    {
        return SearchWithElastic::getConnection();
    }

    /**
     * Get the Elasticsearch index name for the current site
     *
     * @return string The index name
     * @throws InvalidConfigException If the site ID isn't set
     * @since 4.0.0
     */
    public static function index(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }

        return ElasticsearchHelper::generateIndexName(static::$siteId);
    }

    /**
     * Search for documents matching the given query
     *
     * Processes extra fields for highlighting, triggers search events,
     * and returns all matching records.
     *
     * @param string $query The search query
     * @return ElasticsearchRecord[] Array of matching records
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function search(string $query): array
    {
        $extraHighlightParams = $this->processExtraFieldsForSearch();
        $highlightParams = $this->getHighlightParams();
        $highlightParams['fields'] = ArrayHelper::merge($highlightParams['fields'], $extraHighlightParams);
        $this->setHighlightParams($highlightParams);

        $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent(['query' => $query]));
        $queryParams = $this->getQueryParams($query);
        $highlightParams = $this->getHighlightParams();
        return self::find()->query($queryParams)->highlight($highlightParams)->limit(self::find()->count())->all();
    }

    /**
     * Get the best Elasticsearch analyzer for the current site's language
     *
     * @return string The analyzer name
     * @throws InvalidConfigException If the site ID isn't set
     * @since 4.0.0
     */
    public static function siteAnalyzer(): string
    {
        if (static::$siteId === null) {
            throw new InvalidConfigException('siteId was not set');
        }

        return ElasticsearchHelper::getAnalyzerForSite(static::$siteId);
    }

    /**
     * Create the Elasticsearch index with mapping and schema
     *
     * Generates the mapping, processes extra fields, sets the schema,
     * triggers events, and creates the index.
     *
     * @return void
     * @throws InvalidConfigException|Exception
     * @since 4.0.0
     */
    public function createESIndex(): void
    {
        $mapping = static::mapping();
        $this->processExtraFieldsForMapping($mapping);
        $this->setSchema(
            [
                'mappings' => $mapping,
            ]
        );
        $this->trigger(self::EVENT_BEFORE_CREATE_INDEX, new Event());
        Craft::debug('Before create event - site: ' . self::$siteId . ' schema: ' . VarDumper::dumpAsString($this->getSchema()), __METHOD__);
        self::createIndex($this->getSchema());
    }

    /**
     * Create this model's index in Elasticsearch
     *
     * Sets up the attachment pipeline and creates the index with the given schema.
     *
     * @param array $schema The Elasticsearch index definition schema
     * @param bool $force Whether to force recreation of existing index
     * @throws InvalidConfigException If the site ID isn't set
     * @throws Exception If an error occurs while communicating with the Elasticsearch server
     * @since 4.0.0
     */
    public static function createIndex(array $schema, bool $force = false): void
    {
        $db = static::getDb();
        $command = $db->createCommand();

        if ($force === true && $command->indexExists(static::index())) {
            self::deleteIndex();
        }

        $db->delete('_ingest/pipeline/attachment');
        $db->put(
            '_ingest/pipeline/attachment',
            [],
            Json::encode(ElasticsearchHelper::getAttachmentPipelineConfig())
        );

        $db->put(static::index(), ['include_type_name' => 'false'], Json::encode($schema));
    }

    /**
     * Delete this model's index from Elasticsearch
     *
     * @return void
     * @throws InvalidConfigException If the site ID isn't set
     * @throws Exception
     * @since 4.0.0
     */
    public static function deleteIndex(): void
    {
        $db = static::getDb();
        $command = $db->createCommand();
        if ($command->indexExists(static::index())) {
            $command->deleteIndex(static::index());
        }
    }

    /**
     * Get the Elasticsearch mapping configuration for documents
     *
     * Defines the field mappings and analyzers for indexed content.
     *
     * @return array The mapping configuration
     * @throws InvalidConfigException If the site ID isn't set
     * @since 4.0.0
     */
    public static function mapping(): array
    {
        $analyzer = self::siteAnalyzer();
        return [
            'properties' => [
                'title'         => ElasticsearchHelper::createAnalyzedTextFieldMapping($analyzer),
                'postDate'      => ElasticsearchHelper::createDateFieldMapping(),
                'noPostDate'    => ElasticsearchHelper::createBooleanFieldMapping(),
                'updateDate'    => ElasticsearchHelper::createDateFieldMapping(),
                'noUpdateDate'  => ElasticsearchHelper::createBooleanFieldMapping(),
                'expiryDate'    => ElasticsearchHelper::createDateFieldMapping(),
                'noExpiryDate'  => ElasticsearchHelper::createBooleanFieldMapping(),
                'url'           => ElasticsearchHelper::createStoredTextFieldMapping(),
                'content'       => ElasticsearchHelper::createAnalyzedTextFieldMapping($analyzer),
                'elementHandle' => ElasticsearchHelper::createKeywordFieldMapping(),
                'attachment'    => ElasticsearchHelper::createAttachmentMapping($analyzer),
            ],
        ];
    }

    /**
     * Get the current index schema
     *
     * @return array The index schema configuration
     * @since 4.0.0
     */
    public function getSchema(): array
    {
        return $this->_schema;
    }

    /**
     * Set the index schema configuration
     *
     * @param array $schema The schema configuration
     * @return void
     * @since 4.0.0
     */
    public function setSchema(array $schema): void
    {
        $this->_schema = $schema;
    }

    /**
     * Add additional attributes to the document
     *
     * @param array $attributes Additional attribute names
     * @return void
     * @since 4.0.0
     */
    public function addAttributes(array $attributes): void
    {
        $this->_attributes = ArrayHelper::merge($this->_attributes, $attributes);
    }

    /**
     * Get the associated Craft element
     *
     * @return Element The Craft element
     * @since 4.0.0
     */
    public function getElement(): Element
    {
        return $this->_element;
    }

    /**
     * Set the associated Craft element
     *
     * @param Element $element The Craft element
     * @return void
     * @since 4.0.0
     */
    public function setElement(Element $element): void
    {
        $this->_element = $element;
    }

    /**
     * Get the search query parameters for Elasticsearch
     *
     * Builds a complex boolean query with multi-match search and date filtering.
     *
     * @param string $query The search query string
     * @return array|null The Elasticsearch query parameters
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function getQueryParams(string $query): ?array
    {
        if ($this->_queryParams === null) {
            $currentTimeDb = Db::prepareDateForDb(new DateTime());
            $this->_queryParams = [
                'bool' => [
                    'must'   => [
                        [
                            'multi_match' => [
                                'fields'   => $this->getSearchFields(),
                                'query'    => $query,
                                'analyzer' => self::siteAnalyzer(),
                                'operator' => 'and',
                            ],
                        ],
                    ],
                    'filter' => [
                        'bool' => [
                            'must' => [
                                [
                                    'range' => [
                                        'postDate' => [
                                            'lte' => $currentTimeDb,
                                        ],
                                    ],
                                ],
                                [
                                    'bool' => [
                                        'should' => [
                                            [
                                                'range' => [
                                                    'expiryDate' => [
                                                        'gt' => $currentTimeDb,
                                                    ],
                                                ],
                                            ],
                                            [
                                                'term' => [
                                                    'noExpiryDate' => true,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],

                            ],
                        ],
                    ],
                ],
            ];
        }
        return $this->_queryParams;
    }

    /**
     * Set the search query parameters
     *
     * @param array $queryParams The query parameters
     * @return void
     * @since 4.0.0
     */
    public function setQueryParams(array $queryParams): void
    {
        $this->_queryParams = $queryParams;
    }

    /**
     * Get the search highlighting parameters
     *
     * @return array|null The highlighting configuration
     * @since 4.0.0
     */
    public function getHighlightParams(): ?array
    {
        if (is_null($this->_highlightParams)) {
            $this->_highlightParams = ArrayHelper::merge(
                SearchWithElastic::getInstance()->settings->highlight,
                [
                    'fields' => [
                        'attachment.content' => (object)[],
                    ],
                ]
            );
        }
        return $this->_highlightParams;
    }

    /**
     * Set the search highlighting parameters
     *
     * @param array $highlightParams The highlighting configuration
     * @return void
     * @since 4.0.0
     */
    public function setHighlightParams(array $highlightParams): void
    {
        $this->_highlightParams = $highlightParams;
    }

    /**
     * Get the fields to search in
     *
     * @return array The search field names
     * @since 4.0.0
     */
    public function getSearchFields(): array
    {
        return $this->_searchFields;
    }

    /**
     * Set the fields to search in
     *
     * @param array $searchFields The search field names
     * @return void
     * @since 4.0.0
     */
    public function setSearchFields(array $searchFields): void
    {
        $this->_searchFields = $searchFields;
    }

    /**
     * Check if the Elasticsearch index exists
     *
     * @return bool Whether the index exists
     * @throws InvalidConfigException If the site ID isn't set
     * @throws Exception
     * @since 4.0.0
     */
    protected static function indexExists(): bool
    {
        $db = static::getDb();
        $command = $db->createCommand();
        return (bool)$command->indexExists(static::index());
    }

    /**
     * Get the plugin settings instance
     *
     * @return SettingsModel The plugin settings
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    private function getPluginSettings(): SettingsModel
    {
        $instance = SearchWithElastic::getInstance();
        if (!$instance instanceof SearchWithElastic) {
            throw new InvalidConfigException('SearchWithElastic instance not found');
        }
        return $instance->getSettings();
    }

    /**
     * Process extra fields during record initialization
     *
     * Adds extra field attributes defined in plugin settings.
     *
     * @return void
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    private function processExtraFieldsForInit(): void
    {
        $extraFields = $this->getPluginSettings()->extraFields;
        if (!empty($extraFields)) {
            $this->addAttributes(array_keys($extraFields));
        }
    }

    /**
     * Process extra fields during save operation
     *
     * Evaluates extra field values and assigns them to the document.
     *
     * @return void
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    private function processExtraFieldsForSave(): void
    {
        $extraFields = $this->getPluginSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldValue = ArrayHelper::getValue($fieldParams, 'value');
                if (!empty($fieldValue)) {
                    if (is_callable($fieldValue)) {
                        $this->$fieldName = $fieldValue($this->getElement(), $this);
                    } else {
                        $this->$fieldName = $fieldValue;
                    }
                }
            }
        }
    }

    /**
     * Process extra fields during search operation
     *
     * Adds extra fields to the search fields list and collects highlighting parameters.
     *
     * @return array Extra highlight parameters for the extra fields
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    private function processExtraFieldsForSearch(): array
    {
        $extraFields = $this->getPluginSettings()->extraFields;
        $extraHighlightParams = [];

        if (!empty($extraFields)) {
            $this->setSearchFields(ArrayHelper::merge($this->getSearchFields(), array_keys($extraFields)));
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldHighlighter = ArrayHelper::getValue($fieldParams, 'highlighter');
                if (!empty($fieldHighlighter)) {
                    $extraHighlightParams[$fieldName] = $fieldHighlighter;
                }
            }
        }

        return $extraHighlightParams;
    }

    /**
     * Process extra fields for mapping definition
     *
     * Adds extra field mappings to the Elasticsearch index mapping.
     *
     * @param array $mapping The mapping array to modify (passed by reference)
     * @return void
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    private function processExtraFieldsForMapping(array &$mapping): void
    {
        $extraFields = $this->getPluginSettings()->extraFields;
        if (!empty($extraFields)) {
            foreach ($extraFields as $fieldName => $fieldParams) {
                $fieldMapping = ArrayHelper::getValue($fieldParams, 'mapping');
                if ($fieldMapping) {
                    if (is_callable($fieldMapping)) {
                        $fieldMapping = $fieldMapping($this);
                    }
                    $mapping['properties'][$fieldName] = $fieldMapping;
                }
            }
        }
    }
}
