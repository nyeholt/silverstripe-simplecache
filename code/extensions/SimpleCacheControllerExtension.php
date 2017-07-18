<?php

/**
 * @author marcus
 */
class SimpleCacheControllerExtension extends Extension
{
    public function onAfterInit() {
        if ($this->owner->NeverCache) {
            $this->nocacheHeaders();
        }
    }

    public function nocacheHeaders() {
        $res = $this->owner->getResponse();
        if ($res) {
            $current = Config::inst()->get('HTTP', 'cache_control');
            $current['private'] = true;
            $current['no-store'] = true;
            Config::inst()->update('HTTP', 'cache_control', $current);
            $res->addHeader('X-SilverStripe-NoCache', '1');
        }
    }
}
