<?php

/**
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class FrontendProxy {
	protected $server;
	protected $env;

	protected $bypassCookies = array();
	
	/**
	 * @var SimpleCache
	 */
	protected $staticCache;
	
	/**
	 * @var SimpleCache
	 */
	protected $dynamicCache;
	
	/**
	 * Do we cache URLs with GET params?
	 *
	 * @var boolean
	 */
	protected $cacheGetVars = false;
	
	/**
	 * From a cache perspective, should GET parameters be ignored?
	 * @var type 
	 */
	protected $ignoreGetVars = false;
	
	/**
	 * 
	 * A list of url => expiry mapping that indicate how long particular
	 * url sets can be cached on-request. Specify -1 to NEVER cache 
	 * a particular URL, entered in the list prior to the larger rule
	 * that would match it
	 *
	 * @var array
	 */
	protected $urlRules;
	
	/**
	 * The most recent item retrieved or added to the cache
	 * @var SimpleCacheItem
	 */
	protected $currentItem;
	
	/**
	 * A List of headers to output when dumping the currentItem
	 *
	 * @var array
	 */
	protected $headers = array();
	
	/**
	 * Are we enabled? Typically disabled by the cookie check
	 *
	 * @var boolean
	 */
	protected $enabled = true;
	
	public function __construct(
		$staticCache = null, $dynamicCache = null, 
		$urlRules = null, $bypassCookies = array(),
		$serverVars = null, $envVars = null
	) {

		$this->server = $serverVars ? $serverVars : $_SERVER;
		$this->env = $envVars ? $envVars : $_ENV;
		$this->staticCache = $staticCache;
		$this->dynamicCache = $dynamicCache;
		$this->urlRules = $urlRules;
		$this->bypassCookies = $bypassCookies;
		
		$this->cacheGetVars = defined('CACHE_ALLOW_GET_VARS') && CACHE_ALLOW_GET_VARS;
		$this->ignoreGetVars = defined('CACHE_IGNORE_GET_VARS') && CACHE_IGNORE_GET_VARS;
	}
	
	public function checkIfEnabled($host, $url) {
		$fullUrl = "$host/$url/";
		
		foreach ($this->bypassCookies as $cookie) {
			if (isset($_COOKIE[$cookie])) {
				$this->enabled = false;
				return;
			}
		}
		
		// these are set regardless of any additional rules
		if (strpos($fullUrl, '/admin/') === 0 || strpos($fullUrl, '/Security/') === 0 || strpos($fullUrl, '/dev/') === 0) {
			$this->enabled = false;
			return;
		}

		
		if (!($this->cacheGetVars || $this->ignoreGetVars) && count(array_diff(array_keys($_GET), array('url'))) != 0) {
			$this->enabled = false;
			return;
		}
		
		
		
		if (count($_POST)) {
			$this->enabled = false;
			return;
		}
	}

	public function urlIsCached($host, $url) {
		if (!$this->enabled) {
			return false;
		}
		
		$url = strlen($url) ? $url : 'index';
		$url = $this->urlForCaching($url);

		$key = "$host/$url";

		if ($this->staticCache) {
			$this->currentItem = $this->staticCache->get($key);
			if ($this->currentItem) {
				$this->headers[] = 'X-SilverStripe-Cache: hit at '.@date('r');
			}
		}

		if (!$this->currentItem && $this->dynamicCache && $this->canCache($host, $url)) {
			$this->currentItem = $this->dynamicCache->get($key);
			if ($this->currentItem) {
				$this->headers[] = 'X-SilverStripe-Cache: gen-hit at '.@date('r');
			}
		}
		
		return !is_null($this->currentItem);
	}
	
	public function canCache($host, $url) {
		if (!$this->enabled) {
			return false;
		}

		if ($this->urlRules) {
			return $this->expiryForUrl($url) > -1;
		}
	}
	
	public function expiryForUrl($url) {
		$url = $this->urlForCaching($url);
		$config = $this->configForUrl($url);
		return isset($config['expiry']) ? $config['expiry'] : -1;
	}

	public function configForUrl($url) {
		$config = array('expiry' => -1);
		foreach ($this->urlRules as $segment => $options) {
			if (preg_match('{' . $segment . '}', $url, $matches)) {
				// if we've got an array, it means we've got some config options
				if (is_array($options)) {
					$config = array_merge($config, $options);
					$default = isset($options['default']) ? $options['default'] : 0;

					// check for a defined period in which we're after an expiry
					if (isset($options['periods'])) {
						foreach ($options['periods'] as $period) {
							if (isset($option['from']) && isset($option['to'])) {
								// how many seconds into the day are we
								$seconds = time() - strtotime('midnight');
								if ($seconds >= $option['from'] && $seconds <= $option['to']) {
									$config['expiry'] = $option['expiry'];
									break;
								}
							}
						}
					}

					if ($config['expiry'] == -1) {
						$config['expiry'] = $default;
					}
					
					// if there's some capture groups in the regex, see if any of our tags are meant to match
					if (count($matches) > 1 && isset($config['tags'])) {
						foreach ($config['tags'] as $index => $tag) {
							if (is_numeric($tag)) {
								if (isset($matches[(int) $tag])) {
									$config['tags'][$index] = $matches[$tag];
								} else {
									unset($config['tags'][$index]);
								}
							}
						}
					}
				} else {
					$config['expiry'] = $options;
				}
				
				return $config;
			}
		}
		return $config;
	}
	
	/**
	 * Return a URL slightly dressed up to use for cache interrogation
	 * 
	 * @param type $url
	 * @return string
	 */
	public function urlForCaching($url) {
		if ($this->cacheGetVars && strpos($url, '?') === false) {
			$params = $_GET;
			
			unset($params['url']);
			$qs = http_build_query($params);
			if (strlen($qs)) {
				$url .= '?' . $qs;
			}
		}

		// check for ajax and append something to the URL to indicate that
		if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
			$url .= '/ajax';
		}

		return $url;
	}

	public function generateCache($host, $url) {
		$url = $this->urlForCaching($url);
		$key = "$host/$url";
		define('PROXY_CACHE_GENERATING', true);
		
		$config = $this->configForUrl($url);
		$expiry = isset($config['expiry']) ? $config['expiry'] : -1;

		if ($expiry < 0) {
			return;
		}

		ob_start();
		include BASE_PATH .'/framework/main.php';
		$toCache = new stdClass();
		$toCache->Content = ob_get_clean();
		$toCache->LastModified = date('Y-m-d H:i:s');
		$toCache->Age = $expiry;

		// store the content for this 
		if ($this->dynamicCache && strlen($toCache->Content)) {
			$tags = isset($config['tags']) ? $config['tags'] : null;
			$this->dynamicCache->store($key, $toCache, $expiry, $tags);
			$this->headers[] = 'X-SilverStripe-Cache: miss-gen at '.@date('r') . ' on ' . $key;
		}

		$this->currentItem = $toCache;
	}
	
	public function serve($host, $url) {
		if (!$this->currentItem) {
			return false;
		}
		
		header("Cache-Control: no-cache, max-age=0, must-revalidate");

		header("Expires: " . gmdate('D, d M Y H:i:s', time() + $this->currentItem->Age) . ' GMT');
		header("Last-modified: " . gmdate('D, d M Y H:i:s', strtotime($this->currentItem->LastModified)) . ' GMT');
		
		// if there's an if-modified-since header 
		if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
			// force non-304 if the modified since is < 10 mins. Otherwise we let the page get
			// reserved, even if it's from cache
			// TODO - do we even really need this? 
//			if(strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= time() - 600) {
//				header("Last-modified: " . gmdate('D, d M Y H:i:s', time() - 600) . ' GMT', true, 304);
//				exit;
//			}
		}
		
		if (isset($this->currentItem->ContentType) && strlen($this->currentItem->ContentType)) {
			header('Content-type: ' . $this->currentItem->ContentType);
		}

		foreach ($this->headers as $h) {
			header($h);
		}
	
		// check for any cached values
		$content = preg_replace('|<base href="(https?)://(.*?)/"|', '<base href="$1://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/"', $this->currentItem->Content);
		echo preg_replace_callback('/<!--SimpleCache::(.*?)-->/', array($this, 'getCachedValue'), $content);
	}
	
	public function getCachedValue($matches) {
		$params = @explode(',', $matches[1]);
		if ($params && count($params)) {
			$key = array_shift($params);
			$cache = call_user_func_array(array('SimpleCache', 'get_cache'), $params);
			return $cache->get($key);
		}
	}
}
