<?php

/**
 * Description of SimpleCacheAdmin
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SimpleCacheAdmin extends LeftAndMain {
	static $url_segment = 'simplecache';
	static $url_rule = '/$Action/$ID';
	static $menu_title = 'Simple cache';
	
	
	static $caches = array(
		
	);
	
	/**
	 *
	 */
	public static function add_caches() {
		
	}
	
	public function getEditForm($id = null, $fields = null) {
		$tabs = new TabSet('Root', new Tab('Main'));
		$fields = new FieldList($tabs);
		$caches = array();
		$all_caches = SimpleCache::$cache_configs;
		
		foreach ($all_caches as $name => $cacheInfo) {
			$cache = $this->getCache($name);
			if ($cache) {
				$stats = $cache->stats();
				$fields->addFieldToTab('Root.Info', new HeaderField($name.'header', $name));
				$fields->addFieldToTab('Root.Info', new ReadonlyField($name.'Hits', 'Hits', $stats->hits));
				$fields->addFieldToTab('Root.Info', new ReadonlyField($name.'Miss', 'Miss', $stats->misses));
				$fields->addFieldToTab('Root.Info', new ReadonlyField($name.'Count', 'Count', $stats->count));
				
				$caches[$name] = $name;
			}
		}
		
		if (count($caches)) {
			$fields->addFieldToTab('Root.Main', new CheckboxSetField('ToClear', 'Caches to clear', $caches));
			
			$fields->addFieldToTab('Root.Main', new TextField('Key', 'Key to clear from selected caches'));
		
		}
		$actions = new FieldList(FormAction::create('clear', 'Clear')->setUseButtonTag(true));

		$form = CMSForm::create(
				$this, "EditForm", $fields, $actions
			)->setHTMLID('Form_EditForm');

		$form->addExtraClass('cms-edit-form center');
		
		$form->setResponseNegotiator($this->getResponseNegotiator());
		$form->setTemplate('SimpleCacheAdmin_EditForm');
		$form->setAttribute('data-pjax-fragment', 'CurrentForm');
		
		return $form;
	}
	
	public function clear($data, CMSForm $form) {
		if (isset($data['ToClear'])) {
			$cleared = array();
			foreach ($data['ToClear'] as $name) {
				$cache = $this->getCache($name);
				if ($cache) {
					if (isset($data['Key'])) {
						$cache->delete($data['Key']);
					} else {
						$cache->clear();
					}
					
					$cleared[] = $name;
				}
			}
			$cleared = implode(',', $cleared);
			$form->sessionMessage("Cleared $cleared", 'good');
		} else {
			$form->sessionMessage("No caches cleared", 'good');
		}
		
		return $form->getResponseNegotiator()->respond($this->getRequest());
	}

	protected function getCache($name) {
		$cache = SimpleCache::get_cache($name);
		return $cache;
	}
}