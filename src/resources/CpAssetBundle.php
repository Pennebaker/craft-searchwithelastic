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

namespace pennebaker\searchwithelastic\resources;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for Control Panel resources
 *
 * Provides JavaScript and CSS assets for the Search w/Elastic plugin's
 * Control Panel interface, including the reindexing utility.
 * 
 * @since 4.0.0
 */
class CpAssetBundle extends AssetBundle
{
    /**
     * Initialize the asset bundle
     *
     * Sets up the source path, dependencies, and asset files for the
     * Control Panel interface.
     *
     * @return void
     * @since 4.0.0
     */
    public function init(): void
    {
        $this->sourcePath = '@pennebaker/searchwithelastic/resources/cp';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/utilities/reindex.js',
        ];

        $this->css = [
            'css/utility.css',
        ];

        parent::init();
    }
}
