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

namespace pennebaker\searchwithelastic\console\controllers;

use Craft;
use Exception;
use pennebaker\searchwithelastic\models\IndexableElementModel;
use pennebaker\searchwithelastic\queries\IndexableAssetQuery;
use pennebaker\searchwithelastic\queries\IndexableCategoryQuery;
use pennebaker\searchwithelastic\queries\IndexableDigitalProductQuery;
use pennebaker\searchwithelastic\queries\IndexableEntryQuery;
use pennebaker\searchwithelastic\queries\IndexableProductQuery;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console controller for managing Elasticsearch indexes
 *
 * Provides command-line utilities for reindexing elements, testing connections,
 * and managing Elasticsearch indexes for the Search with Elastic plugin.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexController extends Controller
{
    /**
     * @var string The reindex mode to use
     * Options: reset (default), all, missing, updated, missing-updated
     */
    public $mode = 'reset';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        
        // Add --mode option to all reindex actions
        if (strpos($actionID, 'reindex') === 0) {
            $options[] = 'mode';
        }
        
        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'm' => 'mode',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Validate mode option for reindex actions
        if (strpos($action->id, 'reindex') === 0) {
            $validModes = ['reset', 'all', 'missing', 'updated', 'missing-updated'];
            if (!in_array($this->mode, $validModes, true)) {
                $this->stderr("Invalid mode: {$this->mode}" . PHP_EOL, Console::FG_RED);
                $this->stderr("Valid modes: " . implode(', ', $validModes) . PHP_EOL);
                return false;
            }
        }

        return true;
    }

    /**
     * Reindex all element types (entries, assets, categories, products & digital products) in Elasticsearch
     *
     * @return int Shell exit code (0 = success, non-zero = error)
     * @since 4.0.0
     */
    public function actionReindexAll(): int
    {
        // Handle reset mode - recreate indexes first
        if ($this->mode === 'reset') {
            $this->stdout("Recreating indexes..." . PHP_EOL, Console::FG_YELLOW);
            SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
            $this->stdout("Indexes recreated." . PHP_EOL, Console::FG_GREEN);
        }

        $indexableElementModels = SearchWithElastic::getInstance()->elasticsearch->getIndexableElementModels([], [], $this->mode);

        return $this->reindexElements($indexableElementModels, 'everything');
    }

    /**
     * Reindex all entries in Elasticsearch
     *
     * @return int Shell exit code (0 = success, non-zero = error)
     * @since 4.0.0
     */
    public function actionReindexEntries(): int
    {
        // Handle reset mode - recreate indexes first
        if ($this->mode === 'reset') {
            $this->stdout("Recreating indexes..." . PHP_EOL, Console::FG_YELLOW);
            SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
            $this->stdout("Indexes recreated." . PHP_EOL, Console::FG_GREEN);
        }

        // Get site IDs based on mode requirements
        $siteIds = Craft::$app->getSites()->getAllSiteIds();
        $elementTypes = [\craft\elements\Entry::class];
        
        // Use the main elasticsearch service to get models with mode filtering
        $elementDescriptors = SearchWithElastic::getInstance()->elasticsearch->getIndexableElementModels($siteIds, $elementTypes, $this->mode);

        return $this->reindexElements($elementDescriptors, 'entries');
    }

    /**
     * Reindex all assets in Elasticsearch
     *
     * @return int Shell exit code (0 = success, non-zero = error)
     * @since 4.0.0
     */
    public function actionReindexAssets(): int
    {
        // Handle reset mode - recreate indexes first
        if ($this->mode === 'reset') {
            $this->stdout("Recreating indexes..." . PHP_EOL, Console::FG_YELLOW);
            SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
            $this->stdout("Indexes recreated." . PHP_EOL, Console::FG_GREEN);
        }

        // Get site IDs based on mode requirements
        $siteIds = Craft::$app->getSites()->getAllSiteIds();
        $elementTypes = [\craft\elements\Asset::class];
        
        // Use the main elasticsearch service to get models with mode filtering
        $elementDescriptors = SearchWithElastic::getInstance()->elasticsearch->getIndexableElementModels($siteIds, $elementTypes, $this->mode);

        return $this->reindexElements($elementDescriptors, 'assets');
    }

    /**
     * Reindex all categories in Elasticsearch
     *
     * @return int Shell exit code (0 = success, non-zero = error)
     * @since 4.0.0
     */
    public function actionReindexCategories(): int
    {
        // Handle reset mode - recreate indexes first
        if ($this->mode === 'reset') {
            $this->stdout("Recreating indexes..." . PHP_EOL, Console::FG_YELLOW);
            SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
            $this->stdout("Indexes recreated." . PHP_EOL, Console::FG_GREEN);
        }

        // Get site IDs based on mode requirements
        $siteIds = Craft::$app->getSites()->getAllSiteIds();
        $elementTypes = [\craft\elements\Category::class];
        
        // Use the main elasticsearch service to get models with mode filtering
        $elementDescriptors = SearchWithElastic::getInstance()->elasticsearch->getIndexableElementModels($siteIds, $elementTypes, $this->mode);

        return $this->reindexElements($elementDescriptors, 'categories');
    }

    /**
     * Reindex all Craft Commerce products in Elasticsearch
     *
     * @return int Shell exit code (0 = success, non-zero = error)
     * @since 4.0.0
     */
    public function actionReindexProducts(): int
    {
        // Handle reset mode - recreate indexes first
        if ($this->mode === 'reset') {
            $this->stdout("Recreating indexes..." . PHP_EOL, Console::FG_YELLOW);
            SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
            $this->stdout("Indexes recreated." . PHP_EOL, Console::FG_GREEN);
        }

        // Get site IDs based on mode requirements
        $siteIds = Craft::$app->getSites()->getAllSiteIds();
        $elementTypes = [\craft\commerce\elements\Product::class];
        
        // Use the main elasticsearch service to get models with mode filtering
        $elementDescriptors = SearchWithElastic::getInstance()->elasticsearch->getIndexableElementModels($siteIds, $elementTypes, $this->mode);

        return $this->reindexElements($elementDescriptors, 'products');
    }

    /**
     * Reindex all digital products in Elasticsearch
     *
     * @return int Shell exit code (0 = success, non-zero = error)
     * @since 4.0.0
     */
    public function actionReindexDigitalProducts(): int
    {
        // Handle reset mode - recreate indexes first
        if ($this->mode === 'reset') {
            $this->stdout("Recreating indexes..." . PHP_EOL, Console::FG_YELLOW);
            SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
            $this->stdout("Indexes recreated." . PHP_EOL, Console::FG_GREEN);
        }

        // Get site IDs based on mode requirements
        $siteIds = Craft::$app->getSites()->getAllSiteIds();
        $elementTypes = [\craft\digitalproducts\elements\Product::class];
        
        // Use the main elasticsearch service to get models with mode filtering
        $elementDescriptors = SearchWithElastic::getInstance()->elasticsearch->getIndexableElementModels($siteIds, $elementTypes, $this->mode);

        return $this->reindexElements($elementDescriptors, 'digitalProducts');
    }

    /**
     * Recreate empty Elasticsearch indexes for all sites
     *
     * Removes existing indexes and creates fresh empty ones for all configured sites.
     * This is useful for completely resetting the search index structure.
     *
     * @return void
     * @since 4.0.0
     */
    public function actionRecreateEmptyIndexes(): void
    {
        SearchWithElastic::getInstance()->indexManagement->recreateIndexesForAllSites();
    }

    /**
     * Test the connection to the configured Elasticsearch instance
     *
     * Attempts to connect to Elasticsearch using the plugin's configuration
     * and displays the connection status with appropriate console output.
     *
     * @return int Shell exit code (0 = success, non-zero = connection failed)
     * @since 4.0.0
     */
    public function actionTestConnection(): int
    {
        $this->stdout(PHP_EOL);
        $this->stdout("Testing Elasticsearch connection...", Console::FG_YELLOW);
        $this->stdout(PHP_EOL);

        $settings = SearchWithElastic::getInstance()->getSettings();

        if (SearchWithElastic::getInstance()->elasticsearch->testConnection()) {
            $this->stdout("✓ Successfully connected to {$settings->elasticsearchEndpoint}", Console::FG_GREEN);
            $this->stdout(PHP_EOL);
            return ExitCode::OK;
        }

        $this->stdout("✗ Failed to connect to {$settings->elasticsearchEndpoint}", Console::FG_RED);
        $this->stdout(PHP_EOL);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Reindex a collection of elements with progress tracking
     *
     * Processes each element in the provided array, displaying progress
     * and handling any errors that occur during indexing.
     *
     * @param IndexableElementModel[] $indexableElementModels Elements to reindex
     * @param string $type Description of the element type being processed (for display)
     * @return int Shell exit code (0 = success, non-zero = errors occurred)
     * @since 4.0.0
     */
    protected function reindexElements(array $indexableElementModels, string $type): int
    {
        // Process elements with progress tracking
        $elementCount = count($indexableElementModels);
        $processedElementCount = 0;
        $errorCount = 0;
        $warningCount = 0;
        $skippedCount = 0;

        // Display mode information
        $modeDescriptions = [
            'reset' => 'Reset & Index (recreating indexes)',
            'all' => 'All elements',
            'missing' => 'Missing elements only',
            'updated' => 'Updated elements only',
            'missing-updated' => 'Missing & Updated elements'
        ];
        $modeDesc = $modeDescriptions[$this->mode] ?? $this->mode;

        // Display initial message matching Craft's style
        $this->stdout("Reindexing $elementCount $type (Mode: $modeDesc) ..." . PHP_EOL, Console::FG_YELLOW);

        foreach ($indexableElementModels as $indexableElementModel) {
            $processedElementCount++;

            // Get element info for display
            try {
                $element = $indexableElementModel->getElement();
                $elementTitle = (string)$element;
                $elementId = $element->id;
            } catch (Exception $e) {
                $elementTitle = 'Unknown Element';
                $elementId = 'unknown';
            }

            // Display progress for current element (no newline yet)
            $this->stdout("    - [$processedElementCount/$elementCount] Reindexing $elementTitle ($elementId) ... ");

            // Attempt to reindex the element
            $result = $this->reindexElement($indexableElementModel);

            switch ($result['status']) {
                case 'success':
                    $this->stdout('done' . PHP_EOL, Console::FG_GREEN);
                    break;
                case 'warning':
                    $warningCount++;
                    $this->stdout('warning: ' . $result['message'] . PHP_EOL, Console::FG_YELLOW);
                    break;
                case 'skipped':
                    $skippedCount++;
                    $this->stdout('skipped: ' . $result['message'] . PHP_EOL, Console::FG_CYAN);
                    break;
                case 'error':
                    $errorCount++;
                    $this->stderr('error: ' . $result['message'] . PHP_EOL, Console::FG_RED);
                    break;
            }
        }

        $exitCode = $errorCount === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;

        // Display completion summary matching Craft's style
        $message = "Done reindexing $type";
        if ($errorCount > 0 || $warningCount > 0 || $skippedCount > 0) {
            $counts = [];
            if ($errorCount > 0) {
                $counts[] = "$errorCount error" . ($errorCount > 1 ? 's' : '');
            }
            if ($warningCount > 0) {
                $counts[] = "$warningCount warning" . ($warningCount > 1 ? 's' : '');
            }
            if ($skippedCount > 0) {
                $counts[] = "$skippedCount skipped";
            }
            $message .= " (" . implode(', ', $counts) . ")";
        }
        $this->stdout($message . "." . PHP_EOL, Console::FG_YELLOW);

        return $exitCode;
    }

    /**
     * Reindex a single element
     *
     * Attempts to index the specified element and returns the result.
     *
     * @param IndexableElementModel $indexableElementModel The element model to reindex
     * @return array{status: string, message: string|null} Returns status and optional message
     * @since 4.0.0
     */
    protected function reindexElement(IndexableElementModel $indexableElementModel): array
    {
        try {
            $element = $indexableElementModel->getElement();
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $result = SearchWithElastic::getInstance()->elementIndexer->indexElement($element);

        // Handle indexing result status
        if ($result->isSuccess()) {
            return ['status' => 'success', 'message' => null];
        }

        if ($result->isPartial()) {
            // Partial results are warnings - element indexed but missing content
            return ['status' => 'warning', 'message' => $result->reason ?: 'Partially indexed'];
        }

        if ($result->isSkipped()) {
            // Skipped elements (disabled, no URL, etc)
            return ['status' => 'skipped', 'message' => $result->reason ?: 'Skipped'];
        }

        // Failed indexing
        return ['status' => 'error', 'message' => $result->message ?: $result->reason ?: 'Failed to index'];
    }
}
