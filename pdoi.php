<?php

namespace Pdoi;

use Pdo, PdoException, mysqli, mysqli_stmt, mysqli_result, ReflectionClass, Iterator;

class PdoiException extends PdoException
{
}

class Pdoi extends Pdo
{	private ?mysqli $mysqli = null;
	private ?array $options = null;
	private bool $in_transaction = false;
	private $username, $password, $dbname;

	public function __construct($dsn, $username=null, $password=null, $options=null)
	{	// use mysqli?
		if (function_exists('mysqli_init'))
		{	$pos = strpos($dsn, ':');
			if ($pos > 0)
			{	$driver = trim(substr($dsn, 0, $pos));
				if ($driver == 'mysql')
				{	// yes, use mysqli
					$this->mysqli = mysqli_init();
					// parse DSN
					$host = null;
					$port = null;
					$dbname = null;
					$unix_socket = null;
					foreach (explode(';', substr($dsn, $pos+1)) as $kv)
					{	$pos = strpos($kv, '=');
						$k = strtolower(trim(substr($kv, 0, $pos)));
						$v = trim(substr($kv, $pos+1));
						if ($k == 'host')
						{	$host = $v;
						}
						else if ($k == 'port')
						{	$port = (int)$v;
						}
						else if ($k == 'dbname')
						{	$dbname = $v;
						}
						else if ($k == 'unix_socket')
						{	$unix_socket = $v;
						}
						else if ($k == 'charset')
						{	$this->mysqli->options(\MYSQLI_SET_CHARSET_NAME, $v);
						}
						else
						{	throw new PdoiException("Unknown DSN option: $k");
						}
					}
					// check $host
					if ($unix_socket)
					{	if ($host)
						{	throw new PdoiException("Cannot understand DSN: both host and socket specified");
						}
						$host = 'localhost';
					}
					else if (!$host)
					{	throw new PdoiException("Cannot understand DSN: no host or socket");
					}
					if (($options[PDO::ATTR_PERSISTENT] ?? false) and substr($host, 0, 2)!='p:')
					{	$host = "p:$host";
					}
					// $options to be set before connection
					switch ($options[PDO::ATTR_ERRMODE] ?? PDO::ERRMODE_SILENT)
					{	case PDO::ERRMODE_WARNING: mysqli_report(\MYSQLI_REPORT_ERROR); break;
						case PDO::ERRMODE_EXCEPTION: mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT); break;
					}
					if (($v = $options[PDO::MYSQL_ATTR_LOCAL_INFILE] ?? null) !== null)
					{	$this->mysqli->options(\MYSQLI_OPT_LOCAL_INFILE, $v);
					}
					if (($v = $options[PDO::MYSQL_ATTR_INIT_COMMAND] ?? null))
					{	$this->mysqli->options(\MYSQLI_INIT_COMMAND, $v);
					}
					if (defined('PDO::MYSQL_ATTR_READ_DEFAULT_FILE') and ($v = $options[PDO::MYSQL_ATTR_READ_DEFAULT_FILE] ?? null) !== null)
					{	$this->mysqli->options(\MYSQLI_READ_DEFAULT_FILE, $v);
					}
					if (defined('PDO::MYSQL_ATTR_READ_DEFAULT_GROUP') and ($v = $options[PDO::MYSQL_ATTR_READ_DEFAULT_GROUP] ?? null) !== null)
					{	$this->mysqli->options(\MYSQLI_READ_DEFAULT_GROUP, $v);
					}
					if (($v = $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) !== null)
					{	$this->mysqli->options(\MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, $v);
					}
					// connection flags
					$flags = 0;
					if ($options[PDO::MYSQL_ATTR_FOUND_ROWS] ?? false)
					{	$flags |= \MYSQLI_CLIENT_FOUND_ROWS;
					}
					if ($options[PDO::MYSQL_ATTR_IGNORE_SPACE] ?? false)
					{	$flags |= \MYSQLI_CLIENT_IGNORE_SPACE;
					}
					if ($options[PDO::MYSQL_ATTR_COMPRESS] ?? false)
					{	$flags |= \MYSQLI_CLIENT_COMPRESS;
					}
					// TODO: PDO::MYSQL_ATTR_SSL_CA, PDO::MYSQL_ATTR_SSL_CAPATH, PDO::MYSQL_ATTR_SSL_CERT, PDO::MYSQL_ATTR_SSL_CIPHER, PDO::MYSQL_ATTR_SSL_KEY
					// TODO: PDO::MYSQL_ATTR_MULTI_STATEMENTS, PDO::ATTR_CASE, PDO::ATTR_ORACLE_NULLS, PDO::ATTR_STRINGIFY_FETCHES, PDO::ATTR_STATEMENT_CLASS, PDO::ATTR_TIMEOUT
					// connect
					$this->mysqli->real_connect($host, $username, $password, $dbname, $port, $unix_socket, $flags);
					if ($this->mysqli->connect_error)
					{	throw new PdoiException($this->mysqli->connect_error);
					}
					// store $username, $username and $dbname, to be used in __destruct()
					$this->username = $username;
					$this->password = $password;
					$this->dbname = $dbname;
					// $options to be set after connection
					if (($v = $options[PDO::ATTR_AUTOCOMMIT] ?? null) !== null)
					{	$this->mysqli->autocommit($v);
					}
					// done
					$this->options = is_array($options) ? $options : [];
					return;
				}
			}
		}
		parent::__construct($dsn, $username, $password, $options);
	}

	/**	Important to release locks at the end of usage, and not in the beginning of next session, so other parallel connections will not suffer.
	 **/
	function __destruct()
	{	if ($this->mysqli)
		{	$this->mysqli->change_user($this->username, $this->password, $this->dbname);
		}
	}

	private function failure($default_message)
	{	if (($this->options[PDO::ATTR_ERRMODE] ?? PDO::ERRMODE_SILENT) != PDO::ERRMODE_SILENT)
		{	throw new PdoiException($this->mysqli->error ? $this->mysqli->error : $default_message);
		}
		return false;
	}

	public function beginTransaction(): bool
	{	if (!$this->mysqli)
		{	return (bool)parent::beginTransaction();
		}
		else
		{	if (!$this->mysqli->begin_transaction())
			{	return $this->failure("Failed to start transaction");
			}
			$this->in_transaction = true;
			return true;
		}
	}

	public function commit(): bool
	{	if (!$this->mysqli)
		{	return (bool)parent::commit();
		}
		else
		{	if (!$this->mysqli->commit())
			{	return $this->failure("Failed to commit");
			}
			$this->in_transaction = false;
			return true;
		}
	}

	public function errorCode()
	{	if (!$this->mysqli)
		{	return parent::errorCode();
		}
		else
		{	return $this->mysqli->errno;
		}
	}

	public function errorInfo(): array
	{	if (!$this->mysqli)
		{	return parent::errorInfo();
		}
		else
		{	return [$this->mysqli->sqlstate, $this->mysqli->errno, $this->mysqli->error];
		}
	}

	public function exec($statement)
	{	if (!$this->mysqli)
		{	return parent::exec($statement);
		}
		else
		{	$result = $this->mysqli->query($statement, \MYSQLI_USE_RESULT);
			if ($result === false)
			{	return $this->failure("Query failed");
			}
			if ($result === true)
			{	return $this->mysqli->affected_rows;
			}
			$result->free();
			return 0;
		}
	}

	public function getAttribute($attribute)
	{	if (!$this->mysqli)
		{	return parent::getAttribute($attribute);
		}
		else
		{	switch ($attribute)
			{	case PDO::ATTR_SERVER_VERSION:
					return $this->mysqli->server_info;
				case PDO::ATTR_AUTOCOMMIT:
					return $this->options[PDO::ATTR_AUTOCOMMIT] ?? null;
				// TODO: implement
			}
			return $this->options[PDO::ATTR_AUTOCOMMIT] ?? false;
		}
	}

	public function setAttribute($attribute, $value): bool
	{	if (!$this->mysqli)
		{	return parent::setAttribute($attribute, $value);
		}
		else
		{	$this->options[$attribute] = $value;
			// TODO: implement
			return false;
		}
	}

	public static function getAvailableDrivers(): array
	{	return Pdo::getAvailableDrivers();
	}

	public function inTransaction(): bool
	{	if (!$this->mysqli)
		{	return parent::inTransaction();
		}
		else
		{	return $this->in_transaction;
		}
	}

	public function lastInsertId($name=null): string
	{	if (!$this->mysqli)
		{	return parent::inTransaction();
		}
		else
		{	return $this->mysqli->insert_id;
		}
	}

	public function prepare($statement, $driver_options=null)
	{	if (!$this->mysqli)
		{	return parent::prepare($statement, $driver_options);
		}
		else
		{	// TODO: $driver_options
			return new PdoiStatement($this->mysqli, $this->options, true, null, $statement);
		}
	}

	public function query($statement, $fetch_style=null, $fetch_arg_0=null, $fetch_arg_1=null)
	{	if (!$this->mysqli)
		{	return parent::query($statement);
		}
		else
		{	$buffered = $this->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] ?? true;
			$result = $this->mysqli->query($statement, $buffered ? \MYSQLI_STORE_RESULT : \MYSQLI_USE_RESULT);
			if ($result === false)
			{	return $this->failure("Query failed");
			}
			return new PdoiStatement($this->mysqli, $this->options, false, $result, $statement, $fetch_style, $fetch_arg_0, $fetch_arg_1);
		}
	}

	public function quote($string, $parameter_type=PDO::PARAM_STR): string
	{	if (!$this->mysqli)
		{	return parent::quote($string, $parameter_type);
		}
		else
		{	return "'".$this->mysqli->real_escape_string($string)."'";
		}
	}

	public function rollBack(): bool
	{	if (!$this->mysqli)
		{	return parent::rollBack();
		}
		else
		{	if (!$this->mysqli->rollback())
			{	return $this->failure("Failed to rollback");
			}
			$this->in_transaction = false;
			return true;
		}
	}
}

