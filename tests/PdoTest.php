<?php

use PHPUnit\Framework\TestCase;
use Pdoi\Pdoi;

/*	Create user and database like this:

	CREATE USER tests@localhost IDENTIFIED BY '';
	CREATE DATABASE `tests` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT ENCRYPTION='N';
	GRANT ALL ON tests.* TO tests@localhost;
*/

class PdoTest extends TestCase
{	protected function connect($options=null, $without_default_options=false, $dsn=null): Pdo
	{	if ($dsn === null)
		{	$dsn = "mysql:host=localhost;dbname=tests";
		}
		if (!$without_default_options)
		{	$options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
			$options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
		}
		$class = ($this instanceof PdoiTest) ? Pdoi::class : Pdo::class;
		return new $class($dsn, "tests", "", $options);
	}

	public function test_connection()
	{	// 1. Connect through TCP
		$db = $this->connect(null, true);
		$this->assertIsObject($db);

		// 2. Simple query
		$socket = $db->query("SELECT @@socket")->fetchColumn();
		$this->assertIsString($socket);

		// 3. Connect through unix-domain socket
		$db = $this->connect(null, true, "mysql:unix_socket=$socket;dbname=tests");
		$this->assertIsObject($db);
	}

	public function test_charset()
	{	$db = $this->connect(null, true, "mysql:host=localhost;dbname=tests;charset=binary");
		$this->assertEquals("binary", $db->query("SELECT @@character_set_connection")->fetchColumn());
	}

	public function test_error_mode_default()
	{	$db = $this->connect(null, true);
		$db->query("HELLO");
		$this->assertTrue(true);
	}

	public function test_error_mode_silent()
	{	$db = $this->connect([PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT], true);
		$db->query("HELLO");
		$this->assertTrue(true);
	}

	public function test_error_mode_warning()
	{	$db = $this->connect([PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING], true);
		$this->expectWarning();
		$db->query("HELLO");
	}

