<?php
class ContextBase extends MySqlQueries
{
    public static $show_errors = TRUE;

    public static function error($error)
    {
        if (self::$show_errors) {
            throw new ErrorException($error);
        }
    }
}

class MySQLQueryBuilder
{

    protected $need_to_close = false;
    protected $table;
    protected $select = [];
    protected $where = [];
    protected $order = [];
    protected $limit;
    protected $insertData = [];
    private $updateData = [];

    public function table($table)
    {
        $this->table = $table;
        return $this;
    }

    public function select($columns)
    {
        $this->select = $columns;
        return $this;
    }

    public function where($column, $operator, $value, $conjunction = 'AND')
    {
        $this->where[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'conjunction' => $conjunction
        ];
        return $this;
    }

    public function orderBy($column, $direction = 'ASC')
    {
        $this->order[] = [
            'column' => $column,
            'direction' => $direction
        ];
        return $this;
    }

    public function limit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    public function clear()
    {
        $this->table = null;
        $this->select = [];
        $this->where = [];
        $this->order = [];
        $this->limit = null;
        $this->insertData = [];
        $this->updateData = [];
        $this->need_to_close = false;
        return $this;
    }

    public function insert($data)
    {
        $this->need_to_close = true;
        $this->insertData = $data;
        return $this;
    }

    public function update($data)
    {
        $this->need_to_close = true;
        $this->updateData = $data;
        return $this;
    }
    public function build()
    {
        $query = "";

        if (!empty($this->insertData)) {
            $columns = [];
            $values = [];
            if (is_object($this->insertData)) {
                $arr = get_object_vars($this->insertData);
                $columns = array_keys($arr);
                $values = array_values($arr);
            }
            $columnsStr = implode(",", $columns);
            $valuesStr = "'" . implode("','", $values) . "'";
            $query = "INSERT INTO " . $this->table . " (" . $columnsStr . ") VALUES (" . $valuesStr . ")";
        } else if (!empty($this->updateData)) {
            $query = "UPDATE " . $this->table . " SET ";

            $updateStr = "";
            foreach ($this->updateData as $column => $value) {
                $updateStr .= $column . "='" . $value . "',";
            }
            $updateStr = rtrim($updateStr, ",");
            $query .= $updateStr;

            if (!empty($this->where)) {
                $query .= " WHERE ";
                foreach ($this->where as $i => $clause) {
                    $query .= $clause['column'] . " " . $clause['operator'] . " '" . $clause['value'] . "'";
                    if ($i < count($this->where) - 1) {
                        $query .= " " . $clause['conjunction'] . " ";
                    }
                }
            }
        } else {
            $query = "SELECT ";

            if (empty($this->select)) {
                $query .= "*";
            } else {
                $query .= implode(",", $this->select);
            }

            $query .= " FROM " . $this->table;

            if (!empty($this->where)) {
                $query .= " WHERE ";
                foreach ($this->where as $i => $clause) {
                    $query .= $clause['column'] . " " . $clause['operator'] . " " . $clause['value'];
                    if ($i < count($this->where) - 1) {
                        $query .= " " . $clause['conjunction'] . " ";
                    }
                }
            }

            if (!empty($this->order)) {
                $query .= " ORDER BY ";
                foreach ($this->order as $i => $clause) {
                    $query .= $clause['column'] . " " . $clause['direction'];
                    if ($i < count($this->order) - 1) {
                        $query .= ", ";
                    }
                }
            }

            if (!empty($this->limit)) {
                $query .= " LIMIT " . $this->limit;
            }
        }

        return $query;
    }

}

class QueryBuilder
{
    private $fields = [];

    private $conditions = [];

    private $from = [];

    private $sql_query;
    private $need_reset;

