<?php
	namespace Tests;
	
	use Throwable;
	use YetAnother\TagCache\CacheStorageException;
	use YetAnother\TagCache\Key;
	use YetAnother\TagCache\OutputBufferException;
	use function YetAnother\TagCache\global_cache;
	use function YetAnother\TagCache\global_cacheable;
	
	class CacheableTests extends TestCase
	{
		/**
		 * @throws Throwable
		 * @throws CacheStorageException
		 */
		function testCacheableCanFetchGenerateAndBuffer()
		{
			$key = new Key('user_profile')
				->tag('User', 42)
				->tag('Category', 'news')
				->with([new TestObject(7)], classBasename: true)
				->dateRange('2025-01-01', '2025-12-31')
				->global();
			
			$cacheable = $key->cacheable();
			
			$this->assertFalse($cacheable->exists());
			$this->assertEquals('Test Data', $cacheable->text(fn() => "Test Data"));
			$this->assertTrue($cacheable->exists());
			
			$cacheable->invalidate();
			$this->assertFalse($cacheable->exists());
			
			$this->assertTrue($cacheable->beginBuffer());
			echo "Hello, world!";
			$this->assertEquals('Hello, world!', $cacheable->endBuffer(false));
		}
		
		/**
		 * @throws Throwable
		 * @throws CacheStorageException
		 */
		function testCacheableBufferMismatchFails()
		{
			$key = new Key('user_profile')
				->tag('User', 42)
				->tag('Category', 'news')
				->with([new TestObject(7)], classBasename: true)
				->dateRange('2025-01-01', '2025-12-31')
				->global();
			
			$cacheable = $key->cacheable();
			
			$this->assertTrue($cacheable->beginBuffer());
			echo "Hello, world!";
			
			$bufferLevel = ob_get_level();
			
			try
			{
				$this->assertTrue(ob_start());
				
				$this->expectException(OutputBufferException::class);
				$this->expectExceptionMessage("endBuffer() was called but the output buffer level does not match the level when beginBuffer() was called.");
				
				$cacheable->endBuffer(false);
			}
			finally
			{
				while (ob_get_level() >= $bufferLevel)
					ob_end_clean();
			}
		}
		
		/**
		 * @throws Throwable
		 * @throws CacheStorageException
		 */
		function testGlobalCacheableCreatesGlobalCache()
		{
			$cacheable = global_cache('TestData');
			
			$this->assertNull($cacheable->text());
			
			$cacheable->text(fn() => 'Hello, world!');
			
			$this->assertEquals('Hello, world!', $cacheable->text());
			
			$cacheable->invalidate();
			
			$this->assertNull($cacheable->text());
		}
	}
