<?php

/**
 * Service style class responsible for publishing urls into a cache
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SimpleCachePublisher {
	
	const CACHE_PUBLISH = '__cache_publish';
	
	public static $store_type = 'SimpleFileBasedCacheStore';
	public static $store_options = array();
	
	protected $staticBaseUrl = null;
	
	protected $echoProgress = false;
	
	public function setStaticBaseUrl($value) {
		$this->staticBaseUrl = $value;
	}
	
	public function publishDataObject(DataObject $object, $specificUrls = null) {
		if (class_exists('AbstractQueuedJob')) {
			// instead of republishing, we'll actually create a queued job
			$job = new SimpleCachePublishingJob($object, $specificUrls);
			singleton('QueuedJobService')->queueJob($job);
		} else {
			
			if (!$specificUrls && $object->hasMethod('pagesAffectedByChanges')) {
				$specificUrls = $object->pagesAffectedByChanges();
			}
			if (count($specificUrls)) {
				$this->publishUrls($specificUrls);
			}
		}
	}
	
	protected function publishUrls($urls, $keyPrefix = '', $domain = null) {
		// Do we need to map these?
		// Detect a numerically indexed arrays
		if (is_numeric(join('', array_keys($urls)))) $urls = $this->urlsToPaths($urls);
		
		// This can be quite memory hungry and time-consuming
		// @todo - Make a more memory efficient publisher
		increase_time_limit_to();
		increase_memory_limit_to();
		
		// Set the appropriate theme for this publication batch.
		// This may have been set explicitly via StaticPublisher::static_publisher_theme,
		// or we can use the last non-null theme.
		if(!StaticPublisher::static_publisher_theme()) {
			SSViewer::set_theme(SSViewer::current_custom_theme());
		} else {
			SSViewer::set_theme(StaticPublisher::static_publisher_theme());
		}

		$currentBaseURL = Director::baseURL();
		if($this->staticBaseUrl) {
			Director::setBaseURL($this->staticBaseUrl);
		}
		
		if($this->echoProgress) {
			$this->out($this->class.": Publishing to " . $this->staticBaseUrl);		
		}

		$files = array();
		$i = 0;
		$totalURLs = sizeof($urls);

		$cache = $this->getCache();

		foreach($urls as $url => $path) {
			// work around bug introduced in ss3 whereby top level /bathroom.html would be changed to ./bathroom.html
			$path = ltrim($path, './');
			if($this->staticBaseUrl) {
				Director::setBaseURL($this->staticBaseUrl);
			}
			
			$i++;

			if($url && !is_string($url)) {
				user_error("Bad url:" . var_export($url,true), E_USER_WARNING);
				continue;
			}

			if(StaticPublisher::echo_progress()) {
				$this->out(" * Publishing page $i/$totalURLs: $url");
				flush();
			}

			Requirements::clear();
			
			if($url == "") {
				$url = "/";
			}
			
			if(Director::is_relative_url($url)) {
				$url = Director::absoluteURL($url);
			}
			$stage = Versioned::current_stage();
			Versioned::reading_stage('Live');
			
			$GLOBALS[self::CACHE_PUBLISH] = 1;
			$response = Director::test(str_replace('+', ' ', $url));
			unset($GLOBALS[self::CACHE_PUBLISH]);
			Versioned::reading_stage($stage);
			
			Requirements::clear();

			singleton('DataObject')->flushCache();

			$contentType = null;
			// Generate file content			
			if(is_object($response)) {
				if($response->getStatusCode() == '301' || $response->getStatusCode() == '302') {
					$absoluteURL = Director::absoluteURL($response->getHeader('Location'));
					$content = "<meta http-equiv=\"refresh\" content=\"2; URL=$absoluteURL\">";
				} else {
					$content = $response->getBody();
					$type = $response->getHeader('Content-type');
					$contentType = $type ? $type : $contentType;
				}
			} else {
				$content = $response . '';
			}
			
			if ($domain && !$keyPrefix) {
				$keyPrefix = $domain;
			}

			$key = $keyPrefix . '/' . trim($path, '/');
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
			$cache->store($key, $data);
		}

		if($this->staticBaseUrl) {
			Director::setBaseURL($currentBaseURL); 
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
	function unpublishUrls($urls, $keyPrefix = '') {
		
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
			$key = $keyPrefix . '/' . ltrim($path, '/');
			if (isset($contentType)) { // <-- TODO not sure what this is up to?
				$data->ContentType = $contentType;
			}
			$cache->expire($key);
		}
	}

	function publishPages($urls) { 
		$config = SiteConfig::current_site_config();

		if ($config->DisableSiteCache) {
			return;
		}

		$curBase = null;
		$curHost = null;

		if (strlen($config->CacheBaseUrl)) {
			$curBase = $this->staticBaseUrl;
			$baseUrl = Director::baseURL();
			if (!strlen($baseUrl)) {
				$baseUrl = '/';
			}

			if (strpos($baseUrl, '://')) {
				$this->staticBaseUrl = Director::protocol() . $config->CacheBaseUrl .'/';
			} else {
				$this->staticBaseUrl = Director::protocol() . $config->CacheBaseUrl . $baseUrl;
			}

			$curHost = $_SERVER['HTTP_HOST'];
			$_SERVER['HTTP_HOST'] = $config->CacheBaseUrl;
		}
		
		// we do the base URL first
		$this->publishUrls($urls);

		if ($curBase) {
			$this->staticBaseUrl = $curBase;
			$_SERVER['HTTP_HOST'] = $curHost;
		}

		// okay we need to publish these pages for multiple urls, so do that 
		// here
		if ($config->ForDomains && is_array($config->ForDomains->getValue())) {
			$allDomains = $config->ForDomains->getValue();
			foreach ($allDomains as $baseDomain) {
				$oldBaseURL = $this->staticBaseUrl;
				$oldHost = $_SERVER['HTTP_HOST'];
				$_SERVER['HTTP_HOST'] = $baseDomain;

				$baseUrl = Director::baseURL();
				if (!strlen($baseUrl)) {
					$baseUrl = '/';
				}

				if (strpos($baseUrl, '://')) {
					$this->staticBaseUrl = Director::protocol() . $baseDomain .'/';
				} else {
					$this->staticBaseUrl = Director::protocol() . $baseDomain . $baseUrl;
				}

				$this->publishUrls($urls, $baseDomain);
				$this->staticBaseUrl = $oldBaseURL;
				$_SERVER['HTTP_HOST'] = $oldHost;
			}
		}
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
		
			$mappedUrls[$url] = ((dirname($filename) == '/') ? '' :  (dirname($filename).'/')).basename($filename);
		}

		return $mappedUrls;
	}
	
	protected function getCache() {
		$cache = SimpleCache::get_cache('publisher');
		return $cache;
	}
	
	
	protected function out($message) {
		if (Director::is_cli()) {
			echo "$message \n";
		} else {
			echo "$message <br/>";
		}
	}
}
