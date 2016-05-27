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
    
    /**
     * A list of hostnames that should retrieve cached data from a _different_ host
     * 
     * @var array
     */
    protected $remapHosts;
	
    /**
     * A Class name for a class to use for rewriting content for cache responses
     *
     * @var string
     */
    protected $contentRewriter;
    
	/**
	 * Regex matches for whether certain hostnames are enabled or not for caching
	 *
	 * @var boolean
	 */
	protected $blacklist = array();
    
    /**
     * A list of headers to _not_ proxy in dynamic cached requests
     *
     * @var array
     */
    protected $ignoreHeaders = array();
	
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
	}
	
	public function checkIfEnabled($host, $url) {
		$fullUrl = "$host/$url";
		
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
		
		if (count($this->blacklist)) {
			foreach ($this->blacklist as $check) {
				if (preg_match ('{' . $check . '}', $fullUrl)) {
					$this->enabled = false;
					return false;
				}
			}
		}

		if (count($_POST)) {
			$this->enabled = false;
			return;
		}
	}

	public function urlIsCached($host, $url, $checkingRemap = false) {
		if (!$this->enabled) {
			return false;
		}
		
		$url = $this->urlForCaching($url);
		
		$key = "$host/$url";

		if ($this->staticCache) {
			$this->currentItem = $this->staticCache->get($key);
			if ($this->currentItem && strlen($this->currentItem->Content)) {
				$this->headers[] = 'X-SilverStripe-Cache: hit at '.@date('r');
			} 
		}

		if (!$this->currentItem && $this->dynamicCache && $this->canCache($host, $url)) {
			$this->currentItem = $this->dynamicCache->get($key);
			if ($this->currentItem && strlen($this->currentItem->Content)) {
				$this->headers[] = 'X-SilverStripe-Cache: gen-hit at '.@date('r');
			}
		}
        
        if ($this->currentItem && !strlen($this->currentItem->Content)) {
            $this->currentItem = null;
        }

        if (!$this->currentItem && !$checkingRemap) {
            $remapped = $this->remappedHost($host);
            if ($remapped) {
                return $this->urlIsCached($remapped, $url, true);
            }
        }
		
		return is_object($this->currentItem) && strlen($this->currentItem->Content);
	}
	
	public function canCache($host, $url) {
		if (!$this->enabled) {
			return false;
		}

		if ($this->urlRules) {
			return $this->expiryForUrl($url, $host) > -1;
		}
	}
	
	public function expiryForUrl($url, $host = null) {
		$url = $this->urlForCaching($url);
		$config = $this->configForUrl($url, $host);
		return isset($config['expiry']) ? $config['expiry'] : -1;
	}

	public function configForUrl($url, $host = null) {
		$config = array('expiry' => -1);
		foreach ($this->urlRules as $segment => $options) {
			$comparison = $url;
			if (is_array($options) && isset($options['include_host']) && $options['include_host']) {
				$comparison = $host . '/' . $url;
			}
			if (preg_match('{' . $segment . '}', $comparison, $matches)) {
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
                            // backwards compatibility
							if (is_numeric($tag)) {
								$tag = '$' . $tag;
							}

                            $numero = strpos($tag, '$');
                            if ($numero !== false) {
                                $item = $tag{$numero + 1};
                                if (isset($matches[(int) $item])) {
									$config['tags'][$index] = str_replace('$' . $item, $matches[$item], $tag);
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

        $url = strlen($url) ? $url : 'index';

		return $url;
	}

	public function generateCache($host, $url) {
		$url = $this->urlForCaching($url);
		$key = "$host/$url";
		define('PROXY_CACHE_GENERATING', true);
		
		$config = $this->configForUrl($url, $host);
		$expiry = isset($config['expiry']) ? $config['expiry'] : -1;

		if ($expiry < 0) {
			return;
		}

		ob_start();
		include BASE_PATH .'/framework/main.php';
        
		$toCache = new stdClass();
		$toCache->Content = ob_get_clean();
		$toCache->LastModified = date('Y-m-d H:i:s');
		
		// check headers to see if we have an expires header
		$headers = headers_list();
		if ($headerExpiry = $this->ageFromHeaders($headers, $expiry)) {
			if ($headerExpiry < $expiry) {
				$expiry = $headerExpiry;
			}
		}
        
        $storedHeaders = array();
        // store the headers as k => v
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) == 2) {
                $storedHeaders[strtolower($parts[0])] = $parts[1];
            }
        }
        if (count($storedHeaders)) {
            $toCache->Headers = $storedHeaders;
        }
        
		$toCache->Age = $expiry;
        
        $cacheableResponse = true;
        
        if (function_exists('http_response_code')) {
            $response = http_response_code();
            $this->enabled = $response >= 200 && $response < 300;
        }

		// store the content for this 
		if ($this->enabled && $this->dynamicCache && strlen($toCache->Content)) {
			$tags = isset($config['tags']) ? $config['tags'] : null;
			$this->dynamicCache->store($key, $toCache, $expiry, $tags);
			$this->headers[] = 'X-SilverStripe-Cache: miss-gen at '.@date('r') . ' on ' . $key;
		}

		$this->currentItem = $toCache;
	}

	/**
	 * Try and find the max-age from the list of headers
	 * 
	 * @param array $headerList
	 */
	protected function ageFromHeaders($headerList, $minAge = 0) {
		foreach ($headerList as $header) {
			if (preg_match('/max-age=(\d+)/i', $header, $matches)) {
				$age = $matches[1];
				if ($age && $age < $minAge) {
					$minAge = $age;
				}
			}
		}
		
		return $minAge;
	}
	
    /**
     * Serve the content item wrapped by the proxy. 
     * 
     * Note that _IF_ we're here and 'enabled' is false, it is likely the
     * result of the gen-cache process failing to get a valid content type, in which
     * case we don't output extra headers. 
     * 
     * @param string $host
     * @param string $url
     * @return boolean
     */
	public function serve($host, $url) {
		if (!$this->currentItem) {
			return false;
		}
		
        if ($this->enabled) {
            $responseHeaders = $this->currentItem->Headers;
            $outIfNot = function ($name, $value) use ($responseHeaders) {
                if (!isset($responseHeaders[strtolower($name)])) {
                    header($name, $value);
                }
            };
            
            $outIfNot('Cache-Control', 'no-cache, max-age=0, must-revalidate');
            $outIfNot('Edge-Control', "!no-store, max-age=" . $this->currentItem->Age);
            $outIfNot('Expires', gmdate('D, d M Y H:i:s', time() + $this->currentItem->Age) . ' GMT');
            $outIfNot('Last-modified', gmdate('D, d M Y H:i:s', strtotime($this->currentItem->LastModified)) . ' GMT');
            $outIfNot('Content-type', 'text/html');
            
            foreach ($responseHeaders as $headerName => $headerValue) {
                header($headerName . ': ' . $headerValue);
            }
            foreach ($this->headers as $h) {
                header($h);
            }
        }
		
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

		// check for any cached values
		$protocol = $this->isHttps() ? 'https' : 'http';
        $content = '';
        if ($this->contentRewriter) {
            $updater = new $this->contentRewriter;
            $remapped = $this->remappedHost($host);
            $content = $updater->rewrite($this->currentItem->Content, $protocol, $_SERVER['HTTP_HOST'], $remapped);
        } else {
            $content = preg_replace('|<base href="(https?)://(.*?)/"|', '<base href="' . $protocol . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/"', $this->currentItem->Content);
        }

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

	public function setCacheGetVars($v) {
		$this->cacheGetVars = $v;
		return $this;
	}
	
	public function setIgnoreGetVars($v) {
		$this->ignoreGetVars = $v;
		return $this;
	}
	
	public function setBlacklist($v) {
		$this->blacklist = $v;
		return $this;
	}
	
    public function setRemapHosts($v) {
        $this->remapHosts = $v;
        return $this;
    }
    
    public function setContentRewriter($v) {
        $this->contentRewriter = $v;
        return $this;
    }
    
    /**
     * If found, return the host name that should be used for caching a particular piece of content
     * 
     * @param string $host
     * @return string
     */
    protected function remappedHost($host) {
        if (isset($this->remapHosts[$host])) {
            return $this->remapHosts[$host];
        }
    }

	protected function isHttps() {
		$return = false;
		if (defined('PROXY_CACHE_PROTOCOL')) {
			$return = (PROXY_CACHE_PROTOCOL == 'https');
		} else if(
			TRUSTED_PROXY
			&& isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
			&& strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) == 'https'
		) {
			// Convention for (non-standard) proxy signaling a HTTPS forward,
			// see https://en.wikipedia.org/wiki/List_of_HTTP_header_fields
			$return = true;
		} else if(
			TRUSTED_PROXY
			&& isset($_SERVER['HTTP_X_FORWARDED_PROTOCOL'])
			&& strtolower($_SERVER['HTTP_X_FORWARDED_PROTOCOL']) == 'https'
		) {
			// Less conventional proxy header
			$return = true;
		} else if(
			isset($_SERVER['HTTP_FRONT_END_HTTPS'])
			&& strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) == 'on'
		) {
			// Microsoft proxy convention: https://support.microsoft.com/?kbID=307347
			$return = true;
		} else if((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')) {
			$return = true;
		} else if(isset($_SERVER['SSL'])) {
			$return = true;
		} else {
			$return = false;
		}

		return $return;
	}
}
