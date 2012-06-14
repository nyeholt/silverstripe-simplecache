<?php

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SimpleCacheTest extends SapphireTest {
	public function testSimpleCache() {
		$cache = SimpleCache::get_cache('my_cache', new SimpleFileBasedCacheStore(TEMP_FOLDER.'/my_cache'));
		
		$object = new stdClass;
		$object->Title = "Object Title";
		
		$cache->store('one', $object);
		
		$array = array('Value' => 1.2);
		$cache->store('two', $array);
		
		$this->assertEquals('Object Title', $cache->get('one')->Title);
		
		$arr = $cache->get('two');
		$this->assertEquals(1.2, $arr['Value']);
	}
}