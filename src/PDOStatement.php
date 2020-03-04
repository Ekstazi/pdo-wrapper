<?php

/**
 * @package Compeek\PDOWrapper
 */

namespace Compeek\PDOWrapper;

/**
 * PDOStatement wrapper
 *
 * The PDO statement wrapper class works as a drop-in replacement for the standard PDO statement class with a bit of
 * additional functionality. All standard PDO statement methods are exposed by the wrapper, so it can be used in exactly
 * the same way as a standard PDO statement object.
 *
 * Behind the scenes, an actual PDO statement object is hidden within the wrapper so that all references to it can be
 * controlled.
 *
 * When disconnecting from the database, the PDO object and all related PDO statements are destroyed by the PDO wrapper
 * so that they are garbage collected, causing the PDO driver to drop the connection. When reconnecting to the database,
 * the PDO wrapper creates a new PDO object, and each PDO statement is recreated from the new PDO object upon next use.
 * To give the illusion that the PDO statements are the same ones as before, all attributes, options, and bindings are
 * restored when recreating them.
 *
 * @package Compeek\PDOWrapper
 */
class PDOStatement extends \PDOStatement {
    /**
     * @var \Compeek\PDOWrapper\PDO
     */
    protected $pdoWrapper;
    /**
     * @var bool|null last connection alive status
     */
    protected $pdoWrapperLastKnownIsAlive;
    /**
     * @var int|null last time the connection alive status was known
     */
    protected $pdoWrapperLastKnownIsAliveOn;
    /**
     * @var bool whether the statement is prepared
     */
    protected $prepared;
    /**
     * @var array PDO->prepare() or PDO->query() args
     */
    protected $args;
    /**
     * @var \PDOStatement|null
     */
    protected $pdoStatement;
    /**
     * @var array
     */
    protected $pdoStatementAttributes;
    /**
     * @var array
     */
    protected $pdoStatementBindColumns;
    /**
     * @var array columns that cannot be bound until after the statement is executed for the first time
     */
    protected $pdoStatementPostExecuteBindColumnNames;
    /**
     * @var array
     */
    protected $pdoStatementBindParams;
    /**
     * @var array
     */
    protected $pdoStatementBindValues;
    /**
     * @var array|null
     */
    protected $pdoStatementFetchModeArgs;

    /**
     * @param PDO $pdoWrapper
     * @param bool $pdoWrapperLastKnownIsAlive last connection alive status
     * @param int $pdoWrapperLastKnownIsAliveOn last time the connection alive status was known
     * @param bool $prepared whether the statement is prepared
     * @param array $args PDO->prepare() or PDO->query() args
     * @param \PDOStatement $pdoStatement
     */
    public function __construct(\Compeek\PDOWrapper\PDO $pdoWrapper, &$pdoWrapperLastKnownIsAlive, &$pdoWrapperLastKnownIsAliveOn, $prepared, array $args, \PDOStatement &$pdoStatement) {
        $this->pdoWrapper = $pdoWrapper;
        $this->pdoWrapperLastKnownIsAlive = &$pdoWrapperLastKnownIsAlive;
        $this->pdoWrapperLastKnownIsAliveOn = &$pdoWrapperLastKnownIsAliveOn;

        $this->prepared = $prepared;
        $this->args = $args;

        $this->pdoStatement = &$pdoStatement;
        $this->pdoStatementAttributes = array();
        $this->pdoStatementBindColumns = array();
        $this->pdoStatementPostExecuteBindColumnNames = array();
        $this->pdoStatementBindParams = array();
        $this->pdoStatementBindValues = array();
        $this->pdoStatementFetchModeArgs = null;
    }

    /**
     * Informs the PDO wrapper that the PDO statement wrapper is being destroyed and destroys the PDO statement
     */
    public function __destruct() {
        if ($this->pdoStatement !== null) {
            $this->pdoWrapper->forgetPdoStatement($this->pdoStatement);

            $this->pdoStatement = null;
        }
    }

    /**
     * Sets the PDO statement
     *
     * This method should only be called by the PDO wrapper, never elsewhere.
     *
     * @param \PDOStatement $pdoStatement
     */
    public function setPdoStatement(\PDOStatement &$pdoStatement) {
        $this->pdoStatement = &$pdoStatement;
    }

