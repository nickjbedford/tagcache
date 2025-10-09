<?php
	
	namespace YetAnother\TagCache;
	
	use Closure;
	use RuntimeException;
	use Throwable;
	
	/**
	 * Represents the cache file/directory manager.
	 */
	class Cacher
	{
		const int DEFAULT_LIFETIME = 86400;
		const int LOCK_WAIT_TIMEOUT = 30; // Wait for a maximum of 30 seconds to acquire a lock (this allows large content generation to complete without being too eager).
		const int LOCK_ATTEMPT_INTERVAL = 10000; // Attempt to acquire a lock every 10 milliseconds at most
		
		public readonly string $cacheDirectory;
		public readonly string $tagDirectory;
		
		public float $lastGenerateTime = 0.0;
		public float $lastLinksCreationTime = 0.0;
		
		/**
		 * @var Cacher|null $default The default Cacher instance. This must be assigned manually.
		 */
		public static ?self $default = null;
		
		public function __construct(string                 $rootDirectory,
		                            public readonly string $language = 'en',
		                            public readonly bool   $hashedPaths = true)
		{
			$this->cacheDirectory = rtrim($rootDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $this->language . DIRECTORY_SEPARATOR;
			$this->tagDirectory = rtrim($rootDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tags' . DIRECTORY_SEPARATOR . $this->language . DIRECTORY_SEPARATOR;
			$this->createDirectory($this->cacheDirectory);
			$this->createDirectory($this->tagDirectory);
		}
		
		/**
		 * Attempts to retrieve cached text content. If the content is not found or has expired, null is returned.
		 * @param string|Key $key The cache key or Key declaration.
		 * @param int $lifetime The lifetime of the cache in seconds. The default is 86400 seconds (1 day).
		 * @return string|null The cached text content, or null if not found or has expired.
		 */
		public function getText(string|Key $key,
		                        int $lifetime = self::DEFAULT_LIFETIME): ?string
		{
			if ($key instanceof Key)
				$key = $key->key;
			
			if ($this->hashedPaths)
				$key = md5($key);
			
			$path = "$this->cacheDirectory$key.cache";
			
			if (!file_exists($path))
				return null;
			
			if ((filemtime($path) + $lifetime) < time())
				return null;
			
			if (($fp = fopen($path, 'rb')) === false)
				return null;
			
			$start = time();
			$lockAcquired = false;
			
			while ((time() - $start) < self::LOCK_WAIT_TIMEOUT)
			{
				if (flock($fp, LOCK_SH | LOCK_NB))
				{
					$lockAcquired = true;
					break;
				}
				
				usleep(self::LOCK_ATTEMPT_INTERVAL);
			}
			
			if (!$lockAcquired)
			{
				fclose($fp);
				return null;
			}
			
			$content = stream_get_contents($fp);
			flock($fp, LOCK_UN);
			fclose($fp);
			return $content !== false ? $content : null;
		}
		
		/**
		 * Attempts to retrieve cached serialized data. If the data is not found or has expired, null is returned.
		 * @param string|Key $key The cache key or Key declaration.
		 * @param int $lifetime The lifetime of the cache in seconds. The default is 86400 seconds (1 day).
		 * @return mixed The cached data, or null if not found or has expired.
		 */
		public function get(string|Key $key,
		                    int $lifetime = self::DEFAULT_LIFETIME): mixed
		{
			$serialized = $this->getText($key, $lifetime);
			return $serialized !== null ? unserialize($serialized) : null;
		}
		
		/**
		 * Locks the cache file for writing, generates the data using the provided generator,
		 * serializes it and stores it in the cache, then releases the lock.
		 *
		 * @param Key $key The cache key declaration.
		 * @param callable|Closure $generator A callable or closure that generates the data to be cached. It should return the data to be cached.
		 * @param bool $serialize Whether to serialize the generated data before storing it.
		 * @return mixed The generated data.
		 * @throws CacheStorageException if storing the data fails.
		 * @throws Throwable if the generator throws an exception.
		 */
		public function generateDuringLock(Key $key,
		                                   callable|Closure $generator,
		                                   bool $serialize = true): mixed
		{
			$start = microtime(true);
			$pathKey = $key->key;
			
			if ($this->hashedPaths)
				$pathKey = md5($pathKey);
			
			$path = "$this->cacheDirectory$pathKey.cache";
			
			if (($fp = fopen($path, 'w')) === false)
				throw new CacheStorageException("Failed to open path for writing: $path");
			
			$lockAcquired = false;
			
			while ((time() - $start) < self::LOCK_WAIT_TIMEOUT)
			{
				if (flock($fp, LOCK_EX | LOCK_NB))
				{
					$lockAcquired = true;
					break;
				}
				
				usleep(self::LOCK_ATTEMPT_INTERVAL);
			}
			
			if (!$lockAcquired)
			{
				fclose($fp);
				throw new CacheStorageException("Failed to acquire lock for writing after " . self::LOCK_WAIT_TIMEOUT . " seconds: $path");
			}
			
			try
			{
				$value = $generator();
				if ($serialize)
					$value = serialize($value);
				
				if (fwrite($fp, $value) === false)
					throw new CacheStorageException("Failed to write data to cache file: $path");
			}
			catch(Throwable $exception)
			{
				flock($fp, LOCK_UN);
				fclose($fp);
				@unlink($path);
				throw $exception;
			}
			
			flock($fp, LOCK_UN);
			fclose($fp);
			@chmod($path, 0664);
			
			$this->lastGenerateTime = microtime(true) - $start;
			
			$start = microtime(true);
			$this->createLinks($key, $pathKey, $path);
			$this->lastLinksCreationTime = microtime(true) - $start;
			
			return $value;
		}
		
		/**
		 * Locks the cache file for writing, generates the text content using the provided generator,
		 * and stores it in the cache, then releases the lock.
		 *
		 * @param Key $key The cache key declaration.
		 * @param callable|Closure $generator A callable or closure that generates the text content to be cached.
		 * @return string The generated text content.
		 * @throws CacheStorageException if storing the data fails.
		 * @throws Throwable if the generator throws an exception.
		 */
		public function generateTextDuringLock(Key $key,
		                                       callable|Closure $generator): string
		{
			return $this->generateDuringLock($key, $generator, false);
		}
		
		/**
		 * Attempts to retrieve cached data. If the data is not found or has expired, it generates the data
		 * using the provided generator, stores it in the cache if it is not null, and returns the generated data.
		 * The data or text can be optionally generated during a lock to prevent multiple requests
		 * generating the same data at the same time.
		 *
		 * @param Key $key The cache key declaration.
		 * @param callable|Closure $generator A callable or closure that generates the data to be cached. It should return the data to be cached.
		 * @param int $lifetime The lifetime of the cache in seconds. The default is 86400 seconds (1 day).
		 * @param bool $serialize Whether to serialize the data before storing it. Default is true.
		 * @param bool $duringLock Whether to generate the data during a lock. Default is false.
		 * @return mixed The cached or generated data.
		 * @throws CacheStorageException if storing the data fails.
		 * @throws Throwable if the generator throws an exception.
		 */
		public function getOrGenerate(Key              $key,
		                              callable|Closure $generator,
		                              int              $lifetime = self::DEFAULT_LIFETIME,
		                              bool             $serialize = true,
		                              bool             $duringLock = false): mixed
		{
			$value = $serialize ?
				$this->get($key, $lifetime) :
				$this->getText($key, $lifetime);
			
			if ($value !== null)
				return $value;
			
			if ($duringLock)
				return $this->generateDuringLock($key, $generator, $serialize);
			
			$value = $generator();
			if ($value === null)
				return null;
			
			$this->store($key, $value, $serialize);
			return $value;
		}
		
		/**
		 * Attempts to retrieve cached text content. If the content is not found or has expired, it
		 * generates the text using the provided generator, stores it in the cache, and returns the
		 * generated text. The text can be optionally generated during a lock to prevent multiple
		 * requests generating the same text at the same time.
		 *
		 * @param Key $key The cache key declaration.
		 * @param callable|Closure $generator A callable or closure that generates the text content to be cached.
		 * @param int $lifetime The lifetime of the cache in seconds. The default is 86400 seconds (1 day).
		 * @param bool $duringLock Whether to generate the text during a lock. Default is false.
		 * @return string|null The cached or generated text content, or null if generation failed.
		 * @throws CacheStorageException if storing the data fails.
		 * @throws Throwable if the generator throws an exception.
		 */
		public function getOrGenerateText(Key              $key,
		                                  callable|Closure $generator,
		                                  int              $lifetime = self::DEFAULT_LIFETIME,
		                                  bool             $duringLock = false): ?string
		{
			return $this->getOrGenerate($key, $generator, $lifetime, false, $duringLock);
		}
		
		/**
		 * Stores the provided data in the cache under the specified key. The data can be optionally
		 * serialized before storing. If the data is null, it will not be stored for efficiency.
		 *
		 * @param Key $key The cache key declaration.
		 * @param mixed $value The data or text to be cached.
		 * @param bool $serialize Whether to serialize the data before storing it. Default is true.
		 * @return void
		 * @throws CacheStorageException if storing the data fails.
		 * @throws Throwable if the generator throws an exception.
		 */
		public function store(Key $key, mixed $value, bool $serialize = true): void
		{
			if ($value === null)
				return;
			
			$this->generateDuringLock($key, fn() => $value, $serialize);
		}
		
		private function createDirectory(string $directory): void
		{
			if (!is_dir($directory) &&
			    !mkdir($directory, 02775, true) &&
			    !is_dir($directory))
			{
				throw new RuntimeException(sprintf('Directory "%s" was not created', $directory));
			}
			
			if (!is_writable($directory))
			{
				throw new RuntimeException(sprintf('Directory "%s" is not writable', $directory));
			}
			
			$perms = fileperms($directory);
			if ($perms && ($perms & 0o2775) !== 0o2775)
				@chmod($directory, 02775);
		}
		
		private function createLinks(Key $key, string $canonicalKey, string $path): void
		{
			foreach($key->tags as $tag=>$id)
			{
				$tagDir = $this->tagDirectory . sanitize_cache_key_filename($tag) . DIRECTORY_SEPARATOR . sanitize_cache_key_filename($id);
				$this->createDirectory($tagDir);
				$linkPath = $tagDir . DIRECTORY_SEPARATOR . $canonicalKey;
				
				if (!file_exists($linkPath) || @unlink($linkPath))
					@symlink($path, $linkPath);
			}
		}
	}
