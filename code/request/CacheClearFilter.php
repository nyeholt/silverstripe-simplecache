<?php

/**
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class CacheClearFilter implements RequestFilter {
	/**
	 *
	 * @var SimpleCache
	 */
	public $dynamicCache;
	
	public function postRequest(\SS_HTTPRequest $request, \SS_HTTPResponse $response, \DataModel $model) {
		if (Member::currentUserID() && $request->getVar('clear') && Permission::check('ADMIN')) {
			$key = trim($request->getVar('url'), '/');
			$key = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . '/' . $key;
			$item = $this->dynamicCache->get($key);
			if ($item) {
				$response->addHeader('X-SilverStripe-Cache', 'deleted ' . $key);
				$this->dynamicCache->delete($key);
			}
		}
	}

	public function preRequest(\SS_HTTPRequest $request, \Session $session, \DataModel $model) {
		
	}
}
