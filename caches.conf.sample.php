<?php

SimpleCache::$cache_configs = array(
	'default' => array(
		'store_type' => 'SimpleFileBasedCacheStore',
		'store_options' => array(
			'silverstripe-cache/cache_store',
		),
		'cache_options' => array(
			'expiry' => 3600
		)
	),
	'publisher' => array(
		'store_type' => 'SimpleFileBasedCacheStore',
		'store_options' => array(
			'silverstripe-cache/publisher',
		),
		'cache_options' => array(
			'expiry' => 8640000			// a long time
		)
	)
);

/* 
 * A list of URLs (as regexes) that if matched, will have their content cached
 * for the given number of seconds
 */
$cache_urls = array(
        'custom-controller/view/\d+'      => 900,
);

/**
 * a list of hosts we're restricting access to 
 */
$allowed_hosts = array(
	'define.hosts.that.can.be.accessed.com',
);

include_once dirname(__FILE__).'/cache-main-auth.php';
