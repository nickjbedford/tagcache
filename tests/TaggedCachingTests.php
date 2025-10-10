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
		function testSinglyTaggedCacheIsGeneratedAndClearedByObject()
		{
			$text = 'Hello, world!';
			$model = new TestObject(5);
			$didGenerate = false;
			
			$value = $this->cacher->getOrGenerateText($key = tckey('greeting', $model), $generator = function() use (&$didGenerate, $text)
			{
				$didGenerate = true;
				return $text;
			});
			
			$this->assertEquals($text, $value);
			$this->assertTrue($didGenerate);
			
			$didGenerate = false;
			$value = $this->cacher->getOrGenerateText($key, $generator);
			$this->assertEquals($text, $value);
			$this->assertFalse($didGenerate);
			
			$this->assertEquals(1, $this->cacher->invalidateObjects([$model]));
			
			$this->assertEquals('Cache regenerated', $this->cacher->getOrGenerateText($key, fn() => 'Cache regenerated'));
		}
		
		/**
		 * @throws CacheStorageException
		 * @throws Throwable
		 */
		function testCacheExpiresAfter1SecondLiftimeButNotOneDay()
		{
			$text = 'Hello, world!';
			$model = new TestObject(5);
			$didGenerate = false;
			
			$value = $this->cacher->getOrGenerateText($key = tckey('greeting', $model), $generator = function() use (&$didGenerate, $text)
			{
				$didGenerate = true;
				return $text;
			});
			
			$this->assertEquals($text, $value);
			$this->assertTrue($didGenerate);
			
			$didGenerate = false;
			$value = $this->cacher->getOrGenerateText($key, $generator);
			$this->assertEquals($text, $value);
			$this->assertFalse($didGenerate);
			
			sleep(2);
			
			// Still cached before 4 seconds elapses
			$this->assertEquals($text, $this->cacher->getOrGenerateText($key, fn() => 'Cache regenerated', 4));
			
			// But cache expired after 1 second lifetime
			$this->assertEquals('Cache regenerated', $this->cacher->getOrGenerateText($key, fn() => 'Cache regenerated', 1));
		}
		
		/**
		 * @throws CacheStorageException
		 * @throws Throwable
		 */
		function testSinglyTaggedCacheIsGeneratedAndClearedByName()
		{
			$text = 'Hello, world!';
			$model = new TestObject(5);
			
			$this->cacher->getOrGenerateText($key = tckey('greeting', $model), fn() => $text);
			
			$this->assertEquals($text, $this->cacher->getOrGenerateText($key, fn() => 'Should not be called'));
			
			$this->assertEquals(1, $this->cacher->invalidateNamed('greeting'));
			
			$this->assertEquals('Cache regenerated', $this->cacher->getOrGenerateText($key, fn() => 'Cache regenerated'));
		}
		
		/**
		 * @throws CacheStorageException
		 * @throws Throwable
		 */
		function testMultiplyTaggedCacheIsGeneratedAndClearedBySecondObject()
		{
			$text = 'Goodbye, world!';
			$model1 = new TestObject(5);
			$model2 = new OtherObject(99);
			
			$key = tckey('greeting', $model1, $model2);
			
			foreach([$model1, $model2] as $model)
			{
				$this->cacher->getOrGenerateText($key, fn() => $text);
				
				$this->assertEquals($text, $this->cacher->getOrGenerateText($key, fn() => 'Should not be called'));
				
				// Invalidate the same cache entry by each object
				$this->assertEquals(1, $this->cacher->invalidateObject($model));
				
				$this->assertEquals('Cache regenerated', $this->cacher->getOrGenerateText($key, fn() => 'Cache regenerated'));
				
				$this->cacher->invalidateObject($model);
			}
		}
	}
