<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2014 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace think\model;

use think\Model;
use think\Hook;
use think\Request;

/**
 * ThinkPHP关联模型扩展
 */
class RelationModel extends Model
{

    const HAS_ONE = 1;
    const BELONGS_TO = 2;
    const HAS_MANY = 3;
    const MANY_TO_MANY = 4;

    // 关联定义
    protected $_link = array();

    // 定义返回数据
    public $_resData = [];

    // 字段数据源映射源数据字段
    public $_fieldFromDataDict = [];

    // 远端一对多水平关联字段多个查询上一个查询方法
    protected $prevRemoteQueryMethod = '';

    // 远端一对多水平关联字段多个查询当前查询方法
    protected $currentRemoteQueryMethod = '';

    // 字段类型或者格式转换
    protected $type = [];

    // 是否是空值查询
    protected $isNullOrEmptyFilter = false;

    // 当前模块id
    protected $currentModuleId = 0;

    // 自定义字段配置
    protected $customFieldConfig = [];

    /**
     * 动态方法实现
     * @access public
     * @param string $method 方法名称
     * @param array $args 调用参数
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (strtolower(substr($method, 0, 8)) == 'relation') {
            $type = strtoupper(substr($method, 8));
            if (in_array($type, array('ADD', 'SAVE', 'DEL'), true)) {
                array_unshift($args, $type);
                return call_user_func_array(array(&$this, 'opRelation'), $args);
            }
        } else {
            return parent::__call($method, $args);
        }
    }

    /**
     * 数据库Event log Hook
     */
    protected function databaseEventLogHook($param)
    {
        Hook::listen('event_log', $param);
    }

    /**
     * 得到关联的数据表名
     * @param $relation
     * @return string
     */
    public function getRelationTableName($relation)
    {
        $relationTable = !empty($this->tablePrefix) ? $this->tablePrefix : '';
        $relationTable .= $this->tableName ? $this->tableName : $this->name;
        $relationTable .= '_' . $relation->getModelName();
        return strtolower($relationTable);
    }

    /**
     * 查询成功后的回调方法
     * @param $result
     * @param $options
     */
    protected function _after_find(&$result, $options)
    {
        // 获取关联数据 并附加到结果中
        if (!empty($options['link'])) {
            $this->getRelation($result, $options['link']);
        }
    }

    /**
     * 查询数据集成功后的回调方法
     * @param $result
     * @param $options
     */
    protected function _after_select(&$result, $options)
    {
        // 获取关联数据 并附加到结果中
        if (!empty($options['link'])) {
            $this->getRelations($result, $options['link']);
        }

    }

    /**
     * 写入成功后的回调方法
     * @param $pk
     * @param $pkName
     * @param $data
     * @param $options
     */
    protected function _after_insert($pk, $pkName, $data, $options)
    {
        //写入事件日志
        if ($options["model"] != "EventLog") {
            $this->databaseEventLogHook([
                'operate' => 'create',
                'primary_id' => $pk,
                'primary_field' => $pkName,
                'data' => $data,
                'param' => $options,
                'table' => $this->getTableName()
            ]);
        }

        // 关联写入
        if (!empty($options['link'])) {
            $this->opRelation('ADD', $data, $options['link']);
        }
    }

    /**
     * 更新成功后的回调方法
     * @param $result
     * @param $pkName
     * @param $data
     * @param $options
     * @param $writeEvent
     */
    protected function _after_update($result, $pkName, $data, $options, $writeEvent)
    {
        //写入事件日志
        if ($result > 0 && $options["model"] != "EventLog" && $writeEvent) {
            $this->databaseEventLogHook([
                'operate' => 'update',
                'primary_id' => $this->oldUpdateKey,
                'primary_field' => $pkName,
                'data' => ["old" => $this->oldUpdateData, "new" => $this->newUpdateData],
                'param' => $options,
                'table' => $this->getTableName()
            ]);
        }

        // 关联更新
        if (!empty($options['link'])) {
            $this->opRelation('SAVE', $data, $options['link']);
        }

    }

