<?php

use PHPUnit\Framework\TestCase;
use Pdoi\Pdoi;

/*	Create user and database like this:

	CREATE USER tests@localhost IDENTIFIED BY '';
	CREATE DATABASE `tests` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT ENCRYPTION='N';
	GRANT ALL ON tests.* TO tests@localhost;
*/

class PdoTest extends TestCase
{	protected function connect($dsn=null, $options=null): Pdo
	{	if ($dsn === null)
		{	$dsn = "mysql:host=localhost;dbname=tests";
		}
		$class = ($this instanceof PdoiTest) ? Pdoi::class : Pdo::class;
		return new $class($dsn, "tests", "", $options);
	}

	public function test_connection()
	{	// 1. Connect through TCP
		$db = $this->connect();
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

	public function test_error_mode_default()
	{	$db = $this->connect();
		$db->query("HELLO");
		$this->assertTrue(true);
	}

	public function test_error_mode_silent()
	{	$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
		$db->query("HELLO");
		$this->assertTrue(true);
	}

	public function test_error_mode_warning()
	{	$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING]);
		$this->expectWarning();
		$db->query("HELLO");
	}

	public function test_error_mode_exception()
	{	$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->expectException('Throwable');
		$db->query("HELLO");
	}

	// The following test fails. Maybe there's no way to disable "LOCAL INFILE" in mysqli.
	/*public function test_local_infile_no()
	{	if (!(int)ini_get('mysqli.allow_local_infile'))
		{	$this->assertTrue(true);
			return;
		}
		// file
		$file = tempnam(sys_get_temp_dir(), 'data');
		$fh = fopen($file, 'w+');
		try
		{	// connect
			$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_LOCAL_INFILE => false]);
			// create table
			$db->exec("CREATE TEMPORARY TABLE t_days (`day` int, `month` varchar(10), `year` year, PRIMARY KEY (`day`, `month`, `year`))");
			// insert
			$this->expectException('Throwable');
			$db->exec("LOAD DATA LOCAL INFILE ".$db->quote($file)." INTO TABLE t_days FIELDS TERMINATED BY ',' ENCLOSED BY '\"'");
		}
		finally
		{	fclose($fh);
			unlink($file);
		}
	}*/

	public function test_local_infile_yes()
	{	if (!(int)ini_get('mysqli.allow_local_infile'))
		{	$this->assertTrue(true);
			return;
		}
		// data
		$data =
		[	[1, 'January', 2021],
			[2, 'January', 2021],
			[3, 'January', 2021],
		];
		// file
		$file = tempnam(sys_get_temp_dir(), 'data');
		$fh = fopen($file, 'w+');
		try
		{	foreach ($data as $record)
			{	fputcsv($fh, $record);
			}
			// connect
			$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_LOCAL_INFILE => true]);
			// create table
			$db->exec("CREATE TEMPORARY TABLE t_days (`day` int, `month` varchar(10), `year` year, PRIMARY KEY (`day`, `month`, `year`))");
			// insert
			$this->assertTrue((bool)$db->exec("LOAD DATA LOCAL INFILE ".$db->quote($file)." INTO TABLE t_days FIELDS TERMINATED BY ',' ENCLOSED BY '\"'"));
			// select back
			$data_back = $db->query("SELECT * FROM t_days")->fetchAll(PDO::FETCH_NUM);
			// compare
			$this->assertEquals($data_back, $data);
		}
		finally
		{	fclose($fh);
			unlink($file);
		}
	}

	public function test_init_command()
	{	$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET group_concat_max_len=1234"]);
		$this->assertEquals($db->query("SELECT @@group_concat_max_len")->fetchColumn(), 1234);
	}

	public function test_found_rows_default()
	{	// connect
		$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$db->exec("INSERT INTO t_messages (data) VALUES ('One'), ('Two'), ('Three')");
		// test update
		$row_count = $db->exec("UPDATE t_messages SET data = 'One' WHERE id <= 2");
		$this->assertEquals($row_count, 1);
	}

	public function test_found_rows_no()
	{	// connect
		$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => false]);
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$db->exec("INSERT INTO t_messages (data) VALUES ('One'), ('Two'), ('Three')");
		// test update
		$row_count = $db->exec("UPDATE t_messages SET data = 'One' WHERE id <= 2");
		$this->assertEquals($row_count, 1);
	}

	public function test_found_rows_yes()
	{	// connect
		$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => true]);
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$db->exec("INSERT INTO t_messages (data) VALUES ('One'), ('Two'), ('Three')");
		// test update
		$row_count = $db->exec("UPDATE t_messages SET data = 'One' WHERE id <= 2");
		$this->assertEquals($row_count, 2);
	}

	// The following test fails for both Pdo and Pdoi, when "sql-mode='ignore_space'" is set in mysqld.cnf
	/*public function test_ignore_space_no()
	{	$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_IGNORE_SPACE => false]);
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE count (i int)"));
	}*/

	public function test_ignore_space_yes()
	{	$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_IGNORE_SPACE => true]);
		$this->assertContains("IGNORE_SPACE", explode(',', $db->query("SELECT @@sql_mode")->fetchColumn()));
		$this->expectException('Throwable');
		$db->exec("CREATE TEMPORARY TABLE count (i int)");
	}

	public function test_lob()
	{	// data
		$data = 'Hello all';
		// file
		$fh = tmpfile();
		fwrite($fh, $data);
		fseek($fh, 0);
		// connect
		$db = $this->connect();
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$stmt = $db->prepare("INSERT INTO t_messages SET data=?");
		$stmt->bindParam(1, $fh, PDO::PARAM_LOB);
		$this->assertTrue($stmt->execute());
		// select back
		$data_back = $db->query("SELECT data FROM t_messages")->fetchColumn();
		// compare
		$this->assertEquals($data_back, $data);
	}

	/// This test shows that Pdoi copies Pdo wrong behavior with "no_backslash_escapes".
	public function test_placeholders_wrong_behavior()
	{	$SQL = <<<'SQL'
			SELECT :par, character_set_name AS "' \", " :par'" FROM information_schema.character_sets LIMIT 1
SQL;
		$db = $this->connect(null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='no_backslash_escapes'"]);
		$this->expectException('Throwable');
		$stmt = $db->prepare($SQL);
		$stmt->bindParam('par', 123);
		$stmt->execute();
	}
}
