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
				$fields->addFieldToTab('Root.Main', new HeaderField($name.'header', $name));
				$fields->addFieldToTab('Root.Main', new ReadonlyField($name.'Hits', 'Hits', $stats->hits));
				$fields->addFieldToTab('Root.Main', new ReadonlyField($name.'Miss', 'Miss', $stats->misses));
				$fields->addFieldToTab('Root.Main', new ReadonlyField($name.'Count', 'Count', $stats->count));
				
				$caches[$name] = $name;
			}
		}

		if (count($caches)) {
			$fields->addFieldToTab('Root.Clear', new CheckboxSetField('ToClear', 'Caches to clear', $caches));
		}
		
		$actions = new FieldList(new FormAction('clear', 'Clear'));
		$form = new Form($this, 'EditForm', $fields, $actions);
		$form->addExtraClass('cms-edit-form cms-panel-padded center ' . $this->BaseCSSClasses());
		return $form;
	}
	
	public function clear($data, $form) {
		if (isset($data['ToClear'])) {
			foreach ($data['ToClear'] as $name) {
				$cache = $this->getCache($name);
				if ($cache) {
					$cache->clear();
				}
			}
		}
	}

	protected function getCache($name) {
		$cache = SimpleCache::get_cache($name);
		return $cache;
	}
}