    public function __toString(): string
    {
        if (isset($this->sql_query))
            return $this->sql_query;
        $where = $this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions);
        return 'SELECT ' . implode(', ', $this->fields)
            . ' FROM ' . implode(', ', $this->from)
            . $where;
    }

    public function select(string...$select): self
    {
        $this->fields = $select;
        return $this;
    }

    public function where(string...$where): self
    {
        foreach ($where as $arg) {
            $this->conditions[] = $arg;
        }
        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        if ($alias === null) {
            $this->from[] = $table;
        } else {
            $this->from[] = "${table} AS ${alias}";
        }
        return $this;
    }

    public function insert($table, $obj)
    {

        $vars = get_object_vars($obj);
        $keys = "";
        $values = "";
        foreach ($vars as $key => $value) {
            $keys .= $key . ",";
            $values .= "'" . $value . "',";
        }

        $keys = substr($keys, 0, strlen($keys) - 1);
        $values = substr($values, 0, strlen($values) - 1);

        $sql = "INSERT INTO " . $table . " ({{keys}}) VALUES ({{values}})";

        $sql = str_replace("{{keys}}", $keys, $sql);
        $sql = str_replace("{{values}}", $values, $sql);
        $this->sql_query = $sql;
        return $this;
    }
}

class MySqlQueries extends MySQLQueryBuilder
{
    protected $connection;
    protected $query;
    protected $query_closed = TRUE;
    protected $query_count = 0;

    public function execute()
    {
        if ($this->need_to_close)
            $this->query_and_close($this->build());
        else
            $this->query($this->build());

        return $this;
    }

    public function connect()
    {
        $conn = new mysqli(db_servername, db_username, db_password, db_database);
        if ($conn->connect_error) {
            ContextBase::error("Connection failed: " . $conn->connect_error);
        }
        $conn->set_charset(db_charset);
        return $conn;
    }

    public function query($query, ...$values)
    {
        if (!$this->query_closed) {
            $this->query->close();
        }

        foreach ($values as $key => $value) {
            $query = str_replace_first("?", $value, $query);
        }

        $this->connection = $this->connect();

        if ($this->query = $this->connection->prepare($query)) {
            if (func_num_args() > 1) {
                $x = func_get_args();
                $args = array_slice($x, 1);
                $types = '';
                $args_ref = array();
                foreach ($args as $k => &$arg) {
                    if (is_array($args[$k])) {
                        foreach ($args[$k] as $j => &$a) {
                            $types .= $this->_gettype($args[$k][$j]);
                            $args_ref[] = &$a;
                        }
                    } else {
                        $types .= $this->_gettype($args[$k]);
                        $args_ref[] = &$arg;
                    }
                }
                array_unshift($args_ref, $types);
                call_user_func_array(array($this->query, 'bind_param'), $args_ref);
            }
            $this->query->execute();
            if ($this->query->errno) {
                ContextBase::error('Unable to process MySQL query (check your params) - ' . $this->query->error);
            }
            $this->query_closed = FALSE;
            $this->query_count++;
        } else {
            ContextBase::error('Unable to prepare MySQL statement (check your syntax) - ' . $this->connection->error);
        }
        return $this;
    }

    public function query_and_close($query, ...$values)
    {
        if (!$this->query_closed) {
            $this->query->close();
        }

        foreach ($values as $key => $value) {
            $query = str_replace_first("?", $value, $query);
        }

        $this->connection = $this->connect();

        if ($this->query = $this->connection->prepare($query)) {
            if (func_num_args() > 1) {
                $x = func_get_args();
                $args = array_slice($x, 1);
                $types = '';
                $args_ref = array();
                foreach ($args as $k => &$arg) {
                    if (is_array($args[$k])) {
                        foreach ($args[$k] as $j => &$a) {
                            $types .= $this->_gettype($args[$k][$j]);
                            $args_ref[] = &$a;
                        }
                    } else {
                        $types .= $this->_gettype($args[$k]);
                        $args_ref[] = &$arg;
                    }
                }
                array_unshift($args_ref, $types);
                call_user_func_array(array($this->query, 'bind_param'), $args_ref);
            }
            $this->query->execute();
            if ($this->query->errno) {
                ContextBase::error('Unable to process MySQL query (check your params) - ' . $this->query->error);
            }
            $this->query_closed = FALSE;
            $this->query_count++;
        } else {
            ContextBase::error('Unable to prepare MySQL statement (check your syntax) - ' . $this->connection->error);
        }

        $this->close_connection();
        return $this;
    }