class PdoiStatement implements Iterator
{	private mysqli $mysqli;
	private array $options;
	private bool $is_prepared_stmt;
	private $result = null; // mysqli_result|true|null
	private string $query_string;
	private ?int $fetch_style = null;
	private $fetch_arg_0;
	private $fetch_arg_1;
	private ?mysqli_stmt $prepared_stmt = null;
	private ?array $placeholders = null;
	private ?array $bound = null;
	private bool $bound_as_names = false;
	private int $current_key = -1;
	private $current = false;
	private ?string $last_error = null;

	// The following constants are given from "php-7.4.14/ext/mysqlnd_enum_n_def.h" - from "typedef enum mysqlnd_field_types"
	private const MYSQL_TYPES =
	[	'0' => 'DECIMAL',
		'1' => 'TINY',
		'2' => 'SHORT',
		'3' => 'LONG',
		'4' => 'FLOAT',
		'5' => 'DOUBLE',
		'6' => 'NULL',
		'7' => 'TIMESTAMP',
		'8' => 'LONGLONG',
		'9' => 'INT24',
		'10' => 'DATE',
		'11' => 'TIME',
		'12' => 'DATETIME',
		'13' => 'YEAR',
		'14' => 'NEWDATE',
		'15' => 'VARCHAR',
		'16' => 'BIT',
		'245' => 'JSON',
		'246' => 'NEWDECIMAL',
		'247' => 'ENUM',
		'248' => 'SET',
		'249' => 'TINY_BLOB',
		'250' => 'MEDIUM_BLOB',
		'251' => 'LONG_BLOB',
		'252' => 'BLOB',
		'253' => 'VAR_STRING',
		'254' => 'STRING',
		'255' => 'GEOMETRY'
	];

