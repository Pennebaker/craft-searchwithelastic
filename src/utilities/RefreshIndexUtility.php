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

namespace pennebaker\searchwithelastic\utilities;

use Craft;
use craft\base\Utility;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use pennebaker\searchwithelastic\resources\CpAssetBundle;
use pennebaker\searchwithelastic\SearchWithElastic;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;

/**
 * Control Panel utility for refreshing Elasticsearch indexes
 *
 * Provides a web interface for administrators to reindex content in Elasticsearch,
 * with options to select specific sites and element types for reindexing.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class RefreshIndexUtility extends Utility
{
    /**
     * Get the display name of this utility
     *
     * @return string The display name shown in the Control Panel
     * @since 4.0.0
     */
    public static function displayName(): string
    {
        return Craft::t(SearchWithElastic::PLUGIN_HANDLE, 'Refresh Elasticsearch index');
    }

    /**
     * Get the utility's unique identifier
     *
     * The ID is used in URLs and should be in kebab-case format.
     *
     * @return string The utility identifier
     * @since 4.0.0
     */
    public static function id(): string
    {
        return 'refresh-elasticsearch';
    }

    /**
     * Get the path to the utility's SVG icon
     *
     * @return string|null The path to the utility SVG icon
     * @since 4.0.0
     */
    public static function iconPath(): ?string
    {
        return Craft::getAlias('@pennebaker/searchwithelastic/icon-mask.svg');
    }

    /**
     * Get the badge count for the utility's navigation item
     *
     * Shows a badge when there are connection issues or the index is out of sync.
     *
     * @return int The badge count (0 means no badge)
     * @since 4.0.0
     */
    public static function badgeCount(): int
    {
        try {
            if (!SearchWithElastic::getInstance()->elasticsearch->testConnection() || !SearchWithElastic::getInstance()->elasticsearch->isIndexInSync()) {
                return 1;
            }
        } catch (\Exception) {
            // Ignore exceptions to prevent breaking the Control Panel over a badge count
        }

        return 0;
    }

    /**
     * Get the utility's content HTML
     *
     * Renders the utility interface with connection status, available sites,
     * and element types for reindexing.
     *
     * @return string The rendered HTML content
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();

        $view->registerAssetBundle(CpAssetBundle::class);
        $view->registerJs('new Craft.SearchWithElasticUtility(\'search-with-elastic-utility\');');

        // Build list of sites with their available element types and counts
        $sitesWithElementTypes = [];
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $sitesWithElementTypes[] = [
                'id' => $site->id,
                'name' => $site->name,
                'elementTypes' => self::getAvailableElementTypes($site->id)
            ];
        }

        return Craft::$app->getView()->renderTemplate(
            'search-with-elastic/cp/utility',
            [
                'isConnected'                => SearchWithElastic::getInstance()->elasticsearch->testConnection(),
                'inSync'                     => SearchWithElastic::getInstance()->elasticsearch->isIndexInSync(),
                'sites'                      => ArrayHelper::map(Craft::$app->sites->getAllSites(), 'id', 'name'),
                'sitesWithElementTypes'      => $sitesWithElementTypes,
                'notConnectedWarningMessage' => Craft::t(
                    SearchWithElastic::PLUGIN_HANDLE,
                    'Could not connect to the elasticsearch instance. Please check the {pluginSettingsLink}.',
                    [
                        'pluginSettingsLink' => sprintf(
                            '<a href="%s">%s</a>',
                            UrlHelper::cpUrl('settings/plugins/' . SearchWithElastic::PLUGIN_HANDLE),
                            Craft::t(SearchWithElastic::PLUGIN_HANDLE, 'plugin\'s settings')
                        ),
                    ]
                ),
            ]
        );
    }

    /**
     * Get available element types for a site with their counts
     *
     * Queries each element type to determine which ones have indexable content
     * for the specified site.
     *
     * @param int $siteId The site ID to check
     * @return array Array of element type information with counts
     * @since 4.0.0
     */
    private static function getAvailableElementTypes(int $siteId): array
    {
        $plugin = SearchWithElastic::getInstance();
        if (!$plugin) {
            return [];
        }
        $elementTypes = [];

        // Check for indexable entries
        try {
            $entryQuery = $plugin->queries->getIndexableEntryQuery($siteId);
            $entryCount = $entryQuery->count();
            if ($entryCount > 0) {
                $elementTypes[] = [
                    'type' => 'entries',
                    'label' => Craft::t('search-with-elastic', 'Entries ({count})', ['count' => $entryCount]),
                    'count' => $entryCount,
                    'class' => Entry::class,
                    'indexName' => $plugin->indexManagement->getIndexName($siteId, Entry::class)
                ];
            }
        } catch (\Exception) {
            // Skip entries if there's an error
        }

        // Check for indexable assets
        try {
            $assetQuery = $plugin->queries->getIndexableAssetQuery($siteId);
            $assetCount = $assetQuery->count();
            if ($assetCount > 0) {
                $elementTypes[] = [
                    'type' => 'assets',
                    'label' => Craft::t('search-with-elastic', 'Assets ({count})', ['count' => $assetCount]),
                    'count' => $assetCount,
                    'class' => Asset::class,
                    'indexName' => $plugin->indexManagement->getIndexName($siteId, Asset::class)
                ];
            }
        } catch (\Exception) {
            // Skip assets if there's an error
        }

        // Check for indexable categories
        try {
            $categoryQuery = $plugin->queries->getIndexableCategoryQuery($siteId);
            $categoryCount = $categoryQuery->count();
            if ($categoryCount > 0) {
                $elementTypes[] = [
                    'type' => 'categories',
                    'label' => Craft::t('search-with-elastic', 'Categories ({count})', ['count' => $categoryCount]),
                    'count' => $categoryCount,
                    'class' => Category::class,
                    'indexName' => $plugin->indexManagement->getIndexName($siteId, Category::class)
                ];
            }
        } catch (\Exception) {
            // Skip categories if there's an error
        }

        // Check for indexable Commerce products (if Commerce is installed)
        if (class_exists(Product::class)) {
            try {
                $productQuery = $plugin->queries->getIndexableProductQuery($siteId);
                $productCount = $productQuery->count();
                if ($productCount > 0) {
                    $elementTypes[] = [
                        'type' => 'products',
                        'label' => Craft::t('search-with-elastic', 'Products ({count})', ['count' => $productCount]),
                        'count' => $productCount,
                        'class' => Product::class,
                        'indexName' => $plugin->indexManagement->getIndexName($siteId, Product::class)
                    ];
                }
            } catch (\Exception) {
                // Skip products if there's an error
            }
        }

        // Check for indexable Digital Products (if Digital Products is installed)
        if (class_exists(\craft\digitalproducts\elements\Product::class)) {
            try {
                $digitalProductQuery = $plugin->queries->getIndexableDigitalProductQuery($siteId);
                $digitalProductCount = $digitalProductQuery->count();
                if ($digitalProductCount > 0) {
                    $elementTypes[] = [
                        'type' => 'digitalProducts',
                        'label' => Craft::t('search-with-elastic', 'Digital Products ({count})', ['count' => $digitalProductCount]),
                        'count' => $digitalProductCount,
                        'class' => \craft\digitalproducts\elements\Product::class,
                        'indexName' => $plugin->indexManagement->getIndexName($siteId, \craft\digitalproducts\elements\Product::class)
                    ];
                }
            } catch (\Exception) {
                // Skip digital products if there's an error
            }
        }

        return $elementTypes;
    }
}