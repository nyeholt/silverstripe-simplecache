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
				$stage = Versioned::current_stage();
				Versioned::reading_stage('Live');
				$urls = array();
				// loop over the collection so we only add URLs once
				foreach ($collection as $object) {
					if ($object->NeverCache) {
						continue;
					}

					if($object->hasMethod('pagesAffectedByChanges')) {
						$affected = $object->pagesAffectedByChanges($original);
						foreach ($affected as $newUrl) {
							$urls[$newUrl] = true;
						}
					} else {
						$urls[$object->Link()] = true;
					}
				}
				
				if (count($urls)) {
					$urls = array_keys($urls);
				}
				Versioned::set_reading_mode($stage);
			}

			$this->urls = $urls;
			$this->totalSteps = count($urls);
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
