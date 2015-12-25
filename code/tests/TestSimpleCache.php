<?php

/**
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class TestSimpleCache extends SapphireTest
{
    public function testCacheRetrieve()
    {
        $cache = new SimpleCache(new SimpleFileBasedCacheStore(TEMP_FOLDER.'/my_test_cache'));
        
        $object = new stdClass;
        $object->Title = "Object Title";
        
        $cache->store('one', $object);
        
        $array = array('Value' => 1.2);
        $cache->store('two', $array);
        
        $this->assertEquals('Object Title', $cache->get('one')->Title);
        
        $arr = $cache->get('two');
        $this->assertEquals(1.2, $arr['Value']);
    }
    
    public function testTagElement()
    {
        Filesystem::removeFolder(TEMP_FOLDER.'/my_test_cache');
        $cache = new SimpleCache(new SimpleFileBasedCacheStore(TEMP_FOLDER.'/my_test_cache'));
        
        $object = new stdClass;
        $object->Title = "Object Title";
        
        $cache->store('one', $object, -1, array('mytag'));
        
        $other = new stdClass;
        $other->Title = 'Second object';
        
        $cache->store('two', $other, -1, array('mytag'));
        
        $elems = $cache->getByTag('mytag');
        
        $this->assertEquals(2, count($elems));
        
        $this->assertEquals('Second object', $elems[1]->Title);
        
        $cache->deleteByTag('mytag');
        
        $one = $cache->get('one');
        $this->assertNull($one);
    }
    
    public function testClearThousands()
    {
        Filesystem::removeFolder(TEMP_FOLDER.'/my_test_cache');
        $cache = new SimpleCache(new SimpleFileBasedCacheStore(TEMP_FOLDER.'/my_test_cache'));
        
        for ($i = 0; $i < 1000; $i++) {
            $object = new stdClass;
            $object->Title = "Object $i";
            $other = $i % 10;
            $cache->store('key_' . $i, $object, -1, array('mytag', "mod$other"));
        }

        $start = microtime(true);
        $cache->deleteByTag('mytag');
        $end = microtime(true) - $start;
        
        $elems = $cache->getByTag('mytag');
        $this->assertEquals(0, count($elems));
    }
}
