<?php

/**
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace Ytake\LaravelCouchbase\Database;

use Closure;
use CouchbaseBucket;
use Illuminate\Database\Connection;
use Ytake\LaravelCouchbase\Query\Grammar;
use Ytake\LaravelCouchbase\Query\Processor;
use Ytake\LaravelCouchbase\Exceptions\NotSupportedException;

/**
 * Class CouchbaseConnection
 */
class CouchbaseConnection extends Connection
{
    /** @var string */
    protected $bucket;

    /** @var \CouchbaseCluster */
    protected $connection;

    /** @var */
    protected $managerUser;

    /** @var */
    protected $managerPassword;

    /** @var int  */
    protected $fetchMode = 0;

    /** @var array  */
    protected $enableN1qlServers = [];

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->connection = $this->createConnection($config);
        $this->getManagedConfigure($config);

        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }

    /**
     * @param $name
     *
     * @return \CouchbaseBucket
     */
    public function openBucket($name)
    {
        return $this->connection->openBucket($name);
    }

    /**
     * @return Processor
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }

    /**
     * @return Grammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new Grammar;
    }

    /**
     * @param array $config
     *
     * @return void
     */
    protected function getManagedConfigure(array $config)
    {
        $this->enableN1qlServers = (isset($config['enables'])) ? $config['enables'] : [];
        $manager = (isset($config['manager'])) ? $config['manager'] : null;
        if (is_null($manager)) {
            $this->managerUser = $config['user'];
            $this->managerPassword = $config['password'];

            return;
        }
        $this->managerUser = $config['manager']['user'];
        $this->managerPassword = $config['manager']['password'];
    }

    /**
     * @param $dsn
     *
     * @return \CouchbaseCluster
     */
    protected function createConnection($dsn)
    {
        return (new CouchbaseConnector())->connect($dsn);
    }

    /**
     * {@inheritdoc}
     */
    public function getDriverName()
    {
        return 'couchbase';
    }

    /**
     * @return \CouchbaseCluster
     */
    public function getCouchbase()
    {
        return $this->connection;
    }

    /**
     * @param string $table
     *
     * @return \Ytake\LaravelCouchbase\Database\QueryBuilder
     */
    public function table($table)
    {
        $this->bucket = $table;

        return $this->query()->from($table);
    }

    /**
     * @param $bucket
     *
     * @return $this
     */
    public function bucket($bucket)
    {
        $this->bucket = $bucket;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ( $me, $query, $bindings) {
            if ($me->pretending()) {
                return [];
            }
            $query = \CouchbaseN1qlQuery::fromString($query);
            $query->options['args'] = $bindings;
            $query->consistency(\CouchbaseN1qlQuery::REQUEST_PLUS);
            $bucket = $this->openBucket($this->bucket);

            return $bucket->query($query);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return true;
            }
            $query = \CouchbaseN1qlQuery::fromString($query);
            $query->options['args'] = $bindings;
            $bucket = $this->openBucket($this->bucket);

            return (count($bucket->query($query))) ? true : false;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($me, $query, $bindings) {
            if ($me->pretending()) {
                return 0;
            }
            $query = \CouchbaseN1qlQuery::fromString($query);
            $query->options['args'] = $bindings;
            $bucket = $this->openBucket($this->bucket);
            return count($bucket->query($query));
        });
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(Closure $callback)
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack()
    {
        throw new NotSupportedException(__METHOD__);
    }

    /**
     * {@inheritdoc}
     */
    public function setFetchMode($fetchMode)
    {
        $this->fetchMode = null;
    }

    /**
     * {@inheritdoc}
     */
    protected function reconnectIfMissingConnection()
    {
        if (is_null($this->connection)) {
            $this->reconnect();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    /**
     * @param CouchbaseBucket $bucket
     *
     * @return CouchbaseBucket
     */
    protected function enableN1ql(CouchbaseBucket $bucket)
    {
        if(!count($this->enableN1qlServers)) {
            return $bucket;
        }
        $bucket->enableN1ql($this->enableN1qlServers);
        return $bucket;
    }

    /**
     * N1QL upsert query
     *
     * @param  string  $query
     * @param  array   $bindings
     * @return int
     */
    public function upsert($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }
}