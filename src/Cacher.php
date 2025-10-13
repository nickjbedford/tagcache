<?php
	/** @noinspection PhpUnused */
	
	namespace YetAnother\TagCache;
	
	use Closure;
	use FilesystemIterator;
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
		const string KEY_NAME_TAG = '_key_name';
		public readonly string $cacheDirectory;
		public readonly string $tagDirectory;
		
		public float $lastGenerateTime = 0.0;
		public float $lastLinksCreationTime = 0.0;
		
		const bool HASH_XXH128 = true;
		
		/**
		 * @var string[] $removeNamespaces If set, these namespaces will be removed from the class names when generating tags, such as "App\Models\".
		 */
		public static array $removeNamespaces = [
			'App\\Models\\'
		];
		
		/**
		 * @var Cacher|null $default The default Cacher instance. This must be assigned manually.
		 */
		public static ?self $default = null;
		
		/**
		 * @param string $rootDirectory The root directory where cache and tag directories will be created.
		 * @param string $language The language code for the cache (e.g., 'en', 'fr'). Default is 'en'.
		 */
		public function __construct(string $rootDirectory,
		                            public readonly string $language = 'en')
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
			
			$key = Key::hash($key);
			
			$path = "$this->cacheDirectory$key.cache";
			
			if (!file_exists($path))
				return null;
			
			if ((filemtime($path) + $lifetime) < time())
				return null;
			
			$fp = $this->acquireLock($path, 'rb', LOCK_SH);
			
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
			
			$pathKey = $key->hashedKey;
			$path = "$this->cacheDirectory$pathKey.cache";
			
			$timedOut = null;
			$fp = $this->acquireLock($path, 'wb', LOCK_EX, $timedOut);
			
			if (!$fp)
			{
				if ($timedOut)
					throw new CacheStorageException("Failed to acquire lock for writing after " . self::LOCK_WAIT_TIMEOUT . " seconds: $path");
				else
					throw new CacheStorageException("Failed to open cache file for writing: $path");
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
		
		/**
		 * Clears cached entries associated with a specific cache key name regardless of which type/ID
		 * tags it was tagged with. For example, for all keys named "front_end_table_row", call
		 * invalidateNamed("front_end_table_row") to clear all cached entries with that name.
		 * The name will be sanitized and lowercased to match cached filesystem naming conventions.
		 * @param string $name The cache key name to invalidate.
		 * @return int The number of cache entries invalidated.
		 */
		public function invalidateNamed(string $name): int
		{
			return $this->invalidateTag(self::KEY_NAME_TAG, $name);
		}
		
		/**
		 * Clears cached entries associated with one or more tags. Tags should be provided as an associative array
		 * where the key is the tag type and the value is the tag identifier. For example, $tags = [ 'user' => 123, 'account' => 456 ].
		 * Tag types and IDs will be sanitized and lowercased to match cached filesystem naming conventions.
		 * @param array<string, string> $tags An associative array of tags to invalidate.
		 * @return int The number of cache entries invalidated.
		 */
		public function invalidateTags(array $tags): int
		{
			$count = 0;
			foreach($tags as $type=>$id)
			{
				$count += $this->invalidateTag($type, $id);
			}
			return $count;
		}
		
		/**
		 * Clears cached entries associated with a specific tag type and identifier. The tag type and ID
		 * will be sanitized and lowercased to match cached filesystem naming conventions.
		 * @param string $type The tag type or class name.
		 * @param string|int|null $id The tag identifier.
		 * @return int The number of cache entries invalidated.
		 */
		public function invalidateTag(string $type, string|int|null $id): int
		{
			foreach(self::$removeNamespaces as $namespace)
			{
				$type = str_replace($namespace, '', $type);
			}
			
			$type = sanitize_cache_key(ltrim($type, '\\'));
			$id = sanitize_cache_key(strval($id ?? '0'));
			
			$tagDir = $this->tagDirectory . $type . DIRECTORY_SEPARATOR . $id;
			if (!is_dir($tagDir))
				return 0;
			
			$count = 0;
			$iterator = new FilesystemIterator($tagDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME);
			foreach($iterator as $path)
			{
				$source = readlink($path);
				if ($source)
				{
					$this->unlink($source);
					$count++;
				}
				
				@unlink($path);
			}
			
			@rmdir($this->tagDirectory . $type . DIRECTORY_SEPARATOR . $id);
			return $count;
		}
		
		/**
		 * Clears all cached entries associated with the specified object's tag.
		 * @return int The number of cache entries invalidated.
		 */
		public function invalidateObject(object $object, string $property = 'id'): int
		{
			return $this->invalidateTag(get_class($object), $object->{$property} ?? 0);
		}
		
		/**
		 * Clears all cached entries associated with the global tag.
		 * @return int The number of cache entries invalidated.
		 */
		public function invalidateObjects(array $objects, string $property = 'id'): int
		{
			$count = 0;
			foreach($objects as $object)
			{
				$count += $this->invalidateObject($object, $property);
			}
			return $count;
		}
		
		/**
		 * Clears all cached entries associated with the global tag.
		 * @return int The number of cache entries invalidated.
		 */
		public function invalidateGlobal(): int
		{
			return $this->invalidateTag('global', 0);
		}
		
		/**
		 * Creates a directory if it does not exist, ensuring it is writable and has the correct permissions.
		 * @param string $directory The directory path to create.
		 * @return void
		 */
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
		
		/**
		 * Attempts to unlink (delete) a file if it exists. If it cannot be unlinked immediately,
		 * it will attempt to wait for an exclusive lock on the file before unlinking it, but only
		 * up to two seconds.
		 * @param string $source
		 * @return void
		 */
		private function unlink(string $source): void
		{
			if (!file_exists($source))
				return;
			
			if (@unlink($source))
				return;
			
			if ($fp = $this->acquireLock($source, 'rb',LOCK_EX, $timedOut, 2))
			{
				flock($fp, LOCK_UN);
				fclose($fp);
				@unlink($source);
			}
		}
		
		/**
		 * Creates symbolic links for the cache file in the appropriate tag directories.
		 * @param Key $key The cache key declaration.
		 * @param string $canonicalKey The canonical key (hashed if applicable).
		 * @param string $path The path to the cache file.
		 * @return void
		 */
		private function createLinks(Key $key, string $canonicalKey, string $path): void
		{
			$tags = $key->tags;
			$tags[self::KEY_NAME_TAG] = $key->name;
			
			foreach($tags as $tag=>$id)
			{
				$tagDir = $this->tagDirectory . sanitize_cache_key($tag) . DIRECTORY_SEPARATOR . sanitize_cache_key($id);
				$this->createDirectory($tagDir);
				$linkPath = $tagDir . DIRECTORY_SEPARATOR . $canonicalKey;
				
				if (file_exists($linkPath))
					@unlink($linkPath);
				
				@symlink($path, $linkPath);
			}
		}
		
		/**
		 * Attempts to acquire a lock on the specified cache file.
		 *
		 * @param string $path The path to the cache file to lock.
		 * @param string $mode The file mode to open the file with (e.g., 'rb' for read, 'wb' for write).
		 * @param int $lock The type of lock to acquire (LOCK_SH for shared, LOCK_EX for exclusive).
		 * @param bool|null $timedOut Set to true if the lock acquisition timed out, false otherwise.
		 * @param int $timeout The maximum time in seconds to wait for the lock.
		 * @return resource|null The file pointer if the lock was acquired, or null on failure.
		 */
		private function acquireLock(string $path,
		                             string $mode,
		                             int $lock,
		                             ?bool &$timedOut = null,
		                             int $timeout = self::LOCK_WAIT_TIMEOUT)
		{
			$timedOut = false;
			
			if (($fp = fopen($path, $mode)) === false)
				return null;
			
			$start = time();
			$lockAcquired = false;
			
			while ((time() - $start) < $timeout)
			{
				if (flock($fp, $lock | LOCK_NB))
				{
					$lockAcquired = true;
					break;
				}
				
				usleep(self::LOCK_ATTEMPT_INTERVAL);
			}
			
			if (!$lockAcquired)
			{
				fclose($fp);
				$timedOut = true;
				return null;
			}
			
			return $fp;
		}
	}
