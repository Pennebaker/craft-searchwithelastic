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

use Craft;
use craft\base\Component;
use pennebaker\searchwithelastic\events\indexing\QueueEvent;
use pennebaker\searchwithelastic\jobs\IndexElementJob;

/**
 * The Reindex Queue Management service provides APIs for managing reindex queue operations.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->reindexQueueManagement`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class ReindexQueueManagementService extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_ENQUEUE_JOBS = 'beforeEnqueueJobs';
    public const EVENT_AFTER_ENQUEUE_JOBS = 'afterEnqueueJobs';
    public const EVENT_BEFORE_CLEAR_QUEUE = 'beforeClearQueue';
    public const EVENT_AFTER_CLEAR_QUEUE = 'afterClearQueue';

    /**
     * Enqueues reindex jobs for multiple indexable element models.
     *
     * @param array $indexableElementModels Array of IndexableElementModel instances
     */
    public function enqueueReindexJobs(array $indexableElementModels): void
    {
        // Fire a 'beforeEnqueueJobs' event
        $event = new QueueEvent([
            'elementModels' => $indexableElementModels,
            'operation' => 'enqueue',
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_ENQUEUE_JOBS)) {
            $this->trigger(self::EVENT_BEFORE_ENQUEUE_JOBS, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return;
        }

        $jobIds = [];
        foreach ($event->elementModels as $model) {
            $jobIds[] = $this->enqueueJob($model->elementId, $model->siteId, $model->type);
        }

        // Fire an 'afterEnqueueJobs' event
        $event->jobIds = $jobIds;
        if ($this->hasEventHandlers(self::EVENT_AFTER_ENQUEUE_JOBS)) {
            $this->trigger(self::EVENT_AFTER_ENQUEUE_JOBS, $event);
        }
    }

    /**
     * Clears all tracked reindex jobs from the cache.
     */
    public function clearJobs(): void
    {
        // Fire a 'beforeClearQueue' event
        $event = new QueueEvent([
            'operation' => 'clear',
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_CLEAR_QUEUE)) {
            $this->trigger(self::EVENT_BEFORE_CLEAR_QUEUE, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return;
        }

        // Clear any cached job tracking
        Craft::$app->cache->delete('search-with-elastic-jobs');

        // Fire an 'afterClearQueue' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_CLEAR_QUEUE)) {
            $this->trigger(self::EVENT_AFTER_CLEAR_QUEUE, $event);
        }
    }

    /**
     * Removes a specific job from the tracking cache.
     *
     * @param int $id The job ID to remove
     */
    public function removeJob(int $id): void
    {
        // Remove job from tracking cache
        $jobs = Craft::$app->cache->get('search-with-elastic-jobs') ?: [];
        if (isset($jobs[$id])) {
            unset($jobs[$id]);
            Craft::$app->cache->set('search-with-elastic-jobs', $jobs);
        }
    }

    /**
     * Enqueues a single reindex job and tracks it in cache.
     *
     * @param int $elementId The element ID to index
     * @param int $siteId The site ID
     * @param string $elementType The element type class name
     * @return string|null The job ID
     */
    public function enqueueJob(int $elementId, int $siteId, string $elementType): ?string
    {
        $job = new IndexElementJob([
            'elementId' => $elementId,
            'siteId' => $siteId,
            'elementType' => $elementType,
        ]);

        $jobId = Craft::$app->queue->push($job);

        // Track the job
        $jobs = Craft::$app->cache->get('search-with-elastic-jobs') ?: [];
        $jobs[$jobId] = [
            'elementId' => $elementId,
            'siteId' => $siteId,
            'elementType' => $elementType,
            'queued' => time(),
        ];
        Craft::$app->cache->set('search-with-elastic-jobs', $jobs);

        return $jobId;
    }
}
