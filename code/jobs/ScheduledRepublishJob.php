<?php

/**
 * 
 *
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class ScheduledRepublishJob extends AbstractQueuedJob {
	
	public function __construct($types = null, $schedule = 86400) {
		if ($types && count($types)) {
			$this->types = $types;
			$this->schedule = $schedule;
			$this->totalSteps = count($types);
		}
	}
	
	public function getTitle() {
		return "Scheduled republish of " . implode(', ', $this->types) . ' pages';
	}

	public function process() {
		$classes = array();
		$types = $this->types;
		
		foreach ($types as $t) {
			if (class_exists($t)) {
				$pages = SiteTree::get()->filter(array('ClassName' => $t));

				$urls = array();
				// only used for later context
				$object = null;
				foreach ($pages as $object) {
					if (singleton('SimpleCachePublisher')->dontCache($object)) {
						continue;
					}

					if ($object->hasMethod('pagesAffectedByChanges')) {
						$pageUrls = $object->pagesAffectedByChanges();
						foreach ($pageUrls as $url) {
							$urls[] = $url;
						}
					} else {
						$urls[] = $object->AbsoluteLink();
					}
				}

				if ($object) {
					$job = new SimpleCachePublishingJob($object, $urls);
					singleton('QueuedJobService')->queueJob($job);
				}
			}
			
			$this->currentStep ++;
		}

		$this->isComplete = 1;
		$nextRun = date('Y-m-d H:i:s', time() + $this->schedule);
		$job = new ScheduledRepublishJob($this->types, $this->schedule);
		singleton('QueuedJobService')->queueJob($job, $nextRun);
	}
}
