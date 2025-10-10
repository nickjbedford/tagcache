<?php
	
	namespace Tests;
	
	class OtherObject
	{
		public int $id;
		public string $name = 'Other';
		
		public function __construct(int $id)
		{
			$this->id = $id;
		}
	}
