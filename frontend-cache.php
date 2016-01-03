<?php

include_once __DIR__ .'/code/services/SimpleCache.php';
include_once __DIR__.'/code/controllers/FrontendProxy.php';
$framework = dirname(__DIR__) . '/framework';
require_once($framework . '/core/Constants.php');
require_once 'control/injector/Injector.php';


// IIS will sometimes generate this.
if(!empty($_SERVER['HTTP_X_ORIGINAL_URL'])) {
	$_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_ORIGINAL_URL'];
}

/**
 * Figure out the request URL
 */
global $url;

// PHP 5.4's built-in webserver uses this
if (php_sapi_name() == 'cli-server') {
	$url = $_SERVER['REQUEST_URI'];

	// Querystring args need to be explicitly parsed
	if(strpos($url,'?') !== false) {
		list($url, $query) = explode('?',$url,2);
		parse_str($query, $_GET);
		if ($_GET) $_REQUEST = array_merge((array)$_REQUEST, (array)$_GET);
	}

	// Pass back to the webserver for files that exist
	if(file_exists(BASE_PATH . $url) && is_file(BASE_PATH . $url)) return false;

	// Apache rewrite rules use this
} else if (isset($_GET['url'])) {
	$url = $_GET['url'];
	// IIS includes get variables in url
	$i = strpos($url, '?');
	if($i !== false) {
		$url = substr($url, 0, $i);
	}

	// Lighttpd uses this
} else {
	if(strpos($_SERVER['REQUEST_URI'],'?') !== false) {
		list($url, $query) = explode('?', $_SERVER['REQUEST_URI'], 2);
		parse_str($query, $_GET);
		if ($_GET) $_REQUEST = array_merge((array)$_REQUEST, (array)$_GET);
	} else {
		$url = $_SERVER["REQUEST_URI"];
	}
}

// Remove base folders from the URL if webroot is hosted in a subfolder
if (substr(strtolower($url), 0, strlen(BASE_URL)) == strtolower(BASE_URL)) $url = substr($url, strlen(BASE_URL));


if (defined('USE_PROXY') && USE_PROXY) {
	if (defined('PROXY_CONFIG_FILE')) {
		include_once BASE_PATH . '/' . PROXY_CONFIG_FILE;
	}
	
	$publisher = null;
	if (defined('PROXY_PUBLISHER')) {
		$publisher = SimpleCache::get_cache(PROXY_PUBLISHER);
	}
	
	$dynamic = null;
	if (defined('PROXY_DYNAMIC_PUBLISHER')) {
		$dynamic = SimpleCache::get_cache(PROXY_DYNAMIC_PUBLISHER);
	}
	
	$cookies = defined('PROXY_BYPASS_COOKIES') ? explode(',', PROXY_BYPASS_COOKIES) : array();
	$url_config = isset($PROXY_CACHE_URLS) ? $PROXY_CACHE_URLS : null;
	
	$proxyclass = defined('PROXY_CLASS') ? PROXY_CLASS : 'FrontendProxy';

	$proxy = new $proxyclass($publisher, $dynamic, $url_config, $cookies);
	
	$host = $_SERVER['HTTP_HOST'];
	$relativeUrl = trim($url, '/');
	
	$proxy->checkIfEnabled($host, $relativeUrl);
	
	if ($proxy->urlIsCached($host, $relativeUrl)) {
		$proxy->serve($host, $relativeUrl);
	} else if ($proxy->canCache($host, $relativeUrl)) {
		$proxy->generateCache($host, $relativeUrl);
		$proxy->serve($host, $relativeUrl);
	} else {
		include $framework . '/main.php';
	}
	// otherwise we're going to fall back to main processing
} else {
	include $framework . '/main.php';
}