    /**
     * 删除成功后的回调方法
     * @param $result
     * @param $pkName
     * @param $data
     * @param $options
     */
    protected function _after_delete($result, $pkName, $data, $options)
    {
        //写入事件日志
        if ($result > 0 && $options["model"] != "EventLog") {
            $this->databaseEventLogHook([
                'operate' => 'delete',
                'primary_id' => $this->oldDeleteKey,
                'primary_field' => $pkName,
                'data' => $this->oldDeleteData,
                'param' => $options,
                'table' => $this->getTableName()
            ]);
        }

        // 关联删除
        if (!empty($options['link'])) {
            $this->opRelation('DEL', $data, $options['link']);
        }

    }

    /**
     * 对保存到数据库的数据进行处理
     * @access protected
     * @param mixed $data 要操作的数据
     * @return boolean
     */
    protected function _facade($data)
    {
        $this->_before_write($data);
        return $data;
    }

    /**
     * 获取返回数据集的关联记录
     * @access protected
     * @param array $resultSet 返回数据
     * @param string|array $name 关联名称
     * @return array
     */
    protected function getRelations(&$resultSet, $name = '')
    {
        // 获取记录集的主键列表
        foreach ($resultSet as $key => $val) {
            $val = $this->getRelation($val, $name);
            $resultSet[$key] = $val;
        }
        return $resultSet;
    }