	// See "php-7.4.14/ext/pdo_mysql/mysql_statement.c" - "static int pdo_mysql_stmt_col_meta()"
	private const MYSQL_INT_TYPES = ['BIT', 'YEAR', 'TINY', 'SHORT', 'INT24', 'LONG', 'LONGLONG'];

	// The following constants are given from "php-7.4.14/ext/mysqlnd_enum_n_def.h" - from "#define NOT_NULL_FLAG ..."
	private const MYSQL_FIELD_FLAGS =
	[	'not_null' => 1,
		'primary_key' => 2, // this is not "pri_key" - see "php-7.4.14/ext/pdo_mysql/mysql_statement.c" - "static int pdo_mysql_stmt_col_meta()"
		'unique_key' => 4,
		'multiple_key' => 8,
		'blob' => 16,
		'unsigned' => 32,
		'zerofill' => 64,
		'binary' => 128,
		'enum' => 256,
		'auto_increment' => 512,
		'timestamp' => 1024,
		'set' => 2048,
		'no_default_value' => 4096,
		'on_update_now' => 8192,
		'part_key' => 16384,
		'group' => 32768,
		'num' => 32768,
	];

	public function __construct($mysqli=null, $options=null, $is_prepared_stmt=null, $result=null, $query_string=null, $fetch_style=null, $fetch_arg_0=null, $fetch_arg_1=null)
	{	$this->mysqli = $mysqli;
		$this->options = $options;
		$this->is_prepared_stmt = (bool)$is_prepared_stmt;
		$this->result = $result;
		$this->query_string = (string)$query_string;
		$this->fetch_style = $fetch_style;
		$this->fetch_arg_0 = $fetch_arg_0;
		$this->fetch_arg_1 = $fetch_arg_1;
	}

