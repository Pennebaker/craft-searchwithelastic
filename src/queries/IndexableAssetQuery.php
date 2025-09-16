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

namespace pennebaker\searchwithelastic\queries;

use craft\elements\Asset;
use pennebaker\searchwithelastic\traits\FrontendFetchingTrait;

/**
 * Query builder for indexable assets
 *
 * Provides specialized filtering methods for querying assets that should be
 * indexed in Elasticsearch, including filtering by asset kinds, volumes,
 * file properties, and frontend fetching configuration.
 *
 * @template TElement of Asset
 * @author Pennebaker
 * @since 4.0.0
 */
class IndexableAssetQuery extends IndexableElementQuery
{
    use FrontendFetchingTrait;

    /**
     * Get the element type class for this query
     *
     * @return class-string<TElement> The fully qualified element class name
     */
    public static function elementType(): string
    {
        return Asset::class;
    }

    /**
     * Apply default filtering based on plugin settings
     *
     * Applies status, asset kind, and excluded volume filtering based on
     * the plugin configuration.
     *
     * @return self self reference
     */
    protected function applyDefaultFilters(): self
    {
        $settings = $this->getPlugin()->getSettings();

        // Assets are always enabled/disabled, not pending/live like entries
        $this->statuses([Asset::STATUS_ENABLED]);

        // Apply asset kinds filtering
        $this->kind($settings->assetKinds);

        // Apply excluded asset volumes
        $this->excluded($settings->excludedAssetVolumes);

        return $this;
    }

    /**
     * Narrows the query results based on excluded volume handles
     *
     * Possible values include:
     *
     * | Value | Fetches assets…
     * | - | -
     * | `['uploads', 'documents']` | not from volumes with handles `uploads` or `documents`
     *
     * @param array $handles Volume handles to exclude
     * @return self self reference
     */
    public function excluded(array $handles): self
    {
        $this->excludedHandles = $handles;
        $this->applyexcludeFilter('volume');
        return $this;
    }

    /**
     * Narrows the query results based on the assets' volumes
     *
     * Possible values include:
     *
     * | Value | Fetches assets…
     * | - | -
     * | `'uploads'` | in a volume with a handle of `uploads`
     * | `['uploads', 'documents']` | in volumes with handles of `uploads` or `documents`
     * | `['not', 'uploads']` | not in a volume with a handle of `uploads`
     *
     * @param mixed $volumes Volume handles or IDs
     * @return self self reference
     */
    public function volumes(mixed $volumes): self
    {
        $this->volume($volumes);
        return $this;
    }

    /**
     * Narrows the query results based on the assets' file kinds
     *
     * Possible values include:
     *
     * | Value | Fetches assets…
     * | - | -
     * | `'image'` | that are images
     * | `['image', 'video']` | that are images or videos
     * | `['not', 'image']` | that are not images
     *
     * @param mixed $kinds Asset kind names (e.g., 'pdf', 'image', 'video')
     * @return self self reference
     */
    public function kinds(mixed $kinds): self
    {
        $this->kind($kinds);
        return $this;
    }

    /**
     * Get the setting key for excluded frontend fetching items
     *
     * @return string The settings key for excluded asset volumes
     */
    protected function getExcludedFrontendFetchingSettingKey(): string
    {
        return 'excludedFrontendFetchingAssetVolumes';
    }

    /**
     * Get the element query method to apply exclusions
     *
     * @return string The method name for volume filtering
     */
    protected function getFrontendFetchingFilterMethod(): string
    {
        return 'volume';
    }

    /**
     * Narrows the query results to only PDF assets
     *
     * @return self self reference
     */
    public function pdfsOnly(): self
    {
        return $this->kinds(['pdf']);
    }

    /**
     * Narrows the query results to only image assets
     *
     * @return self self reference
     */
    public function imagesOnly(): self
    {
        return $this->kinds(['image']);
    }

    /**
     * Narrows the query results to only video assets
     *
     * @return self self reference
     */
    public function videosOnly(): self
    {
        return $this->kinds(['video']);
    }

    /**
     * Narrows the query results to only audio assets
     *
     * @return self self reference
     */
    public function audioOnly(): self
    {
        return $this->kinds(['audio']);
    }

    /**
     * Narrows the query results to only document assets (PDF, Word, Excel, PowerPoint, text)
     *
     * @return self self reference
     */
    public function documentsOnly(): self
    {
        return $this->kinds(['pdf', 'word', 'excel', 'powerpoint', 'text']);
    }

    /**
     * Narrows the query results based on the assets' file extensions
     *
     * Possible values include:
     *
     * | Value | Fetches assets…
     * | - | -
     * | `'jpg'` | with a file extension of `jpg`
     * | `['jpg', 'png']` | with file extensions of `jpg` or `png`
     * | `['not', 'gif']` | not with a file extension of `gif`
     *
     * @param mixed $extensions File extensions to filter by (without dots)
     * @return self self reference
     */
    public function extensions(mixed $extensions): self
    {
        $this->extension($extensions);
        return $this;
    }

    /**
     * Narrows the query results to assets with a minimum file size
     *
     * @param int $minSize Minimum file size in bytes
     * @return self self reference
     */
    public function minSize(int $minSize): self
    {
        $this->size('>= ' . $minSize);
        return $this;
    }

    /**
     * Narrows the query results to assets with a maximum file size
     *
     * @param int $maxSize Maximum file size in bytes
     * @return self self reference
     */
    public function maxSize(int $maxSize): self
    {
        $this->size('<= ' . $maxSize);
        return $this;
    }

    /**
     * Narrows the query results to assets within a specific file size range
     *
     * @param int $minSize Minimum file size in bytes
     * @param int $maxSize Maximum file size in bytes
     * @return self self reference
     */
    public function sizeRange(int $minSize, int $maxSize): self
    {
        $this->size(['and', '>= ' . $minSize, '<= ' . $maxSize]);
        return $this;
    }
}