    /**
     * Recreates the PDO statement
     *
     * To recreate a PDO statement, all attributes, options, and bindings are restored from the previous one, giving the
     * illusion that the PDO statement is the same one as before. However, since sometimes columns can only be bound
     * after a result set is retrieved, any errors binding columns here will be ignored, and the column bindings will be
     * tried again after the statement is next executed.
     *
     * @return bool whether successful
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    protected function reconstructPdoStatement() {
        if ($this->pdoWrapper->reconstructPdoStatement($this, $this->prepared, $this->args)) {
            foreach ($this->pdoStatementAttributes as $attribute => $value) {
                $this->pdoStatement->setAttribute($attribute, $value);
            }

            $this->pdoStatementPostExecuteBindColumnNames = array();

            foreach ($this->pdoStatementBindColumns as $column => $args) {
                $args[1] = &$args[1]; // ensure reference is passed to function since PHP seems to convert reference to value if no other variables reference data (e.g. if column bound to local variable in function that has ended)

                try {
                    $success = call_user_func_array(array($this->pdoStatement, 'bindColumn'), $args);
                } catch (\PDOException $e) {
                    $success = false;
                }

                if (!$success) { // column cannot be bound before statement execution
                    $this->pdoStatementPostExecuteBindColumnNames[$column] = $column;
                }
            }

            foreach ($this->pdoStatementBindParams as $args) {
                $args[1] = &$args[1]; // ensure reference is passed to function since PHP seems to convert reference to value if no other variables reference data (e.g. if param bound to local variable in function that has ended)

                call_user_func_array(array($this->pdoStatement, 'bindParam'), $args);
            }

            foreach ($this->pdoStatementBindValues as $args) {
                call_user_func_array(array($this->pdoStatement, 'bindValue'), $args);
            }

            if ($this->pdoStatementFetchModeArgs !== null) {
                call_user_func_array(array($this->pdoStatement, 'setFetchMode'), $this->pdoStatementFetchModeArgs);
            }

            return true;
        } else { // applies only if PDO::ATTR_ERRMODE attribute != PDO::ERRMODE_EXCEPTION (will not reach here on error otherwise)
            return false;
        }
    }

    /**
     * Requires a connection to the database, automatically connecting if allowed if the client is not connected
     *
     * If the PDO statement does not exist, that means it was destroyed when last disconnected from the database and
     * must be recreated, which in turn will require a connection. If the PDO statement does exist, there is already
     * still a connection.
     *
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    protected function requireConnection() {
        if ($this->pdoStatement === null) {
            $this->reconstructPdoStatement();
        }
    }

    /**
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function debugDumpParams() {
        $this->requireConnection();

        return $this->pdoStatement->debugDumpParams();
    }

    /**
     * @return string
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function errorCode() {
        $this->requireConnection();

        return $this->pdoStatement->errorCode();
    }

    /**
     * @return array
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function errorInfo() {
        $this->requireConnection();

        return $this->pdoStatement->errorInfo();
    }

    /**
     * @param int $attribute
     * @return mixed
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function getAttribute($attribute) {
        $this->requireConnection();

        return $this->pdoStatement->getAttribute($attribute);
    }

    /**
     * @param int $attribute
     * @param mixed $value
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function setAttribute($attribute, $value) {
        $this->requireConnection();

        $result = $this->pdoStatement->setAttribute($attribute, $value);

        if ($result) {
            $this->pdoStatementAttributes[$attribute] = $value;
        }

        return $result;
    }

    /**
     * @param mixed $column
     * @param mixed $param
     * @param int $type
     * @param int $maxlen
     * @param mixed $driverdata
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null) {
        $this->requireConnection();

        $args = func_get_args();
        $args[1] = &$param;

        $result = call_user_func_array(array($this->pdoStatement, 'bindColumn'), $args);

        if ($result) {
            unset($this->pdoStatementPostExecuteBindColumnNames[$column]);
            $this->pdoStatementBindColumns[$column] = $args;
        }

        return $result;
    }

    /**
     * @param mixed $parameter
     * @param mixed $variable
     * @param int $data_type
     * @param int $length
     * @param mixed $driver_options
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function bindParam($parameter, &$variable, $data_type = \PDO::PARAM_STR, $length = null, $driver_options = null) {
        $this->requireConnection();

        $args = func_get_args();
        $args[1] = &$variable;

        $result = call_user_func_array(array($this->pdoStatement, 'bindParam'), $args);

        if ($result) {
            unset($this->pdoStatementBindValues[$parameter]);
            $this->pdoStatementBindParams[$parameter] = $args;
        }

        return $result;
    }

    /**
     * @param mixed $parameter
     * @param mixed $value
     * @param int $data_type
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function bindValue($parameter, $value, $data_type = \PDO::PARAM_STR) {
        $this->requireConnection();

        $args = func_get_args();

        $result = call_user_func_array(array($this->pdoStatement, 'bindValue'), $args);

        if ($result) {
            unset($this->pdoStatementBindParams[$parameter]);
            $this->pdoStatementBindValues[$parameter] = $args;
        }

        return $result;
    }

    /**
     * @param array $input_parameters
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function execute($input_parameters = null) {
        $this->requireConnection();

        $executedOn = microtime(true);

        $result = call_user_func_array(array($this->pdoStatement, 'execute'), func_get_args());

        if ($result) {
            $this->pdoWrapperLastKnownIsAlive = true;
            $this->pdoWrapperLastKnownIsAliveOn = $executedOn;

            foreach ($this->pdoStatementPostExecuteBindColumnNames as $column) {
                $this->pdoStatementBindColumns[$column][1] = &$this->pdoStatementBindColumns[$column][1]; // ensure reference is passed to function since PHP seems to convert reference to value if no other variables reference data (e.g. if column bound to local variable in function that has ended)

                call_user_func_array(array($this->pdoStatement, 'bindColumn'), $this->pdoStatementBindColumns[$column]);
            }

            $this->pdoStatementPostExecuteBindColumnNames = array();
        }

        return $result;
    }

    /**
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function nextRowset() {
        $this->requireConnection();

        return $this->pdoStatement->nextRowset();
    }

    /**
     * @param int $column
     * @return array
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function getColumnMeta($column) {
        $this->requireConnection();

        return $this->pdoStatement->getColumnMeta($column);
    }

    /**
     * @return int
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function columnCount() {
        $this->requireConnection();

        return $this->pdoStatement->columnCount();
    }

    /**
     * @return int
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function rowCount() {
        $this->requireConnection();

        return $this->pdoStatement->rowCount();
    }

    /**
	 * {@inheritDoc}
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function setFetchMode($mode, $arg2 = null, $arg3 = null) {
        $this->requireConnection();

        $args = func_get_args();

        $result = call_user_func_array(array($this->pdoStatement, 'setFetchMode'), $args);

        if ($result) {
            $this->pdoStatementFetchModeArgs = $args;
        }

        return $result;
    }

    /**
     * @param int $fetch_style
     * @param int $cursor_orientation
     * @param int $cursor_offset
     * @return mixed|false
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function fetch($fetch_style = null, $cursor_orientation = \PDO::FETCH_ORI_NEXT, $cursor_offset = 0) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetch'), func_get_args());
    }

    /**
     * @param int $fetch_style
     * @param mixed $fetch_argument
     * @param array $ctor_args
     * @return array|false
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function fetchAll($fetch_style = null, $fetch_argument = null, $ctor_args = null) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetchAll'), func_get_args());
    }

    /**
     * @param int $column_number
     * @return string
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function fetchColumn($column_number = 0) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetchColumn'), func_get_args());
    }

    /**
     * @param string $class_name
     * @param array $ctor_args
     * @return object|false
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function fetchObject($class_name = "stdClass", $ctor_args = null) {
        $this->requireConnection();

        return call_user_func_array(array($this->pdoStatement, 'fetchObject'), func_get_args());
    }

    /***
     * @return bool
     * @throws \Compeek\PDOWrapper\NotConnectedException
     */
    public function closeCursor() {
        $this->requireConnection();

        return $this->pdoStatement->closeCursor();
    }
}