	private function failure($default_message, $error=null)
	{	if (!$error)
		{	$error = $this->mysqli->error ? $this->mysqli->error : $default_message;
		}
		$this->last_error = $error;
		if (($this->options[PDO::ATTR_ERRMODE] ?? PDO::ERRMODE_SILENT) != PDO::ERRMODE_SILENT)
		{	throw new PdoiException($error);
		}
		return false;
	}

	private function success()
	{	$this->last_error = null;
	}

	public function __get($name)
	{	if ($name == 'queryString')
		{	return $this->query_string;
		}
	}

	public function rewind()
	{
	}

	public function current()
	{	return $this->current;
	}

	public function key()
	{	return $this->current_key;
	}

	public function next()
	{	if ($this->current !== false)
		{	$this->current_key++;
			$this->current = false;
		}
	}

	public function valid()
	{	if ($this->current === false)
		{	$this->current = $this->fetch();
			return $this->current !== false;
		}
		return true;
	}

	public function bindColumn($column, &$param, $type=null, $maxlen=null, $driverdata=null): bool
	{	// TODO: ...
		return false;
	}

	public function bindParam($parameter, &$variable, $data_type=PDO::PARAM_STR, $length=null, $driver_options=null): bool
	{	// TODO: $data_type, $length, $driver_options
		if (!$this->is_prepared_stmt)
		{	return $this->failure("Not a prepared statement");
		}
		$want_name = !is_numeric($parameter);
		if ($want_name ? $this->bound && !$this->bound_as_names : $this->bound_as_names)
		{	return $this->failure("Invalid parameter number: mixed named and positional parameters");
		}
		if ($want_name)
		{	$this->bound_as_names = true;
			if (substr($parameter, 0, 1) == ':')
			{	$parameter = substr($parameter, 1);
			}
		}
		else if (!$this->bound)
		{	$this->bound[0] = '';
			if ($parameter < 1)
			{	return $this->failure("Parameter number must be 1-based");
			}
		}
		$this->bound[$parameter] = &$variable;
		return true;
	}

	public function bindValue($parameter, $value, $data_type=PDO::PARAM_STR): bool
	{	// TODO: ...
		return false;
	}

	public function closeCursor(): bool
	{	if ($this->result !== true)
		{	$this->result->free();
		}
		return true;
	}

	public function columnCount(): int
	{	if ($this->result !== true)
		{	return (int)$this->result->field_count;
		}
		return 0;
	}

	public function debugDumpParams()
	{	// TODO: ...
	}

	public function errorCode()
	{	return $this->mysqli->sqlstate;
	}

	public function errorInfo(): array
	{	if ($this->last_error)
		{	return ['42000', -1, $this->last_error];
		}
		else
		{	return [$this->mysqli->sqlstate, $this->mysqli->errno, $this->mysqli->error];
		}
	}

