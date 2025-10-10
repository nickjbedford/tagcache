<?php
	
	namespace YetAnother\TagCache;
	
	/**
	 * Represents a cache key generate from tags. All keys will be stored in lowercase.
	 */
	class Key
	{
		private(set) array $tags = [];
		private(set) string $name;
		private(set) bool $global = false;
		
		/**
		 * Initializes a new instance of the Key class with the specified name.
		 * @param string $name The name of the cache key to be used as a key suffix.
		 */
		public function __construct(string $name)
		{
			$this->name = sanitize_cache_key($name);
		}
		
		/**
		 * Marks the cache as global, meaning it is tied to a global data tag that can be cleared with other global caches.
		 * @return self The current Key instance for method chaining.
		 */
		public function global(bool $global = true): self
		{
			if ($global)
				$this->tags['global'] = 0;
			else
				unset($this->tags['global']);
			return $this;
		}
		
		/**
		 * Tags the key with a date using the specified format. Default format is 'Ymd'.
		 * @param int|string $timestamp The date as a timestamp or date string.
		 * @param string $key The key to use for the date tag. Default is 'date'.
		 * @param string $format The date format to use. Default is 'Ymd'.
		 * @return self The current Key instance for method chaining.
		 */
		public function dated(int|string $timestamp, string $key = 'date', string $format = 'Ymd'): self
		{
			if (is_string($timestamp))
				$timestamp = strtotime($timestamp) ?: 0;
			return $this->tag($key, date($format, $timestamp));
		}
		
		/**
		 * Tags the key with a date range using 'datefrom' and 'dateto' tags. Default format is 'Ymd'.
		 * @param int|string $from The start date as a timestamp or date string.
		 * @param int|string $to The end date as a timestamp or date string.
		 * @return self The current Key instance for method chaining.
		 */
		public function dateRange(int|string $from, int|string $to, string $format = 'Ymd'): self
		{
			if (is_string($from))
				$from = strtotime($from) ?: 0;
			if (is_string($to))
				$to = strtotime($to) ?: 0;
			
			return $this
				->tag('datefrom', date($format, $from))
				->tag('dateto', date($format, $to));
		}
		
		/**
		 * Tags the key with an object using its class name and a key property. Default is $id.
		 * @param object $object The object to tag the cache with.
		 * @param string $property The property of the object to use as the identifier. Default is 'id'.
		 * @param bool $classBasename If true, only the class basename will be used (without namespace). Default is false.
		 * @return self The current Key instance for method chaining.
		 */
		public function object(object $object, string $property = 'id', bool $classBasename = false): self
		{
			$type = $object::class;
			if ($classBasename)
			{
				$parts = explode('\\', $type);
				$type = end($parts);
			}
			return $this->tag($type, $object->{$property} ?: 0);
		}
		
		/**
		 * Tags the key with multiple objects using their class names and a key property. Default is $id.
		 * @param array<int, object> $objects The objects to tag the cache with.
		 * @return self The current Key instance for method chaining.
		 */
		public function with(array|object $objects, string $property = 'id', bool $classBasename = false): self
		{
			if (!is_array($objects))
				$objects = [$objects];
			
			foreach ($objects as $object)
				$this->object($object, $property, $classBasename);
			return $this;
		}
		
		/**
		 * Tags the key with a type and identifier.
		 * @param string $type The type or class name to tag the cache with.
		 * @param string|int|null $id The identifier for the type.
		 * @return self The current Key instance for method chaining.
		 */
		public function tag(string $type, string|int|null $id): self
		{
			foreach(Cacher::$removeNamespaces as $namespace)
			{
				$type = str_replace($namespace, '', $type);
			}
			
			$type = sanitize_cache_key(ltrim($type, '\\'));
			$id = sanitize_cache_key(strval($id ?? 0));
			
			assert(!empty($type), 'Type cannot be empty');
			assert($id !== '', 'ID cannot be empty');
			
			$this->tags[$type] = $id;
			return $this;
		}
		
		/**
		 * @var string $key The generated cache key based on the name and tags.
		 */
		public string $key
		{
			get
			{
				ksort($this->tags);
				foreach ($this->tags as $type => $id)
					$parts[] = "{$type}_$id";
				$parts[] = $this->name;
				return implode('-', $parts);
			}
		}
	}