    /**
     * 获取返回数据的关联记录
     * @access protected
     * @param mixed $result 返回数据
     * @param string|array $name 关联名称
     * @param boolean $return 是否返回关联数据本身
     * @return array
     */
    protected function getRelation(&$result, $name = '', $return = false)
    {
        if (!empty($this->_link)) {
            foreach ($this->_link as $key => $val) {
                $mappingName = !empty($val['mapping_name']) ? $val['mapping_name'] : $key; // 映射名称
                if (empty($name) || true === $name || $mappingName == $name || (is_array($name) && in_array($mappingName, $name))) {
                    $mappingType = !empty($val['mapping_type']) ? $val['mapping_type'] : $val; //  关联类型
                    $mappingClass = !empty($val['class_name']) ? $val['class_name'] : $key; //  关联类名
                    $mappingFields = !empty($val['mapping_fields']) ? $val['mapping_fields'] : '*'; // 映射字段
                    $mappingCondition = !empty($val['condition']) ? $val['condition'] : '1=1'; // 关联条件
                    $mappingKey = !empty($val['mapping_key']) ? $val['mapping_key'] : $this->getPk(); // 关联键名
                    if (strtoupper($mappingClass) == strtoupper($this->name)) {
                        // 自引用关联 获取父键名
                        $mappingFk = !empty($val['parent_key']) ? $val['parent_key'] : 'parent_id';
                    } else {
                        $mappingFk = !empty($val['foreign_key']) ? $val['foreign_key'] : strtolower($this->name) . '_id'; //  关联外键
                    }
                    // 获取关联模型对象
                    $model = D($mappingClass);
                    switch ($mappingType) {
                        case self::HAS_ONE:
                            $pk = $result[$mappingKey];
                            $mappingCondition .= " AND {$mappingFk}='{$pk}'";
                            $relationData = $model->where($mappingCondition)->field($mappingFields)->find();
                            if (!empty($val['relation_deep'])) {
                                $model->getRelation($relationData, $val['relation_deep']);
                            }
                            break;
                        case self::BELONGS_TO:
                            if (strtoupper($mappingClass) == strtoupper($this->name)) {
                                // 自引用关联 获取父键名
                                $mappingFk = !empty($val['parent_key']) ? $val['parent_key'] : 'parent_id';
                            } else {
                                $mappingFk =
                                    !empty($val['foreign_key']) ? $val['foreign_key'] : strtolower($model->getModelName()) . '_id'; //  关联外键
                            }
                            $fk = $result[$mappingFk];
                            $mappingCondition .= " AND {$model->getPk()}='{$fk}'";
                            $relationData = $model->where($mappingCondition)->field($mappingFields)->find();
                            if (!empty($val['relation_deep'])) {
                                $model->getRelation($relationData, $val['relation_deep']);
                            }
                            break;
                        case self::HAS_MANY:
                            $pk = $result[$mappingKey];
                            $mappingCondition .= " AND {$mappingFk}='{$pk}'";
                            $mappingOrder = !empty($val['mapping_order']) ? $val['mapping_order'] : '';
                            $mappingLimit = !empty($val['mapping_limit']) ? $val['mapping_limit'] : '';
                            // 延时获取关联记录
                            $relationData = $model->where($mappingCondition)->field($mappingFields)->order($mappingOrder)->limit($mappingLimit)->select();
                            if (!empty($val['relation_deep'])) {
                                foreach ($relationData as $key => $data) {
                                    $model->getRelation($data, $val['relation_deep']);
                                    $relationData[$key] = $data;
                                }
                            }
                            break;
                        case self::MANY_TO_MANY:
                            $pk = $result[$mappingKey];
                            $prefix = $this->tablePrefix;
                            $mappingCondition = " {$mappingFk}='{$pk}'";
                            $mappingOrder = $val['mapping_order'];
                            $mappingLimit = $val['mapping_limit'];
                            $mappingRelationFk = $val['relation_foreign_key'] ? $val['relation_foreign_key'] : $model->getModelName() . '_id';
                            if (isset($val['relation_table'])) {
                                $mappingRelationTable = preg_replace_callback("/__([A-Z_-]+)__/sU", function ($match) use ($prefix) {
                                    return $prefix . strtolower($match[1]);
                                }, $val['relation_table']);
                            } else {
                                $mappingRelationTable = $this->getRelationTableName($model);
                            }
                            $sql = "SELECT b.{$mappingFields} FROM {$mappingRelationTable} AS a, " . $model->getTableName() . " AS b WHERE a.{$mappingRelationFk} = b.{$model->getPk()} AND a.{$mappingCondition}";
                            if (!empty($val['condition'])) {
                                $sql .= ' AND ' . $val['condition'];
                            }
                            if (!empty($mappingOrder)) {
                                $sql .= ' ORDER BY ' . $mappingOrder;
                            }
                            if (!empty($mappingLimit)) {
                                $sql .= ' LIMIT ' . $mappingLimit;
                            }
                            $relationData = $this->query($sql);
                            if (!empty($val['relation_deep'])) {
                                foreach ($relationData as $key => $data) {
                                    $model->getRelation($data, $val['relation_deep']);
                                    $relationData[$key] = $data;
                                }
                            }
                            break;
                    }
                    if (!$return) {
                        if (isset($val['as_fields']) && in_array($mappingType, array(self::HAS_ONE, self::BELONGS_TO))) {
                            // 支持直接把关联的字段值映射成数据对象中的某个字段
                            // 仅仅支持HAS_ONE BELONGS_TO
                            $fields = explode(',', $val['as_fields']);
                            foreach ($fields as $field) {
                                if (strpos($field, ':')) {
                                    list($relationName, $nick) = explode(':', $field);
                                    $result[$nick] = $relationData[$relationName];
                                } else {
                                    $result[$field] = $relationData[$field];
                                }
                            }
                        } else {
                            $result[$mappingName] = $relationData;
                        }
                        unset($relationData);
                    } else {
                        return $relationData;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 操作关联数据
     * @access protected
     * @param string $opType 操作方式 ADD SAVE DEL
     * @param mixed $data 数据对象
     * @param string $name 关联名称
     * @return mixed
     */
    protected function opRelation($opType, $data = '', $name = '')
    {
        $result = false;
        if (empty($data) && !empty($this->data)) {
            $data = $this->data;
        } elseif (!is_array($data)) {
            // 数据无效返回
            return false;
        }
        if (!empty($this->_link)) {
            // 遍历关联定义
            foreach ($this->_link as $key => $val) {
                // 操作制定关联类型
                $mappingName = $val['mapping_name'] ? $val['mapping_name'] : $key; // 映射名称
                if (empty($name) || true === $name || $mappingName == $name || (is_array($name) && in_array($mappingName, $name))) {
                    // 操作制定的关联
                    $mappingType = !empty($val['mapping_type']) ? $val['mapping_type'] : $val; //  关联类型
                    $mappingClass = !empty($val['class_name']) ? $val['class_name'] : $key; //  关联类名
                    $mappingKey = !empty($val['mapping_key']) ? $val['mapping_key'] : $this->getPk(); // 关联键名
                    // 当前数据对象主键值
                    $pk = $data[$mappingKey];
                    if (strtoupper($mappingClass) == strtoupper($this->name)) {
                        // 自引用关联 获取父键名
                        $mappingFk = !empty($val['parent_key']) ? $val['parent_key'] : 'parent_id';
                    } else {
                        $mappingFk = !empty($val['foreign_key']) ? $val['foreign_key'] : strtolower($this->name) . '_id'; //  关联外键
                    }
                    if (!empty($val['condition'])) {
                        $mappingCondition = $val['condition'];
                    } else {
                        $mappingCondition = array();
                        $mappingCondition[$mappingFk] = $pk;
                    }
                    // 获取关联model对象
                    $model = D($mappingClass);
                    $mappingData = isset($data[$mappingName]) ? $data[$mappingName] : false;
                    if (!empty($mappingData) || 'DEL' == $opType) {
                        switch ($mappingType) {
                            case self::HAS_ONE:
                                switch (strtoupper($opType)) {
                                    case 'ADD': // 增加关联数据
                                        $mappingData[$mappingFk] = $pk;
                                        $result = $model->add($mappingData);
                                        break;
                                    case 'SAVE': // 更新关联数据
                                        $result = $model->where($mappingCondition)->save($mappingData);
                                        break;
                                    case 'DEL': // 根据外键删除关联数据
                                        $result = $model->where($mappingCondition)->delete();
                                        break;
                                }
                                break;
                            case self::BELONGS_TO:
                                break;
                            case self::HAS_MANY:
                                switch (strtoupper($opType)) {
                                    case 'ADD': // 增加关联数据
                                        $model->startTrans();
                                        foreach ($mappingData as $val) {
                                            $val[$mappingFk] = $pk;
                                            $result = $model->add($val);
                                        }
                                        $model->commit();
                                        break;
                                    case 'SAVE': // 更新关联数据
                                        $model->startTrans();
                                        $pk = $model->getPk();
                                        foreach ($mappingData as $vo) {
                                            if (isset($vo[$pk])) {
                                                // 更新数据
                                                $mappingCondition = "$pk ={$vo[$pk]}";
                                                $result = $model->where($mappingCondition)->save($vo);
                                            } else {
                                                // 新增数据
                                                $vo[$mappingFk] = $data[$mappingKey];
                                                $result = $model->add($vo);
                                            }
                                        }
                                        $model->commit();
                                        break;
                                    case 'DEL': // 删除关联数据
                                        $result = $model->where($mappingCondition)->delete();
                                        break;
                                }
                                break;
                            case self::MANY_TO_MANY:
                                $mappingRelationFk = $val['relation_foreign_key'] ? $val['relation_foreign_key'] : $model->getModelName() . '_id'; // 关联
                                $prefix = $this->tablePrefix;
                                if (isset($val['relation_table'])) {
                                    $mappingRelationTable = preg_replace_callback("/__([A-Z_-]+)__/sU", function ($match) use ($prefix) {
                                        return $prefix . strtolower($match[1]);
                                    }, $val['relation_table']);
                                } else {
                                    $mappingRelationTable = $this->getRelationTableName($model);
                                }
                                if (is_array($mappingData)) {
                                    $ids = array();
                                    foreach ($mappingData as $vo) {
                                        $ids[] = $vo[$mappingKey];
                                    }

                                    $relationId = implode(',', $ids);
                                }
                                switch (strtoupper($opType)) {
                                    case 'ADD': // 增加关联数据
                                        if (isset($relationId)) {
                                            $this->startTrans();
                                            // 插入关联表数据
                                            $sql = 'INSERT INTO ' . $mappingRelationTable . ' (' . $mappingFk . ',' . $mappingRelationFk . ') SELECT a.' . $this->getPk() . ',b.' . $model->getPk() . ' FROM ' . $this->getTableName() . ' AS a ,' . $model->getTableName() . " AS b where a." . $this->getPk() . ' =' . $pk . ' AND  b.' . $model->getPk() . ' IN (' . $relationId . ") ";
                                            $result = $model->execute($sql);
                                            if (false !== $result) // 提交事务
                                            {
                                                $this->commit();
                                            } else // 事务回滚
                                            {
                                                $this->rollback();
                                            }
                                        }
                                        break;
                                    case 'SAVE': // 更新关联数据
                                        if (isset($relationId)) {
                                            $this->startTrans();
                                            // 删除关联表数据
                                            $this->table($mappingRelationTable)->where($mappingCondition)->delete();
                                            // 插入关联表数据
                                            $sql = 'INSERT INTO ' . $mappingRelationTable . ' (' . $mappingFk . ',' . $mappingRelationFk . ') SELECT a.' . $this->getPk() . ',b.' . $model->getPk() . ' FROM ' . $this->getTableName() . ' AS a ,' . $model->getTableName() . " AS b where a." . $this->getPk() . ' =' . $pk . ' AND  b.' . $model->getPk() . ' IN (' . $relationId . ") ";
                                            $result = $model->execute($sql);
                                            if (false !== $result) // 提交事务
                                            {
                                                $this->commit();
                                            } else // 事务回滚
                                            {
                                                $this->rollback();
                                            }

                                        }
                                        break;
                                    case 'DEL': // 根据外键删除中间表关联数据
                                        $result = $this->table($mappingRelationTable)->where($mappingCondition)->delete();
                                        break;
                                }
                                break;
                        }
                        if (!empty($val['relation_deep'])) {
                            $model->opRelation($opType, $mappingData, $val['relation_deep']);
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 进行关联查询
     * @access public
     * @param mixed $name 关联名称
     * @return Model
     */
    public function relation($name)
    {
        $this->options['link'] = $name;
        return $this;
    }

    /**
     * 关联数据获取 仅用于查询后
     * @access public
     * @param string $name 关联名称
     * @return array
     */
    public function relationGet($name)
    {
        if (empty($this->data)) {
            return false;
        }

        return $this->getRelation($this->data, $name, true);
    }


    /**
     * 字符串命名风格转换
     * type 0 将Java风格转换为C的风格 1 将C风格转换为Java的风格
     * @param string $name 字符串
     * @param integer $type 转换类型
     * @param bool $ucfirst 首字母是否大写（驼峰规则）
     * @return string
     */
    public function parseName($name, $type = 0, $ucfirst = true)
    {
        if ($type) {
            $name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
                return strtoupper($match[1]);
            }, $name);
            return $ucfirst ? ucfirst($name) : lcfirst($name);
        } else {
            return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
        }
    }

    /**
     * 数据读取 类型转换
     * @access public
     * @param mixed $value 值
     * @param string|array $type 要转换的类型
     * @return mixed
     */
    protected function readTransform($value, $type)
    {
        if (is_array($type)) {
            list($type, $param) = $type;
        } elseif (strpos($type, ':')) {
            list($type, $param) = explode(':', $type, 2);
        }
        switch ($type) {
            case 'integer':
                $value = (int)$value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float)$value;
                } else {
                    $value = (float)number_format($value, $param);
                }
                break;
            case 'boolean':
                $value = (bool)$value;
                break;
            case 'timestamp':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value = date($format, $value);
                }
                break;
            case 'datetime':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value = date($format, strtotime($value));
                }
                break;
            case 'json':
                $value = json_decode($value, true);
                break;
            case 'array':
                $value = is_null($value) ? [] : json_decode($value, true);
                break;
            case 'object':
                $value = empty($value) ? new \stdClass() : json_decode($value);
                break;
            case 'serialize':
                $value = unserialize($value);
                break;
        }
        return $value;
    }

    /**
     * 获取对象原始数据 如果不存在指定字段返回false
     * @access public
     * @param string $name 字段名 留空获取全部
     * @return mixed
     * @throws \Exception
     */
    public function getData($name = null)
    {
        if (is_null($name)) {
            return $this->data;
        } elseif (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        } else {
            StrackE('property not exists:' . $name);
        }
    }

    /**
     * 获取器 获取数据对象的值
     * @param $name
     * @return mixed
     */
    public function getAttr($name)
    {
        try {
            $value = $this->getData($name);
        } catch (\Exception $e) {
            $value = null;
        }

        // 检测属性获取器
        $method = 'get' . $this->parseName($name, 1) . 'Attr';
        if (method_exists($this, $method)) {
            $value = $this->$method($value, $this->data);
        } elseif (isset($this->type[$name])) {
            // 类型转换
            $value = $this->readTransform($value, $this->type[$name]);
        }

        return $value;
    }

    /**
     * 处理查询数据
     * @param $data
     * @return array
     */
    protected function handleQueryData($data)
    {
        $item = [];
        $this->data = !empty($data) ? $data : $this->data;
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $this->data = $val;
                $arr = [];
                foreach ($val as $k => $v) {
                    $arr[$k] = $this->getAttr($k);
                }
                $item[$key] = $arr;
            } else {
                $item[$key] = $this->getAttr($key);
            }
        }

        return !empty($item) ? $item : [];
    }

    /**
     * 处理返回数据
     * @param $data
     * @param bool $first
     * @return array
     */
    public function handleReturnData($first = true, $data = [])
    {
        $dealData = !empty($data) ? $data : $this->data;

        $this->data = $this->handleQueryData($dealData);

        if ($first && is_many_dimension_array($this->data)) {
            $item = [];
            foreach ($this->data as $value) {


                $this->data = $value;
                $item[] = $this->handleReturnData(false);
            }
            return $item;
        } else {
            //过滤属性
            if (!empty($this->visible)) {
                $data = array_intersect_key($this->data, array_flip($this->visible));
            } elseif (!empty($this->hidden)) {
                $data = array_diff_key($this->data, array_flip($this->hidden));
            } else {
                $data = $this->data;
            }

            // 追加属性自定义字段
            if (!empty($this->appendCustomField)) {
                foreach ($this->appendCustomField as $field => $value) {
                    $data[$field] = $value;
                }
            }

            // 追加属性（必须定义获取器）
            if (!empty($this->append)) {
                foreach ($this->append as $name) {
                    $data[$name] = $this->getAttr($name);
                }
            }
            return !empty($data) ? $data : [];
        }
    }


    /**
     * 新增数据，成功返回当前添加的一条完整数据
     * @param array $param 新增数据参数
     * @return array|bool|mixed
     */
    public function addItem($param = [])
    {
        $this->resetDefault();
        if ($this->create($param, self::MODEL_INSERT)) {
            $result = $this->add();
            if (!$result) {
                //新增失败
                return false;
            } else {
                //新增成功，返回当前添加的一条完整数据
                $pk = $this->getPk();
                $this->_resData = $this->where([$pk => $result])->find();
                $this->successMsg = "Add {$this->name} items successfully.";
                return $this->handleReturnData(false, $this->_resData);
            }
        } else {
            //数据验证失败，返回错误
            return false;
        }
    }

    /**
     * 修改数据，必须包含主键，成功返回当前修改的一条完整数据
     * @param array $param 修改数据参数
     * @return array|bool|mixed
     */
    public function modifyItem($param = [])
    {

        $this->resetDefault();
        if ($this->create($param, self::MODEL_UPDATE)) {
            $result = $this->save();
            if (!$result) {
                // 修改失败
                if ($result === 0) {
                    // 没有数据被更新
                    $this->error = 'No data has been changed.';
                    return false;
                } else {
                    return false;
                }
            } else {
                // 修改成功，返回当前修改的一条完整数据
                $pk = $this->getPk();
                $this->_resData = $this->where([$pk => $param[$pk]])->find();
                $this->successMsg = "Modify {$this->name} items successfully.";
                return $this->handleReturnData(false, $this->_resData);
            }
        } else {
            // 数据验证失败，返回错误
            return false;
        }
    }

    /**
     * 更新单个组件基础方法
     * @param $data
     * @return array|bool|mixed
     */
    public function updateWidget($data)
    {
        $this->resetDefault();
        if ($this->create($data, self::MODEL_UPDATE)) {
            $result = $this->save();
            if (!$result) {
                if ($result === 0) {
                    // 没有数据被更新
                    $this->error = 'No data has been changed.';
                    return false;
                } else {
                    return false;
                }
            } else {
                $pk = $this->getPk();
                $this->_resData = $this->where([$pk => $data[$pk]])->find();
                return $this->handleReturnData(false, $this->_resData);
            }
        } else {
            // 数据验证失败，返回错误
            return false;
        }
    }


    /**
     * 删除数据
     * @param array $param
     * @return mixed
     */
    public function deleteItem($param = [])
    {
        $this->resetDefault();
        $result = $this->where($param)->delete();
        if (!$result) {
            // 数据删除失败，返回错误
            if ($result == 0) {
                // 没有数据被删除
                $this->error = 'No data has been changed.';
                return false;
            } else {
                return false;
            }
        } else {
            // 删除成功，返回当前添加的一条完整数据
            $this->successMsg = "Delete {$this->name} items successfully.";
            return true;
        }
    }


    /**
     * 处理过滤条件数据结构
     * @param $result
     * @param $filter
     * @param $currentFilter
     * @param int $depth
     * @param int $index
     */
    private function parserFilterParam(&$result, $filter, $currentFilter, $depth = 0, $index = 1)
    {
        if ($depth > 0) {
            $currentDepth = count($currentFilter);
            $currentIndex = 1;

            $dictIndex = $depth - 1;

            foreach ($currentFilter as $key => $value) {

                if ($index !== ($depth - 1)) {

                    if (is_array($value) && is_many_dimension_array($value)) {
                        $index++;
                        $this->parserFilterParam($result, $filter, $value, $depth, $index);
                    }
                } else {

                    if ($key !== "_logic") {

                        if (is_array($value) && is_many_dimension_array($value)) {
                            continue;
                        }

                        // 把所有相关联模块存下来
                        $fieldsParam = explode('.', $key);
                        if (!in_array($fieldsParam[0], Request::$complexFilterRelatedModule)) {
                            Request::$complexFilterRelatedModule[] = $fieldsParam[0];
                        }

                        // 按模板分组存储字段信息
                        if (array_key_exists($dictIndex, $result)) {
                            if (!array_key_exists($fieldsParam[0], $result[$dictIndex])) {
                                $result[$dictIndex][$fieldsParam[0]] = [
                                    $fieldsParam[1] => $value
                                ];
                            } else {
                                $result[$dictIndex][$fieldsParam[0]][$fieldsParam[1]] = $value;
                            }
                        } else {
                            $result[$dictIndex] = [
                                $fieldsParam[0] => [$fieldsParam[1] => $value]
                            ];
                        }

                    } else {
                        // 逻辑关系
                        if (array_key_exists($dictIndex, $result)) {
                            $result[$dictIndex][$key] = $value;
                        } else {
                            $result[$dictIndex] = [$key => $value];
                        }
                    }

                    $currentIndex++;

                    if ($currentDepth === $currentIndex) {
                        // 循环到末尾往上遍历
                        if (!array_key_exists('_logic', $result[$dictIndex])) {
                            $result[$dictIndex]['_logic'] = 'AND';
                        }
                        $this->parserFilterParam($result, $filter, $filter, $depth - 1, 1);
                    }
                }
            }
        }
    }

    /**
     * @param $val
     */
    private function parserFilterValue($val)
    {

    }

    /**
     * 处理过滤条件
     * @param $filter
     */
    private function buildFilter($filter)
    {
        if (Request::$isComplexFilter) {
            // 复杂过滤条件处理
            $filterReverse = [];

            foreach ($filter as $filterKey => $filterVal) {
                if (is_array($filterVal)) {
                    $depth = array_depth($filterVal);
                    $filterReverseItem = [];
                    $this->parserFilterParam($filterReverseItem, $filterVal, $filterVal, $depth);
                    $filterReverse[] = $filterReverseItem;
                }
            }

            echo json_encode($filterReverse);

            die;

            $this->parserFilterParam($filterReverse, $filter);


            echo json_encode(Request::$complexFilterRelatedModule);

            echo json_encode($filterReverse);
            die;
        }

        return $filter;
    }


    /**
     * 获取一条数据
     * @param array $options
     * @param bool $needFormat
     * @return array|mixed
     */
    public function findData($options = [], $needFormat = true)
    {
        if (array_key_exists("fields", $options)) {
            // 有字段参数
            $this->field($options["fields"]);
        }

        if (array_key_exists("filter", $options)) {
            //有过滤条件
            $this->where($options["filter"]);
        }

        $findData = $this->find();

        if (empty($findData)) {
            $this->error = 'Data does not exist.';
            return [];
        }

        // 数据格式化
        if ($needFormat) {
            return $this->handleReturnData(false, $findData);
        } else {
            return $findData;
        }
    }


    /**
     * 获取多条数据
     * @param array $options
     * @param bool $needFormat
     * @return array
     */
    public function selectData($options = [], $needFormat = true)
    {
        if (array_key_exists("filter", $options)) {
            // 有过滤条件
            $this->where($this->buildFilter($options["filter"]));
        }

        // 统计个数
        $total = $this->count();

        // 获取数据
        if ($total > 0) {

            if (array_key_exists("fields", $options)) {
                // 有字段参数
                $this->field($options["fields"]);
            }

            if (array_key_exists("filter", $options)) {
                // 有过滤条件
                $this->where($options["filter"]);
            }

            if (array_key_exists("page", $options)) {
                // 有分页参数
                $pageSize = $options["page"][1] > C("DB_MAX_SELECT_ROWS") ? C("DB_MAX_SELECT_ROWS") : $options["page"][1];
                $this->page($options["page"][0], $pageSize);
            } else {
                if (array_key_exists("limit", $options) && $options["limit"] <= C("DB_MAX_SELECT_ROWS")) {
                    // 有limit参数
                    $this->limit($options["limit"]);
                } else {
                    $this->limit(C("DB_MAX_SELECT_ROWS"));
                }
            }

            if (array_key_exists("order", $options)) {
                // 有order参数
                $this->order($options["order"]);
            }

            $selectData = $this->select();

        } else {
            $selectData = [];
        }

        if (empty($selectData)) {
            $this->error = 'Data does not exist.';
            return ["total" => 0, "rows" => []];
        }

        // 数据格式化
        if ($needFormat) {
            foreach ($selectData as &$selectItem) {
                $selectItem = $this->handleReturnData(false, $selectItem);
            }
            return ["total" => $total, "rows" => $selectData];
        } else {
            return ["total" => $total, "rows" => $selectData];
        }
    }


    /**
     * 获取字段数据源映射
     */
    private function getFieldFromDataDict()
    {
        // 用户数据映射
        $allUserData = M("User")->field("id,name")->select();
        $allUserDataMap = array_column($allUserData, null, "id");
        $this->_fieldFromDataDict["user"] = $allUserDataMap;

        // 模块数据映射
        $allModuleData = M("Module")->field("id,name,code,type")->select();
        $moduleMapData = [];
        $moduleCodeMapData = [];
        foreach ($allModuleData as $allModuleDataItem) {
            $moduleMapData[$allModuleDataItem["id"]] = $allModuleDataItem;
            $moduleCodeMapData[$allModuleDataItem["code"]] = $allModuleDataItem;
        }

        $this->_fieldFromDataDict["module"] = $moduleMapData;
        $this->_fieldFromDataDict["module_code"] = $moduleCodeMapData;;
    }

    /**
     * 关联模型查询
     * @param array $param
     * @param string $formatMode
     * @return array
     */
    public function getRelationData($param = [])
    {

    }

    /**
     * 生成排序规则
     * @param $sortRule
     * @param $groupRule
     * @return string
     */
    private function buildSortRule($sortRule, $groupRule = [])
    {

    }

    /**
     * 生成最终过滤条件
     * @param $request
     * @param $other
     * @return array
     */
    private function buildFinalFilter($request, $other)
    {

    }

    /**
     * 生成控件过滤条件
     * @param $item
     * @return array
     */
    public function buildWidgetFilter($item)
    {
        switch ($item["editor"]) {
            case "text":
            case "textarea":
                switch ($item["condition"]) {
                    case "LIKE":
                    case "NOTLIKE":
                        $value = "%" . $item["value"] . "%";
                        break;
                    default:
                        $value = $item["value"];
                        break;
                }
                $filter = [$item["condition"], $value];
                break;
            case "combobox":
            case "tagbox":
            case "horizontal_relationship":
            case "checkbox":
                //$filter = [$item["condition"], $item["value"]];
                $filter = $this->checkFilterValWeatherNullOrEmpty($item["condition"], $item["value"]);
                break;
            case "datebox":
            case "datetimebox":
                switch ($item["condition"]) {
                    case "BETWEEN":
                        $dateBetween = explode(",", $item["value"]);
                        $filter = [$item["condition"], [strtotime($dateBetween[0]), strtotime($dateBetween[1])]];
                        break;
                    default:
                        $filter = [$item["condition"], strtotime($item["value"])];
                        break;
                }
                break;
            default:
                $filter = [];
                break;
        }
        return $filter;
    }
}
