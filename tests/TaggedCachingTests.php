<?php
	namespace Tests;
	
	use Throwable;
	use YetAnother\TagCache\CacheStorageException;
	
	class TaggedCachingTests extends TestCase
	{
		/**
		 * @throws CacheStorageException
		 * @throws Throwable
		 */
		function testSinglyTaggedCacheIsGenerated()
		{
			$text = 'Hello, world!';
			$model = new TestObject(5);
			$didGenerate = false;
			
			$value = $this->cacher->getOrGenerateText(tckey('greeting', $model), $generator = function() use (&$didGenerate, $text)
			{
				$didGenerate = true;
				return $text;
			});
			
			$this->assertEquals($text, $value);
			$this->assertTrue($didGenerate);
			
			$didGenerate = false;
			$value = $this->cacher->getOrGenerateText(tckey('greeting', $model), $generator);
			$this->assertEquals($text, $value);
			$this->assertFalse($didGenerate);
		}
	}
