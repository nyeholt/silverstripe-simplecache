<?php

/**
 * @author marcus
 */
class SimpleCacheControllerExtension extends Extension
{
    public function onAfterInit() {
        if ($this->owner->NeverCache) {
            $res = $this->owner->getResponse();
            if ($res) {
                $res->addHeader('X-SilverStripe-NoCache', '1');
            }
        }
    }
}
