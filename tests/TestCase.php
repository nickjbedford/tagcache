<?php
	
	namespace Tests;
	
	use PHPUnit\Framework\TestCase as PHPUnitTestCase;
	use YetAnother\TagCache\Cacher;
	
	class TestCase extends PHPUnitTestCase
	{
		protected Cacher $cacher;
		
		const string RESULTS_DIR = __DIR__ . '/.test-results';
		
		protected function setUp(): void
		{
			error_reporting(E_ALL);
			$rootDirectory = __DIR__ . DIRECTORY_SEPARATOR . '.caches';
			$this->removeDirectory($rootDirectory);
			$this->cacher = new Cacher($rootDirectory);
			Cacher::$removeNamespaces[] = 'Tests\\';
			
			if (!is_dir(self::RESULTS_DIR))
				mkdir(self::RESULTS_DIR, recursive: true);
		}
		
		protected function tearDown(): void
		{
			parent::tearDown();
			$rootDirectory = __DIR__ . DIRECTORY_SEPARATOR . '.caches';
			$this->removeDirectory($rootDirectory);
		}
		
		/**
		 * Recursively removes a directory and all its contents.
		 * @param string $directory
		 * @return void
		 */
		private function removeDirectory(string $directory): void
		{
			if (!is_dir($directory))
				return;
			
			$items = scandir($directory);
			if ($items === false)
				return;
			
			foreach ($items as $item)
			{
				if ($item === '.' || $item === '..')
					continue;
				
				$path = $directory . DIRECTORY_SEPARATOR . $item;
				if (is_dir($path))
					$this->removeDirectory($path);
				else
					@unlink($path);
			}
			
			@rmdir($directory);
		}
	}
