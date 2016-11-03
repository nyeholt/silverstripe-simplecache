<?php

/**
 * @author marcus
 */
class SimpleCacheControllerExtension extends Extension
{
    public function onAftereInit() {
        if ($this->owner->NeverCache) {
            $res = $this->owner->getResponse();
            if ($res) {
                $res->addHeader('X-SilverStripe-NoCache', '1');
            }
        }
    }
}
