<?php

/**
 * @author marcus
 */
class ViewKeyTask extends BuildTask
{
    public function run($request)
    {
        if (!Director::is_cli()) {
            exit("Invalid\n");
        }
        $cacheName = $request->getVar('cache');
        $key = $request->getVar('key');
        
        if (!strlen($cacheName) || !strlen($key)) {
            exit("Cache and key required\n");
        }
        
        $cache = SimpleCache::get_cache($cacheName);
        
        if ($cache) {
            $data = $cache->get($key);
            if ($data) {
                var_export($data);
            }
        }
    }
}
