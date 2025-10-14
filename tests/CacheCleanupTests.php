<?php
	namespace Tests;
	
	use Throwable;
	use YetAnother\TagCache\CacheStorageException;
	use function YetAnother\TagCache\cache_key;
	
	class CacheCleanupTests extends TestCase
	{
		/**
		 * @throws CacheStorageException
		 * @throws Throwable
		 */
		function testCacheIsCleanedUpCorrectly()
		{
			$text = 'Hello, world!';
			$model = new TestObject(5);
			
			$this->cacher->getOrGenerateText(cache_key('greeting', $model), $generator = fn() => $text);
			
			$this->assertGreaterThan(0, $cacheSize = $this->cacher->cacheSize());
			
			$bytesCleaned = 0;
			$count = $this->cacher->cleanupExpiredCaches(0, $bytesCleaned);
			$this->assertEquals($cacheSize, $bytesCleaned);
			$this->assertEquals(1, $count);
			
			$count = $this->cacher->cleanupExpiredCaches(0, $bytesCleaned);
			$this->assertEquals(0, $bytesCleaned);
			$this->assertEquals(0, $count);
			
			$this->cacher->getOrGenerateText(cache_key('greeting', $model), $generator = fn() => $text);
			
			$count = $this->cacher->cleanupExpiredCaches(bytesCleaned: $bytesCleaned);
			$this->assertEquals(0, $bytesCleaned);
			$this->assertEquals(0, $count);
		}
	}
