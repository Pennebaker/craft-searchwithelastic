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

namespace pennebaker\searchwithelastic;

use Craft;
use craft\base\Element;
use craft\base\Plugin;
use craft\commerce\elements\Product;
use craft\console\Application as ConsoleApplication;
use craft\digitalproducts\elements\Product as DigitalProduct;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\errors\MissingComponentException;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use craft\i18n\Locale;
use craft\models\Section;
use craft\queue\Queue;
use craft\services\Plugins;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use DateTime;
use DateTimeZone;
use Exception;
use pennebaker\searchwithelastic\exceptions\IndexElementException;
use pennebaker\searchwithelastic\exceptions\IndexingException;
use pennebaker\searchwithelastic\models\SettingsModel;
use pennebaker\searchwithelastic\services\CallbackValidator;
use pennebaker\searchwithelastic\services\ElasticsearchService;
use pennebaker\searchwithelastic\services\ElementIndexerService;
use pennebaker\searchwithelastic\services\IndexManagementService;
use pennebaker\searchwithelastic\services\ModelService;
use pennebaker\searchwithelastic\services\QueryService;
use pennebaker\searchwithelastic\services\RateLimiterService;
use pennebaker\searchwithelastic\services\RecordService;
use pennebaker\searchwithelastic\services\ReindexQueueManagementService;
use pennebaker\searchwithelastic\services\SearchTemplateService;
use pennebaker\searchwithelastic\utilities\RefreshIndexUtility;
use pennebaker\searchwithelastic\variables\SearchVariable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\debug\Module as DebugModule;
use yii\elasticsearch\Connection;
use yii\elasticsearch\DebugPanel;
use yii\queue\ExecEvent;

/**
 * Search w/Elastic plugin for Craft CMS 4.x
 *
 * Provides high-performance search across all content types with real-time
 * indexing, advanced querying, and production reliability.
 *
 * @property  services\ElasticsearchService elasticsearch
 * @property  services\ReindexQueueManagementService reindexQueueManagement
 * @property  services\ElementIndexerService elementIndexer
 * @property  services\IndexManagementService indexManagement
 * @property  services\RecordService records
 * @property  services\ModelService models
 * @property  services\QueryService queries
 * @property  services\RateLimiterService rateLimiter
 * @property  services\SearchTemplateService searchTemplates
 * @property  services\CallbackValidator callbackValidator
 * @property  SettingsModel settings
 * @property-read array $textBasedAssetKinds
 * @property-read array $allAssetKinds
 * @method    SettingsModel                          getSettings()
 * @author Pennebaker
 * @since 4.0.0
 */
class SearchWithElastic extends Plugin
{
    /**
     * @since 4.0.0
     */
    public const EVENT_ERROR_NO_ATTACHMENT_PROCESSOR = 'errorNoAttachmentProcessor';
    
    /**
     * @since 4.0.0
     */
    public const PLUGIN_HANDLE = 'search-with-elastic';

    /**
     * @var bool
     * @since 4.0.0
     */
    public bool $hasCpSettings = true;

