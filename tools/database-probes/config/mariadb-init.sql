-- Synthetic users for the disposable database-proxy fixtures.
CREATE USER IF NOT EXISTS 'simplequery_monitor'@'%' IDENTIFIED BY 'simplequery-monitor';
GRANT RELOAD, PROCESS, SHOW DATABASES, REPLICATION SLAVE, REPLICATION CLIENT, SLAVE MONITOR
    ON *.* TO 'simplequery_monitor'@'%';

CREATE USER IF NOT EXISTS 'simplequery_maxscale'@'%' IDENTIFIED BY 'simplequery-maxscale';
GRANT SELECT ON mysql.* TO 'simplequery_maxscale'@'%';
GRANT SHOW DATABASES ON *.* TO 'simplequery_maxscale'@'%';

FLUSH PRIVILEGES;
