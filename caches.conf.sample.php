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
    'PublisherCache' => array(
        'store_type' => 'SimpleFileBasedCacheStore',
        'store_options' => array(
            'publisher_cache',
        ),
        'cache_options' => array(
            'expiry' => 8640000 
        )
    ),
    'DynamicPublisherCache' => array(
        'store_type' => 'SimpleFileBasedCacheStore',
        'store_options' => array(
            'silverstripe-cache/dynamic_publisher',
        ),
        'cache_options' => array(
            'expiry' => 3600    
        )
    ),
    'FragmentCache' => array(
        'store_type' => 'SimpleFileBasedCacheStore',
        'store_options' => array(
            'silverstripe-cache/fragment_cache',
        ),
        'cache_options' => array(
            'expiry' => 864000  
        )
    ),
);
