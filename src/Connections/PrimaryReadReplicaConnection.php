<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Connections;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Events;
use InvalidArgumentException;

use function array_rand;
use function assert;
use function count;
use function func_get_args;

/**
 * Primary-Replica Connection
 *
 * Connection can be used with primary-replica setups.
 *
 * Important for the understanding of this connection should be how and when
 * it picks the replica or primary.
 *
 * 1. Replica if primary was never picked before and ONLY if 'getWrappedConnection'
 *    or 'executeQuery' is used.
 * 2. Primary picked when 'exec', 'executeUpdate', 'insert', 'delete', 'update', 'createSavepoint',
 *    'releaseSavepoint', 'beginTransaction', 'rollback', 'commit', 'query' or
 *    'prepare' is called.
 * 3. If Primary was picked once during the lifetime of the connection it will always get picked afterwards.
 * 4. One replica connection is randomly picked ONCE during a request.
 *
 * ATTENTION: You can write to the replica with this connection if you execute a write query without
 * opening up a transaction. For example:
 *
 *      $conn = DriverManager::getConnection(...);
 *      $conn->executeQuery("DELETE FROM table");
 *
 * Be aware that Connection#executeQuery is a method specifically for READ
 * operations only.
 *
 * Use Connection#executeUpdate for any SQL statement that changes/updates
 * state in the database (UPDATE, INSERT, DELETE or DDL statements).
 *
 * This connection is limited to replica operations using the
 * Connection#executeQuery operation only, because it wouldn't be compatible
 * with the ORM or SchemaManager code otherwise. Both use all the other
 * operations in a context where writes could happen to a replica, which makes
 * this restricted approach necessary.
 *
 * You can manually connect to the primary at any time by calling:
 *
 *      $conn->ensureConnectedToPrimary();
 *
 * Instantiation through the DriverManager looks like:
 *
 * @example
 *
 * $conn = DriverManager::getConnection(array(
 *    'wrapperClass' => 'Doctrine\DBAL\Connections\PrimaryReadReplicaConnection',
 *    'driver' => 'pdo_mysql',
 *    'primary' => array('user' => '', 'password' => '', 'host' => '', 'dbname' => ''),
 *    'replica' => array(
 *        array('user' => 'replica1', 'password', 'host' => '', 'dbname' => ''),
 *        array('user' => 'replica2', 'password', 'host' => '', 'dbname' => ''),
 *    )
 * ));
 *
 * You can also pass 'driverOptions' and any other documented option to each of this drivers
 * to pass additional information.
 */
class PrimaryReadReplicaConnection extends Connection
{
    /**
     * Primary and Replica connection (one of the randomly picked replicas).
     *
     * @var array<string, DriverConnection|null>
     */
    protected $connections = ['primary' => null, 'replica' => null];

    /**
     * You can keep the replica connection and then switch back to it
     * during the request if you know what you are doing.
     *
     * @var bool
     */
    protected $keepReplica = false;

    /**
     * Creates Primary Replica Connection.
     *
     * @param array<string, mixed> $params
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        array $params,
        Driver $driver,
        ?Configuration $config = null,
        ?EventManager $eventManager = null
    ) {
        if (! isset($params['replica'], $params['primary'])) {
            throw new InvalidArgumentException('primary or replica configuration missing');
        }

        if (count($params['replica']) === 0) {
            throw new InvalidArgumentException('You have to configure at least one replica.');
        }

        $params['primary']['driver'] = $params['driver'];
        foreach ($params['replica'] as $replicaKey => $replica) {
            $params['replica'][$replicaKey]['driver'] = $params['driver'];
        }

        $this->keepReplica = (bool) ($params['keepReplica'] ?? false);

        parent::__construct($params, $driver, $config, $eventManager);
    }

    /**
     * Checks if the connection is currently towards the primary or not.
     */
    public function isConnectedToPrimary(): bool
    {
        return $this->_conn !== null && $this->_conn === $this->connections['primary'];
    }

    public function connect(?string $connectionName = null): void
    {
        if ($connectionName !== null) {
            throw new InvalidArgumentException('Passing a connection name as first argument is not supported anymore. Use ensureConnectedToPrimary()/ensureConnectedToReplica() instead.');
        }

        $this->performConnect();
    }

