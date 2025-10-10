<?php
	namespace Tests;
	
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
			
			$this->assertEquals('category_news-datefrom_20250101-dateto_20251231-global_0-testobject_7-user_42-user_profile', $key->key);
			$this->assertEquals('e95c2edaacf7cd8c838ca694148317a8', md5($key->key));
		}
	}
