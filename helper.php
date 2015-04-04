<?php
/**
 * RRDGraph Plugin: Helper classes
 * 
 * @author Daniel Go� <developer@flashsystems.de>
 * @license MIT
 */

/**
 * Base class for all cache implementations within the RRDGraph plugin.
 * This class is derived from the DokuWiki cache class.
 * It implements the dependency handling mechanism that is needed for the
 * rrd INCLUDE tag.
 */
class cache_rrdgraphbase extends cache {
	/** @var String Page-Number of the page that is managed by this cache instance. */
    private $pageId;
    
    /** @var String Name of the plugin using this cache. This value is used to get the dependencies metadata. */
    private $pluginName;

    /**
     * C'tor
     * @param String $pluginName The name of the plugin. This can be retrieved by getPluginName() and makes the plugin more robust to renaming.
     * @param String $pageId The wiki id of the page the cached content is on.
     * @param String $key Uniq value identifying the cached content on the page provied by $pageId. The identifier is hashed before being used.
     * @param String $ext The extension of the cache file.
     */
    protected function __construct($pluginName, $pageId, $key, $ext) {
        $this->pageId = $pageId;
        $this->pluginName = $pluginName;
        
        parent::__construct($pageId . '/' . $key, $ext);
    }

    /**
     * Adds the dependencies from the plugin_[name] -> dependencies metadata element.
     * This way the included dependencies of the rrd graphs on a page can be tracked.
     */
    protected function _addDependencies() {
        $files = array (
                wikiFN($this->pageId) 
        );
        
        //-- We oversimplify this a litte and add all dependencies of the current page to very image
        //   without distinction between the recipies.
        //   But if one include is changed recalculating all images only generates litte overhead because
        //   they are regenerated every time after a cache timeout.
        $dependencies = p_get_metadata($this->pageId, 'plugin_' . $this->pluginName . ' dependencies');
        
        if (! empty($dependencies)) {
            foreach ($dependencies as $dependency) {
                $files[] = wikiFN($dependency);
            }
        }
        
        if (! array_key_exists('files', $this->depends))
            $this->depends['files'] = $files;
        else
            $this->depends['files'] = array_merge($files, $this->depends['files']);
        
        parent::_addDependencies();
    }
}

/**
 * This cache class manages the rrd recipe cache.
 * This cache only times out if the recipe changes. 
 *
 */
class cache_rrdgraph extends cache_rrdgraphbase {
    /**
     * C'tor
     * @param String $pluginName The name of the plugin. This can be retrieved by getPluginName() and makes the plugin more robust to renaming.
     * @param String $pageId The wiki id of the page the cached content is on.
     * @param String $recipeName An identifier used to identify the cache recipe on the page provied by pageId. The identifier is hashed before being used.
     */
    public function __construct($pluginName, $pageId, $recipeName) {
        $this->pluginName = $pluginName;
        
        parent::__construct($pluginName, $pageId, $recipeName, ".rrd");
    }
}

/**
 * This cache class manages the png images generated by rrdtool.
 * The cached images are used as long as the recipe does not change and the maximum age (config) is not reached.
 *
 */
class cache_rrdgraphimage extends cache_rrdgraphbase {
    /** @var Integer Maximum age of the image to be considered usable. */
    private $maxAge;

    /**
     * C'tor
     * @param String $pluginName The name of the plugin. This can be retrieved by getPluginName() and makes the plugin more robust to renaming.
     * @param String $pageId The wiki id of the page the cached content is on.
     * @param String $recipeName An identifier used to identify the cache recipe on the page provied by pageId. The identifier is hashed before being used.
     * @param Integer $rangeNr ID of the time range this image is cached for. 
     * @param String $conditions An identifier for the conditions used for creating the image (fullscreen, etc.).
     * @param Integer $maxAge Maximum age of the image in seconds. If the image is older than the given age, it is not used and must be recreated.
     */
    public function __construct($pluginName, $pageId, $recipeName, $rangeNr, $conditions, $maxAge) {
        $this->maxAge = $maxAge;
        parent::__construct($pluginName, $pageId, $recipeName . '/' . $conditions . '/' . $rangeNr, ".png");
    }

    /**
     * Determins the name of the file used for caching. This name can be used to pass it to other functions to update the content of the cache.
     * @returns Returns the name and path of the cache file.
     */
    public function getCacheFileName() {
        return $this->cache;
    }

