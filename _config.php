<?php

if (defined('PROXY_CONFIG_FILE')) {
	if (!isset(SimpleCache::$cache_configs['PublisherCache'])) {
		include_once BASE_PATH . '/' . PROXY_CONFIG_FILE;
	}

    SimpleCache::register_caches();
}