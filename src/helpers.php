<?php
	namespace YetAnother\TagCache;
	
	use DirectoryIterator;
	use FilesystemIterator;
	use RecursiveDirectoryIterator;
	use RecursiveIteratorIterator;
	
	/**
	 * Sanitizes a string to be used as a safe filename by replacing unsafe characters with underscores.
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
	 * Creates a cache key with the given name and tagged objects.
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
	 * Garbage collects symlinks in the tag directory that point to non-existent cache files,
	 * optionally removing empty tag directories.
	 * @param Cacher $cacher The Cacher instance to clean.
	 * @return int The number of symlinks removed.
	 */
	function gc_symlinks(Cacher $cacher): int
	{
		$directory = $cacher->tagDirectory;
		
		$countRemoved = 0;
		$iterator1 = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
		
		foreach ($iterator1 as $item1)
		{
			if (!$item1->isDir())
				continue;
			
			$iterator2 = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO);
			$contentsCount = 0;
			
			foreach ($iterator2 as $item2)
			{
				if ($item2->isDir())
				{
					$contentsCount++;
					continue;
				}
				
				if ($item2->isLink())
				{
					$target = readlink($item2->getPathname());
					if ($target === false || !file_exists($target))
					{
						@unlink($item2->getPathname());
						$countRemoved++;
					}
					else
						$contentsCount++;
				}
				else
					$contentsCount++;
			}
			
			if ($contentsCount == 0)
			{
				@rmdir($directory);
			}
		}
		
		return $countRemoved;
	}
