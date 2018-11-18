<?php
/**
 * 数据库操作类
 *
 * Author : tang long
 */
class DBMySqli
{
    private $_mysqli;
    private $_mysqli_stmt;
    private $_table;
    private $_sql;
    private $_result;
    private $_offset;
    private $_limit;
    private $_insert_data;
    private $_where_data;
    private $_where;
    private $_and_expressions;
    private $_or_expressions;
    private $_order_by;
    private $_escape_val;
    private $_params;
    private $_where_params;
    private $_set_params;

    public $config;

    /**
     * 数据库操作类构造函数.
     * @param array $config
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->_connect();
        $this->_reset();
    }


    /**
     * 魔法函数
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if($name == 'insert_id') {
            return $this->_mysqli->insert_id;
        }
    }


    /**
     * 数据库连接函数.
     * @return void
     */
    private function _connect()
    {
        $this->_mysqli = new mysqli($this->config['host'],$this->config['user'],$this->config['pwd'],$this->config['dbname']);
        if($this->_mysqli->connect_error) {
            $this->_error('数据库连接失败，错误代码：' . $this->_mysqli->connect_errno);
        }
        $this->_mysqli->set_charset($this->config['charset']);
    }

    /**
     * 错误处理函数
     * @param string $msg
     * @return void
     */
    private function _error($msg)
    {
        if($this->config['mode'] == 'production') {
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        throw new Exception($this->_sql . '<br />' . $msg);
        exit;
    }

    /**
     * 构建 SQL语句
     * @param string $prefix
     * @return void
     */

    private function _build_sql($prefix)
    {
        $sql = '';
        $fileds = empty($this->_fields) ? '*' : $this->_mysqli->real_escape_string($this->_fields);
        $where = $this->_where;
        $ins_expressions = array();

        $this->_table = $this->_mysqli->real_escape_string($this->_table);

        if(!empty($this->_and_expressions)) {
            if(!empty($where)) {
                $where .= ' AND ';
            }
            $where .= '(' . implode("AND",$this->_and_expressions) . ')';
        }
        if(!empty($this->_or_expressions)) {
            if(!empty($where)) {
                $where .= ' AND ';
            }
            $where .= '(' . implode("OR",$this->_or_expressions) . ')';
        }
        foreach($this->_insert_data as $k => $v) {
            $k = $this->_mysqli->real_escape_string($k);
			if($this->_escape_val) {
				$ins_expressions[]= "{$k} = ?";
				$this->_set_params[] = $v;
			}
			else {
				$ins_expressions[] = "{$k} = {$v}";
			}
        }
		$ins_expressions = implode(', ',$ins_expressions);
		empty($where) OR $where = 'WHERE ' . $where;

        switch ($prefix) {
            case 'SELECT':
                $sql = $prefix . " {$fileds} FROM `{$this->_table}` {$where}";
                break;
            case 'SELECT COUNT':
                $sql = $prefix . "(*) AS cnt FROM `{$this->_table}` {$where}";
                break;
            case 'INSERT':
                $sql = $prefix . " `{$this->_table}` SET {$ins_expressions}";
                break;
            case 'UPDATE':
                $sql = $prefix ." `{$this->_table}` SET {$ins_expressions} {$where}";
                break;
            case 'DELETE':
                $sql = $prefix ." FROM `{$this->_table}` {$where}";
                break;
            default:
                break;
        }
        if(!empty($this->_order_by)) {
            $sql .= ' ORDER BY ' . $this->_order_by;
        }
        if(!is_null($this->_limit)) {
            $sql .= " LIMIT {$this->_offset},{$this->_limit}";
        }
        $this->_sql = $sql;
    }


    /**
     * 初始化查询变量
     *
     * @return void
     */
    private function _reset()
    {
        $this->_table = '';
        $this->_sql = '';
        $this->_where = '';
        if($this->_mysqli_stmt instanceof mysqli_stmt) {
            $this->_mysqli_stmt->close();
		}
        if($this->_result instanceof mysqli_result) {
            $this->_mysqli->_result->free();
		}
		$this->_offset = 0;
        $this->_limit = null;
        $this->_insert_data = array();
        $this->_where_data = array();
        $this->_and_expressions = array();
        $this->_or_expressions = array();
        $this->_order_by = '';
        $this->_escape_val = true;
        $this->_params = array();
        $this->_where_params = array();
        $this->_set_params = array();
    }


    private function _exec()
    {
        if(!$this->_mysqli_stmt) {
            return false;
        }
        if($this->config['debug']) {
            echo $this->_sql . '<br />';
        }
        if($this->_mysqli_stmt->execute()) {
            return true;
        }
        else {
            if($this->_mysqli->error) {
                $this->_error($this->_mysqli->error);
                return null;
            }
            return false;
        }
    }

    /**
     *  执行原生SQL
     *  @param $sql
     *  @param $values
     *  @return DB
     */
    public function query($sql,$params = array())
    {
        $this->_sql = $sql;
        $this->_params = $params;
        if($this->_prepare()) {
            $this->_bind_params();
        }
        return $this;
    }

    /**
     * 执行预处理指令
     * @return bool
     */

    public function execute()
    {
        $r = $this->_exec();
        $this->_reset();
        return $r;
    }

    /**
     * 预编译SQL
     * @return void
     */

    private function _prepare()
    {
        return $this->_mysqli_stmt = $this->_mysqli->prepare($this->_sql);
    }

    /**
     * 绑定参数
     * @return void
     */

    private function _bind_params()
    {
        $this->_params = array_merge($this->_params,$this->_set_params);
        $this->_params = array_merge($this->_params,$this->_where_params);

        if(empty($this->_params)) {
            return false;
        }
        $ref = array();
        $ref[0] = str_repeat('s',count($this->_params));
        for($i = 0;$i < count($this->_params);$i ++) {
            $ref[]= &$this->_params[$i];
        }

        if(!call_user_func_array(array($this->_mysqli_stmt,'bind_param'),$ref)) {
            echo $this->_sql;
            var_dump($this->_params);
            return false;
        }
        else {
            return true;
        }

        //$this->_mysqli_stmt->bind_param($ref[0],$this->_params[0],$this->_params[1]);
    }

    /**
     * 条件查询函数
     * @param array $data
     * @return DB
     */
    public function where($data,$condition = 'AND')
    {
        if(!is_array($data)) {
            $this->_error('查询条件错误');
        }
        $this->_where_data = $data;

        foreach($data as $expression => $value) {
            if(preg_match("/([a-zA-Z0-9_]+)[\s]*([=|<|>|!]+)/i",$expression,$matches)) {
                $matches[1] = $this->_mysqli->real_escape_string($matches[1]);

                if($condition == 'AND') {
                    $this->_and_expressions[] = " {$matches[1]} {$matches[2]} ? ";
                }
                else if($condition == 'OR') {
                    $this->_or_expressions[] = " {$matches[1]} {$matches[2]} ? ";
                }
                $this->_where_params[] = $value;
            }
            else {
                $this->_error('WHERE 表达式错误:' . $expression);
            }
        }

        return $this;
    }


    /**
     * WHERE IN 表达式
     *
     * @param string $column;
     * @param array $arr
     * @return DB
     */
    public function where_in($column,$arr)
    {
        $column =  $this->_mysqli->real_escape_string($column);
        $this->_where_params = array(implode(',',$arr));
        $this->_where = " {$column} IN (?)";
        return $this;
    }


    /**
     * LIKE 表达式
     *
     * @param string $column;
     * @param array $arr
     * @return DB
     */
    public function like($column,$q,$match = 'BOTH')
    {
        switch($match)
        {
            case 'BOTH':
                $this->_where .= " {$column} LIKE '%{$q}%'";
                break;
            case 'LEFT':
                $this->_where .= " {$column} LIKE '%{$q}'";
                break;
            case 'RIGHT':
                $this->_where .= " {$column} LIKE '{$q}%'";
                break;
        }

        return $this;
    }


    /**
     * OR LIKE 表达式
     *
     * @param string $column;
     * @param array $arr
     * @return DB
     */
    public function or_like($column,$q,$match = 'BOTH')
    {
        if(!empty($this->_where)) {
            $this->_where .= ' OR ';
        }
        switch($match)
        {
            case 'BOTH':
                $this->_where .= " {$column} LIKE '%{$q}%'";
                break;
            case 'LEFT':
                $this->_where .= " {$column} LIKE '%{$q}'";
                break;
            case 'RIGHT':
                $this->_where .= " {$column} LIKE '{$q}%'";
                break;
        }

        return $this;
    }


    /**
     * 选择要查询的表
     * @param string $table
     * @return DB
     */
    public function get($table)
    {
        $this->_table = $table;
        return $this;
    }

    /**
     * 插入数据
     * @param string $table
     * @param string $data
     * @return DB
     */
    public function insert($table,$data,$escape_val = true)
    {
        $this->_table = $table;
        $this->_insert_data = $data;
        $this->_escape_val = $escape_val;
		$this->_build_sql('INSERT');
        if($this->_prepare()) {
            $this->_bind_params();
        }
        $r = $this->_exec();
        $this->_reset();
        return $r;
    }

    /**
     * 更新数据
     * @param string $table
     * @param string $data
     * @return DB
     */
    public function update($table,$data,$escape_val = true)
    {
        $this->_table = $table;
        $this->_insert_data = $data;
        $this->_escape_val = $escape_val;
        $this->_build_sql('UPDATE');

        if($this->_prepare()) {
            $this->_bind_params();
        }

        $r = $this->_exec();
        $this->_reset();
        return $r;
    }

    /**
     * 删除数据
     *
     * @param string $data
     * @return DB
     */

     public function delete($table)
     {
        $this->_table = $table;
        $this->_build_sql('DELETE');
        if($this->_prepare()) {
            $this->_bind_params();
        }
        $r = $this->_exec();
        $this->_reset();
        return $r;
     }


    /**
     * 获得查询结果（多个记录）
     * @return array
     */
    public function result_array()
    {
        if(empty($this->_sql)) {
            $this->_build_sql('SELECT');
        }
        if($this->_prepare()) {
            $this->_bind_params();
        }
        if(!$this->_exec()) {
            $this->_reset();
            return;
        }
        $result = $this->_mysqli_stmt->get_result();
        $arr = $result->fetch_all(MYSQLI_ASSOC);
        $this->_reset();
        return $arr;
    }

    /**
     * 获得查询结果（单个记录）
     * @return array
     */
    public function result_one()
    {
        if(empty($this->_sql)) {
            $this->_build_sql('SELECT');
        }
        if($this->_prepare()) {
            $this->_bind_params();
        }
        if(!$this->_exec()) {
            $this->_reset();
            return;
        }
        $result = $this->_mysqli_stmt->get_result();
        $row = $result->fetch_array();
        $this->_reset();
        return $row;
    }

    /**
     * 查询总记录数
     * @param string $table
     * @return DB
     */
    public function count($table)
    {
        $this->_table = $table;

        $this->_build_sql('SELECT COUNT');
        $this->_prepare();

        $result = $this->_exec();
        $row = $result->fetch_array();
        $this->_reset();
        return $row['cnt'];
    }

    public function limit($limit)
    {
        $this->_limit = $limit;
        return $this;
    }
    public function offset($offset)
    {
        $this->_offset = $offset;

        return $this;
    }

    public function real_escape_string($string)
    {
        $string = $this->_mysqli->real_escape_string($string);
        return $string;
    }


    public function order_by($order_by)
    {
        $this->_order_by = $order_by;

        return $this;
    }

    // 事务开启
    // (PHP 5 >= 5.5.0, PHP 7)
    public function begin_transaction()
    {
        return $this->_mysqli->begin_transaction();
    }

    // 事务提交
    // (PHP 5 >= 5.5.0, PHP 7)
    public function commit()
    {
        return $this->_mysqli->commit();
    }

    // 事务回滚
    // (PHP 5 >= 5.5.0, PHP 7)
    public function rollback()
    {
        return $this->_mysqli->rollback();
    }

    /**
     * 数据库操作类析构函数
     */
    public function __destruct()
    {
        if($this->_result instanceof mysqli_result)
        {
            $this->_mysqli->_result->free();
        }
        $this->_mysqli->close();
    }
}