    /**
     * (non-PHPdoc)
     * @see cache_rrdgraphbase::_addDependencies()
     */
    protected function _addDependencies() {
        //-- Set maximum age.
        $this->depends['age'] = $this->maxAge;
        
        parent::_addDependencies();
    }

    /**
     * Returns the time until this image is valid.
     * If the cache file does not exist (the data was never cached) 0 is returned.
     * @return Integer Unix timestamp when this image is no longer valid.
     */
    public function getValidUntil() {
        if ($this->useCache()) {
            return $this->_time + $this->maxAge;
        } else {
            return 0;
        }
    }

    /**
     * Determins the last modification time of the cache data.
     * If the cache file does not exist (the data was never cached) the current time is returned.
     * @return Integer Unix timestamp of the last modification time of the cached file.
     */
    public function getLastModified() {
        if (empty($this->_time))
            return time();
        else
            return $this->_time;
    }
}

/**
 * Stores information about a rrd image. This information can be used to update the
 * cache image file. To load the image file and to construct HTTP headers for tramsmission.
 *
 */
class rrdgraph_image_info {
	/** @var String Name of the rrd image file within the cache. */
    private $fileName;
    
    /** @var Resource File handle used to lock the file. */
    private $fileHandle;

    /** @var Integer Timestamp until the file named by $fileName ist considered valid. */
    private $validUntil;

    /** @var Integer Timestamp when the file named by $fileName was last updated. */
    private $lastModified;

    /**
     * C'tor
     * @param String $fileName Sets the $fileName value.
     * @param Integer $validUntil Sets the $validUntil value.
     * @param Integer $lastModified Sets the $lastModfiied value.
     */
    public function __construct($fileName, $validUntil, $lastModified) {
        $this->fileName = $fileName;
        $this->validUntil = $validUntil;
        $this->lastModified = $lastModified;
        
        //-- Get a shared lock on the lock-file.
        $this->fileHandle = fopen($fileName . ".lock", "w+");
        flock($this->fileHandle, LOCK_SH);
    }
    
    /**
     * D'tor
     */
    public function __destruct() {
        fclose($this->fileHandle);
    }

    /**
     * @see cache_rrdgraphimage::getCacheFileName()
     */
    public function getFileName() {
        return $this->fileName;
    }

    /**
     * @see cache_rrdgraphimage::getValidUntil()
     */
    public function getValidUntil() {
        return $this->validUntil;
    }

    /**
     * @see cache_rrdgraphimage::getLastModified()
     */
    public function getLastModified() {
        return $this->lastModified;
    }

    /**
     * Checks if the cached file returned by getFileName() is still valid.
     * @return boolean Returns "true" if the cached file should still be used or "false" if it must be recreated. 
     */
    public function isValid() {
        return $this->validUntil > time();
    }
    
    public function upgradeLock() {
        flock($this->fileHandle, LOCK_EX);
    }
}

/**
 * DokiWuki helper plugin class. This class supplies some methods used throughout the other RRDGraph plugin modules.
 *
 */
class helper_plugin_rrdgraph extends DokuWiki_Plugin {
    /** @var Array Cache for already loaded and inflated recipes. This speeds up loading the same recipe multiple times on the same wiki page */ 
    private $localRecipeCache;

    /**
     * Returns an array of method declarations for docuwiki.
     * @see https://www.dokuwiki.org/devel:helper_plugins
     * @return Returns the declaration array.
     */
    public function getMethods() {
        //-- Non of the contained functions are for public use!
        return array();
    }

    /**
     * Stores a rrd recipe for the given page.
     * @param String $pageId Wiki page id.
     * @param String $recipeName Name of the recipe to store.
     * @param Array $recipeData Array of recipe data to be stored. 
     */
    public function storeRecipe($pageId, $recipeName, $recipeData) {
        //-- Put the file into the cache.
        $cache = new cache_rrdgraph($this->getPluginName(), $pageId, $recipeName);
        $cache->storeCache(serialize($recipeData));
        
        $this->localRecipeCache[$pageId . "/" . $recipeName] = $recipeData;
    }

