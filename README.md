# Pdoi - The Improved PDO

This library provides class whose API matches `PDO` API, and can be used as `PDO` replacement with little application changes.
This class is called `Pdoi`.
It supports most (but not all) `PDO` features.
For drivers other than MySQL, it works exactly like `PDO` (function calls are forwarded to internal `PDO` instance), and for MySQL it explicitly uses `mysqli` extension.

This is to achieve 2 goals:

1. Saner connections pooling.
2. Access to `mysqli`-specific features.

## About database connections pooling

As of January 2021, my eyes don't see usable database connections pooling solution in PHP world.

Persistent connections can be created with `mysqli` using "p:" prefix for hostname. And with `PDO` they can be created with help of `PDO::ATTR_PERSISTENT` attribute.
Together with PHP-FPM process pool this can build up the true database connections pooling system.

This worked even on PHP4, but nobody practically used them, because there are problems reusing a dirty connection.

1. `SET @flag=1` issued once, will be initially set on next connection usage.
2. Started and not committed transactions will hold locks on touched rows.
3. `SELECT Get_lock('Busy', 10)` will prevent other parallel connections in pool from entering critical section.

Current persistent connections implementation in `mysqli` and `PDO`, as of January 2021, resets connection state by calling `mysqli_change_user()` function. But my experience reveals that user-locks are not released. I suppose that this function is called when a connection is picked up from pool, before using it. So whole time period since the connection was left alone, and till it's new usage (that can not happen at all), locks are locking what they want to lock.

Each connection should be reset immediately at script end. This library calls `mysqli_change_user()` in destructor of `Pdoi` object. What i love in PHP is reference-counting objects, and that destructors are called immediately when the object is not in use.

## Example

```php
<?php

use Pdoi\Pdoi as Pdo;

require_once __DIR__.'/../Packages/Pdoi/index.php';

$db = new Pdo
(	'mysql:host=localhost',
	'root',
	'',
	[	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
		PDO::ATTR_PERSISTENT => true,
	]
);

var_dump($db->query("SELECT Connection_id()")->fetchColumn());
```
