<?php

class ScheduledRebuildSimpleCacheTask extends BuildTask {
	public function run($request) {
		if (isset($_GET['Types']) && (Director::is_cli() || Permission::check('ADMIN'))) {
			$job = new ScheduledRepublishJob(explode(',', $_GET['Types']), isset($_GET['seconds']) ? $_GET['seconds'] : 86400);
			$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d 00:30:00', time() + 86400);
			singleton('QueuedJobService')->queueJob($job, $start);
		}
	}
}
