<?php

use PHPUnit\Framework\TestCase;
use Pdoi\Pdoi;

/*	Create user and database like this:

	CREATE USER tests@localhost IDENTIFIED BY '';
	CREATE DATABASE `tests` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT ENCRYPTION='N';
	GRANT ALL ON tests.* TO tests@localhost;
*/

class PdoTest extends TestCase
{	protected function connect($dsn, $options=null)
	{	$class = ($this instanceof PdoiTest) ? Pdoi::class : Pdo::class;
		return new $class($dsn, "tests", "", $options);
	}

	public function test_connection()
	{	// 1. Connect through TCP
		$db = $this->connect("mysql:host=localhost;dbname=tests");
		$this->assertIsObject($db);

		// 2. Simple query
		$socket = $db->query("SELECT @@socket")->fetchColumn();
		$this->assertIsString($socket);

		// 3. Connect through unix-domain socket
		$db = $this->connect("mysql:unix_socket=$socket;dbname=tests");
		$this->assertIsObject($db);
	}

	public function test_charset()
	{	$db = $this->connect("mysql:host=localhost;dbname=tests;charset=binary");
		$this->assertEquals($db->query("SELECT @@character_set_connection")->fetchColumn(), "binary");
	}

	public function test_error_mode_silent()
	{	$db = $this->connect("mysql:host=localhost;dbname=tests", [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$db->query("HELLO");
		$this->assertTrue(true);
	}

	public function test_error_mode_warning()
	{	$db = $this->connect("mysql:host=localhost;dbname=tests", [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
		$this->expectWarning();
		$db->query("HELLO");
	}

	public function test_error_mode_exception()
	{	$db = $this->connect("mysql:host=localhost;dbname=tests", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->expectException('Throwable');
		$db->query("HELLO");
	}
}
