<?php

/**
 * @author marcus
 */
class SimpleCacheControllerExtension extends Extension
{
    public function onBeforeInit() {
        if ($this->owner->NeverCache) {
            $res = $this->owner->getResponse();
            if ($res) {
//                $res->addHeader('X-SilverStripe-NoCache', '1');
            }
        }
    }
}