    /**
     * Load a gieven rrd recipe. If the recipe is not available within the cache or needs to be updated the wiki page is rendered
     * to give the syntax plugin a chance to create and cache the rrd data.
     * @param String $pageId Wiki page id.
     * @param String $recipeName Name of the recipe to load.
     * @returns Array Returns an array containing an rrd recipe. If the recipe can not be found or recreated this method returns null.
     */
    public function fetchRecipe($pageId, $recipeName) {
        if (! isset($this->localRecipeCache[$pageId . "/" . $recipeName])) {
            $cache = new cache_rrdgraph($this->getPluginName(), $pageId, $recipeName);
            if ($cache->useCache()) {
                $this->localRecipeCache[$pageId . "/" . $recipeName] = unserialize($cache->retrieveCache());
            } else {
                //-- The rrd-information is not cached. Render the page
                //   to refresh the stored rrd information.
                p_wiki_xhtml($pageId);
                
                //-- Try again to get the data
                $this->localRecipeCache[$pageId . "/" . $recipeName] = unserialize($cache->retrieveCache());
            }
        }
        
        if (empty($this->localRecipeCache[$pageId . "/" . $recipeName])) $this->localRecipeCache[$pageId . "/" . $recipeName] = null;
        
        return $this->localRecipeCache[$pageId . "/" . $recipeName];
    }
    
    /**
     * Inflates a given recipe.
     * When a recipe is inflated, included recipes are automatically loaded (and rendered if necessary) and included into the given recipe.
     * @param Array $recipe A rrd recipe. If this value is not an array, null is returned.
     * @return Array If the recipe could be successfully inflate, the recipe is returned with all includes replaced by the included elements.
     * @throws Exception If an error occures (if the ACL does not allow loading an included recpipe) an exception is thrown.
     */
    public function inflateRecipe($recipe) {
        if (! is_array($recipe)) return null;
        
        //-- Cache the setting if ACLs should be checked for includes.
        $checkACL = ($this->getConf('include_acl') > 0);
        
        //-- Resolve includes
        $inflatedRecipe = array ();
        $includeDone = false;
        foreach ($recipe as $element) {
            switch (strtoupper($element[1])) {
            case 'INCLUDE' :
                list ($incPageId, $incTmplName) = explode('>', $element[2], 2);
                $incPageId = trim($incPageId);
                $incTmplName = trim($incTmplName);
                
                if ($checkACL) {
                    if (auth_quickaclcheck($incPageId) < AUTH_READ) throw new Exception("Access denied by ACL.");
                }
                
                $includedPageRecipe = $this->fetchRecipe($incPageId, $incTmplName);
                if ($includedPageRecipe !== null) {
                    $inflatedRecipe = array_merge($inflatedRecipe, $includedPageRecipe);
                }
                break;
            default :
                $inflatedRecipe[] = $element;
            }
        }
        
        $recipe = $inflatedRecipe;
        
        return $recipe;
    }

    /**
     * Parses a recipe and returns the wiki page ids of all included recipes.
     * @param Array $recipe The rrd recipe to parse.
     * @return Array A string array continaing all page ids included by the given recipe.
     */
    public function getDependencies($recipe) {
        $depPageIds = array ();
        
        foreach ($recipe as $element) {
            if (strcasecmp($element[1], 'INCLUDE') == 0) {
                list ($incPageId, $incTmplName) = explode('>', $element[2], 2);
                $incPageId = trim($incPageId);
                
                $depPageIds[$incPageId] = $incPageId;
                break;
            }
        }
        
        return array_values($depPageIds);
    }

    /**
     * Returns a rrdgraph_image_info instance contianing the information needed to deliver or recreate the given png rrd image.
     * @param String $pageId The wiki id of the page the cached content is on.
     * @param String $recipeName An identifier used to identify the cache recipe on the page provied by pageId. The identifier is hashed before being used.
     * @param Integer $rangeNr ID of the time range this image is cached for. 
     * @param String $conditions An identifier for the conditions used for creating the image (fullscreen, etc.).
     */
    public function getImageCacheInfo($pageId, $recipeName, $rangeNr, $conditions) {
        $cache = new cache_rrdgraphimage($this->getPluginName(), $pageId, $recipeName, $rangeNr, $conditions, $this->getConf('cache_timeout'));
        
        return new rrdgraph_image_info($cache->getCacheFileName(), $cache->getValidUntil(), $cache->getLastModified());
    }
}