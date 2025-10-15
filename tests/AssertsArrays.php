<?php
	
	namespace Tests;
	
	/**
	 * @mixin TestCase
	 */
	trait AssertsArrays
	{
		/**
		 * Asserts that an associative array has identical keys and values.
		 *
		 * @param array $expected
		 * @param array $actual
		 * @param string $message
		 * @param bool $checkKeys
		 */
		public function assertArrayIsIdentical(array $expected, array $actual, string $message = '', bool $checkKeys = true): void
		{
			foreach($expected as $key=>$expectedValue)
			{
				$this->assertArrayHasKey($key, $actual, $message ?: $key);
				
				$actualValue = $actual[$key];

				if (is_array($expectedValue))
				{
					$this->assertIsArray($actualValue, $message ?: $key);
					$this->assertArrayIsIdentical($expectedValue, $actualValue, $checkKeys, $message ?: $key);
				}
				else if (is_object($expectedValue))
				{
					$this->assertIsObject($actualValue, $message ?: $key);
					
					if (enum_exists($expectedValue::class))
					{
						$this->assertSame($expectedValue, $actualValue);
					}
					else
					{
						$this->assertObjectEquals($expectedValue, $actualValue, message: $message ?: $key);
					}
				}
				else
				{
					$this->assertEquals($expectedValue, $actualValue, $message ?: $key);
				}
			}

			if ($checkKeys)
			{
				foreach($actual as $key=>$actualValue)
				{
					$this->assertArrayHasKey($key, $expected, $message ?: $key);
				}
			}
		}
	}