    public function fetchAll($callback = null)
    {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break')
                    break;
            } else {
                $result[] = $r;
            }
        }

        $this->close_connection();
        return $result;
    }

    public function fetchAllAs($class, $callback = null)
    {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break')
                    break;
            } else {
                $obj = new $class();
                set_object_vars($obj, $r);
                $result[] = $obj;
            }
        }

        $this->close_connection();
        return $result;
    }

    public function fetchAs($class)
    {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            foreach ($row as $key => $val) {
                $result[$key] = $val;
            }
        }

        $obj = new $class();
        set_object_vars($obj, $result);

        $this->close_connection();
        return $obj;
    }

    public function fetchArray()
    {
        $params = array();
        $row = array();
        $meta = $this->query->result_metadata();
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            foreach ($row as $key => $val) {
                $result[$key] = $val;
            }
        }

        $this->close_connection();
        return $result;
    }

    public function numRows()
    {
        $this->query->store_result();
        return $this->query->num_rows;
    }

    public function affectedRows()
    {
        return $this->query->affected_rows;
    }

    public function lastInsertID()
    {
        return $this->connection->insert_id;
    }

    public function close_connection()
    {
        $this->query->close();
        $this->query_closed = TRUE;
        $this->connection->close();
        $this->clear();
    }

    private function _gettype($var)
    {
        if (is_string($var))
            return 's';
        if (is_float($var))
            return 'd';
        if (is_int($var))
            return 'i';
        return 'b';
    }

    public function selectAll($table_name)
    {
        $sql_query = (new QueryBuilder())->select("*")->from($table_name);
        echo $sql_query;
        return $this->query("SELECT * FROM ?;", $table_name)->fetchAll();
    }
    public function selectAllAs($table_name, $class)
    {
        return $this->query("SELECT * FROM ?;", $table_name)->fetchAllAs($class);
    }

    public function whereAs($table_name, $key, $value, $class)
    {
        return $this->query("SELECT * FROM ? WHERE ? = ?;", $table_name, $key, $value)->fetchAllAs($class);
    }

    public function single($table_name, $key, $value)
    {
        return $this->query("SELECT * FROM ? WHERE ? = ? LIMIT 1;", $table_name, $key, $value)->fetchArray();
    }

    public function singleAs($table_name, $key, $value, $class)
    {
        $obj = $this->single($table_name, $key, $value);
        $res = new $class();
        set_object_vars($res, $obj);
        return $res;
    }

    public function column_exists($table_name, $column)
    {
        $obj = $this->query("SHOW COLUMNS FROM ? LIKE '?';", $table_name, $column)->fetchArray();
        return count($obj) > 0;
    }
    public function column_drop($table_name, $column_name)
    {
        $this->query_and_close("ALTER TABLE ? DROP COLUMN ?;", $table_name, $column_name);
        return $this;
    }
    public function column_create($table_name, $column_name, $dataTypes)
    {
        $this->query_and_close("ALTER TABLE ? ADD COLUMN ? ?;", $table_name, $column_name, $dataTypes);
        return $this;
    }

    public function table_create_and_update($table_name, $table_schema)
    {
        if ($this->table_exists($table_name)) {
            $this->table_update($table_name, $table_schema);
            return;
        }

        return $this->table_create($table_name, $table_schema);
    }


    public function table_create($table_name, $table_schema)
    {
        if ($this->table_exists($table_name))
            return;

        $sql = "CREATE TABLE " . $table_name . " (";
        foreach ($table_schema as $key => $value) {
            $sql .= $key . " " . $value . ",\n";
        }

        $sql = substr($sql, 0, -2);

        $sql .= ");";

        $this->query_and_close($sql);

        return $this;
    }

    public function table_update($table_name, $table_schema)
    {
        $columns = $this->table_columns($table_name);
        $found = true;

        foreach ($table_schema as $field => $value) {
            $found = false;
            foreach ($columns as $k => $column) {
                if ($column->field == $field) {
                    $found = true;
                    unset($columns[$k]);
                    break;
                }
            }
            if (!$found) {
                $this->column_create($table_name, $field, $value);
            }
        }

        $columns = $this->table_columns($table_name);
        foreach ($columns as $k => $column) {
            $found = false;
            foreach ($table_schema as $field => $value) {
                if ($column->field == $field) {
                    $found = true;
                    unset($table_schema[$field]);
                    break;
                }
            }
            if (!$found) {
                $this->column_drop($table_name, $column->field);
            }
        }

    }

    public function table_exists($table_name)
    {
        $obj = $this->query("SHOW TABLES LIKE '?';", $table_name)->fetchArray();
        return count($obj) > 0;
    }

    public function table_drop($table_name)
    {
        $this->query_and_close("DROP TABLE ?;", $table_name);
        return $this;
    }

    public function table_clear($table_name)
    {
        $this->query_and_close("TRUNCATE TABLE ?;", $table_name);
        return $this;
    }

    public function table_columns($table_name)
    {
        $list = $this->query("SHOW COLUMNS FROM ? FROM ?;", $table_name, db_database)->fetchAll();
        $res = array();

        foreach ($list as $key => $value) {
            $t = new MySqlColumn();
            set_object_vars($t, $value, true);
            array_push($res, $t);
        }
        return $res;
    }
}


