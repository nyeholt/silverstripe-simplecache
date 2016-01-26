<?php

if (defined('USE_PROXY') && defined('PROXY_CONFIG_FILE')) {
	if (!isset(SimpleCache::$cache_configs['PublisherCache'])) {
		include_once BASE_PATH . '/' . PROXY_CONFIG_FILE;
	}
	foreach (array('PublisherCache', 'DynamicPublisherCache', 'FragmentCache') as $cacheName) {
		$cache = SimpleCache::get_cache($cacheName);
		if ($cache) {
			Injector::inst()->registerService($cache, $cacheName);
		}
	}
}