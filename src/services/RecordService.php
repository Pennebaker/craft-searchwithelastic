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

use craft\base\Component;
use craft\base\Element;
use pennebaker\searchwithelastic\events\indexing\RecordEvent;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * The Record service provides APIs for managing Elasticsearch records.
 *
 * An instance of the service is available via [[\pennebaker\searchwithelastic\SearchWithElastic::getInstance()|`SearchWithElastic::getInstance()->records`]].
 *
 * @author Pennebaker
 * @since 4.0.0
 */
class RecordService extends Component
{
    // Event constants following Craft patterns
    public const EVENT_BEFORE_SAVE_RECORD = 'beforeSaveRecord';
    public const EVENT_AFTER_SAVE_RECORD = 'afterSaveRecord';
    public const EVENT_BEFORE_DELETE_RECORD = 'beforeDeleteRecord';
    public const EVENT_AFTER_DELETE_RECORD = 'afterDeleteRecord';

    /**
     * Retrieves element record from Elasticsearch if it exists.
     *
     * @param Element $element The element to look up
     * @return object|null The element record object or null if not found
     */
    public function getElementRecord(Element $element): ?object
    {
        try {
            $connection = SearchWithElastic::getConnection();
            $indexName = SearchWithElastic::getInstance()->indexManagement->getIndexName($element->siteId, get_class($element));
            $documentId = $element->id . '_' . $element->siteId;

            $response = $connection->createCommand()->get($indexName, '_doc', $documentId);

            if ($response && isset($response['found']) && $response['found']) {
                $dateUpdated = $response['_source']['dateUpdated'] ?? null;
                return (object)[
                    'elementId' => $element->id,
                    'siteId' => $element->siteId,
                    'dateUpdated' => $dateUpdated,
                    'documentId' => $documentId,
                    'attributes' => [
                        'updateDate' => $dateUpdated,
                        'elementId' => $element->id,
                        'siteId' => $element->siteId,
                    ]
                ];
            }

            return null;
        } catch (\Exception $e) {
            // Log error for debugging purposes
            \Craft::error("Failed to get element record for element $element->id: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Returns the count of pending elements for a site.
     * Always returns 0 since this implementation doesn't use database tracking.
     *
     * @param int $siteId The site ID
     * @return int Always returns 0 (no database tracking)
     */
    public function getPendingElementsCount(int $siteId): int
    {
        // No database tracking implemented
        return 0;
    }

    /**
     * Records element save operation. No-op since tracking is handled by Elasticsearch.
     *
     * @param Element $element The element being saved
     * @param string $documentId The document ID in Elasticsearch
     * @return bool Always returns true
     */
    public function saveElementRecord(Element $element, string $documentId): bool
    {
        // Fire a 'beforeSaveRecord' event
        $event = new RecordEvent([
            'element' => $element,
            'documentId' => $documentId,
            'operation' => 'save',
            'result' => true,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_RECORD)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_RECORD, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return $event->result ?? true;
        }

        // Tracking handled directly by Elasticsearch indexing
        $event->result = true;

        // Fire an 'afterSaveRecord' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_RECORD)) {
            $this->trigger(self::EVENT_AFTER_SAVE_RECORD, $event);
        }

        return $event->result;
    }

    /**
     * Records element deletion operation. No-op since handling is done by Elasticsearch.
     *
     * @param Element $element The element being deleted
     * @return bool Always returns true
     */
    public function deleteElementRecord(Element $element): bool
    {
        // Fire a 'beforeDeleteRecord' event
        $event = new RecordEvent([
            'element' => $element,
            'documentId' => null, // No document ID for deletion
            'operation' => 'delete',
            'result' => true,
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_RECORD)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_RECORD, $event);
        }

        // If event handler wants to skip default operation
        if ($event->skipDefaultOperation) {
            return $event->result ?? true;
        }

        // Deletion handled directly by Elasticsearch
        $event->result = true;

        // Fire an 'afterDeleteRecord' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_RECORD)) {
            $this->trigger(self::EVENT_AFTER_DELETE_RECORD, $event);
        }

        return $event->result;
    }

    /**
     * Deletes all records for a site. No-op since no database records are maintained.
     *
     * @param int $siteId The site ID
     * @return bool Always returns true
     */
    public function deleteAllRecordsForSite(int $siteId): bool
    {
        // No database records maintained
        return true;
    }

    /**
     * Retrieves all records for a site. Returns empty array since no database tracking is used.
     *
     * @param int $siteId The site ID
     * @return array Always returns empty array
     */
    public function getRecordsForSite(int $siteId): array
    {
        // No database records maintained
        return [];
    }
}
