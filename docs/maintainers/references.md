# Technical references

Primary vendor documentation is preferred for compatibility decisions. Access
dates and minimal reproductions belong in the evidence record for each accepted
deployment-sensitive policy.

## PHP and PDO

- [Supported PHP versions](https://www.php.net/supported-versions.php)
- [PDO manual](https://www.php.net/manual/en/book.pdo.php)
- [PDO prepare contract](https://www.php.net/manual/en/pdo.prepare.php)
- [PDO connections and persistence](https://www.php.net/manual/en/pdo.connections.php)
- [PDO transactions](https://www.php.net/manual/en/pdo.transactions.php)
- [`PDO::lastInsertId()`](https://www.php.net/manual/en/pdo.lastinsertid.php)
- [`PDOStatement::rowCount()`](https://www.php.net/manual/en/pdostatement.rowcount.php)
- [PDO MySQL driver](https://www.php.net/manual/en/ref.pdo-mysql.php)
- [PDO SQLite driver](https://www.php.net/manual/en/ref.pdo-sqlite.php)

## MariaDB

- [MariaDB and MySQL compatibility differences](https://mariadb.com/docs/release-notes/community-server/about/compatibility-and-differences/mariadb-vs-mysql-compatibility)
- [Prepared statements](https://mariadb.com/docs/server/reference/sql-statements/prepared-statements)
- [MariaDB 11.8 LTS announcement](https://mariadb.org/11-8-is-lts/)

## MySQL

- [Prepared statements](https://dev.mysql.com/doc/refman/8.0/en/sql-prepared-statements.html)
- [SQL modes](https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html)
- [Implicit commits](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html)
- [Savepoints](https://dev.mysql.com/doc/refman/8.0/en/savepoint.html)

## SQLite

- [Locking](https://sqlite.org/lockingv3.html)
- [Write-ahead logging](https://sqlite.org/wal.html)
- [Isolation](https://sqlite.org/isolation.html)
- [Foreign keys](https://sqlite.org/foreignkeys.html)
- [Type affinity](https://www.sqlite.org/datatype3.html)
- [Limits](https://sqlite.org/limits.html)
- [In-memory databases](https://sqlite.org/inmemorydb.html)
- [PRAGMA statements](https://sqlite.org/pragma.html)
- [SQLite quirks](https://sqlite.org/quirks.html)

## ProxySQL and MaxScale

- [ProxySQL multiplexing](https://proxysql.com/documentation/multiplexing/)
- [ProxySQL prepared-statement pooling](https://proxysql.com/documentation/mysql-prepared-statements-pooling-and-caching/)
- [MaxScale read/write splitter](https://mariadb.com/docs/maxscale/reference/maxscale-routers/maxscale-readwritesplit)

Documentation can change. Tests govern only the exact versions and
configuration published in the supported compatibility matrix.
