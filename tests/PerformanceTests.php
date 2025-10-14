<?php
	
	namespace Tests;
	
	use Random\RandomException;
	use Throwable;
	use YetAnother\TagCache\CacheStorageException;
	use YetAnother\TagCache\Key;
	
	class PerformanceTests extends TestCase
	{
		/**
		 * @throws RandomException
		 */
		function testKeyGenerationPerformance()
		{
			$cycles = 500_000 + random_int(0, 100);
			$finalHash = Key::hash('initial_hash');
			$start = microtime(true);
			
			for($i = 0; $i < $cycles; $i++)
			{
				$key = new Key($finalHash)
					->tag('User', random_int(10, 100))
					->tag('Category', random_int(10, 100))
					->with([new TestObject(random_int(10, 100))], classBasename: true)
					->dateRange('2025-01-01', '2025-12-31')
					->global();
				
				$finalHash = $key->hashedKey;
			}
			
			$duration = (microtime(true) - $start) * 1000;
			$microseconds = ($duration * 1000) / $cycles;
			$lines = [
				"Key generation for $cycles cycles took {$duration}ms",
				"Microseconds per cycle: {$microseconds}us",
				"Final hash: $finalHash",
			];
			
			file_put_contents(self::RESULTS_DIR . '/key_generation_performance.txt', join("\n", $lines), LOCK_EX);
			
			$this->assertTrue(true);
		}
		
		/**
		 * @throws RandomException
		 * @throws Throwable
		 * @throws CacheStorageException
		 */
		function testCacheHitPerformance()
		{
			$cycles = 5000 + random_int(0, 100);
			$finalHash = Key::hash('initial_hash');
			$keys = [];
			$start = microtime(true);
			$totalLinksCreationTime = 0.0;
			$totalStoreTime = 0.0;
			
			// Generate ~5000 cache files to simulate cache hits
			for($i = 0; $i < $cycles; $i++)
			{
				$key = new Key($finalHash)
					->tag('User', random_int(10, 100))
					->tag('Category', random_int(10, 100))
					->with([new TestObject(random_int(10, 100))], classBasename: true)
					->dateRange('2025-01-01', '2025-12-31')
					->global();
				
				$this->cacher->store($key, $key);
				$totalStoreTime += $this->cacher->lastTimeToGenerate;
				$totalLinksCreationTime += $this->cacher->lastTimeToCreateSymlinks;
				
				$finalHash = Key::hash($key->key);
				$keys[] = $key;
			}
			
			$durationSeconds = microtime(true) - $start;
			$perCycle = ($durationSeconds * 1_000) / $cycles;
			$perStore = ($totalStoreTime * 1_000) / $cycles;
			$perLinks = ($totalLinksCreationTime * 1_000) / $cycles;
			
			$lines = [
				"Cache file generation for $cycles cycles took $durationSeconds seconds",
				"Milliseconds per cycle: {$perCycle}ms",
				"Milliseconds per store: {$perStore}ms",
				"Milliseconds per links creation: {$perLinks}ms",
				"---------------------------------------------------"
			];
			
			$start = microtime(true);
			$keyNames = [];
			
			// Time the cache hits for all generated keys
			foreach($keys as $i=>$key)
			{
				/** @var Key $value */
				$value = $this->cacher->get($key);
				$keyNames[$i] = $value;
			}
			
			$durationSeconds = microtime(true) - $start;
			$perCycle = ($durationSeconds * 1_000_000) / $cycles;
			
			// Confirm saved data matches expected
			foreach($keys as $i=>$key)
			{
				$this->assertEquals($key->key, $keyNames[$i]->key);
			}
			
			$lines[] = "{$cycles}x cache hits took $durationSeconds seconds";
			$lines[] = "Microseconds per cycle: {$perCycle}us";
			
			file_put_contents(self::RESULTS_DIR . '/cache_hit_performance.txt', join("\n", $lines), LOCK_EX);
		}
	}
