<?php
/**
 * HTML Cache plugin for Craft CMS 3.x
 *
 * HTML Cache Service
 *
 * @link      http://www.bolden.nl
 * @copyright Copyright (c) 2018 Bolden B.V.
 * @author Klearchos Douvantzis
 */

namespace bolden\htmlcache\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use bolden\htmlcache\assets\HtmlcacheAssets;
use bolden\htmlcache\HtmlCache;
use craft\elements\Entry;
use craft\services\Elements;
use yii\base\Event;
use craft\elements\db\ElementQuery;
use bolden\htmlcache\records\HtmlCacheCache;
use bolden\htmlcache\records\HtmlCacheElement;

/**
 * HtmlCache Service
 */
class HtmlcacheService extends Component
{
    /**
     * Check if cache file exists
     *
     * @return void
     */
    public function checkForCacheFile()
    {
        // first check if we can create a file
        if (!$this->canCreateCacheFile()) {
            return;
        }
        $uri = \Craft::$app->request->getParam('p');
        $siteId = \Craft::$app->getSites()->getCurrentSite()->id;
        $cacheEntry = HtmlCacheCache::findOne(['uri' => $uri, 'siteId' => $siteId]);
        if ($cacheEntry) {
            // check cache
            $this->checkCache($cacheEntry->uid);
            return \Craft::$app->end();
        }
        // Turn output buffering on
        ob_start();
    }
    
    /**
     * Check if creation of file is allowed
     *
     * @return boolean
     */
    public function canCreateCacheFile()
    {
        // Skip if we're running in devMode and not in force mode
        $settings = HtmlCache::getInstance()->getSettings();
        if (\Craft::$app->config->general->devMode === true && $settings->forceOn == false) {
            return false;
        }

        // skip if not enabled
        if ($settings->enableGeneral == false) {
            return false;
        }
        
        // Skip if system is not on and not in force mode
        if (!\Craft::$app->getIsSystemOn() && $settings->forceOn == false) {
            return false;
        }

        // Skip if it's a CP Request
        if (\Craft::$app->request->getIsCpRequest()) {
            return false;
        }

        // Skip if it's an action Request
        if (\Craft::$app->request->getIsActionRequest()) {
            return false;
        }

        // Skip if it's a preview request
        if (\Craft::$app->request->getIsLivePreview()) {
            return false;
        }
        // Skip if it's a post/ajax request
        if (!\Craft::$app->request->getIsGet()) {
            return false;
        }
        return true;
    }
    
    /**
     * Create the cache file
     *
     * @return void
     */
    public function createCacheFile()
    {
        $uri = \Craft::$app->request->getParam('p');
        $siteId = \Craft::$app->getSites()->getCurrentSite()->id;
        // check if valid to create the file
        if ($this->canCreateCacheFile() && http_response_code() == 200) {
            $cacheEntry = HtmlCacheCache::findOne(['uri' => $uri, 'siteId' => $siteId]);
            // check if entry exists and start capturing content
            if ($cacheEntry) {
                $content = ob_get_contents();
                ob_end_clean();
                $file = $this->getCacheFileName($cacheEntry->uid);
                $fp = fopen($file, 'w+');
                if ($fp) {
                    fwrite($fp, $content);
                    fclose($fp);
                }
                else {
                    \Craft::info('HTML Cache could not write cache file "' . $file . '"');
                }
                echo $content;
            } else {
                \Craft::info('HTML Cache could not find cache entry for siteId: "' . $siteId . '" and uri: "' . $uri .'"');
            }
        }
    }
    
    /**
     * clear cache for given elementId
     *
     * @param integer $elementId
     * @return boolean
     */
    public function clearCacheFile($elementId)
    {
        // get all possible caches
        $elements = HtmlCacheElement::findAll(['elementId' => $elementId]);
        // \craft::Dd($elements);
        $cacheIds = array_map(function($el) {
            return $el->cacheId;
        }, $elements);

        // get all possible caches
        $caches = HtmlCacheCache::findAll(['id' => $cacheIds]);
        foreach ($caches as $cache) {
            $file = $this->getCacheFileName($cache->uid);
            if (file_exists($file)) {
                @unlink($file);
            }
        }


        // delete caches for related entry
        HtmlCacheCache::deleteAll(['id'=> $cacheIds]);
        return true;
    }

    /**
     * Clear all caches
     *
     * @return void
     */
    public function clearCacheFiles()
    {
        FileHelper::clearDirectory();
        HtmlCacheCache::deleteAll();
    }

    /**
     * Get the filename path
     *
     * @param string $uid
     * @return string
     */
    private function getCacheFileName($uid)
    {
        return $this->getDirectory() . $uid . '.html';
    }

    /**
     * Get the directory path
     *
     * @return string
     */
    private function getDirectory()
    {
        // Fallback to default directory if no storage path defined
        if (defined('CRAFT_STORAGE_PATH')) {
            $basePath = CRAFT_STORAGE_PATH;
        } else {
            $basePath = CRAFT_BASE_PATH . DIRECTORY_SEPARATOR . 'storage';
        }

        return $basePath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'htmlcache' . DIRECTORY_SEPARATOR;
    }

    /**
     * Check cache and return it if exists
     *
     * @param string $uid
     * @return mixed
     */
    private function checkCache($uid)
    {
        $file = $this->getCacheFileName($uid);
        // check if file exists
        if (file_exists($file)) {
            if (file_exists($settingsFile = $this->getDirectory() . 'settings.json')) {
                $settings = json_decode(file_get_contents($settingsFile), true);
            } else {
                $settings = ['cacheDuration' => 3600];
            }
            if (time() - ($fmt = filemtime($file)) >= $settings['cacheDuration']) {
                unlink($file);
                return false;
            }
            $content = file_get_contents($file);

            // Check the content type
            $isJson = false;
            if (strlen($content) && ($content[0] == '[' || $content[0] == '{')) {
                // JSON?
                @json_decode($content);
                if (json_last_error() == JSON_ERROR_NONE) {
                    $isJson = true;
                }
            }
            
            // Add extra headers
            if ($isJson) {
                if ($direct) {
                    header('Content-type:application/json');
                }
                echo $content;
            } else {
                if ($direct) {
                    header('Content-type:text/html;charset=UTF-8');
                }
                echo $content;
            }
        }
        return true;
    }

}
