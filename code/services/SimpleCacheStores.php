<?php

/**
 * A cache store that uses the filesystem for storing cached content
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SimpleFileBasedCacheStore implements SimpleCacheStore {
	public static $cache_location = null;
	protected $location = '';
	
	public function __construct($location = null) {
		$this->location = $location ? $location : self::$cache_location;
	}

	public function store($key, $data) {
		$location = $this->getDiskLocation($key);
		file_put_contents($location, $data);
		@chmod($location, 0660);
	}

	public function get($key) {
		$data = null;
		$location = $this->getDiskLocation($key, false);
		if (file_exists($location)) {
			$data = file_get_contents($location);
		}
		return $data;
	}

	public function delete($key) {
		$location = $this->getDiskLocation($key);
		if (file_exists($location)) {
			unlink($location);
		}
	}

	public function clear() {
		$location = $this->getCacheLocation();
		rrmdir($location);
	}
	
	private function getCacheLocation() {
		if (!$this->location) {
			$cacheLocation = TEMP_FOLDER . '/cache_store';
		} else {
			if ($this->location{0} == '/') {
				$cacheLocation = $this->location;
			} else {
				$cacheLocation = BASE_PATH . DIRECTORY_SEPARATOR . $this->location;
			}
		}
		if (!is_dir($cacheLocation)) {
			mkdir($cacheLocation, 0770, true);
		}
		return $cacheLocation;
	}

	private function getDiskLocation($key, $create = true) {
		$name = md5($key);
		$dir = $this->getCacheLocation() . '/' . mb_substr($name, 0, 3);
		if (!is_dir($dir) && $create) {
			mkdir($dir, 0770, true);
		}
		return $dir . '/' . $name;
	}
	
	public function count() {
		$glob = glob($this->getCacheLocation().'/**');
		$count = 0;
		foreach ($glob as $file) {
			if (is_dir($file)) {
				$children = glob($file.'/**');
				$count += count($children);
			} else {
				$count ++;
			}
		}
		return $count;
	}
}


/**
 * A cache store that uses the filesystem for storing cached content
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SimpleApcBasedCacheStore implements SimpleCacheStore {
	protected $name = '';
	
	public function __construct($name = '') {
		$this->name = $name;
	}

	public function store($key, $data) {
		apc_store($this->name . $key, $data);
	}

	public function get($key) {
		return apc_fetch($this->name . $key);
	}

	public function delete($key) {
		apc_delete($this->name . $key);
	}

	public function clear() {
		apc_clear_cache('user');
	}

	public function count() {
		$info = apc_cache_info('user');
		return $info['num_entries'];
	}
}


/**
 * A cache store that uses the php-memcacheD module for storing cached content
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SimpleMemcacheBasedCacheStore implements SimpleCacheStore {
	protected $config = array(
		'servers'		=> array(
			array (
				'host'			=> 'localhost',
				'port'			=> '11211',
			)
		),
	);
	
	protected $cache;
	
	protected $name = '';
	
	public function __construct($name = 'default', $config = null) {
		$this->cache = new Memcache();
		if ($config) {
			$this->config = $config;
		}
		foreach ($this->config['servers'] as $server) {
			$this->cache->addServer($server['host'], $server['port']);
		}
		$this->name = $name;
	}

	public function store($key, $data) {
		$this->cache->set($this->name.'-'.$key, $data);
	}

	public function get($key) {
		return $this->cache->get($this->name.'-'.$key);
	}

	public function delete($key) {
		$this->cache->delete($this->name.'-'.$key);
	}

	public function clear() {
		$this->cache->flush();
	}
	
	public function count() {
		$stats = $this->cache->getStats();
		foreach ($stats as $server => $cache) {
			return $cache['curr_items'];
		}
	}
	
	public function getUnderlyingCache() {
		return $this->cache;
	}
}

/**
 * A cache store that uses the php-memcacheD module for storing cached content
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SimpleMemcachedBasedCacheStore implements SimpleCacheStore {
	protected $config = array(
		'servers'		=> array(
			array (
				'host'			=> 'localhost',
				'port'			=> '11211',
			)
		),
		
	);
	
	protected $cache;
	
	protected $name = '';
	
	public function __construct($name = 'default', $config = null) {
		$this->cache = new Memcached();
		if ($config) {
			$this->config = $config;
		}
		foreach ($this->config['servers'] as $server) {
			$this->cache->addServer($server['host'], $server['port']);
		}
		$this->name = $name;
	}

	public function store($key, $data) {
		$this->cache->set($this->name.'-'.$key, $data);
	}

	public function get($key) {
		return $this->cache->get($this->name.'-'.$key);
	}

	public function delete($key) {
		$this->cache->delete($this->name.'-'.$key);
	}

	public function clear() {
		$this->cache->flush();
	}
	
	public function count() {
		$stats = $this->cache->getStats();
		foreach ($stats as $server => $cache) {
			return $cache['curr_items'];
		}
	}
	
	public function getUnderlyingCache() {
		return $this->cache;
	}
}


/**
 * A cache store definition
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
interface SimpleCacheStore {

	/**
	 * Saves data with the given key at the particular value
	 * 
	 * @param String $key
	 * 			The key for the data to be stored
	 * @param String $value
	 * 			The data being stored
	 */
	public function store($key, $value);

	/**
	 * Retrieve content from the cache. 
	 * 
	 * Returns null in case of missing data
	 * 
	 * @param String $key
	 * 			The key for the data
	 * 
	 * @return SimpleCacheItem
	 */
	public function get($key);

	/**
	 * Delete a given key from the cache
	 * 
	 * @param String $key
	 */
	public function delete($key);
	
	/**
	 * Clear the whole cache
	 */
	public function clear();
	
	/**
	 * Return the number of items in the cache
	 */
	public function count();
}
