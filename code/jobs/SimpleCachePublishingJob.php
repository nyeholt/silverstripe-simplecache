<?php

/**
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SimpleCachePublishingJob extends AbstractQueuedJob {
	public static $dependencies = array(
		'cachePublisher'			=> '%$SimpleCachePublisher',
	);
	
	/**
	 * @var SimpleCachePublisher
	 */
	public $cachePublisher;
	
	public function __construct($object = null, $urls = null) {
		if ($object) {
            if (singleton('SimpleCachePublisher')->dontCache($object)) {
                $this->totalSteps = 1;
                return;
            }
			$collection = null;
			if ($object instanceof DataList) {
				$collection = $object;
				$object = $object->First();
			} else {
				$collection = new ArrayList();
				$collection->push($object);
			}

			$this->setObject($object);
			
			if (!$urls) {
				// swap over to a proper base URL if needed
				if ($object->SiteID && class_exists('Multisites')) {
					// let's set the base directly
					$base = $object->Site()->getUrl();
					Config::inst()->update('Director', 'alternate_base_url', $base);
				}
				$stage = Versioned::current_stage();
				Versioned::reading_stage('Live');
				$urls = array();
				// loop over the collection so we only add URLs once
				foreach ($collection as $object) {
					if ($object->NeverCache) {
						continue;
					}

					if($object->hasMethod('pagesAffectedByChanges')) {
						$affected = $object->pagesAffectedByChanges(null);
						foreach ($affected as $newUrl) {
							$newUrl = Director::absoluteURL($newUrl);
							$urls[$newUrl] = true;
						}
					} else {
						$urls[$object->AbsoluteLink()] = true;
					}
				}
				
				if (count($urls)) {
					$urls = array_keys($urls);
				}
				Versioned::reading_stage($stage);
				
				$object->extend('updateAffectedPages', $urls);
			}

			$this->urls = array_unique($urls);
			$this->totalSteps = count($this->urls);
		}
	}
	
	public function getSignature() {
		return md5(serialize($this->urls));
	}

	public function getTitle() {
		return 'Republish cache for ' . $this->getObject()->Title . '(#' . $this->getObject()->ID .')';
	}

	public function getJobType() {
		if ($this->totalSteps < 10) {
			return QueuedJob::IMMEDIATE;
		} else if ($this->totalSteps < 100) {
			return QueuedJob::QUEUED;
		} else {
			return QueuedJob::LARGE;
		}
	}
	
	public function setup() {
		parent::setup();
		$obj = $this->getObject();
		if ($obj) {
			$this->cachePublisher->recacheFragments($obj);	
		}
	}

	public function process() {
		$urls = $this->urls;
		
		if (!count($urls)) {
			$this->currentStep = $this->totalSteps;
			$this->isComplete = true;
			return;
		}
		$url = array_shift($urls);
		
		$stage = Versioned::current_stage();
		Versioned::reading_stage('Live');
		$this->cachePublisher->publishPages(array($url));
		Versioned::reading_stage($stage);

		$this->urls = $urls;
		$this->currentStep++;
		if (!count($urls)) {
			$this->currentStep = $this->totalSteps;
			$this->isComplete = true;
		}
	}
}
