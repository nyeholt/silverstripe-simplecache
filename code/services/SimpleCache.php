<?php

include_once(dirname(__FILE__).'/SimpleCacheStores.php');

/**
 * A simple cache service that uses a configurable CacheStore
 * for persisting items for a given length of time. 
 * 
 * Usage
 * 
 * SimpleCache::get_cache('name')->get('mykey');
 * SimpleCache::get_cache('name')->store('mykey', {someobject}, 3600seconds);
 * 
 * 
 */
class SimpleCache {

	const HIT_KEY = '__SC_HIT';
	const MISS_KEY = '__SC_MISS';
    
    private static $default_caches = array('PublisherCache', 'DynamicPublisherCache', 'FragmentCache');
	
	/**
	 * The cache store to use for actually putting and retrieving items from
	 * 
	 * @var CacheStore
	 */
	protected $store = null;

	/**
	 * The type of store we're using for the cache
	 * 
	 * @var string
	 */
	
	public static $store_type = 'SimpleFileBasedCacheStore';
	
	/**
	 *
	 * @var Define some config for named caches
	 */
	public static $cache_configs = array(
		'default'	=> array(
			'store_type'		=> 'SimpleFileBasedCacheStore',
			'store_options'		=> array(
				'silverstripe-cache/cache_store',
			),
			'cache_options'		=> array(
				'expiry'	=> 600
			)
		),
	);

	/**
	 * @var int
	 */
	public $expiry = 0;

	/**
	 * In memory object store
	 *
	 * @var array
	 */
	private $items = array();
	
	/**
	 * Track cache instances
	 *
	 * @var array
	 */
	private static $instances = array();
	
	/**
	 * Track hits and misses 
	 *
	 * @var int
	 */
	private $hits = 0;
	private $misses = 0;
	
	public $recordStats = false;

	/**
	 * Get the instance
	 * @return CacheService
	 */
	public function inst() {
		return self::get_cache('default');
	}
	
    /**
     * Registers caches in the injector
     */
    public static function register_caches($cacheNames = null) {
        if (!$cacheNames) {
            $cacheNames = self::$default_caches;
        }

        foreach ($cacheNames as $cacheName) {
            $cache = self::get_cache($cacheName);
            if ($cache) {
                Injector::inst()->registerService($cache, $cacheName);
            }
        }
    }
    
	/**
	 * Get a named cache
	 *
	 * @param type $name
	 * @param type $store 
	 * 
	 * @return SimpleCache
	 */
	public static function get_cache($name='default', $store=null, $config = null) {
		if (!isset(self::$instances[$name])) {
			
			if (!isset(self::$cache_configs[$name])) {
				$name = 'default';
			}

			if (isset(self::$cache_configs[$name]) && $conf = self::$cache_configs[$name]) {
				$type = $conf['store_type'];
				$storeOpts = $conf['store_options'];
				$config = isset($conf['cache_options']) ? $conf['cache_options'] : $config;
				$reflector = new ReflectionClass($type);
                
                // note that this bit is to be backwards 
                $args = array(
                    $name,
                    $storeOpts
                );
                
				$store = $reflector->newInstanceArgs($args);
			}

			self::$instances[$name] = new SimpleCache($store, $config);
		}
		return self::$instances[$name];
	}

	public function __construct($store = null, $config = null) {
		if (!$store) {
			$store = self::$store_type;
		}
		if (is_string($store)) {
			$store = new $store;
		}
		
		$this->store = $store;
		
		if ($config) {
			$this->configure($config);
		}
		
		register_shutdown_function(array($this, 'shutdown'));
	}

	/**
	 * Set config for this cache
	 */
	public function configure($config) {
		$this->expiry = isset($config['expiry']) ? $config['expiry'] : $this->expiry;
	}

	/**
	 * On shutdown, store hits and misses
	 */
	public function shutdown() {
		if ($this->recordStats) {
			if ($this->hits) {
				$curr = $this->get(self::HIT_KEY);
				$curr += $this->hits; // don't count the hit on hit_key!
				$this->store(self::HIT_KEY, $curr);
			}

			if ($this->misses) {
				$curr = $this->get(self::MISS_KEY);
				$curr += $this->misses;
				$this->store(self::MISS_KEY, $curr);
			}
		}
	}

	/**
	 * Gets statistics about this cache 
	 */
	public function stats() {
		$stats = new stdClass();
		$stats->hits = $this->get(self::HIT_KEY);
		$stats->misses = $this->get(self::MISS_KEY);
		$stats->count = $this->store->count();
		
		return $stats;
	}
	
	protected function tagKey($tag) {
		return "__TAG__$tag";
	}


