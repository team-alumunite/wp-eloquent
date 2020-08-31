<?php

namespace As247\WpEloquent\Capsule;

use DateTime;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use Serializable;
use wpdb;
use As247\WpEloquent\Database\MySqlConnection;
use As247\WpEloquent\Database\QueryException;

class WpConnection extends MySqlConnection
{
    /**
     * @var wpdb
     */
    public $db;
    /**
     * The database connection configuration options.
     *
     * @var array
     */
    protected $config = [];
    /**
     * Initializes the Database class
     *
     * @return static
     */
    public static function instance()
    {
        static $instance = false;
        global $wpdb;
        if (!$instance) {
            $instance = new self($wpdb,DB_NAME,$wpdb->prefix);
        }
        return $instance;
    }

    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $this->db = $pdo;
    }
    /**
     * Run a select statement and return a single result.
     *
     * @param  string $query
     * @param  array $bindings
     * @param  bool $useReadPdo
     * @throws QueryException
     *
     * @return mixed
     */
    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $query = $this->bindParams($query, $bindings);
        $error=$this->db->suppress_errors();
        $result = $this->db->get_row($query);
        $this->db->suppress_errors($error);
        if ($result === false || $this->db->last_error)
            throw new QueryException($query, $bindings, new Exception($this->db->last_error));
        return $result;
    }
    /**
     * Run a select statement against the database.
     *
     * @param  string $query
     * @param  array $bindings
     * @param  bool $useReadPdo
     * @throws QueryException
     *
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $query = $this->bindParams($query, $bindings);
        $error=$this->db->suppress_errors();
        $result = $this->db->get_results($query);
        $this->db->suppress_errors($error);
        if ($result === false || $this->db->last_error)
            throw new QueryException($query, $bindings, new Exception($this->db->last_error));
        return $result;
    }
    /**
     * A hacky way to emulate bind parameters into SQL query
     *
     * @param $query
     * @param $bindings
     *
     * @return mixed
     */
    private function bindParams($query, $bindings)
    {
        $query = str_replace('"', '`', $query);
        $bindings = $this->prepareBindings($bindings);
        if (!$bindings) {
            return $query;
        }
        $bindings = array_map(function ($replace) {
            if (is_string($replace)) {
                $replace = "'" . esc_sql($replace) . "'";
            } elseif ($replace === null) {
                $replace = "null";
            }
            return $replace;
        }, $bindings);
        $query = str_replace(array('%', '?'), array('%%', '%s'), $query);
        $query = vsprintf($query, $bindings);
        return $query;
    }
    /**
     * Run an insert statement against the database.
     *
     * @param  string $query
     * @param  array $bindings
     *
     * @return bool
     */
    public function insert($query, $bindings = array())
    {
        return $this->statement($query, $bindings);
    }
    /**
     * Run an update statement against the database.
     *
     * @param  string $query
     * @param  array $bindings
     *
     * @return int
     */
    public function update($query, $bindings = array())
    {
        return $this->affectingStatement($query, $bindings);
    }
    /**
     * Run a delete statement against the database.
     *
     * @param  string $query
     * @param  array $bindings
     *
     * @return int
     */
    public function delete($query, $bindings = array())
    {
        return $this->affectingStatement($query, $bindings);
    }
    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param  string $query
     * @param  array $bindings
     *
     * @return bool
     */
    public function statement($query, $bindings = array())
    {
        $new_query = $this->bindParams($query, $bindings);
        return $this->unprepared($new_query);
    }
    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param  string $query
     * @param  array $bindings
     *
     * @return int
     */
    public function affectingStatement($query, $bindings = array())
    {
        $new_query = $this->bindParams($query, $bindings);
        $error=$this->db->suppress_errors();
        $result = $this->db->query($new_query);
        $this->db->suppress_errors($error);
        if ($result === false || $this->db->last_error)
            throw new QueryException($new_query, $bindings, new Exception($this->db->last_error));
        return intval($result);
    }
    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param  string $query
     *
     * @return bool
     */
    public function unprepared($query)
    {
        $error=$this->db->suppress_errors();
        $result = $this->db->query($query);
        $this->db->suppress_errors($error);
        if($result === false || $this->db->last_error){
            throw new QueryException($query, [], new Exception($this->db->last_error));
        }
        return true;
    }
    /**
     * Prepare the query bindings for execution.
     *
     * @param  array $bindings
     *
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();
        foreach ($bindings as $key => $value) {
            // Micro-optimization: check for scalar values before instances
            if (is_bool($value)) {
                $bindings[$key] = intval($value);
            } elseif (is_scalar($value)) {
                continue;
            } elseif ($value instanceof DateTime) {
                // We need to transform all instances of the DateTime class into an actual
                // date string. Each query grammar maintains its own date string format
                // so we'll just ask the grammar for the format to get from the date.
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif(is_object($value)){
                if( method_exists($value,'__toString')) {
                    $bindings[$key] = $value->__toString();
                }elseif($value instanceof Serializable){
                    $bindings[$key] = $value->serialize();
                }elseif($value instanceof JsonSerializable){
                    $bindings[$key] = json_encode($value->jsonSerialize());
                }else{
                    throw new InvalidArgumentException("Could not convert ".get_class($value).' to scalar');
                }
            }
        }
        return $bindings;
    }
    /**
     * Return self as PDO
     *
     */
    public function getPdo()
    {
        return $this;
    }
    /**
     * Return the last insert id
     *
     * @param  string $args
     *
     * @return int
     */
    public function lastInsertId($args)
    {
        return $this->db->insert_id;
    }

}
