<?php

/**
 * Defines a section that can be written out to a cache, and be 
 * regenerated on demand, but will return a separate fragment
 * if the system is currently in cache generation mode 
 * 
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class CachedFragment extends ViewableData
{
    public $cacheName = '__OVERRIDE_THIS__';
    
    /**
     * @var SimpleCache
     */
    public $cache;
    
    /**
     * Do we ignore the cache and just regenerate things regardless?
     *
     * @var boolean
     */
    private $forceRegen = false;
    
    protected $templateName = '';
    
    protected $context = null;
    
    public function __construct($context, $templateName = '', $fragmentName = 'fragment')
    {
        $this->context = $context;
        $this->templateName = $templateName ? $templateName : get_class($this);
        $this->forceRegen = isset($_GET['flush']) || Versioned::current_stage() == 'Stage';
    }

    public function setForce($f)
    {
        $this->forceRegen = $f;
    }
    
    /**
     * 
     * @return SimpleCache
     */
    protected function getCache()
    {
        return $this->cache;
    }
    
    /**
     * Get the key to use for this cached fragment
     * 
     * If we're forcing the regen, 
     * 
     * @param boolean $forCacheGen
     * @return string
     */
    protected function getKey($forCacheGen = false)
    {
        $key = get_class($this) . '-' . $this->templateName;
        
        // detect site specific behaviour 
        if (class_exists('Multisites')) {
            $key .= '-' . Multisites::inst()->getCurrentSiteID();
        }
        
        return $key;
        
        /// WHAT is this doing? I can't remember, leaving here for later
        // "oh that's right" moment.
        if (defined('PROXY_CACHE_GENERATING') || $forCacheGen) {
            // use the fragment key instead
            return $key . '-fragment';
        }
    }
    
    /**
     * Are we in a situation where cache generation is okay?
     */
    protected function canGenerateCache()
    {
        return Versioned::current_stage() == 'Live';
    }
    
    public function regenerate()
    {
        $this->forceRegen = true;
        $this->forTemplate();
    }

    public function forTemplate()
    {
        $key = $this->getKey();
        $cache = $this->getCache();
        
        $data = $cache->get($key);

        if ($data && !$this->forceRegen) {
            if (defined('PROXY_CACHE_GENERATING')) {
                return '<!--SimpleCache::' . $key . ',FragmentCache-->';
            }
            return $data;
        }

        $data = $this->context->renderWith($this->templateName);
        $data = $data->raw();

        if (Versioned::current_stage() != 'Stage' && strlen($data) && !isset($_GET['flush'])) {
            //			$this->cache->store($key, $menu, self::CACHE_LENGTH);
                // when storing for the frontend cache replacement stuff, use whatever we have
                // configured
                // do NOT store if we're on stage or we have _flush=1
            $this->cache->store($key, $data);
        }
        
        // clear the cache key for next request
        if (isset($_GET['flush'])) {
            $this->cache->delete($key);
        }
        
        if (defined('PROXY_CACHE_GENERATING')) {
            return '<!--SimpleCache::' . $key . ',FragmentCache-->';
        }
        return $data;
    }
}
