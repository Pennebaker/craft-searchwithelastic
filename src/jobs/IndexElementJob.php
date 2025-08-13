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

namespace pennebaker\searchwithelastic\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use pennebaker\searchwithelastic\exceptions\IndexableElementModelException;
use pennebaker\searchwithelastic\models\IndexableElementModel;
use pennebaker\searchwithelastic\SearchWithElastic;
use yii\base\InvalidConfigException;

/**
 * Job for indexing a single element in Elasticsearch
 *
 * This job handles the indexing of individual elements (entries, assets, etc.)
 * into the Elasticsearch index for a specific site.
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexElementJob extends BaseJob
{
    /**
     * @var int The site ID for the element being indexed
     * @since 4.0.0
     */
    public int $siteId;

    /**
     * @var int The ID of the element to index
     * @since 4.0.0
     */
    public int $elementId;

    /**
     * @var class-string<ElementInterface> The element type class name to index
     * @since 4.0.0
     */
    public string $elementType;

    /**
     * Execute the indexing job
     *
     * Sets the appropriate site context and indexes the specified element
     * into the Elasticsearch index.
     *
     * @param mixed $queue The queue instance
     * @return void
     * @throws IndexableElementModelException
     * @throws InvalidConfigException
     * @since 4.0.0
     */
    public function execute($queue): void
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($this->siteId);
        $sites->setCurrentSite($site);

        $model = new IndexableElementModel();
        $model->elementId = $this->elementId;
        $model->siteId = $this->siteId;
        $model->type = $this->elementType;
        SearchWithElastic::getInstance()->elementIndexer->indexElement($model->getElement());
    }

    /**
     * Get the default job description for display in the queue
     *
     * Creates a human-readable description showing the element type, ID, and site
     * for this indexing job.
     *
     * @return string The job description
     * @since 4.0.0
     */
    protected function defaultDescription(): string
    {
        $type = ($pos = strrpos($this->elementType, '\\')) ? substr($this->elementType, $pos + 1) : $this->elementType;

        return Craft::t(
            SearchWithElastic::PLUGIN_HANDLE,
            sprintf(
                'Index %s #%d (site #%d) in Elasticsearch',
                $type,
                $this->elementId,
                $this->siteId
            )
        );
    }
}
