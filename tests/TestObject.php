<?php
	
	namespace Tests;
	
	class TestObject
	{
		public int $id;
		public string $name = 'Test';
		
		public function __construct(int $id)
		{
			$this->id = $id;
		}
	}
