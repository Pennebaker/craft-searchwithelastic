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
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use pennebaker\searchwithelastic\helpers\ElasticsearchHelper;
use pennebaker\searchwithelastic\SearchWithElastic;

/**
 * Handles dependent re-indexing when related element data changes.
 *
 * Two scenarios:
 * 1. Relational re-indexing - When any related element is saved, elements
 *    referencing it via relational fields may have stale data in their
 *    indexed documents.
 * 2. Structure sort order - When an entry moves position in a structure,
 *    sibling entries' order fields become stale.
 *
 * @author Pennebaker
 * @since 5.2.0
 */
class DependentReindexService extends Component
{
    /**
     * Re-indexes all elements that reference the given element via relational fields.
     *
     * Called on EVENT_AFTER_SAVE to propagate changes to any element that
     * has a relation pointing to the saved element.
     *
     * @param Element $element The element that was just saved
     */
    public function handleRelatedReindexing(Element $element): void
    {
        if (!$element->id || !$element->siteId) {
            return;
        }

        $this->reindexRelatedElements($element->id, $element->siteId);
    }

    /**
     * Finds all elements that reference the given target element via relational
     * fields and enqueues them for re-indexing.
     *
     * Uses Craft's native relatedTo query parameter to properly handle all
     * relation types including Matrix and nested element relations.
     *
     * @param int $targetElementId The element that was saved
     * @param int|null $siteId The site ID to scope the query (null for all sites)
     */
    public function reindexRelatedElements(int $targetElementId, ?int $siteId = null): void
    {
        $plugin = SearchWithElastic::getInstance();
        if (!$plugin) {
            return;
        }

        $batchLimit = $plugin->getSettings()->dependentReindexBatchLimit;
        $relatedTo = ['targetElement' => $targetElementId];
        $enqueued = 0;

        // Query each indexable element type for relations to the changed element
        $elementTypes = [
            Entry::class,
            Asset::class,
            Category::class,
        ];

        // Add Commerce element types if available
        if ($plugin->isCommerceEnabled()) {
            $elementTypes[] = \craft\commerce\elements\Product::class;

            if ($plugin->isDigitalProductsEnabled()) {
                $elementTypes[] = \craft\digitalproducts\elements\Product::class;
            }
        }

        foreach ($elementTypes as $elementType) {
            if ($enqueued >= $batchLimit) {
                break;
            }

            $remaining = $batchLimit - $enqueued;

            $query = $elementType::find()
                ->relatedTo($relatedTo)
                ->status(null)
                ->limit($remaining);

            if ($siteId !== null) {
                $query->siteId($siteId);
            }

            $elements = $query->all();

            foreach ($elements as $element) {
                if (ElasticsearchHelper::shouldSkipIndexing($element)) {
                    continue;
                }

                $plugin->reindexQueueManagement->enqueueJob(
                    $element->id,
                    $element->siteId,
                    get_class($element)
                );
                $enqueued++;
            }
        }

        if ($enqueued > 0) {
            Craft::info(
                "Enqueued {$enqueued} related elements for re-indexing (target element #{$targetElementId})",
                __METHOD__
            );
        }
    }

    /**
     * Checks if the saved element belongs to a structure section or category group
     * and re-indexes all other elements in that structure.
     *
     * Called on every element save when structure re-indexing is enabled.
     * Only triggers re-indexing for entries in structure sections and categories.
     *
     * @param Element $element The element that was just saved
     */
    public function handleStructureReindexing(Element $element): void
    {
        $plugin = SearchWithElastic::getInstance();
        if (!$plugin) {
            return;
        }

        $batchLimit = $plugin->getSettings()->dependentReindexBatchLimit;

        if ($element instanceof Entry) {
            $section = $element->getSection();
            if ($section && $section->type === 'structure') {
                $this->reindexEntryStructureSiblings($element, $batchLimit);
            }
        } elseif ($element instanceof Category) {
            $this->reindexCategoryStructureSiblings($element, $batchLimit);
        }
    }

    /**
     * Re-indexes all entries in the same structure section as the moved entry.
     *
     * @param Entry $movedEntry The entry that was moved
     * @param int $batchLimit Maximum number of jobs to enqueue
     */
    private function reindexEntryStructureSiblings(Entry $movedEntry, int $batchLimit): void
    {
        $plugin = SearchWithElastic::getInstance();
        if (!$plugin) {
            return;
        }

        $section = $movedEntry->getSection();
        if (!$section || $section->type !== 'structure') {
            return;
        }

        $entries = Entry::find()
            ->sectionId($section->id)
            ->siteId($movedEntry->siteId)
            ->status(null)
            ->id(['not', $movedEntry->id])
            ->limit($batchLimit)
            ->all();

        Craft::info(
            "Structure move: re-indexing " . count($entries) . " entries in section \"{$section->name}\"",
            __METHOD__
        );

        foreach ($entries as $entry) {
            if (ElasticsearchHelper::shouldSkipIndexing($entry)) {
                continue;
            }

            $plugin->reindexQueueManagement->enqueueJob(
                $entry->id,
                $entry->siteId,
                Entry::class
            );
        }
    }

    /**
     * Re-indexes all categories in the same group as the moved category.
     *
     * @param Category $movedCategory The category that was moved
     * @param int $batchLimit Maximum number of jobs to enqueue
     */
    private function reindexCategoryStructureSiblings(Category $movedCategory, int $batchLimit): void
    {
        $plugin = SearchWithElastic::getInstance();
        if (!$plugin) {
            return;
        }

        $group = $movedCategory->getGroup();
        if (!$group) {
            return;
        }

        $categories = Category::find()
            ->groupId($group->id)
            ->siteId($movedCategory->siteId)
            ->status(null)
            ->id(['not', $movedCategory->id])
            ->limit($batchLimit)
            ->all();

        Craft::info(
            "Structure move: re-indexing " . count($categories) . " categories in group \"{$group->name}\"",
            __METHOD__
        );

        foreach ($categories as $category) {
            if (ElasticsearchHelper::shouldSkipIndexing($category)) {
                continue;
            }

            $plugin->reindexQueueManagement->enqueueJob(
                $category->id,
                $category->siteId,
                Category::class
            );
        }
    }
}
