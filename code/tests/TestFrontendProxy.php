<?php

/**
 *
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class TestFrontendProxy extends SapphireTest {

	public function testProxyRuleMatch() {
		$config = array(
			'products/.*?(\d+)$'	=> array(
				'default'	=> 7200,
				'tags'		=> array('product', 1),
			)
		);
		
		$url = '/bathroom/products/some-kind-of-product-12345';
		
		$proxy = new FrontendProxy(null, null, $config);
		
		$conf = $proxy->configForUrl($url);
		
		$this->assertEquals($conf['tags'][1], '12345');
		
	}
}
