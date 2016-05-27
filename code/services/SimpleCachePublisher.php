<?php

/**
 * Service style class responsible for publishing urls into a cache
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SimpleCachePublisher {
	
	const CACHE_PUBLISH = '__cache_publish';
	
	public $excludeTypes = array(
        'Site',
		'UserDefinedForm',
		'SolrSearchPage',
		'RedirectorPage',
	);
	
	/**
	 * @var SimpleCache
	 */
	public $cache;
	
	protected $staticBaseUrl = null;
	
	protected $echoProgress = false;
	
	/**
	 * Does the user need to "opt in" for caching pages?
	 * 
	 * If so, the page must have the CacheThis property set
	 * 
	 * If this is set to false (ie page cache always in effect) then the
	 * user must have the "DontCache" property set to NOT cache the page
	 * 
	 * @var boolean
	 */
	protected $optInCaching = true;
	
	/**
	 * Use queuedjobs for generating cached data?
	 *
	 * @var boolean
	 */
	public $useJobs = true;
	
	/**
	 * If useJobs = false, we _may_ still opt to use them of the jobThreshold is set
	 * 
	 * @var int
	 */
	public $jobThreshold = 0;
	
	
	public function setStaticBaseUrl($value) {
		$this->staticBaseUrl = $value;
	}
	
	public function setOptInCaching($value) {
		$this->optInCaching = $value;
	}

	public function getOptInCaching() {
		return $this->optInCaching;
	}

	public function publishDataObject(DataObject $object, $specificUrls = null) {
		if ($this->dontCache($object)) {
			return;
		}
		if ($this->useJobs && class_exists('AbstractQueuedJob')) {
			// instead of republishing, we'll actually create a queued job
			$job = new SimpleCachePublishingJob($object, $specificUrls);
			singleton('QueuedJobService')->queueJob($job);
		} else {
            $currentBase = Config::inst()->get('Director', 'alternate_base_url');
            
            if ($object->SiteID && class_exists('Multisites')) {
                // let's set the base directly
                $base = $object->Site()->getUrl();
                if ($base != $currentBase) {
                    if(substr($base, -1) !== '/') {
                        // ensures base URL has a trailing slash 
                        $base .= '/';
                    }
                    Config::inst()->update('Director', 'alternate_base_url', $base);
                }
            }
            
			if (!$specificUrls) {
				$specificUrls = array();
				if ($object->hasMethod('pagesAffectedByChanges')) {
					$pageUrls = $object->pagesAffectedByChanges();
					foreach ($pageUrls as $url) {
						if (Director::is_relative_url($url)) {
							$url = Director::absoluteURL($url);
						}
						$specificUrls[] = $url;
					}
				} else {
					$specificUrls = array($object->AbsoluteLink());
				}
			}

			if (count($specificUrls)) {
				$object->extend('updateAffectedPages', $specificUrls);
				
				if (class_exists('AbstractQueuedJob') && $this->jobThreshold && count($specificUrls) >= $this->jobThreshold) {
					$job = new SimpleCachePublishingJob($object, $specificUrls);
					singleton('QueuedJobService')->queueJob($job);
				} else {
					$this->publishUrls($specificUrls);
				}
			}

			$this->recacheFragments($object);
            Config::inst()->update('Director', 'alternate_base_url', $currentBase);
		}
	}
	
	/**
	 * Indicate whether we "don't" cache the given object
	 * @param type $object
	 * @return type 
	 */
	public function dontCache($object) {
		$nocache = $object->extend('canCache');
		
		if (count($nocache) && min($nocache) == 0) {
			return true;
		}
        
        if ($object->NeverCache) {
            return false;
        }
        
        if ($object->SiteID && class_exists('Multisites')) {
            $site = $object->Site();
            if ($site && $site->ID && $site->DisableSiteCache) {
                return true;
            }
        }
		
		if (method_exists($object, 'canCache') && !$object->canCache()) {
			return true;
		}
		
		$hierarchy = ClassInfo::ancestry($object->ClassName);
		foreach ($this->excludeTypes as $excluded) {
			if (in_array($excluded, $hierarchy)) {
				return true;
			}
		}
		if ($this->optInCaching && !$object->CacheThis) {
			return true;
		} else if (!$this->optInCaching && $object->DontCacheThis) {
			return true;
		}
	}
	
	protected function publishUrls($urls, $keyPrefix = '', $domain = null) {
		if (defined('PROXY_CONFIG_FILE') && !isset(SimpleCache::$cache_configs['PublisherCache'])) {
			include_once BASE_PATH . '/' . PROXY_CONFIG_FILE;
		}
		
		$config = SiteConfig::current_site_config();

		if ($config->DisableSiteCache) {
			return;
		}
		
		$urls = array_unique($urls);

		// Do we need to map these?
		// Detect a numerically indexed arrays
		if (is_numeric(join('', array_keys($urls)))) {
			$urls = $this->urlsToPaths($urls);
		}
		
		// This can be quite memory hungry and time-consuming
		// @todo - Make a more memory efficient publisher
		increase_time_limit_to();
		increase_memory_limit_to();
		
		$currentBaseURL = Director::baseURL();
		
		$files = array();
		$i = 0;
		$totalURLs = sizeof($urls);

		$cache = $this->getCache();
		
		if (!defined('PROXY_CACHE_GENERATING')) {
			define('PROXY_CACHE_GENERATING', true);
		}

		foreach($urls as $url => $path) {
			// work around bug introduced in ss3 whereby top level /bathroom.html would be changed to ./bathroom.html
			$path = ltrim($path, './');
			$url = rtrim($url, '/');
			
			// TODO: Detect the scheme + host URL from the URL's absolute path
			// and set that as the base URL appropriately
			$baseUrlSrc = $this->staticBaseUrl ? $this->staticBaseUrl : $url;
			$urlBits = parse_url($baseUrlSrc);
			
			if (isset($urlBits['scheme']) && isset($urlBits['host'])) {
				// now see if there's a host mapping
				// we want to set the base URL correctly
				Config::inst()->update('Director', 'alternate_base_url', $urlBits['scheme'] . '://' . $urlBits['host'] . '/');
			}

			$i++;

			if($url && !is_string($url)) {
				user_error("Bad url:" . var_export($url,true), E_USER_WARNING);
				continue;
			}

			Requirements::clear();
			
			if (strrpos($url, '/home') == strlen($url) - 5) {
				$url = substr($url, 0, strlen($url) - 5);
			}

			if($url == "" || $url == 'home') {
				$url = "/";
			}

			if(Director::is_relative_url($url)) {
				$url = Director::absoluteURL($url);
			}
			$stage = Versioned::current_stage();
			Versioned::reading_stage('Live');
			$GLOBALS[self::CACHE_PUBLISH] = 1;
			Config::inst()->update('SSViewer', 'theme_enabled', true);
			if (class_exists('Multisites')) {
				Multisites::inst()->resetCurrentSite();
			}
			$response = Director::test(str_replace('+', ' ', $url));
			Config::inst()->update('SSViewer', 'theme_enabled', false);
			unset($GLOBALS[self::CACHE_PUBLISH]);
			Versioned::reading_stage($stage);

			Requirements::clear();

			singleton('DataObject')->flushCache();

			$contentType = null;
			// Generate file content			
			if(is_object($response)) {
				if($response->getStatusCode() == '301' || $response->getStatusCode() == '302') {
					$absoluteURL = Director::absoluteURL($response->getHeader('Location'));
					$content = null;
				} else {
					$content = $response->getBody();
					$type = $response->getHeader('Content-type');
					$contentType = $type ? $type : $contentType;
				}
			} else {
				$content = $response . '';
			}

			if (!$content) {
				continue;
			}
			
			if (isset($urlBits['host'])) {
				$domain = $urlBits['host'];
			}
			
			if ($domain && !$keyPrefix) {
				$keyPrefix = $domain;
			}

			$path = trim($path, '/');
			if ($path == 'home') {
				$path = '';
			}
			$path = (strlen($path) ? $path : 'index');
			
			$data = new stdClass;
			$data->Content = $content;
			$data->LastModified = date('Y-m-d H:i:s');
			$cacheAge = SiteConfig::current_site_config()->CacheAge;
			if ($cacheAge) {
				$data->Age = $cacheAge;
			} else {
				$data->Age = HTTP::get_cache_age();
			}

			if (!empty($contentType)) {
				$data->ContentType = $contentType;
			}
			
			$key = $keyPrefix . '/' . $path;
			$cache->store($key, $data);
			
			if ($domain && isset($PROXY_CACHE_HOSTMAP) && isset($PROXY_CACHE_HOSTMAP[$domain])) {
				$hosts = $PROXY_CACHE_HOSTMAP[$domain];
				foreach ($hosts as $otherDomain) {
					$key = $otherDomain .'/' . $path;
					$storeData = clone $data;
					$storeData->Content = str_replace($domain, $otherDomain, $storeData->Content);
					$cache->store($key, $storeData);
				}
			}
		}

		Director::setBaseURL($currentBaseURL); 
	}
	
	/**
	 * Recache fragments of a data object
	 * 
	 * @param DataObject $object
	 */
	public function recacheFragments($object) {
		$current = Config::inst()->get('SSViewer', 'theme_enabled');
		if (!$current) {
			Config::inst()->update('SSViewer', 'theme_enabled', true);
		}
		
		if (method_exists($object, 'cacheFragments')) {
			$fragments = $object->cacheFragments();
			$regenContext = method_exists($object, 'regenerationContext') ? $object->regenerationContext() : $object;
			$current = Versioned::current_stage();
			Versioned::reading_stage('Live');
			foreach ($fragments as $fragment) {
				$item = Injector::inst()->create('CachedFragment', $regenContext, $fragment);
				$item->regenerate();
			}
			Versioned::reading_stage($current);
		}
		if (!$current) {
			Config::inst()->update('SSViewer', 'theme_enabled', false);
		}
	}

	/**
	 * Unpublish a list of URLs
	 * 
	 * @param array $urls
	 *				The URLs to unpublish
	 * @param string $keyPrefix 
	 *				The 'prefix' of these URLs as stored in cache. In multisite systems this is generally the
	 *				subsite's primary domain, but may be something more complex if publishing the same content for
	 *				multiple domains
	 */
	function unpublishUrls($urls) {
		global $PROXY_CACHE_HOSTMAP;
		// Do we need to map these?
		// Detect a numerically indexed arrays
		if (is_numeric(join('', array_keys($urls)))) {
			$urls = $this->urlsToPaths($urls);
		}

		// This can be quite memory hungry and time-consuming
		// @todo - Make a more memory efficient publisher
		increase_time_limit_to();
		increase_memory_limit_to();

		$cache = $this->getCache();
		foreach($urls as $url => $path) {
			$baseUrlSrc = $this->staticBaseUrl ? $this->staticBaseUrl : $url;
			$urlBits = parse_url($baseUrlSrc);
			if (!isset($urlBits['host'])) {
				$urlBits = parse_url(Director::absoluteBaseURL());
			}

			$domain = isset($urlBits['host']) ? $urlBits['host'] : (isset($_SERVER['HOST_NAME']) ? $_SERVER['HOST_NAME'] : '');
			$key = $domain . '/' . ltrim($path, '/');
			$cache->expire($key);
			
			if ($domain && isset($PROXY_CACHE_HOSTMAP) && isset($PROXY_CACHE_HOSTMAP[$domain])) {
				$hosts = $PROXY_CACHE_HOSTMAP[$domain];
				foreach ($hosts as $otherDomain) {
					$key = $otherDomain . '/' . ltrim($path, '/');
					$cache->expire($key);
				}
			}
		}
	}

	public function publishPages($urls) { 
		// we do the base URL first
		$this->publishUrls($urls);
	}
	
	/**
	 * Transforms relative or absolute URLs to their static path equivalent.
	 * This needs to be the same logic that's used to look up these paths through
	 * framework/static-main.php. Does not include the {@link $destFolder} prefix.
	 * 
	 * URL filtering will have already taken place for direct SiteTree links via SiteTree->generateURLSegment()).
	 * For all other links (e.g. custom controller actions), we assume that they're pre-sanitized
	 * to suit the filesystem needs, as its impossible to sanitize them without risking to break
	 * the underlying naming assumptions in URL routing (e.g. controller method names).
	 * 
	 * Examples (without $domain_based_caching):
	 *  - http://mysite.com/mywebroot/ => /index.html (assuming your webroot is in a subfolder)
	 *  - http://mysite.com/about-us => /about-us.html
	 *  - http://mysite.com/parent/child => /parent/child.html
	 * 
	 * Examples (with $domain_based_caching):
	 *  - http://mysite.com/mywebroot/ => /mysite.com/index.html (assuming your webroot is in a subfolder)
	 *  - http://mysite.com/about-us => /mysite.com/about-us.html
	 *  - http://myothersite.com/about-us => /myothersite.com/about-us.html
	 *  - http://subdomain.mysite.com/parent/child => /subdomain.mysite.com/parent/child.html
	 * 
	 * @param Array $urls Absolute or relative URLs
	 * @return Array Map of original URLs to filesystem paths (relative to {@link $destFolder}).
	 */
	function urlsToPaths($urls) {
		$mappedUrls = array();
		foreach($urls as $url) {

			// parse_url() is not multibyte safe, see https://bugs.php.net/bug.php?id=52923.
			// We assume that the URL hsa been correctly encoded either on storage (for SiteTree->URLSegment),
			// or through URL collection (for controller method names etc.).
			$urlParts = @parse_url($url);
			
			// Remove base folders from the URL if webroot is hosted in a subfolder (same as static-main.php)
			$path = isset($urlParts['path']) ? $urlParts['path'] : '';
			if(mb_substr(mb_strtolower($path), 0, mb_strlen(BASE_URL)) == mb_strtolower(BASE_URL)) {
				$urlSegment = mb_substr($path, mb_strlen(BASE_URL));
			} else {
				$urlSegment = $path;
			}

			// Normalize URLs
			$urlSegment = trim($urlSegment, '/');

			$filename = $urlSegment ? "$urlSegment" : "index";

			// @TODO Re-evaluate this for multisite support
//			if (self::$domain_based_caching) {
//				if (!$urlParts) continue; // seriously malformed url here...
//				$filename = $urlParts['host'] . '/' . $filename;
//			}
		
			$mappedUrls[$url] = (dirname($filename) == '/') ? '' : $filename; //  (dirname($filename).'/')).basename($filename);
		}

		return $mappedUrls;
	}
	
	protected function getCache() {
		if (!$this->cache) {
			$this->cache = Injector::inst()->get('PublisherCache');
		}
		return $this->cache;
	}

	protected function out($message) {
		if (Director::is_cli()) {
			echo "$message \n";
		} else {
			echo "$message <br/>";
		}
	}
}