	/**
	 * Cache an item
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $expiry
	 * 			How many seconds to cache this object for (no value uses the configured default)
     * @param array $tags
     *          Any tags to flag this item has
	 */
	public function store($key, $value, $expiry = -1, $tags = null) {
		if ($expiry == -1) {
			$expiry = $this->expiry;
		}
		$entry = new SimpleCacheItem();

		$entry->value = serialize($value);
		$entry->stored = time();
		if ($expiry) {
			$entry->expireAt = time() + $expiry;
		} else {
			$entry->expireAt = 0;
		}

		$data = serialize($entry);
		$this->store->store($key, $data, $expiry);
		
		// if we've got tags, add this element to the list of keys stored for
		// a given tag
		if ($tags) {
			foreach ($tags as $tag) {
				$tagKey = $this->tagKey($tag);
				$tagStore = $this->get($tagKey);
				if (!$tagStore) {
					$tagStore = array();
				}
				// we store the key in the map to prevent duplicated keys
				$tagStore[$key] = true;
				$this->store($tagKey, $tagStore, 0);
			}
		}

		$this->items[$key] = $entry;
	}

	/**
	 * Gets a cached value for a given key
	 * @param String $key
	 * 			The key to retrieve data for
	 */
	public function get($key) {
		$entry = null;

		if (isset($this->items[$key])) {
			$entry = $this->items[$key];
		} else {
			$data = $this->store->get($key);
			if ($data) {
				$entry = unserialize($data);
			}
		}

		if (!$entry) {
			if ($key != self::HIT_KEY && $key != self::MISS_KEY)  {
				++$this->misses;
			}
			
			return $entry;
		}

		// if the expire time is in the future
		if ($entry->expireAt > time() || $entry->expireAt == 0) {
			if ($key != self::HIT_KEY && $key != self::MISS_KEY)  {
				++$this->hits;
			}
			return unserialize($entry->value);
		}

		if ($key != self::MISS_KEY) {
			++$this->misses;
		}
		// if we got to here, we need to expire the value
		$this->expire($key);
		return null;
	}
	
	/**
	 * Get a list of items by tag
	 * 
	 * @param string $tag
	 * @return array
	 */
	public function getByTag($tag) {
		$tagKey = $this->tagKey($tag);
		$keys = $this->get($tagKey);
		
		$elems = array();
		if ($keys) {
			foreach ($keys as $key => $keyval) {
				$elem = $this->get($key);
				if (!$elem) {
					unset($keys[$key]);
				} else {
					$elems[] = $elem;
				}
			}
			
			// re-save all the relevant keys
			$this->store($tagKey, $keys, 0);
		}

		return $elems;
	}
	
	/**
	 * Gets the raw item underneath a given key, so we can see things about expiry etc
	 * @param type $key 
	 */
	public function getCacheEntry($key) {
		$entry = null;
		if (isset($this->items[$key])) {
			$entry = $this->items[$key];
		} else {
			$data = $this->store->get($key);
			if ($data) {
				$entry = unserialize($data);
			}
		}

		return $entry;
	}
	
	/**
	 * Delete from the cache
	 *
	 * @param mixed $key 
	 */
	public function delete($key) {
		unset($this->items[$key]);
		$this->store->delete($key);
	}
	
	/**
	 * Clear out all elements with a particular tag
	 *
	 * @param string $tag
	 */
	public function deleteByTag($tag) {
		$tagKey = $this->tagKey($tag);
		$keys = $this->get($tagKey);
		$num = 0;
		if ($keys) {
			$num = count($keys);
			foreach ($keys as $key => $dummy) {
				$this->delete($key);
			}
		}
		
		$this->delete($tagKey);
        return $num;
	}

	/**
	 * Explicitly expire the given key
	 * 
	 * @param $key
	 */
	public function expire($key) {
		$this->delete($key);
	}

	/**
	 * Flush the whole cache clean
	 */
	public function clear() {
		$this->items = array();
		$this->store->clear();
	}
	
	/**
	 * If underlying store access is needed; avoid using if possible! 
	 * 
	 * @return SimpleCacheStore
	 */
	public function getStore() {
		return $this->store;
	}
}

/**
 * Basic wrapper around items that need to be stored in the cache
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SimpleCacheItem {

	public $value;
	public $expireAt;
	public $stored;

}

if (!function_exists('rrmdir')) {
	function rrmdir($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (filetype($dir . "/" . $object) == "dir")
						rrmdir($dir . "/" . $object); else
						unlink($dir . "/" . $object);
				}
			}
			reset($objects);
			rmdir($dir);
		}
	}
}
