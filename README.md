# TagCache

TagCache is a PHP data and text file-based caching library designed to make it simple to
generate and store serialized data and text/HTML content for highly efficient retrieval 
when paired with high-performance memory-cached file systems such as on Linux. Cache retrieval
times (including deserialization) can be submillisecond on modern SSD-based infrastructure
using native file systems.

Cache names are created based on tagged keys, such as object type/ID or date ranges,
making it easy to manage and invalidate caches as needed. Symbolic links are used
to create a tag-based directory structure for easy clearing of canonical cache files.

Cache keys are created from a specific name combined with tags againsts objects or dates
related to that cache. When an object is updated, all canonical cache files related to that
object can be cleared easily via the symlink directory structure.

For example:

A cache key named "row-listing" related to "order #123" and "account #21" would create
the canonical cache file of `feb9ed37caf09e97ec0f49f65fccad64.cache` which is typically
stored as its md5 hash :

```text
cache-dir/
└── cache/
    └── en/
        └── feb9ed37caf09e97ec0f49f65fccad64.cache
└── tags/
    └── en/
        └── order/    
            └── 123 -> cache/en/feb9ed37caf09e97ec0f49f65fccad64.cache
        └── account/    
            └── 21  -> cache/en/feb9ed37caf09e97ec0f49f65fccad64.cache
```

## Performance Characteristics

The following performance benchmarks are based on tests run on an 2020 iMac Retina 5K
with the following specifications:

* 3.3GHz Intel Core i5 6-Core processor
* Apple 1TB SSD (APPLE SSD AP1024N Media)
  * Benchmarked at ~2.5GB/s read and write speed
* macOS Sequoia 15.7 (24G222)

### Cache Retrieval (Hit)

TagCache is optimized for read-heavy workloads where cache retrieval speed is critical.
By leveraging modern file systems and fast storage, the cache retrieval process involves
the following steps:

1. Construct the cache key declaration.
2. Generate the canonical MD5 cache key and full path.
3. Check if the file exists.
4. Check if the existing file modification time is within the valid cache duration.
5. Acquire a shared lock on the file for reading.
6. Read the file contents into a `string` variable.
7. Close the file and release the lock.
8. Deserialize the string into an object (if required).

This entire process for a small multi-property PHP object can take as little as
20-30 microseconds (including deserialization) when the operating system has
the file cached in memory. See `tests/PerformanceTests.php` for benchmarks.

### Cache Generation (Miss)

Cache generation introduces some overhead due to the need to create symbolic
links as well as write the cache file. The process involves the following steps:

1. Construct the cache key declaration.
2. Generate the canonical MD5 cache key and full path.
3. Acquire an exclusive lock on the cache directory to prevent race conditions.
4. Generate and serialize the data value to be cached using the supplied generator callable.
5. Write the serialized data to the cache file.
6. Close the file and release the lock.
7. For each tag, create the necessary directories and symbolic links to the cache file.

Despite all of these steps, cache storage and symbolic link creation can still be performed
in as low as 0.5 milliseconds (500μs) for a small multi-property PHP object on modern SSD-based
infrastructure with a modern file system. See `tests/PerformanceTests.php` for benchmarks.
