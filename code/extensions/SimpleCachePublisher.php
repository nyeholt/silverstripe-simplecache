<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SimpleCachePublisher extends FilesystemPublisher {
	
	const CACHE_PUBLISH = '__cache_publish';
	
	public static $store_type = 'SimpleFileBasedCacheStore';
	public static $store_options = array();
	
	public static $exclude_types = array(
		'UserDefinedForm',
	);

	public function __construct() {
		parent::__construct('cache', 'html');
	}
	
	public function onAfterPublish($original) {
		$this->republish($original);
	}

	public function republish($original) {
		if (in_array($this->owner->ClassName, self::$exclude_types)) {
			return;
		}
		if (SiteConfig::current_site_config()->DisableSiteCache) {
			return;
		}
		// instead of republishing, we'll actually create a queued job
		$job = new SimpleCachePublishingJob($this->owner);
		singleton('QueuedJobService')->queueJob($job);
	}

	public function onRenameLinkedAsset($original) {
		if (SiteConfig::current_site_config()->DisableSiteCache) {
			return;
		}
		$job = new SimpleCachePublishingJob($this->owner);
		singleton('QueuedJobService')->queueJob($job);
	}

	protected function getCache() {
		$cache = SimpleCache::get_cache('publisher');
		return $cache;
	}

	protected function publishUrls($urls, $keyPrefix = '') {
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
		if(self::$static_base_url) Director::setBaseURL(self::$static_base_url);
		if($this->fileExtension == 'php') SSViewer::setOption('rewriteHashlinks', 'php'); 
		if(StaticPublisher::echo_progress()) $this->out($this->class.": Publishing to " . self::$static_base_url);		
		$files = array();
		$i = 0;
		$totalURLs = sizeof($urls);

		$cache = $this->getCache();

		foreach($urls as $url => $path) {
			if(self::$static_base_url) Director::setBaseURL(self::$static_base_url);
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
			
			if($url == "") $url = "/";
			if(Director::is_relative_url($url)) $url = Director::absoluteURL($url);
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
			
			if ($this->owner->SubsiteID) {
				$keyPrefix = $this->owner->Subsite()->getPrimaryDomain();
			}

			$key = $keyPrefix . '/' . ltrim($path, '/');
			$data = new stdClass;
			$data->Content = $content;
			$data->LastModified = date('Y-m-d H:i:s');
			$cacheAge = SiteConfig::current_site_config()->CacheAge;
			if ($cacheAge) {
				$data->Age = $cacheAge;
			} else {
				$data->Age = HTTP::get_cache_age();
			}

			if ($contentType) {
				$data->ContentType = $contentType;
			}
			$cache->store($key, $data);
		}

		if(self::$static_base_url) Director::setBaseURL($currentBaseURL); 
		if($this->fileExtension == 'php') SSViewer::setOption('rewriteHashlinks', true); 
	}

	function publishPages($urls) { 
		$config = SiteConfig::current_site_config();

		if ($config->DisableSiteCache) {
			return;
		}
		
		$curBase = null;
		$curHost = null;

		if (strlen($config->CacheBaseUrl)) {
			$curBase = self::$static_base_url;
			$baseUrl = Director::baseURL();
			if (!strlen($baseUrl)) {
				$baseUrl = '/';
			}
			if (strpos($baseUrl, '://')) {
				self::$static_base_url = Director::protocol() . $config->CacheBaseUrl .'/';
			} else {
				self::$static_base_url = Director::protocol() . $config->CacheBaseUrl . $baseUrl;
			}

			$curHost = $_SERVER['HTTP_HOST'];
			$_SERVER['HTTP_HOST'] = $config->CacheBaseUrl;
		}
		
		// we do the base URL first
		$this->publishUrls($urls);

		if ($curBase) {
			self::$static_base_url = $curBase;
			$_SERVER['HTTP_HOST'] = $curHost;
		}

		// okay we need to publish these pages for multiple urls, so do that 
		// here
		if ($config->ForDomains) {
			$allDomains = $config->ForDomains->getValue();
			if (is_array($allDomains)) {
				foreach ($allDomains as $baseDomain) {
					$oldBaseURL = self::$static_base_url;
					$oldHost = $_SERVER['HTTP_HOST'];
					$_SERVER['HTTP_HOST'] = $baseDomain;

					$baseUrl = Director::baseURL();
					if (!strlen($baseUrl)) {
						$baseUrl = '/';
					}

					if (strpos($baseUrl, '://')) {
						self::$static_base_url = Director::protocol() . $baseDomain .'/';
					} else {
						self::$static_base_url = Director::protocol() . $baseDomain . $baseUrl;
					}

					$this->publishUrls($urls, $baseDomain);
					self::$static_base_url = $oldBaseURL;
					$_SERVER['HTTP_HOST'] = $oldHost;
				}
			}
		}
	}

	/**
	 * On after unpublish, get changes and hook into underlying
	 * functionality
	 */
	function onAfterUnpublish($page) {
		if (self::$disable_realtime) return;
		
		// Get the affected URLs
		if($this->owner->hasMethod('pagesAffectedByUnpublishing')) {
			$urls = $this->owner->pagesAffectedByUnpublishing();
			$urls = array_unique($urls);
		} else {
			$urls = array($this->owner->Link());
		}

		// immediately unpublish
		$this->unpublishPages($urls);
		
		$repub = array();
		if ($this->owner->hasMethod('pagesAffectedByChanges')) {
			$repub = $this->owner->pagesAffectedByChanges();
			$repub = array_diff($repub, $urls);
			if (count($repub)) {
				if (class_exists('SimpleCachePublishingJob')) {
					$job = new SimpleCachePublishingJob($this->owner, $repub);
					singleton('QueuedJobService')->queueJob($job);
				} else {
					$this->publishPages($repub);
				}
			}
		}
	}

	function unpublishPages($urls) {
		// we do the base one first
		$this->unpublishUrls($urls);

		// okay we need to publish these pages for multiple urls, so do that 
		// here
		$allDomains = SiteConfig::current_site_config()->ForDomains->getValue();
		if (is_array($allDomains)) {
			foreach ($allDomains as $baseDomain) {
				$oldBaseURL = self::$static_base_url;
				$oldHost = $_SERVER['HTTP_HOST'];
				$_SERVER['HTTP_HOST'] = $baseDomain;
				self::$static_base_url = Director::protocol() . $baseDomain . Director::baseURL();
				$this->unpublishUrls($urls, $baseDomain);
				self::$static_base_url = $oldBaseURL;
				$_SERVER['HTTP_HOST'] = $oldHost;
			}
		}
		
	}
	
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
			$keyPrefix = '';
			if ($this->owner->SubsiteID) {
				$keyPrefix = $this->owner->Subsite()->getPrimaryDomain();
			}

			$key = $keyPrefix . '/' . ltrim($path, '/');
			if ($contentType) {
				$data->ContentType = $contentType;
			}
			$cache->expire($key);
			
		}
	}

	protected function generatePHPCacheFile($content, $age, $lastModified) {
		// force cache age - this is done because Form sets cache age to 0 but we know that
		// our page is going to be cached and don't mind it having a dud value here. 
		$cacheAge = SiteConfig::current_site_config()->CacheAge;
		if ($cacheAge) {
			$age = $cacheAge;
		}
		$content = parent::generatePHPCacheFile($content, $age, $lastModified);
		return $content;
	}
	
	
	protected function out($message) {
		if (Director::is_cli()) {
			echo "$message \n";
		} else {
			echo "$message <br/>";
		}
	}
}
