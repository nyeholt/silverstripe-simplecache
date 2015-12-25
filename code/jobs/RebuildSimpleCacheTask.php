<?php

class RebuildSimpleCacheTask extends BuildTask
{

    public function run($request)
    {
        if (isset($_GET['Types']) && (Director::is_cli() || Permission::check('ADMIN'))) {
            $types = explode(',', $_GET['Types']);
            $classes = array();
            foreach ($types as $t) {
                if (class_exists($t)) {
                    $pages = SiteTree::get()->filter(array('ClassName' => $t));
                    
                    $urls = array();
                    // only used for later context
                    $cachedObject = null;

                    foreach ($pages as $object) {
                        if ($object->SiteID && class_exists('Multisites')) {
                            // let's set the base directly
                            $base = $object->Site()->getUrl();
                            Config::inst()->update('Director', 'alternate_base_url', $base);
                        }
                        if (singleton('SimpleCachePublisher')->dontCache($object)) {
                            continue;
                        }

                        if ($object->hasMethod('pagesAffectedByChanges')) {
                            $pageUrls = $object->pagesAffectedByChanges();
                            foreach ($pageUrls as $url) {
                                $urls[] = $url;
                            }
                        } else {
                            $urls[] = $object->AbsoluteLink();
                        }
                        $cachedObject = $object;
                    }
                    
                    if ($cachedObject) {
                        $job = new SimpleCachePublishingJob($cachedObject, $urls);
                        singleton('QueuedJobService')->queueJob($job);
                    }
                }
            }
        }
    }
}
