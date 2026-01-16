<?php
	namespace YetAnother\TagCache;
	
	use Closure;
	use Exception;
	use InvalidArgumentException;
	use Throwable;
	
	/**
	 * Represents a cache item with its associated key, providing a chainable way
	 * to fetch or generate the cached data. It offers output buffer handling
	 * as well as generator-based cache creation.
	 */
	class Cacheable
	{
		public readonly Cacher $cacher;
		private bool $duringLock = true;
		private ?string $existingBuffer = null;
		private mixed $lockedFile = null;
		private ?int $bufferLevel = null;
		
		/**
		 * Initializes a new instance of the Cacheable class with the specified key, lifetime, and optional cacher.
		 * @param Key $key The cache key.
		 * @param int $lifetime The cache lifetime in seconds. Default is Cacher::DEFAULT_LIFETIME.
		 * @param Cacher|null $cacher The cacher instance to use. If null, the default cacher will be used.
		 */
		public function __construct(public readonly Key $key,
									public readonly int $lifetime = Cacher::DEFAULT_LIFETIME,
		                            ?Cacher $cacher = null)
		{
			$cacher ??= Cacher::$default;
			
			if ($cacher === null)
				throw new InvalidArgumentException("No default cacher is set.");
			
			$this->cacher = $cacher;
		}
		
		/**
		 * Destructor to ensure output buffering is properly ended and any locked files are closed.
		 * @throws Throwable
		 */
		public function __destruct()
		{
			if ($this->bufferLevel !== null && ob_get_level() === $this->bufferLevel)
				ob_get_clean();

			$this->closeLockedFile();
		}
		
		/**
		 * Checks if the cache entry exists and is still valid based on its lifetime.
		 * @return bool True if the cache entry exists and is valid; otherwise, false.
		 */
		public function exists(): bool
		{
			return $this->cacher->exists($this->key, $this->lifetime);
		}
		
		/**
		 * Invalidates the cache entry associated with this key.
		 */
		public function invalidate(): void
		{
			$this->cacher->invalidateKey($this->key);
		}
		
		/**
		 * Sets whether the cache generation should occur during the lock period.
		 * @param bool $duringLock Whether to generate the cache during the lock period. Default is true.
		 * @return self The current Cacheable instance for method chaining.
		 */
		public function generateDuringLock(bool $duringLock = true): self
		{
			$this->duringLock = $duringLock;
			return $this;
		}
		
		/**
		 * Gets the cached data or generates it using the provided generator function (if provided).
		 * @throws Throwable if an error occurs during cache generation.
		 * @throws CacheStorageException if there is an error with the cache storage.
		 */
		public function text(callable|Closure|null $generator = null): ?string
		{
			if ($generator === null)
				return $this->cacher->getText($this->key, $this->lifetime);
			return $this->cacher->getOrGenerateText($this->key, $generator, $this->lifetime, true);
		}
		
		/**
		 * Gets the cached (serialized) data or generates it using the provided generator function (if provided).
		 *
		 * @return mixed The cached or generated data.
		 *@throws CacheStorageException if there is an error with the cache storage.
		 * @throws Throwable if an error occurs during cache generation.
		 */
		public function data(callable|Closure|null $generator = null): mixed
		{
			if ($generator === null)
				return $this->cacher->get($this->key, $this->lifetime);
			return $this->cacher->getOrGenerate($this->key, $generator, $this->lifetime, true, $this->duringLock);
		}
		
		/**
		 * Gets the cached HTML text data or begins output buffering if the cache is not found.
		 * If the function returns true, you should generate the HTML output and then call endBuffer().
		 * If it returns false, the cached HTML was found and you simply call endBuffer().
		 *
		 * @return bool
		 * @throws Exception
		 */
		public function beginBuffer(): bool
		{
			if ($this->bufferLevel !== null)
				throw new Exception("Output buffering is already in progress.");
			
			$this->existingBuffer = $this->cacher->getText($this->key, $this->lifetime);
			if ($this->existingBuffer !== null)
				return false;
			
			if ($this->duringLock)
				$this->lockedFile = $this->cacher->acquireLockForWriting($this->key);
			
			if (!ob_start())
				return false;
			
			$this->bufferLevel = ob_get_level();
			return true;
		}
		
		/**
		 * Ends output buffering and either echoes or returns the buffered HTML.
		 * If the cached HTML was found in beginBuffer(), it will be echoed or returned instead.
		 *
		 * @param bool $echo Whether to echo the HTML (true) or return it (false). Default is true.
		 * @return string|null The buffered HTML if $echo is false, or null if echoed or if not buffering.
		 * @throws CacheStorageException if there is an error with the cache storage.
		 * @throws Throwable
		 */
		public function endBuffer(bool $echo = true): ?string
		{
			if ($this->bufferLevel === null)
				return null;
			
			if ($this->bufferLevel !== ob_get_level())
			{
				$this->bufferLevel = null;
				$this->closeLockedFile();
				throw new OutputBufferException("endBuffer() was called but the output buffer level does not match the level when beginBuffer() was called.");
			}
			
			$html = $this->existingBuffer ?? ob_get_clean();
			$this->existingBuffer = null;
			
			if (!is_string($html))
			{
				$this->closeLockedFile();
				return null;
			}
			
			try
			{
				if (is_resource($this->lockedFile))
				{
					try
					{
						if (fwrite($this->lockedFile, $html) === false)
							throw new CacheStorageException("Failed to write data to cache file.");
					}
					finally
					{
						$this->closeLockedFile();
					}
				}
				else
				{
					$this->cacher->store($this->key, $html, serialize: false);
				}
			}
			finally
			{
				if ($echo)
				{
					echo $html;
					return null;
				}
				
				return $html;
			}
		}
		
		/**
		 * Closes the locked file if it is open.
		 * @return void
		 */
		private function closeLockedFile(): void
		{
			if (!$this->lockedFile)
				return;

			flock($this->lockedFile, LOCK_UN);
			fclose($this->lockedFile);
			$this->lockedFile = null;
		}
	}
