<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class MemcachedStatsTask extends BuildTask {
	
	public function run($request) {
		$config = SimpleCache::$cache_configs;
		foreach ($config as $name => $conf) {
			if ($conf['store_type'] == 'SimpleMemcacheBasedCacheStore') {
				$cache = singleton('SimpleCache')->get_cache($name);
				$store = $cache->getStore()->getUnderlyingCache();
				
				$stats = $store->getStats();
				
				
				
				echo $this->printOut($stats);
			}
		}
	}
	
	function printOut($arr) {

		if (!is_array($arr)) {
			return $arr;
		}

		$lines = array();

		foreach ($arr as $key => $val) {
			$lines[] = "<tr><td>$key </td><td>" . $this->printOut($val) . "</td></tr>";
		}

		return '<table>' . implode("\n", $lines) . '</table>';

	}
}
