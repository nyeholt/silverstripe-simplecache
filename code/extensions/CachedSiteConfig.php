<?php

/**
 * A site config used for sites that are cached
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class CachedSiteConfig extends DataExtension {
	public static $db = array(
        'CacheAge'					=> 'Int',
		'DisableSiteCache'		=> 'Boolean',			// disabled for this site
		'CacheBaseUrl'			=> 'Varchar(64)',		// use the specified base_url
		'ForDomains'			=> 'MultiValueField',	// whether to generate the same content for additional domain names
	);

    public function updateSettingsFields(FieldList $fields) {
		$ages = array(
			'60'		=> '1 minute',
			'600'		=> '10 minutes',
			'3600'		=> '1 hour',
			'86400'		=> '1 day',
		);
		
		$fields->addFieldToTab('Root.Cache', new CheckboxField('DisableSiteCache', 'Disable cache-on-publish'));
		$fields->addFieldToTab('Root.Cache', new DropdownField('CacheAge', 'Cache lifetime (only affects newly published pages)', $ages));

		$fields->addFieldToTab('Root.Cache', new TextField('CacheBaseUrl', 'Generate cache files as this domain'));
		$fields->addFieldToTab('Root.Cache', new MultiValueTextField('ForDomains', 'Generate cache files for additional domains'));
	}
    
}
