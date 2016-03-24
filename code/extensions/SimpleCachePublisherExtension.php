<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SimpleCachePublisherExtension extends DataExtension {
	
	public static $db = array(
		'CacheThis'			=> 'Boolean',
		'DontCacheThis'		=> 'Boolean'
	);

	/**
	 * @var SimpleCachePublisher
	 */
	public $cachePublisher;
	
	/**
	 *
	 * @var Injector
	 */
	public $injector;
	
	public function updateSettingsFields(FieldList $fields) {
		if ($this->cachePublisher->getOptInCaching()) {
			$fields->addFieldToTab('Root.Cache', new LiteralField('OptInHeader', _t('SimpleCache.OPT_IN', '<strong>You must choose to have this page cached on publish</strong>')));
			$fields->addFieldToTab('Root.Cache', new CheckboxField('CacheThis', _t('SimpleCache.CACHE_THIS', 'Cache this item')));
		} else {
			$fields->addFieldToTab('Root.Cache', new LiteralField('OptInHeader', _t('SimpleCache.OPT_OUT', '<strong>You must choose to NOT cache this page</strong>')));
			$fields->addFieldToTab('Root.Cache', new CheckboxField('DontCacheThis', _t('SimpleCache.DONT_CACHE_THIS', 'Do NOT cache this item')));
		}
	}

	public function onAfterPublish($original) {
		// if it's versioned, let's check its old URL
		if (strlen($original->URLSegment) && strlen($this->owner->URLSegment) && 
			$original->URLSegment != $this->owner->URLSegment) {
			$oldUrl = $original->Link();
			$this->unpublishPages(array($oldUrl));
		}
		
		
		$this->republish($original);
	}

	public function republish($original) {
		if (SiteConfig::current_site_config()->DisableSiteCache) {
			return;
		}
        if (class_exists('Multisites')) {
            if ($this->owner->SiteID) {
                $site = $this->owner->Site();
            } else {
                $site = Multisites::inst()->getActiveSite();
            }
            if ($site && $site->DisableSiteCache) {
                return;
            }
        }
		$this->cachePublisher->publishDataObject($this->owner);
	}

	public function updateAffectedPages(&$urlsToPublish) {
		if ($this->owner->hasMethod('DependentPages')) {
			foreach($this->owner->DependentPages(false) as $page) {
				$urlsToPublish[] = $page->AbsoluteLink();
			}
		}
	}

	public function onRenameLinkedAsset($original) {
		if (SiteConfig::current_site_config()->DisableSiteCache) {
			return;
		}
        if (class_exists('Multisites') && Multisites::inst()->getActiveSite()->DisableSiteCache) {
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
		
		array_walk($urls, function (&$entry) {
			$entry = Director::absoluteURL($entry);
		});

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
		// we do the base one first
		$this->cachePublisher->unpublishUrls($urls, $keyPrefix);
	}
	
	/**
	 * Retrieve a cached fragment in the context of this page. 
	 * 
	 * @param string $name
	 * @return string
	 */
	public function CachedFragment($name) {
		$item = $this->injector->create('CachedFragment', $this->owner, $name);
		return $item;
	}
}