    /**
     * @since 4.0.0
     */
    public function init(): void
    {
        parent::init();
        $isCommerceEnabled = $this->isCommerceEnabled();
        $isDigitalProductsEnabled = $this->isDigitalProductsEnabled();

        $this->setComponents(
            [
                'elasticsearch'                 => ElasticsearchService::class,
                'reindexQueueManagement'        => ReindexQueueManagementService::class,
                'elementIndexer'                => ElementIndexerService::class,
                'indexManagement'               => IndexManagementService::class,
                'records'                       => RecordService::class,
                'models'                        => ModelService::class,
                'queries'                       => QueryService::class,
                'rateLimiter'                   => RateLimiterService::class,
                'searchTemplates'               => SearchTemplateService::class,
                'callbackValidator'             => CallbackValidator::class,
            ]
        );

        $this->initializeElasticConnector();

        // Add console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'pennebaker\searchwithelastic\console\controllers';
        }

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            // Remove entry from the index upon deletion
            Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, [$this, 'onElementDelete']);
            Event::on(Asset::class, Asset::EVENT_AFTER_DELETE, [$this, 'onElementDelete']);
            Event::on(Category::class,Category::EVENT_AFTER_DELETE, [$this, 'onElementDelete']);

            // Index entry, asset & products upon save (creation or update)
            Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
            Event::on(Asset::class, Asset::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
            Event::on(Category::class, Category::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
            if ($isCommerceEnabled) {
                Event::on(Product::class, Product::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
                Event::on(Product::class, Product::EVENT_AFTER_DELETE, [$this, 'onElementDelete']);

                if ($isDigitalProductsEnabled) {
                    Event::on(DigitalProduct::class, DigitalProduct::EVENT_AFTER_SAVE, [$this, 'onElementSaved']);
                    Event::on(DigitalProduct::class, DigitalProduct::EVENT_AFTER_DELETE, [$this, 'onElementDelete']);
                }
            }

            // Add the sidebar status to the entry, asset, and category edit pages
            Event::on(Entry::class, Entry::EVENT_DEFINE_SIDEBAR_HTML, [$this, 'sidebarStatus']);
            Event::on(Asset::class, Asset::EVENT_DEFINE_SIDEBAR_HTML, [$this, 'sidebarStatus']);
            Event::on(Category::class, Category::EVENT_DEFINE_SIDEBAR_HTML, [$this, 'sidebarStatus']);
            if ($isCommerceEnabled) {
                Event::on(Product::class, Product::EVENT_DEFINE_SIDEBAR_HTML, [$this, 'sidebarStatus']);

                if ($isDigitalProductsEnabled) {
                    Event::on(DigitalProduct::class, DigitalProduct::EVENT_DEFINE_SIDEBAR_HTML, [$this, 'sidebarStatus']);
                }
            }

            // Re-index all entries when plugin settings are saved
            Event::on(
                Plugins::class,
                Plugins::EVENT_AFTER_SAVE_PLUGIN_SETTINGS,
                function (PluginEvent $event) {
                    if ($event->plugin === $this) {
                        $this->onPluginSettingsSaved();
                    }
                }
            );

            // On reindex job success, remove its id from the cache (cache is used to keep track of reindex jobs and clear those having failed before reindexing all entries)
            Event::on(
                Queue::class,
                Queue::EVENT_AFTER_EXEC,
                function (ExecEvent $event) {
                    $this->reindexQueueManagement->removeJob($event->id);
                }
            );

            // Register the plugin's CP utility
            Event::on(
                Utilities::class,
                Utilities::EVENT_REGISTER_UTILITY_TYPES,
                static function (RegisterComponentTypesEvent $event) {
                    $event->types[] = RefreshIndexUtility::class;
                }
            );

            // Register custom permissions
            Event::on(
                UserPermissions::class,
                UserPermissions::EVENT_REGISTER_PERMISSIONS,
                static function (RegisterUserPermissionsEvent $event) {
                    $event->permissions[] = [
                        'heading' => Craft::t('search-with-elastic', 'Search with Elastic'),
                        'permissions' => [
                            'search-with-elastic:index-element' => [
                                'label' => Craft::t('search-with-elastic', 'Index individual elements'),
                            ],
                        ],
                    ];
                }
            );

            // Register our CP routes
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                static function (RegisterUrlRulesEvent $event) {
                    $event->rules['search-with-elastic/cp/test-connection'] = 'search-with-elastic/cp/test-connection';
                    $event->rules['search-with-elastic/cp/reindex-perform-action'] = 'search-with-elastic/cp/reindex-perform-action';
                    $event->rules['search-with-elastic/cp/reindex-single-element'] = 'search-with-elastic/cp/reindex-single-element';
                    $event->rules['search-with-elastic/cp/delete-single-element'] = 'search-with-elastic/cp/delete-single-element';
                    $event->rules['search-with-elastic/cp/get-element-status'] = 'search-with-elastic/cp/get-element-status';
                }
            );

            // Display a flash message if the ingest attachment plugin isn't activated on the Elasticsearch instance
            Event::on(
                self::class,
                self::EVENT_ERROR_NO_ATTACHMENT_PROCESSOR,
                static function () {
                    $application = Craft::$app;
                    if ($application instanceof \yii\web\Application) {
                        $application->getSession()->setError('The ingest-attachment plugin seems to be missing on your Elasticsearch instance.');
                    }
                }
            );
        }

        // Add the Elasticsearch panel to the Yii debug bar
        Event::on(
            Application::class,
            Application::EVENT_BEFORE_REQUEST,
            static function () {
                /** @var DebugModule|null $debugModule */
                $debugModule = Craft::$app->getModule('debug');
                if ($debugModule) {
                    $debugModule->panels['elasticsearch'] = new DebugPanel(
                        [
                            'id'     => 'elasticsearch',
                            'module' => $debugModule,
                        ]
                    );
                }
            }
        );

        // Register variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('searchWithElastic', SearchVariable::class);
            }
        );

        // Register our site routes (used by the console commands to reindex entries and public search)
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event) {
                $event->rules['search-with-elastic/get-all-elements'] = 'search-with-elastic/site/get-all-elements';
                $event->rules['search-with-elastic/reindex-all'] = 'search-with-elastic/site/reindex-all';
                $event->rules['search-with-elastic/reindex-element'] = 'search-with-elastic/site/reindex-element';
                
                // Public search endpoints with rate limiting
                $event->rules['search-with-elastic/search'] = 'search-with-elastic/search/search';
                $event->rules['search-with-elastic/search-extra'] = 'search-with-elastic/search/search-extra';
            }
        );

        Craft::info("$this->name plugin loaded", __METHOD__);
    }

    /**
     * Creates and returns the model used to store the plugin's settings.
     *
     * @return SettingsModel
     * @since 4.0.0
     */
    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws \yii\base\Exception
     * @since 4.0.0
     */
    protected function settingsHtml(): string
    {
        // Get and pre-validate the settings
        $settings = $this->getSettings();

        // Get the settings that are being defined by the config file
        $overrides = Craft::$app->getConfig()->getConfigFromFile(strtolower($this->handle));

        $sections = ArrayHelper::map(
            Craft::$app->sections->getAllSections(),
            'id',
            static function (Section $section): array {
                return [
                    'label' => Craft::t('site', $section->name),
                    'types' => ArrayHelper::map(
                        $section->getEntryTypes(),
                        'id',
                        static function ($section): array {
                            return ['label' => Craft::t('site', $section->name)];
                        }
                    ),
                ];
            }
        );

        return Craft::$app->view->renderTemplate(
            'search-with-elastic/cp/settings',
            [
                'settings'  => $settings,
                'overrides' => array_keys($overrides),
                'sections'  => $sections,
            ]
        );
    }

    /**
     * @since 4.0.0
     */
    public function beforeSaveSettings(): bool
    {
        $settings = $this->getSettings();
        $settings->elasticsearchComponentConfig = null;
        return parent::beforeSaveSettings();
    }

    /**
     * @return Connection
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public static function getConnection(): Connection
    {
        /** @var Connection $connection */
        $connection = Craft::$app->get(self::PLUGIN_HANDLE);

        return $connection;
    }

    /**
     * Initialize the Elasticsearch connector
     * @param SettingsModel|null $settings
     * @throws InvalidConfigException If the configuration passed to the yii2-elasticsearch module is invalid
     * @since 4.0.0
     */
    public function initializeElasticConnector(SettingsModel $settings = null): void
    {
        if ($settings === null) {
            $settings = $this->getSettings();
        }

        if ($settings->elasticsearchComponentConfig !== null) {
            $definition = $settings->elasticsearchComponentConfig;
        } else {
            $endpoint = $settings->elasticsearchEndpoint;

            // Check if endpoint already has a protocol
            if (preg_match('#^https?://#i', $endpoint)) {
                $protocol = parse_url($endpoint, PHP_URL_SCHEME);
                $endpointUrlWithoutProtocol = preg_replace("#^$protocol(?:://)?#", '', $endpoint);
            } else {
                // No protocol specified, use as-is but default to http for Elasticsearch client
                $protocol = 'http';
                $endpointUrlWithoutProtocol = $endpoint;
            }

            $definition = [
                'connectionTimeout' => 10,
                'autodetectCluster' => false,
                'nodes'             => [
                    [
                        'protocol'     => $protocol,
                        'http_address' => $endpointUrlWithoutProtocol,
                        'http'         => ['publish_address' => $endpoint],
                    ],
                ],
            ];

            if ($settings->isAuthEnabled) {
                $definition['auth'] = [
                    'username' => $settings->username,
                    'password' => $settings->password,
                ];
            }
        }

        $definition['class'] = Connection::class;

        // Configure nodes for proper connection handling with null safety
        if (!isset($definition['nodes'])) {
            // If no nodes are defined, create a default configuration based on elasticsearchEndpoint
            $endpoint = $settings->elasticsearchEndpoint ?? 'elasticsearch:9200';
            
            // Check if endpoint already has a protocol
            if (preg_match('#^https?://#i', $endpoint)) {
                $protocol = parse_url($endpoint, PHP_URL_SCHEME);
                $endpointUrlWithoutProtocol = preg_replace("#^$protocol(?:://)?#", '', $endpoint);
            } else {
                // No protocol specified, use as-is but default to http for Elasticsearch client
                $protocol = 'http';
                $endpointUrlWithoutProtocol = $endpoint;
            }
            
            $definition['nodes'] = [
                [
                    'protocol'     => $protocol,
                    'http_address' => $endpointUrlWithoutProtocol,
                    'http'         => ['publish_address' => $endpoint],
                ],
            ];
        } elseif ($definition['nodes'] === null || !is_array($definition['nodes'])) {
            // Handle case where nodes is explicitly null or not an array
            Craft::warning(
                'Elasticsearch configuration contains invalid nodes definition. Using default configuration.',
                __METHOD__
            );
            
            $endpoint = $settings->elasticsearchEndpoint ?? 'elasticsearch:9200';
            
            // Check if endpoint already has a protocol
            if (preg_match('#^https?://#i', $endpoint)) {
                $protocol = parse_url($endpoint, PHP_URL_SCHEME);
                $endpointUrlWithoutProtocol = preg_replace("#^$protocol(?:://)?#", '', $endpoint);
            } else {
                // No protocol specified, use as-is but default to http for Elasticsearch client
                $protocol = 'http';
                $endpointUrlWithoutProtocol = $endpoint;
            }
            
            $definition['nodes'] = [
                [
                    'protocol'     => $protocol,
                    'http_address' => $endpointUrlWithoutProtocol,
                    'http'         => ['publish_address' => $endpoint],
                ],
            ];
        }

        // Safely configure each node in the array
        if (is_array($definition['nodes']) && !empty($definition['nodes'])) {
            array_walk(
                $definition['nodes'],
                static function (&$node) {
                    // Ensure node is an array before processing
                    if (!is_array($node)) {
                        $node = [];
                    }

                    if (!isset($node['http'])) {
                        $node['http'] = [];
                    }

                    if (!isset($node['http']['publish_address'])) {
                        $node['http']['publish_address'] = sprintf(
                            '%s://%s',
                            $node['protocol'] ?? 'http',
                            $node['http_address'] ?? 'elasticsearch:9200'
                        );
                    }
                }
            );
        }

        Craft::$app->set(self::PLUGIN_HANDLE, $definition);
    }

    /**
     * Check for presence of Craft Commerce Plugin
     * @return bool
     * @since 4.0.0
     */
    public function isCommerceEnabled(): bool
    {
        return class_exists(\craft\commerce\Plugin::class);
    }

    /**
     * Check for presence of Craft Digital Products Plugin
     * @return bool
     * @since 4.0.0
     */
    public function isDigitalProductsEnabled(): bool
    {
        return class_exists(\craft\digitalproducts\Plugin::class);
    }

    /**
     * Returns all available asset kinds including custom ones from general config
     * @return array
     * @since 4.0.0
     */
    public function getAllAssetKinds(): array
    {
        // Get the built-in asset kinds from Craft CMS constants
        $builtInKinds = [
            Asset::KIND_ACCESS,
            Asset::KIND_AUDIO,
            Asset::KIND_CAPTIONS_SUBTITLES,
            Asset::KIND_COMPRESSED,
            Asset::KIND_EXCEL,
            Asset::KIND_FLASH,
            Asset::KIND_HTML,
            Asset::KIND_ILLUSTRATOR,
            Asset::KIND_IMAGE,
            Asset::KIND_JAVASCRIPT,
            Asset::KIND_JSON,
            Asset::KIND_PDF,
            Asset::KIND_PHOTOSHOP,
            Asset::KIND_PHP,
            Asset::KIND_POWERPOINT,
            Asset::KIND_TEXT,
            Asset::KIND_VIDEO,
            Asset::KIND_WORD,
            Asset::KIND_XML,
            Asset::KIND_UNKNOWN,
        ];

        // Get custom asset kinds from general config
        $customKinds = array_keys(Craft::$app->getConfig()->getGeneral()->extraFileKinds);

        // Merge and return all kinds
        return array_merge($builtInKinds, $customKinds);
    }

    /**
     * Returns asset kinds that support text-based content extraction
     * @return array
     * @since 4.0.0
     */
    public function getTextBasedAssetKinds(): array
    {
        // Check if any custom asset kinds should be considered text-based
        // For now, we'll be conservative and only include built-in text kinds
        // Users can extend this via config if needed
        return [
            Asset::KIND_HTML,
            Asset::KIND_JAVASCRIPT,
            Asset::KIND_JSON,
            Asset::KIND_TEXT,
            Asset::KIND_XML,
            Asset::KIND_PHP,
        ];
    }

    /**
     * Handle element save events to queue for indexing
     *
     * @param ModelEvent $event The model event containing the saved element
     * @throws InvalidConfigException
     * @throws IndexElementException
     * @since 4.0.0
     */
    public function onElementSaved(ModelEvent $event): void
    {
        /** @var Element $element */
        $element = $event->sender;

        // Handle drafts and revisions for Craft 3.2 and upper
        $notDraftOrRevision = true;
        $schemaVersion = Craft::$app->installedSchemaVersion;
        if (version_compare($schemaVersion, '3.2.0', '>=')) {
            $notDraftOrRevision = !ElementHelper::isDraftOrRevision($element);
        }

        if ($notDraftOrRevision) {
            if ($element->enabled) {
                $this->reindexQueueManagement->enqueueJob($element->id, $element->siteId, get_class($element));
            } else {
                $this->elementIndexer->deleteElement($element);
            }
        }
    }

    /**
     * Handle element deletion events to remove from index
     *
     * @param Event $event The event containing the deleted element
     * @throws IndexElementException
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function onElementDelete(Event $event): void
    {
        /** @var Element $element */
        $element = $event->sender;
        $this->elementIndexer->deleteElement($element);
    }

    /**
     * Handle plugin settings saved event to reinitialize connection and reindex
     * @throws Exception|InvalidConfigException|MissingComponentException
     * @since 4.0.0
     */
    protected function onPluginSettingsSaved(): void
    {
        $this->initializeElasticConnector();

        Craft::debug('Search with Elastic plugin settings saved => re-index all elements', __METHOD__);
        try {
            $this->indexManagement->recreateIndexesForAllSites();

            // Remove previous reindexing jobs as all elements will be reindexed anyway
            $this->reindexQueueManagement->clearJobs();
            $this->reindexQueueManagement->enqueueReindexJobs($this->elasticsearch->getIndexableElementModels());
        } catch (IndexingException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
        }
    }

    /**
     * Add sidebar status information to element edit pages
     *
     * @param DefineHtmlEvent $event The sidebar HTML event
     * @throws Exception|InvalidConfigException|LoaderError|RuntimeError|SyntaxError|\DateInvalidTimeZoneException|\yii\base\Exception
     * @since 4.0.0
     */
    public function sidebarStatus(DefineHtmlEvent $event): void
    {
        $element = $event->sender ?? null;

        if (!$element instanceof Asset && !$element instanceof Entry && !$element instanceof Category && !$element instanceof Product && !$element instanceof DigitalProduct) {
            return;
        }

        $disabledType = false;

        if ($element instanceof Asset && !in_array($element->kind, $this->getSettings()->assetKinds, true)) {
            $disabledType = true;
        }

        if ($element instanceof Entry && in_array($element->getType()->handle, $this->getSettings()->excludedEntryTypes, true)) {
            $disabledType = true;
        }

        if ($element instanceof Category && in_array($element->getGroup()->handle, $this->getSettings()->excludedCategoryGroups, true)) {
            $disabledType = true;
        }

        if ($element instanceof Product && in_array($element->getType()->handle, $this->getSettings()->excludedProductTypes, true)) {
            $disabledType = true;
        }

        if ($element instanceof DigitalProduct && in_array($element->getType()->handle, $this->getSettings()->excludedDigitalProductTypes, true)) {
            $disabledType = true;
        }

        $isNew = $element->id === null;

        if ($isNew) {
            return;
        }

        if ($element->getIsDraft()) {
            $element = $element->getCanonical();
        }

        $esRecord = $this->elasticsearch->getElementIndex($element);
        $status = $this->elasticsearch->getElementIndexStatus($element);

        $dateUpdated = null;
        $revisionNum = null;

        if ($esRecord) {
            // Try parsing as ISO 8601 format first (what we store), then fall back to other formats
            $dateString = $esRecord->attributes['updateDate'];
            $dateUpdated = false;

            if ($dateString) {
                // Try ISO 8601 format first (format 'c')
                $dateUpdated = DateTime::createFromFormat('c', $dateString);

                // Fall back to Y-m-d H:i:s format for compatibility
                if (!$dateUpdated) {
                    $dateUpdated = DateTime::createFromFormat('Y-m-d H:i:s', $dateString, new DateTimeZone('UTC'));
                }

                // Final fallback - try creating directly
                if (!$dateUpdated) {
                    try {
                        $dateUpdated = new DateTime($dateString);
                    } catch (\yii\elasticsearch\Exception) {
                        $dateUpdated = false;
                    }
                }
            }

            if ($dateUpdated instanceof DateTime) {
                $dateUpdated->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

                $revisionNum = $this->elasticsearch->getElementIndexRevision($element, $esRecord->attributes['updateDate']);
            }
        }

        $event->html .= Craft::$app->view->renderTemplate(
            'search-with-elastic/cp/sidebar',
            [
                'status' => $status,
                'disabledType' => $disabledType,
                'dateUpdated' => $dateUpdated ? $dateUpdated->format(Craft::$app->getLocale()->getDateTimeFormat('short', Locale::FORMAT_PHP)) : null,
                'revisionNum' => $revisionNum,
                'attributes' => $esRecord ? $esRecord->attributes : [],
                'element' => $element,
            ]
        );
    }
}
