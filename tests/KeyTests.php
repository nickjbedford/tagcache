<?php
	namespace Tests;
	
	use Random\RandomException;
	use YetAnother\TagCache\Key;
	
	class KeyTests extends TestCase
	{
		function testKeyWithTagsGeneratesCorrectKey()
		{
			$key = new Key('user_profile')
				->tag('User', 42)
				->tag('Category', 'news')
				->with([new TestObject(7)], classBasename: true)
				->dateRange('2025-01-01', '2025-12-31')
				->global();
			
			$this->assertEquals('global-category_news-datefrom_20250101-dateto_20251231-testobject_7-user_42-user_profile', $key->key);
			$this->assertEquals('de7b9b4a86ffb0951d0bd9880fb929d7', md5($key->key));
		}
	}
