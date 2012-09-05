<?php

/**
 * Publish a single URL using the cache publisher
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class PublishUrlTask extends BuildTask {
	public static $dependencies = array(
		'cachePublisher'			=> '%$SimpleCachePublisher',
	);
	
	/**
	 * @var SimpleCachePublisher
	 */
	public $cachePublisher;
	
	public function run($request) {
		if (!$this->cachePublisher) {
			Injector::inst()->inject($this);
		}
		$url = $request->getVar('publish_url');
		
		echo "Publish $url<br/>\n";
		if (!strlen($url)) {
			exit("Invalid URL");
		}
		$this->cachePublisher->publishPages(array($url));
	}
}
