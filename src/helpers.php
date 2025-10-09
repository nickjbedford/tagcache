<?php
	use YetAnother\TagCache\Key;
	
	/**
	 * Sanitizes a string to be used as a safe filename by replacing unsafe characters with underscores.
	 * @param string $basename
	 * @return string
	 */
	function sanitize_cache_key_filename(string $basename): string
	{
		$basename = preg_replace('/[^a-z0-9+_.\-]/i', '_', strtolower($basename));
		return str_replace(['\\', '..'], '_', $basename);
	}
	
	/**
	 * Creates a cache key with the given name and tagged objects.
	 * @param string $name The base name for the cache key.
	 * @param object ...$tags Objects to tag the cache key with (using the $id property on each object).
	 * @return Key The generated cache key.
	 */
	function tckey(string $name, object... $tags): Key
	{
		return new Key($name)->with(...$tags);
	}
	