class DBSet extends MySqlQueries
{
    protected $table_class;
    protected $table_name;
    protected $table_schema;


    public function __construct($class)
    {
        $this->table_class = $class;
        $this->table_schema = (new $this->table_class())->table_schema();

        $this->table_name = $this->table_schema["table_name"];
        unset($this->table_schema["table_name"]);
    }

    public function get_all()
    {
        return $this->selectAllAs($this->table_name, $this->table_class);
    }

    public function get($id)
    {
        return $this->singleAs($this->table_name, "id", $id, $this->table_class);
    }

    public function get_where($prop, $value)
    {
        return $this->whereAs($this->table_name, $prop, $value, $this->table_class);
    }

    public function add($obj)
    {
        $this->table($this->table_name)->insert($obj);
        return $this;
    }

    public function update($obj)
    {

        $columns = $this->table_columns($this->table_name);
        $fields = array_column($columns, 'field');

        $sql = "UPDATE " . $this->table_name . " \nSET ";
        foreach ($obj as $key => $value) {
            if ($columns[array_search($key, $fields)]->key)
                continue;
            $sql .= "$key='$value' ,\n";
        }
        $sql = substr($sql, 0, -2);
        $sql .= " WHERE id=$obj->id;";
        $this->query($sql);
        return $this;
    }

    public function delete($id)
    {
        $this->query("DELETE FROM ? WHERE id = '?';", $this->table_name, $id);
        return $this;
    }


    public function table_exists()
    {
        return parent::table_exists($this->table_name);
    }

    public function table_drop()
    {
        parent::table_drop($this->table_name);
        return $this;
    }

    public function table_clear()
    {
        parent::table_clear($this->table_name);
        return $this;
    }

    public function table_create_and_update()
    {
        if ($this->table_exists()) {
            $this->table_update();
            return;
        }

        return $this->table_create();
    }

    public function table_create()
    {
        if ($this->table_exists())
            return;

        parent::table_create($this->table_name, $this->table_schema);

        return $this;
    }
    public function table_update()
    {
        parent::table_update($this->table_name, $this->table_schema);
        return $this;
    }
}

class MySqlColumn
{
    public $field;
    public $type;
    public $null;
    public $key;
    public $default;
    public $extra;
}

abstract class DbModel
{
    public function __construct()
    {
        $arguments = func_get_args();
        $numberOfArguments = func_num_args();

        $constructor = method_exists(
            $this,
            $fn = "__construct" . $numberOfArguments
        );

        if ($numberOfArguments < 1) {
            $this->__construct0();
        } else if ($constructor) {
            call_user_func_array([$this, $fn], $arguments);
        } else {
            $args_count = count($arguments);
            $index = 0;
            foreach ($this as $key => $value) {
                $this->$key = $arguments[$index++];
                if ($args_count == $index)
                    break;
            }
        }
    }
    public function __construct0()
    {
    }
    public abstract function table_schema();
}

?>