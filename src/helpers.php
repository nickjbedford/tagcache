<?php
	namespace YetAnother\TagCache;
	
	/**
	 * Sanitizes a string to be used as a safe filename by replacing unsafe characters with underscores.
	 * This method is used internally to ensure cache keys are valid filenames.
	 *
	 * @param string $basename
	 * @return string
	 */
	function sanitize_cache_key(string $basename): string
	{
		$basename = preg_replace('/[^a-z0-9+_.\-]/i', '_', strtolower($basename));
		return str_replace(['\\', '..'], '_', $basename);
	}
	
	/**
	 * Creates a cache Key object with the given name and tagged objects.
	 *
	 * @param string $name The base name for the cache key.
	 * @param object ...$objects Objects to tag the cache key with (using the $id property on each object).
	 * @return Key The generated cache key.
	 */
	function cache_key(string $name, object ...$objects): Key
	{
		return new Key($name)->with($objects);
	}
	
	/**
	 * Creates a global Cacheable object with the specified name and lifetime.
	 *
	 * @param string $name The name of the cacheable item.
	 * @param int $lifetime The lifetime of the cacheable item in seconds.
	 * @param Cacher|null $cacher Optional. Cacher instance to use for caching, otherwise the default.
	 * @return Cacheable
	 */
	function global_cache(string $name, int $lifetime = Cacher::DEFAULT_LIFETIME, ?Cacher $cacher = null): Cacheable
	{
		return cache_key($name)->cacheable($lifetime, $cacher);
	}