	public function execute($input_parameters=null): bool
	{	if (!$this->is_prepared_stmt)
		{	return $this->failure("Not a prepared statement");
		}
		if (is_array($input_parameters))
		{	foreach (array_keys($input_parameters) as $k)
			{	$parameter = !is_numeric($k) ? $k : $k+1; // bindParam() wants 1-based
				$this->bindParam($parameter, $input_parameters[$k]);
			}
		}
		$lobs = null;
		if ($this->bound)
		{	if ($this->bound_as_names)
			{	if ($this->placeholders === null)
				{	$placeholders = self::find_placeholders_in_sql($this->query_string); // TODO: ansi_quotes, no_backslash_escapes
					$this->placeholders = $placeholders ?? [];
					if ($placeholders === null)
					{	return $this->failure('Cannot use "?" placeholders with named parameters');
					}
				}
				$bound = [''];
				foreach ($this->placeholders as $parameter)
				{	if (!array_key_exists($parameter, $this->bound))
					{	return $this->failure("Parameter \"$parameter\" not bound");
					}
					$bound[] = &$this->bound[$parameter];
				}
				$this->bound = $bound;
			}
			$null = null;
			$types = '';
			for ($i=1, $i_end=count($this->bound)-1; $i<=$i_end; $i++)
			{	if (!array_key_exists($i, $this->bound))
				{	return $this->failure("Parameter #$i not bound");
				}
				if (is_int($this->bound[$i]))
				{	$types .= 'i';
				}
				else if (is_float($this->bound[$i]))
				{	$types .= 'd';
				}
				else if (is_resource($this->bound[$i]))
				{	$types .= 'b';
					$lobs[$i-1] = $this->bound[$i];
					$this->bound[$i] = &$null;
				}
				else
				{	$types .= 's';
				}
			}
			$this->bound[0] = $types;
		}
		if ($this->prepared_stmt === null)
		{	$prepared_stmt = $this->mysqli->prepare($this->query_string);
			if ($prepared_stmt === false)
			{	return $this->failure("Query failed");
			}
			$this->prepared_stmt = $prepared_stmt;
		}
		if ($this->bound)
		{	if (call_user_func_array([$this->prepared_stmt, 'bind_param'], $this->bound) === false)
			{	return $this->failure("Failed to bind parameters", $this->prepared_stmt->error);
			}
			if ($lobs)
			{	foreach ($lobs as $i => $fh)
				{	while (!feof($fh))
					{	$this->prepared_stmt->send_long_data($i, fread($fh, 16*1024));
					}
				}
			}
		}
		$result = (bool)$this->prepared_stmt->execute();
		$this->bound = null;
		$this->bound_as_names = false;
		$this->current_key = -1;
		$this->current = false;
		$resultset = $result ? $this->prepared_stmt->get_result() : null;
		$this->result = $resultset===false ? true : $resultset;
		$this->success();
		return $result;
	}