    protected function performConnect(?string $connectionName = null): void
    {
        $requestedConnectionChange = ($connectionName !== null);
        $connectionName            = $connectionName ?? 'replica';

        if ($connectionName !== 'replica' && $connectionName !== 'primary') {
            throw new InvalidArgumentException('Invalid option to connect(), only primary or replica allowed.');
        }

        // If we have a connection open, and this is not an explicit connection
        // change request, then abort right here, because we are already done.
        // This prevents writes to the replica in case of "keepReplica" option enabled.
        if ($this->_conn !== null && ! $requestedConnectionChange) {
            return;
        }

        $forcePrimaryAsReplica = false;

        if ($this->getTransactionNestingLevel() > 0) {
            $connectionName        = 'primary';
            $forcePrimaryAsReplica = true;
        }

        if (isset($this->connections[$connectionName])) {
            $this->_conn = $this->connections[$connectionName];

            if ($forcePrimaryAsReplica && ! $this->keepReplica) {
                $this->connections['replica'] = $this->_conn;
            }

            return;
        }

        if ($connectionName === 'primary') {
            $this->connections['primary'] = $this->_conn = $this->connectTo($connectionName);

            // Set replica connection to primary to avoid invalid reads
            if (! $this->keepReplica) {
                $this->connections['replica'] = $this->connections['primary'];
            }
        } else {
            $this->connections['replica'] = $this->_conn = $this->connectTo($connectionName);
        }

        if (! $this->_eventManager->hasListeners(Events::postConnect)) {
            return;
        }

        $eventArgs = new ConnectionEventArgs($this);
        $this->_eventManager->dispatchEvent(Events::postConnect, $eventArgs);
    }

    /**
     * Connects to the primary node of the database cluster.
     *
     * All following statements after this will be executed against the primary node.
     */
    public function ensureConnectedToPrimary(): void
    {
        $this->performConnect('primary');
    }

    /**
     * Connects to a replica node of the database cluster.
     *
     * All following statements after this will be executed against the replica node,
     * unless the keepReplica option is set to false and a primary connection
     * was already opened.
     */
    public function ensureConnectedToReplica(): void
    {
        $this->performConnect('replica');
    }

    /**
     * Connects to a specific connection.
     */
    protected function connectTo(string $connectionName): DriverConnection
    {
        $params = $this->getParams();

        $connectionParams = $this->chooseConnectionConfiguration($connectionName, $params);

        try {
            return $this->_driver->connect($connectionParams);
        } catch (DriverException $e) {
            throw DBALException::driverException($this->_driver, $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function chooseConnectionConfiguration(string $connectionName, array $params): array
    {
        if ($connectionName === 'primary') {
            return $params['primary'];
        }

        $config = $params['replica'][array_rand($params['replica'])];

        if (! isset($config['charset']) && isset($params['primary']['charset'])) {
            $config['charset'] = $params['primary']['charset'];
        }

        return $config;
    }

    /**
     * {@inheritDoc}
     */
    public function executeUpdate(string $query, array $params = [], array $types = []): int
    {
        $this->ensureConnectedToPrimary();

        return parent::executeUpdate($query, $params, $types);
    }

    public function beginTransaction(): void
    {
        $this->ensureConnectedToPrimary();

        parent::beginTransaction();
    }

    public function commit(): void
    {
        $this->ensureConnectedToPrimary();

        parent::commit();
    }

    public function rollBack(): void
    {
        $this->ensureConnectedToPrimary();

        parent::rollBack();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $table, array $identifier, array $types = []): int
    {
        $this->ensureConnectedToPrimary();

        return parent::delete($table, $identifier, $types);
    }

    public function close(): void
    {
        unset($this->connections['primary'], $this->connections['replica']);

        parent::close();

        $this->_conn       = null;
        $this->connections = ['primary' => null, 'replica' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function update(string $table, array $data, array $identifier, array $types = []): int
    {
        $this->ensureConnectedToPrimary();

        return parent::update($table, $data, $identifier, $types);
    }

    /**
     * {@inheritDoc}
     */
    public function insert(string $table, array $data, array $types = []): int
    {
        $this->ensureConnectedToPrimary();

        return parent::insert($table, $data, $types);
    }

    public function exec(string $statement): int
    {
        $this->ensureConnectedToPrimary();

        return parent::exec($statement);
    }

    public function createSavepoint(string $savepoint): void
    {
        $this->ensureConnectedToPrimary();

        parent::createSavepoint($savepoint);
    }

    public function releaseSavepoint(string $savepoint): void
    {
        $this->ensureConnectedToPrimary();

        parent::releaseSavepoint($savepoint);
    }

    public function rollbackSavepoint(string $savepoint): void
    {
        $this->ensureConnectedToPrimary();

        parent::rollbackSavepoint($savepoint);
    }

    public function query(string $sql): Result
    {
        $this->ensureConnectedToPrimary();
        assert($this->_conn instanceof DriverConnection);

        $args = func_get_args();

        $logger = $this->getConfiguration()->getSQLLogger();
        $logger->startQuery($sql);

        $statement = $this->_conn->query($sql);

        $logger->stopQuery();

        return $statement;
    }

    public function prepare(string $sql): Statement
    {
        $this->ensureConnectedToPrimary();

        return parent::prepare($sql);
    }
}