<?php

/**
 * A site config used for sites that are cached
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class CachedSiteConfig extends DataExtension {
	public static $db = array(
		'DisableSiteCache'		=> 'Boolean',			// disabled for this site
		'CacheBaseUrl'			=> 'Varchar(64)',		// use the specified base_url
		'ForDomains'			=> 'MultiValueField',	// whether to generate the same content for additional domain names
	);
}
