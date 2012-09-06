<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SimpleCachePublisherExtension extends DataExtension {
	
	public static $exclude_types = array(
		'UserDefinedForm',
		'SolrSearchPage',
	);
	
	public static $dependencies = array(
		'cachePublisher'			=> '%$SimpleCachePublisher',
	);
	
	/**
	 * @var SimpleCachePublisher
	 */
	public $cachePublisher;

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
		$this->cachePublisher->publishDataObject($this->owner);
	}

	public function onRenameLinkedAsset($original) {
		if (SiteConfig::current_site_config()->DisableSiteCache) {
			return;
		}
		$this->cachePublisher->publishDataObject($this->owner);
	}

	/**
	 * On after unpublish, get changes and hook into underlying
	 * functionality
	 */
	function onAfterUnpublish($page) {
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
				$this->cachePublisher->publishDataObject($this->owner, $repub);
			}
		}
	}

	function unpublishPages($urls) {
		$keyPrefix = null;
		if ($this->owner->SubsiteID) {
			$keyPrefix = $this->owner->Subsite()->getPrimaryDomain();
		}
		// we do the base one first
		$this->cachePublisher->unpublishUrls($urls, $keyPrefix);

		// okay we need to publish these pages for multiple urls, so do that 
		// here
		$allDomains = SiteConfig::current_site_config()->ForDomains->getValue();
		if (is_array($allDomains)) {
			foreach ($allDomains as $baseDomain) {
				$this->cachePublisher->unpublishUrls($urls, $baseDomain);
			}
		}
	}
}