	private static function find_placeholders_in_sql(&$sql, $ansi_quotes=false, $no_backslash_escapes=false): ?array
	{	$placeholders = [];
		$pos = 0;
		$len = strlen($sql);
		while (true)
		{	$pos += strcspn($sql, '\'"`?:', $pos);
			if ($pos >= $len)
			{	break;
			}
			$qt = $sql[$pos];
			if ($qt == '?')
			{	return null; // Cannot use "?" placeholders with named parameters
			}
			else if ($qt == ':')
			{	$tok_len = strspn($sql, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz', $pos+1);
				if ($tok_len == 0)
				{	$pos++; // just a ':' token (skip it)
				}
				else
				{	// substitute the found placeholder with a '?' char, and add it's name to $placeholders
					$placeholders[] = substr($sql, $pos+1, $tok_len);
					$sql[$pos++] = '?';
					while ($tok_len-- > 0)
					{	$sql[$pos++] = ' ';
					}
				}
			}
			else if ($qt=='`' or $no_backslash_escapes or $qt=='"' && $ansi_quotes)
			{	$pos += strcspn($sql, $qt, $pos+1) + 2; // skip the string
			}
			else
			{	while (true)
				{	$pos += strcspn($sql, "$qt\\", $pos+1) + 1; // go to a quote or to a backslash in '...' or '...\
					if ($pos<$len and $sql[$pos]=='\\')
					{	$pos += 2; // skip the backslash and a char after it
					}
					else
					{	$pos++; // skip closing quote
						break;
					}
				}
			}
		}
		return $placeholders;
	}

	public function fetch($fetch_style=null, $cursor_orientation=PDO::FETCH_ORI_NEXT, $cursor_offset=0)
	{	// TODO: $cursor_orientation, $cursor_offset
		if ($this->result === true)
		{	return false;
		}
		if ($this->result === null)
		{	return $this->failure("Prepared query not executed");
		}
		if ($fetch_style === null)
		{	$fetch_style = $this->fetch_style;
			if ($fetch_style === null)
			{	$fetch_style = $this->options[PDO::ATTR_DEFAULT_FETCH_MODE] ?? PDO::FETCH_BOTH;
			}
		}
		if ($fetch_style == PDO::FETCH_COLUMN)
		{	return $this->fetchColumn($this->fetch_arg_0);
		}
		else if ($fetch_style & PDO::FETCH_CLASS) // can be combined with PDO::FETCH_PROPS_LATE and PDO::FETCH_CLASSTYPE
		{	return $this->fetch_class($fetch_style, $this->fetch_arg_0, $this->fetch_arg_1);
		}
		else if ($fetch_style == PDO::FETCH_OBJ)
		{	$row = $this->result->fetch_object();
		}
		else if ($fetch_style == PDO::FETCH_NUM)
		{	$row = $this->result->fetch_row();
		}
		else if ($fetch_style == PDO::FETCH_ASSOC)
		{	$row = $this->result->fetch_assoc();
		}
		else if ($fetch_style == PDO::FETCH_BOTH)
		{	$row = $this->result->fetch_array();
		}
		else if ($fetch_style == PDO::FETCH_BOUND)
		{	throw new PdoiException("PDO::FETCH_BOUND is not implemented in Pdoi"); // TODO: implement
		}
		else if ($fetch_style == PDO::FETCH_INTO)
		{	throw new PdoiException("PDO::FETCH_INTO is not implemented in Pdoi"); // TODO: implement
		}
		else if ($fetch_style == PDO::FETCH_LAZY)
		{	throw new PdoiException("PDO::FETCH_LAZY is not implemented in Pdoi"); // TODO: implement
		}
		else if ($fetch_style == PDO::FETCH_NAMED)
		{	$row = $this->result->fetch_row();
			if ($row) // if not false or null
			{	$row_named = [];
				foreach ($this->result->fetch_fields() as $i => $f)
				{	if (!array_key_exists($f, $row_named))
					{	$row_named[$f] = $row[$i];
					}
					else if (!is_array($row_named[$f]))
					{	$row_named[$f] = [$row_named[$f], $row[$i]];
					}
					else
					{	$row_named[$f][] = $row[$i];
					}
				}
				return $row_named;
			}
		}
		else
		{	throw new PdoiException("Such fetch type is not implemented in Pdoi");
		}
		if (!$row) // if false or null
		{	if ($row === false)
			{	$this->failure("Failed to fetch row");
			}
			return false;
		}
		return $row;
	}

	public function fetchAll($fetch_style=null, $fetch_arg_0=null, $fetch_arg_1=null)
	{	$all = [];
		if ($fetch_style === null)
		{	$fetch_style = $this->fetch_style;
		}
		if ($fetch_style == PDO::FETCH_COLUMN)
		{	if ($fetch_arg_0 === null)
			{	$fetch_arg_0 = $this->fetch_arg_0;
			}
			while (($row = $this->fetchColumn($fetch_arg_0)) !== false)
			{	$all[] = $row;
			}
		}
		else if ($fetch_style & PDO::FETCH_CLASS) // can be combined with PDO::FETCH_PROPS_LATE and PDO::FETCH_CLASSTYPE
		{	if ($this->fetch_style & PDO::FETCH_CLASS)
			{	if ($fetch_arg_0 === null)
				{	$fetch_arg_0 = $this->fetch_arg_0;
				}
				if ($fetch_arg_1 === null)
				{	$fetch_arg_1 = $this->fetch_arg_1;
				}
			}
			while (($row = $this->fetch_class($fetch_style, $fetch_arg_0, $fetch_arg_1)) !== false)
			{	$all[] = $row;
			}
		}
		else
		{	while (($row = $this->fetch($fetch_style)) !== false)
			{	$all[] = $row;
			}
		}
		return $all;
	}

	public function fetchColumn($column_number=null)
	{	if ($this->result === true)
		{	return false;
		}
		if ($column_number===null and $this->fetch_style===PDO::FETCH_COLUMN)
		{	$column_number = $this->fetch_arg_0;
		}
		$column_number = (int)$column_number;
		$row = $this->result->fetch_row();
		if (!$row) // false or null
		{	if ($row === false)
			{	$this->failure("Failed to fetch row");
			}
			return false;
		}
		if (!array_key_exists($column_number, $row))
		{	return $this->failure("No such column: $column_number");
		}
		return $row[$column_number];
	}

	public function fetchObject($class_name=null, $ctor_args=null)
	{	return $this->fetch_class(0, $class_name, $ctor_args);
	}

	private function fetch_class($fetch_style=0, $class_name=null, $ctor_args=null)
	{	if ($class_name === null)
		{	$class_name = 'stdClass';
		}
		if ($class_name == 'stdClass')
		{	$row = $this->result->fetch_object();
			if (!$row) // false or null
			{	if ($row === false)
				{	$this->failure("Failed to fetch row");
				}
				return false;
			}
			return $row;
		}
		$assoc = $this->result->fetch_assoc();
		if ($assoc === false)
		{	return $this->failure("Failed to fetch row");
		}
		$ref = new ReflectionClass($class_name);
		if ($fetch_style & PDO::FETCH_PROPS_LATE)
		{	$row = $ref->newInstanceArgs($ctor_args ?? []);
		}
		else
		{	$row = $ref->$ref->newInstanceWithoutConstructor();
		}
		foreach ($assoc as $k => $v)
		{	$row->$k = $v;
		}
		if (!($fetch_style & PDO::FETCH_PROPS_LATE))
		{	call_user_func_array([$row, '__construct'], $ctor_args ?? []);
		}
		// TODO: PDO::FETCH_CLASSTYPE
		return $row;
	}

	public function getAttribute($attribute)
	{	// TODO: implement
	}

	public function getColumnMeta($column)
	{	if ($this->result === true)
		{	return false;
		}
		$meta = $this->result->fetch_field_direct($column);
		if ($meta === false)
		{	return false;
		}
		$flags = [];
		foreach (self::MYSQL_FIELD_FLAGS as $k => $v)
		{	if ($meta->flags & $v)
			{	$flags[] = $k;
			}
		}
		$native_type = self::MYSQL_TYPES[$meta->type] ?? null;
		$pdo_type = (self::MYSQL_INT_TYPES[$native_type] ?? false) ? PDO::PARAM_INT : PDO::PARAM_STR;
		return
		[	'native_type' => $native_type,
			'pdo_type' => $pdo_type,
			'flags' => $flags,
			'table' => $meta->table,
			'name' => $meta->name,
			'len' => $meta->length,
			'precision' => $meta->decimals,
		];
	}

	public function nextRowset(): bool
	{	// TODO: implement
		return false;
	}

	public function rowCount()
	{	if ($this->result !== true)
		{	return (int)$this->result->num_rows;
		}
		return 0;
	}

	public function setAttribute($attribute, $value): bool
	{	// TODO: implement
		return false;
	}

	public function setFetchMode($fetch_style, $fetch_arg_0=null, $fetch_arg_1=null): bool
	{	$this->fetch_style = $fetch_style;
		$this->fetch_arg_0 = $fetch_arg_0;
		$this->fetch_arg_1 = $fetch_arg_1;
		return true;
	}
}
