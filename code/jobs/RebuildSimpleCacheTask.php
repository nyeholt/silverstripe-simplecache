<?php

class RebuildSimpleCacheTask extends BuildTask {

	public function run($request) {
		if (isset($_GET['Types']) && (Director::is_cli() || Permission::check('ADMIN'))) {
			$types = explode(',', $_GET['Types']);
			$classes = array();
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
			}
		}
	}
}