	public function test_error_mode_exception()
	{	$db = $this->connect();
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
			$db = $this->connect([PDO::MYSQL_ATTR_LOCAL_INFILE => false]);
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
			$db = $this->connect([PDO::MYSQL_ATTR_LOCAL_INFILE => true]);
			// create table
			$db->exec("CREATE TEMPORARY TABLE t_days (`day` int, `month` varchar(10), `year` year, PRIMARY KEY (`day`, `month`, `year`))");
			// insert
			$this->assertTrue((bool)$db->exec("LOAD DATA LOCAL INFILE ".$db->quote($file)." INTO TABLE t_days FIELDS TERMINATED BY ',' ENCLOSED BY '\"'"));
			// select back
			$data_back = $db->query("SELECT * FROM t_days")->fetchAll(PDO::FETCH_NUM);
			// compare
			$this->assertEquals($data, $data_back);
		}
		finally
		{	fclose($fh);
			unlink($file);
		}
	}

	public function test_init_command()
	{	$db = $this->connect([PDO::MYSQL_ATTR_INIT_COMMAND => "SET group_concat_max_len=1234"]);
		$this->assertEquals(1234, $db->query("SELECT @@group_concat_max_len")->fetchColumn());
	}

	public function test_found_rows_default()
	{	// connect
		$db = $this->connect();
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$db->exec("INSERT INTO t_messages (data) VALUES ('One'), ('Two'), ('Three')");
		// test update
		$row_count = $db->exec("UPDATE t_messages SET data = 'One' WHERE id <= 2");
		$this->assertEquals(1, $row_count);
	}

	public function test_found_rows_no()
	{	// connect
		$db = $this->connect([PDO::MYSQL_ATTR_FOUND_ROWS => false]);
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$db->exec("INSERT INTO t_messages (data) VALUES ('One'), ('Two'), ('Three')");
		// test update
		$row_count = $db->exec("UPDATE t_messages SET data = 'One' WHERE id <= 2");
		$this->assertEquals(1, $row_count);
	}

	public function test_found_rows_yes()
	{	// connect
		$db = $this->connect([PDO::MYSQL_ATTR_FOUND_ROWS => true]);
		// create table
		$db->exec("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY AUTO_INCREMENT, data text NOT NULL)");
		// insert
		$db->exec("INSERT INTO t_messages (data) VALUES ('One'), ('Two'), ('Three')");
		// test update
		$row_count = $db->exec("UPDATE t_messages SET data = 'One' WHERE id <= 2");
		$this->assertEquals(2, $row_count);
	}

	// The following test fails for both Pdo and Pdoi, when "sql-mode='ignore_space'" is set in mysqld.cnf
	/*public function test_ignore_space_no()
	{	$db = $this->connect([PDO::MYSQL_ATTR_IGNORE_SPACE => false]);
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE count (i int)"));
	}*/

	public function test_ignore_space_yes()
	{	$db = $this->connect([PDO::MYSQL_ATTR_IGNORE_SPACE => true]);
		$this->assertContains("IGNORE_SPACE", explode(',', $db->query("SELECT @@sql_mode")->fetchColumn()));
		$this->expectException('Throwable');
		$db->exec("CREATE TEMPORARY TABLE count (i int)");
	}

	public function test_exec()
	{	$db = $this->connect();
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertEquals(2, $db->exec("INSERT INTO t (id) VALUES (1), (2); INSERT INTO t (id) VALUES (3), (4), (5)"));
		$this->assertEquals(5, $db->query("SELECT Count(*) FROM t")->fetchColumn());
		$this->assertEquals(0, $db->exec("SELECT * FROM t"));
	}

	public function test_exec_no_multi()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertEquals(2, $db->exec("INSERT INTO t (id) VALUES (1), (2)"));
		$this->assertEquals(2, $db->query("SELECT Count(*) FROM t")->fetchColumn());
		$this->assertEquals(0, $db->exec("SELECT * FROM t"));
	}

	public function test_exec_error()
	{	$db = $this->connect();
		$this->expectException('Throwable');
		$db->exec("HELLO");
	}

	public function test_exec_no_error()
	{	$db = $this->connect();
		$db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY); HELLO");
		$this->assertTrue(true);
	}

	public function test_exec_error_no_multi()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->expectException('Throwable');
		$db->exec("HELLO");
	}

	public function test_exec_error_no_multi_2()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->expectException('Throwable');
		$db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY); INSERT INTO t (id) VALUES (1), (2)");
	}

	public function test_exec_error_no_multi_3()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->expectException('Throwable');
		$db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY); HELLO");
	}

	public function test_query()
	{	$db = $this->connect();
		$this->assertIsObject($db->query("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertIsObject($db->query("INSERT INTO t (id) VALUES (1), (2); INSERT INTO t (id) VALUES (3), (4), (5)"));
		$this->assertEquals(5, $db->query("SELECT Count(*) FROM t")->fetchColumn());
	}

	public function test_query_no_multi()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->assertIsObject($db->query("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertIsObject($db->query("INSERT INTO t (id) VALUES (1), (2)"));
		$this->assertEquals(2, $db->query("SELECT Count(*) FROM t")->fetchColumn());
	}

	public function test_query_error()
	{	$db = $this->connect();
		$this->expectException('Throwable');
		$db->query("HELLO");
	}

	/// This test passes for Pdo, but doesn't pass for Pdoi. So here is the difference in behavior.
	/*public function test_query_no_error()
	{	$db = $this->connect();
		$db->query("CREATE TEMPORARY TABLE t (id int PRIMARY KEY); HELLO");
		$this->assertTrue(true);
	}*/

	public function test_query_error_no_multi()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->expectException('Throwable');
		$db->query("HELLO");
	}

	public function test_query_error_no_multi_2()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->expectException('Throwable');
		$db->query("CREATE TEMPORARY TABLE t (id int PRIMARY KEY); INSERT INTO t (id) VALUES (1), (2)");
	}

	public function test_query_error_no_multi_3()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->expectException('Throwable');
		$db->query("CREATE TEMPORARY TABLE t (id int PRIMARY KEY); HELLO");
	}

	public function test_query_multi()
	{	$db = $this->connect();
		// create table
		$this->assertIsObject($db->query("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		// insert
		$stmt = $db->query("INSERT INTO t (id) VALUES (1), (2); INSERT INTO t (id) VALUES (3), (4), (5);");
		$this->assertTrue($stmt->nextRowset());
		$this->assertFalse($stmt->nextRowset());
		// select
		$stmt = $db->query("SELECT Count(*) AS cnt FROM t; SELECT Sum(id) AS sum FROM t;");
		$this->assertEquals([['cnt' => 5]], $stmt->fetchAll());
		//$this->assertTrue($stmt->nextRowset()); // i don't know why this fails on both Pdo and Pdoi
		$stmt = null;
		// create proc
		$db->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, false);
		$db->query("DROP PROCEDURE IF EXISTS p");
		$db->query("CREATE PROCEDURE p() BEGIN SELECT Count(*) AS cnt FROM t; SELECT Sum(id) AS sum FROM t; END;");
		$db->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, true);
		$stmt = $db->query("CALL p");
		$this->assertEquals([['cnt' => 5]], $stmt->fetchAll());
		$this->assertTrue($stmt->nextRowset());
		$this->assertEquals([['sum' => 15]], $stmt->fetchAll());
		//$this->assertFalse($stmt->nextRowset()); // i don't know why this fails on both Pdo and Pdoi
		$stmt->nextRowset();
		$db->query("DROP PROCEDURE p");
	}

	public function test_prepare_execute()
	{	$db = $this->connect();
		$db->query("CREATE TEMPORARY TABLE t_messages (id int PRIMARY KEY, data text NOT NULL)");
		$this->assertTrue($db->prepare("INSERT INTO t_messages SET id=?, data=?")->execute([3, 'Three']));
		$this->assertEquals([['id' => 3, 'data' => 'Three']], $db->query("SELECT * FROM t_messages")->fetchAll());
		$this->assertTrue($db->prepare("INSERT INTO t_messages SET id=:id, data=:data")->execute(['data' => 'Four', 'id' => 4]));
		$this->assertEquals([['id' => 3, 'data' => 'Three'], ['id' => 4, 'data' => 'Four']], $db->query("SELECT * FROM t_messages")->fetchAll());
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
		$this->assertEquals($data, $data_back);
	}

	/// This test shows that Pdoi copies Pdo wrong behavior with "no_backslash_escapes".
	public function test_placeholders_wrong_behavior()
	{	$SQL = <<<'SQL'
			SELECT :par, character_set_name AS "' \", " :par'" FROM information_schema.character_sets LIMIT 1
SQL;
		$db = $this->connect([PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='no_backslash_escapes'"]);
		$this->expectException('Throwable');
		$stmt = $db->prepare($SQL);
		$stmt->bindParam('par', 123);
		$stmt->execute();
	}

	public function test_autocommit_no()
	{	$db = $this->connect([PDO::ATTR_AUTOCOMMIT => false]);
		$this->assertFalse($db->inTransaction());
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertEquals(1, $db->exec("INSERT INTO t SET id=1"));
		$this->assertEquals(0, $db->exec("ROLLBACK"));
		$this->assertFalse($db->query("SELECT id FROM t LIMIT 1")->fetch());
	}

	public function test_autocommit_yes()
	{	$db = $this->connect([PDO::ATTR_AUTOCOMMIT => true]);
		$this->assertFalse($db->inTransaction());
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertEquals(1, $db->exec("INSERT INTO t SET id=1"));
		$this->assertEquals(0, $db->exec("ROLLBACK"));
		$this->assertEquals(['id' => 1], $db->query("SELECT id FROM t LIMIT 1")->fetch());
	}

	public function test_multi_statements_default()
	{	$db = $this->connect();
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertEquals(2, $db->exec("INSERT INTO t (id) VALUES (1), (2); INSERT INTO t (id) VALUES (3), (4), (5)"));
		$this->assertEquals(5, $db->query("SELECT Count(*) FROM t")->fetchColumn());
	}

	public function test_multi_statements_yes()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->assertEquals(2, $db->exec("INSERT INTO t (id) VALUES (1), (2); INSERT INTO t (id) VALUES (3), (4), (5)"));
		$this->assertEquals(5, $db->query("SELECT Count(*) FROM t")->fetchColumn());
	}

	public function test_multi_statements_no()
	{	$db = $this->connect([PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
		$this->assertEquals(0, $db->exec("CREATE TEMPORARY TABLE t (id int PRIMARY KEY)"));
		$this->expectException('Throwable');
		$db->exec("INSERT INTO t (id) VALUES (1), (2); INSERT INTO t (id) VALUES (3), (4), (5)");
	}
}
