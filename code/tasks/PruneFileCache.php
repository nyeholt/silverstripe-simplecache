<?php

class PruneFileCache extends BuildTask {
	
	public static $cache_paths = array(
		'silverstripe-cache/cache_store'
	);
	
	protected $save = array();
	
	protected $trial = true;
	
	public function run($request) {
		if ($request->getVar('flush')) {
			$this->trial = false;
		}
		foreach (self::$cache_paths as $path) {
			if ($path{0} != '/') {
				$path = Director::baseFolder().'/' . $path;
			}
			$glob = glob($path.'/**');
			$count = 0;
			$deletes = array();
			foreach ($glob as $file) {
				if (is_dir($file)) {
					$children = glob($file.'/**');
					foreach ($children as $subfile) {
						if ($this->checkFile($subfile)) {
							$deletes[] = $subfile;
						}
					}
				} else {
					if ($this->checkFile($file)) {
						$deletes[] = $subfile;
					}
				}
			}
			echo "Cleaned $path of " . count($deletes) ." expired entries<br/>\n";
			echo implode("<br/>\n", $deletes);
			echo "<br/><br/>\n\n";
		}

		echo implode("<br/>\n", $this->save);
	}
	
	protected function checkFile($file) {
		$delete = false;
		if (is_file($file)) {
			$content = file_get_contents($file);
			if (!strlen($content)) {
				$delete = true;
			} else {
				$data = @unserialize($content);
				if ($data->expireAt == 0 || $data->expireAt > time()) {
					$this->save[] = "Saving $file - expires " . date('Y-m-d H:i:s', $data->expireAt);
				} else {
					$delete = true;
				}
			}
		}
		
		if ($delete && !$this->trial) {
			unlink($file);
		}
		return $delete;
	}
}