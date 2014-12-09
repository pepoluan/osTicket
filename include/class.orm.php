<?php
/*********************************************************************
    class.orm.php

    Simple ORM (Object Relational Mapper) for PHP5 based on Django's ORM,
    except that complex filter operations are not supported. The ORM simply
    supports ANDed filter operations without any GROUP BY support.

    Jared Hancock <jared@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

class OrmException extends Exception {}
class OrmConfigurationException extends Exception {}

/**
 * Meta information about a model including edges (relationships), table
 * name, default sorting information, database fields, etc.
 *
 * This class is constructed and built automatically from the model's
 * ::_inspect method using a class's ::$meta array.
 */
class ModelMeta implements ArrayAccess {

    static $base = array(
        'pk' => false,
        'table' => false,
        'defer' => array(),
        'select_related' => array(),
        'view' => false,
    );
    var $model;

    function __construct($model) {
        $this->model = $model;
        $meta = $model::$meta + self::$base;

        // TODO: Merge ModelMeta from parent model (if inherited)

        if (!$meta['table'])
            throw new OrmConfigurationException(
                __('Model does not define meta.table'), $model);
        elseif (!$meta['pk'])
            throw new OrmConfigurationException(
                __('Model does not define meta.pk'), $model);

        // Ensure other supported fields are set and are arrays
        foreach (array('pk', 'ordering', 'defer') as $f) {
            if (!isset($meta[$f]))
                $meta[$f] = array();
            elseif (!is_array($meta[$f]))
                $meta[$f] = array($meta[$f]);
        }

        // Break down foreign-key metadata
        if (!isset($meta['joins']))
            $meta['joins'] = array();
        foreach ($meta['joins'] as $field => &$j) {
            $this->processJoin($j);
        }
        unset($j);
        $this->base = $meta;
    }

    function processJoin(&$j) {
        if (isset($j['reverse'])) {
            list($fmodel, $key) = explode('.', $j['reverse']);
            $info = $fmodel::$meta['joins'][$key];
            $constraint = array();
            if (!is_array($info['constraint']))
                throw new OrmConfigurationException(sprintf(__(
                    // `reverse` here is the reverse of an ORM relationship
                    '%s: Reverse does not specify any constraints'),
                    $j['reverse']));
            foreach ($info['constraint'] as $foreign => $local) {
                list(,$field) = explode('.', $local);
                $constraint[$field] = "$fmodel.$foreign";
            }
            $j['constraint'] = $constraint;
            if (!isset($j['list']))
                $j['list'] = true;
            if (!isset($j['null']))
                $j['null'] = $info['null'] ?: false;
        }
        // XXX: Make this better (ie. composite keys)
        $keys = array_keys($j['constraint']);
        $foreign = $j['constraint'][$keys[0]];
        $j['fkey'] = explode('.', $foreign);
        $j['local'] = $keys[0];
    }

    function offsetGet($field) {
        if (!isset($this->base[$field]))
            $this->setupLazy($field);
        return $this->base[$field];
    }
    function offsetSet($field, $what) {
        $this->base[$field] = $what;
    }
    function offsetExists($field) {
        return isset($this->base[$field]);
    }
    function offsetUnset($field) {
        throw new Exception('Model MetaData is immutable');
    }

    function setupLazy($what) {
        switch ($what) {
        case 'fields':
            $this->base['fields'] = self::inspectFields();
            break;
        case 'newInstance':
            $class_repr = sprintf(
                'O:%d:"%s":0:{}',
                strlen($this->model), $this->model
            );
            $this->base['newInstance'] = function() use ($class_repr) {
                return unserialize($class_repr);
            };
            break;
        default:
            throw new Exception($what . ': No such meta-data');
        }
    }

    function inspectFields() {
        return DbEngine::getCompiler()->inspectTable($this['table']);
    }
}

class VerySimpleModel {
    static $meta = array(
        'table' => false,
        'ordering' => false,
        'pk' => false
    );

    var $ht;
    var $dirty = array();
    var $__new__ = false;
    var $__deleted__ = false;
    var $__deferred__ = array();

    function __construct($row) {
        $this->ht = $row;
    }

    function get($field, $default=false) {
        if (array_key_exists($field, $this->ht))
            return $this->ht[$field];
        elseif (isset(static::$meta['joins'][$field])) {
            // Make sure joins were inspected
            if (!static::$meta instanceof ModelMeta)
                static::_inspect();
            $j = static::$meta['joins'][$field];
            // Support instrumented lists and such
            if (isset($this->ht[$j['local']])
                    && isset($j['list']) && $j['list']) {
                $fkey = $j['fkey'];
                $v = $this->ht[$field] = new InstrumentedList(
                    // Send Model, Foriegn-Field, Local-Id
                    array($fkey[0], $fkey[1], $this->get($j['local']))
                );
                return $v;
            }
            // Support relationships
            elseif (isset($j['fkey'])
                    && ($class = $j['fkey'][0])
                    && class_exists($class)) {
                try {
                    $v = $this->ht[$field] = $class::lookup(
                        array($j['fkey'][1] => $this->ht[$j['local']]));
                }
                catch (DoesNotExist $e) {
                    $v = null;
                }
                return $v;
            }
        }
        elseif (isset($this->__deferred__[$field])) {
            // Fetch deferred field
            $row = static::objects()->filter($this->getPk())
                ->values_flat($field)
                ->one();
            if ($row)
                return $this->ht[$field] = $row[0];
        }
        elseif ($field == 'pk') {
            return $this->getPk();
        }

        if (isset($default))
            return $default;
        // TODO: Inspect fields from database before throwing this error
        throw new OrmException(sprintf(__('%s: %s: Field not defined'),
            get_class($this), $field));
    }
    function __get($field) {
        return $this->get($field, null);
    }

    function __isset($field) {
        return array_key_exists($field, $this->ht)
            || isset(static::$meta['joins'][$field]);
    }
    function __unset($field) {
        unset($this->ht[$field]);
    }

    function set($field, $value) {
        // Update of foreign-key by assignment to model instance
        if (isset(static::$meta['joins'][$field])) {
            static::_inspect();
            $j = static::$meta['joins'][$field];
            if ($j['list'] && ($value instanceof InstrumentedList)) {
                // Magic list property
                $this->ht[$field] = $value;
                return;
            }
            if ($value === null) {
                if (in_array($j['local'], static::$meta['pk'])) {
                    // Reverse relationship — don't null out local PK
                    $this->ht[$field] = $value;
                    return;
                }
                // Pass. Set local field to NULL in logic below
            }
            elseif ($value instanceof $j['fkey'][0]) {
                if ($value->__new__)
                    $value->save();
                // Capture the object under the object's field name
                $this->ht[$field] = $value;
                $value = $value->get($j['fkey'][1]);
                // Fall through to the standard logic below
            }
            else
                throw new InvalidArgumentException(
                    sprintf(__('Expecting NULL or instance of %s. Got a %s instead'),
                    $j['fkey'][0], get_class($value)));

            // Capture the foreign key id value
            $field = $j['local'];
        }
        $old = isset($this->ht[$field]) ? $this->ht[$field] : null;
        if ($old != $value) {
            // isset should not be used here, because `null` should not be
            // replaced in the dirty array
            if (!array_key_exists($field, $this->dirty))
                $this->dirty[$field] = $old;
            $this->ht[$field] = $value;
        }
    }
    function __set($field, $value) {
        return $this->set($field, $value);
    }

    function setAll($props) {
        foreach ($props as $field=>$value)
            $this->set($field, $value);
    }

    function __onload() {}
    static function __oninspect() {}

    static function _inspect() {
        if (!static::$meta instanceof ModelMeta) {
            static::$meta = new ModelMeta(get_called_class());

            // Let the model participate
            static::__oninspect();
        }
    }

    /**
     * objects
     *
     * Retrieve a QuerySet for this model class which can be used to fetch
     * models from the connected database. Subclasses can override this
     * method to apply forced constraints on the QuerySet.
     */
    static function objects() {
        return new QuerySet(get_called_class());
    }

    /**
     * lookup
     *
     * Retrieve a record by its primary key. This method may be short
     * circuited by model caching if the record has already been loaded by
     * the database. In such a case, the database will not be consulted for
     * the model's data.
     *
     * This method can be called with an array of keyword arguments matching
     * the PK of the object or the values of the primary key. Both of these
     * usages are correct:
     *
     * >>> User::lookup(1)
     * >>> User::lookup(array('id'=>1))
     *
     * For composite primary keys and the first usage, pass the values in
     * the order they are given in the Model's 'pk' declaration in its meta
     * data.
     *
     * Parameters:
     * $criteria - (mixed) primary key for the sought model either as
     *      arguments or key/value array as the function's first argument
     *
     * Returns:
     * (Object<Model>|null) a single instance of the sought model or null if
     * no such instance exists.
     */
    static function lookup($criteria) {
        // Model::lookup(1), where >1< is the pk value
        if (!is_array($criteria)) {
            $criteria = array();
            foreach (func_get_args() as $i=>$f)
                $criteria[static::$meta['pk'][$i]] = $f;
        }
        if ($cached = ModelInstanceManager::checkCache(get_called_class(),
                $criteria))
            return $cached;
        try {
            return static::objects()->filter($criteria)->one();
        }
        catch (DoesNotExist $e) {
            return null;
        }
    }

    function delete($pk=false) {
        $ex = DbEngine::delete($this);
        try {
            $ex->execute();
            if ($ex->affected_rows() != 1)
                return false;

            $this->__deleted__ = true;
            Signal::send('model.deleted', $this);
        }
        catch (OrmException $e) {
            return false;
        }
        return true;
    }

    function save($refetch=false) {
        if (count($this->dirty) === 0)
            return true;
        elseif ($this->__deleted__)
            throw new OrmException('Trying to update a deleted object');

        $ex = DbEngine::save($this);
        try {
            $ex->execute();
            if ($ex->affected_rows() != 1)
                return false;
        }
        catch (OrmException $e) {
            return false;
        }

        $pk = static::$meta['pk'];
        $wasnew = $this->__new__;

        if ($this->__new__) {
            if (count($pk) == 1)
                // XXX: Ensure AUTO_INCREMENT is set for the field
                $this->ht[$pk[0]] = $ex->insert_id();
            $this->__new__ = false;
            Signal::send('model.created', $this);
        }
        else {
            $data = array('dirty' => $this->dirty);
            Signal::send('model.updated', $this, $data);
        }
        # Refetch row from database
        # XXX: Too much voodoo
        if ($refetch) {
            // Uncache so that the lookup will not be short-cirtuited to
            // return this object
            ModelInstanceManager::uncache($this);
            $self = static::lookup($this->get('pk'));
            $this->ht = $self->ht;
        }
        if ($wasnew)
            $this->__onload();
        $this->dirty = array();
        return $this->get($pk[0]);
    }

    static function create($ht=false) {
        if (!$ht) $ht=array();
        $class = get_called_class();
        $i = new $class(array());
        $i->__new__ = true;
        foreach ($ht as $field=>$value)
            if (!is_array($value))
                $i->set($field, $value);
        return $i;
    }

    private function getPk() {
        $pk = array();
        foreach ($this::$meta['pk'] as $f)
            $pk[$f] = $this->ht[$f];
        return $pk;
    }
}

/**
 * AnnotatedModel
 *
 * Simple wrapper class which allows wrapping and write-protecting of
 * annotated fields retrieved from the database. Instances of this class
 * will delegate most all of the heavy lifting to the wrapped Model instance.
 */
class AnnotatedModel {

    var $model;
    var $annotations;

    function __construct($model, $annotations) {
        $this->model = $model;
        $this->annotations = $annotations;
    }

    function __get($what) {
        return $this->get($what);
    }
    function get($what) {
        if (isset($this->annotations[$what]))
            return $this->annotations[$what];
        return $this->model->get($what, null);
    }
    function __set($what, $to) {
        return $this->set($what, $to);
    }
    function set($what, $to) {
        if (isset($this->annotations[$what]))
            throw new OrmException('Annotated fields are read-only');
        return $this->model->set($what, $to);
    }

    // Delegate everything else to the model
    function __call($what, $how) {
        return call_user_func_array(array($this->model, $what), $how);
    }
    static function __callStatic($what, $how) {
        return call_user_func_array(array($this->model, $what), $how);
    }
}

class SqlFunction {
    var $alias;

    function SqlFunction($name) {
        $this->func = $name;
        $this->args = array_slice(func_get_args(), 1);
    }

    function toSql($compiler, $model=false, $alias=false) {
        $args = array();
        foreach ($this->args as $A) {
            if ($A instanceof SqlFunction)
                $A = $A->toSql($compiler, $model);
            else
                $A = $compiler->input($A);
            $args[] = $A;
        }
        return sprintf('%s(%s)%s', $this->func, implode(',', $args),
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }

    function getAlias() {
        return $this->alias;
    }
    function setAlias($alias) {
        $this->alias = $alias;
    }

    static function __callStatic($func, $args) {
        $I = new static($func);
        $I->args = $args;
        return $I;
    }
}

class SqlExpr extends SqlFunction {
    function __construct($args) {
        $this->args = $args;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $O = array();
        foreach ($this->args as $field=>$value) {
            list($field, $op) = $compiler->getField($field, $model);
            if (is_callable($op))
                $O[] = call_user_func($op, $field, $value, $model);
            else
                $O[] = sprintf($op, $field, $compiler->input($value));
        }
        return implode(' ', $O) . ($alias ? ' AS ' . $alias : '');
    }
}

class SqlExpression extends SqlFunction {
    var $operator;
    var $operands;

    function toSql($compiler, $model=false, $alias=false) {
        $O = array();
        foreach ($this->args as $operand) {
            if ($operand instanceof SqlFunction)
                $O[] = $operand->toSql($compiler, $model);
            else
                $O[] = $compiler->input($operand);
        }
        return implode(' '.$this->func.' ', $O)
            . ($alias ? ' AS '.$compiler->quote($alias) : '');
    }

    static function __callStatic($operator, $operands) {
        switch ($operator) {
            case 'minus':
                $operator = '-'; break;
            case 'plus':
                $operator = '+'; break;
            case 'times':
                $operator = '*'; break;
            case 'bitand':
                $operator = '&'; break;
            case 'bitor':
                $operator = '|'; break;
            default:
                throw new InvalidArgumentException('Invalid operator specified');
        }
        return parent::__callStatic($operator, $operands);
    }
}

class SqlInterval extends SqlFunction {
    var $type;

    function toSql($compiler, $model=false, $alias=false) {
        $A = $this->args[0];
        if ($A instanceof SqlFunction)
            $A = $A->toSql($compiler, $model);
        else
            $A = $compiler->input($A);
        return sprintf('INTERVAL %s %s',
            $A,
            $this->func)
            . ($alias ? ' AS '.$compiler->quote($alias) : '');
    }

    static function __callStatic($interval, $args) {
        if (count($args) != 1) {
            throw new InvalidArgumentException("Interval expects a single interval value");
        }
        return parent::__callStatic($interval, $args);
    }
}

class SqlField extends SqlFunction {
    function __construct($field) {
        $this->field = $field;
    }

    function toSql($compiler, $model=false, $alias=false) {
        list($field) = $compiler->getField($this->field, $model);
        return $field;
    }
}

class SqlCode extends SqlFunction {
    function __construct($code) {
        $this->code = $code;
    }

    function toSql($compiler, $model=false, $alias=false) {
        return $this->code;
    }
}

class SqlAggregate extends SqlFunction {

    var $func;
    var $expr;
    var $distinct=false;
    var $constraint=false;

    function __construct($func, $expr, $distinct=false, $constraint=false) {
        $this->func = $func;
        $this->expr = $expr;
        $this->distinct = $distinct;
        if ($constraint instanceof Q)
            $this->constraint = $constraint;
        elseif ($constraint)
            $this->constraint = new Q($constraint);
    }

    static function __callStatic($func, $args) {
        $distinct = @$args[1] ?: false;
        $constraint = @$args[2] ?: false;
        return new static($func, $args[0], $distinct, $constraint);
    }

    function toSql($compiler, $model=false, $alias=false) {
        $options = array('constraint' => $this->constraint, 'model' => true);

        // For DISTINCT, require a field specification — not a relationship
        // specification.
        list($field, $rmodel) = $compiler->getField($this->expr, $model, $options);
        if ($this->distinct) {
            $pk = false;
            foreach ($rmodel::$meta['pk'] as $f) {
                $pk |= false !== strpos($field, $f);
            }
            if (!$pk) {
                // Try and use the foriegn primary key
                if (count($rmodel::$meta['pk']) == 1) {
                    list($field) = $compiler->getField(
                        $this->expr . '__' . $rmodel::$meta['pk'][0],
                        $model, $options);
                }
                else {
                    throw new OrmException(
                        sprintf('%s :: %s', $rmodel, $field) .
                        ': DISTINCT aggregate expressions require specification of a single primary key field of the remote model'
                    );
                }
            }
        }

        return sprintf('%s(%s%s)%s', $this->func,
            $this->distinct ? 'DISTINCT ' : '', $field,
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }

    function getFieldName() {
        return strtolower(sprintf('%s__%s', $this->args[0], $this->func));
    }
}

class QuerySet implements IteratorAggregate, ArrayAccess, Serializable {
    var $model;

    var $constraints = array();
    var $ordering = array();
    var $limit = false;
    var $offset = 0;
    var $related = array();
    var $values = array();
    var $defer = array();
    var $annotations = array();
    var $extra = array();
    var $distinct = array();
    var $lock = false;

    const LOCK_EXCLUSIVE = 1;
    const LOCK_SHARED = 2;

    var $compiler = 'MySqlCompiler';
    var $iterator = 'ModelInstanceManager';

    var $params;
    var $query;

    function __construct($model) {
        $this->model = $model;
    }

    function filter() {
        // Multiple arrays passes means OR
        foreach (func_get_args() as $Q) {
            $this->constraints[] = $Q instanceof Q ? $Q : new Q($Q);
        }
        return $this;
    }

    function exclude() {
        foreach (func_get_args() as $Q) {
            $this->constraints[] = $Q instanceof Q ? $Q->negate() : Q::not($Q);
        }
        return $this;
    }

    function defer() {
        foreach (func_get_args() as $f)
            $this->defer[$f] = true;
        return $this;
    }

    function order_by() {
        $this->ordering = array_merge($this->ordering, func_get_args());
        return $this;
    }
    function getSortFields() {
        $ordering = $this->ordering;
        if ($this->extra['order_by'])
            $ordering = array_merge($ordering, $this->extra['order_by']);
        return $ordering;
    }

    function lock($how=false) {
        $this->lock = $how ?: self::LOCK_EXCLUSIVE;
        return $this;
    }

    function limit($count) {
        $this->limit = $count;
        return $this;
    }

    function offset($at) {
        $this->offset = $at;
        return $this;
    }

    function select_related() {
        $this->related = array_merge($this->related, func_get_args());
        return $this;
    }

    function extra(array $extra) {
        foreach ($extra as $section=>$info) {
            $this->extra[$section] = array_merge($this->extra[$section] ?: array(), $info);
        }
        return $this;
    }

    function distinct() {
        foreach (func_get_args() as $D)
            $this->distinct[] = $D;
        return $this;
    }

    function values() {
        foreach (func_get_args() as $A)
            $this->values[$A] = $A;
        $this->iterator = 'HashArrayIterator';
        // This disables related models
        $this->related = false;
        return $this;
    }

    function values_flat() {
        $this->values = func_get_args();
        $this->iterator = 'FlatArrayIterator';
        // This disables related models
        $this->related = false;
        return $this;
    }

    function all() {
        return $this->getIterator()->asArray();
    }

    function first() {
        $list = $this->limit(1)->all();
        return $list[0];
    }

    /**
     * one
     *
     * Finds and returns a single model instance based on the criteria in
     * this QuerySet instance.
     *
     * Throws:
     * DoesNotExist - if no such model exists with the given criteria
     * ObjectNotUnique - if more than one model matches the given criteria
     *
     * Returns:
     * (Object<Model>) a single instance of the sought model is guarenteed.
     * If no such model or multiple models exist, an exception is thrown.
     */
    function one() {
        $list = $this->all();
        if (count($list) == 0)
            throw new DoesNotExist();
        elseif (count($list) > 1)
            throw new ObjectNotUnique('One object was expected; however '
                .'multiple objects in the database matched the query. '
                .sprintf('In fact, there are %d matching objects.', count($list))
            );
        // TODO: Throw error if more than one result from database
        return $list[0];
    }

    function count() {
        $class = $this->compiler;
        $compiler = new $class();
        return $compiler->compileCount($this);
    }

    /**
     * exists
     *
     * Determines if there are any rows in this QuerySet. This can be
     * achieved either by evaluating a SELECT COUNT(*) query or by
     * attempting to fetch the first row from the recordset and return
     * boolean success.
     *
     * Parameters:
     * $fetch - (bool) TRUE if a compile and fetch should be attempted
     *      instead of a SELECT COUNT(*). This would be recommended if an
     *      accurate count is not required and the records would be fetched
     *      if this method returns TRUE.
     *
     * Returns:
     * (bool) TRUE if there would be at least one record in this QuerySet
     */
    function exists($fetch=false) {
        if ($fetch) {
            return (bool) $this[0];
        }
        return $this->count() > 0;
    }

    function annotate($annotations) {
        if (!is_array($annotations))
            $annotations = func_get_args();
        foreach ($annotations as $name=>$A) {
            if ($A instanceof SqlAggregate) {
                if (is_int($name))
                    $name = $A->getFieldName();
                $A->setAlias($name);
            }
            $this->annotations[$name] = $A;
        }
        return $this;
    }

    function delete() {
        $class = $this->compiler;
        $compiler = new $class();
        // XXX: Mark all in-memory cached objects as deleted
        $ex = $compiler->compileBulkDelete($this);
        $ex->execute();
        return $ex->affected_rows();
    }

    function update(array $what) {
        $class = $this->compiler;
        $compiler = new $class;
        $ex = $compiler->compileBulkUpdate($this, $what);
        $ex->execute();
        return $ex->affected_rows();
    }

    function __clone() {
        unset($this->_iterator);
        unset($this->query);
    }

    // IteratorAggregate interface
    function getIterator() {
        $class = $this->iterator;
        if (!isset($this->_iterator))
            $this->_iterator = new $class($this);
        return $this->_iterator;
    }

    // ArrayAccess interface
    function offsetExists($offset) {
        return $this->getIterator()->offsetExists($offset);
    }
    function offsetGet($offset) {
        return $this->getIterator()->offsetGet($offset);
    }
    function offsetUnset($a) {
        throw new Exception(__('QuerySet is read-only'));
    }
    function offsetSet($a, $b) {
        throw new Exception(__('QuerySet is read-only'));
    }

    function __toString() {
        return (string) $this->getQuery();
    }

    function getQuery($options=array()) {
        if (isset($this->query))
            return $this->query;

        // Load defaults from model
        $model = $this->model;
        $query = clone $this;
        if (!$query->ordering && isset($model::$meta['ordering']))
            $query->ordering = $model::$meta['ordering'];
        if (!$query->related && $model::$meta['select_related'])
            $query->related = $model::$meta['select_related'];
        if (!$query->defer && $model::$meta['defer'])
            $query->defer = $model::$meta['defer'];

        $class = $this->compiler;
        $compiler = new $class($options);
        $this->query = $compiler->compileSelect($query);

        return $this->query;
    }

    function serialize() {
        $info = get_object_vars($this);
        unset($info['query']);
        unset($info['limit']);
        unset($info['offset']);
        unset($info['_iterator']);
        return serialize($info);
    }

    function unserialize($data) {
        $data = unserialize($data);
        foreach ($data as $name => $val) {
            $this->{$name} = $val;
        }
    }
}

class DoesNotExist extends Exception {}
class ObjectNotUnique extends Exception {}

abstract class ResultSet implements Iterator, ArrayAccess {
    var $resource;
    var $position = 0;
    var $queryset;
    var $cache = array();

    function __construct($queryset=false) {
        $this->queryset = $queryset;
        if ($queryset) {
            $this->model = $queryset->model;
            $this->resource = $queryset->getQuery();
        }
    }

    abstract function fillTo($index);

    function asArray() {
        $this->fillTo(PHP_INT_MAX);
        return $this->cache;
    }

    // Iterator interface
    function rewind() {
        $this->position = 0;
    }
    function current() {
        $this->fillTo($this->position);
        return $this->cache[$this->position];
    }
    function key() {
        return $this->position;
    }
    function next() {
        $this->position++;
    }
    function valid() {
        $this->fillTo($this->position);
        return count($this->cache) > $this->position;
    }

    // ArrayAccess interface
    function offsetExists($offset) {
        $this->fillTo($offset);
        return $this->position >= $offset;
    }
    function offsetGet($offset) {
        $this->fillTo($offset);
        return $this->cache[$offset];
    }
    function offsetUnset($a) {
        throw new Exception(sprintf(__('%s is read-only'), get_class($this)));
    }
    function offsetSet($a, $b) {
        throw new Exception(sprintf(__('%s is read-only'), get_class($this)));
    }
}

class ModelInstanceManager extends ResultSet {
    var $model;
    var $map;

    static $objectCache = array();

    function __construct($queryset=false) {
        parent::__construct($queryset);
        if ($queryset) {
            $this->map = $this->resource->getMap();
        }
    }

    function cache($model) {
        $model::_inspect();
        $key = sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->pk));
        self::$objectCache[$key] = $model;
    }

    /**
     * uncache
     *
     * Drop the cached reference to the model. If the model is deleted
     * database-side. Lookups for the same model should not be short
     * circuited to retrieve the cached reference.
     */
    static function uncache($model) {
        $key = sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->pk));
        unset(self::$objectCache[$key]);
    }

    static function checkCache($modelClass, $fields) {
        $key = $modelClass::$meta->model;
        foreach ($modelClass::$meta['pk'] as $f)
            $key .= '.'.$fields[$f];
        return @self::$objectCache[$key];
    }

    /**
     * getOrBuild
     *
     * Builds a new model from the received fields or returns the model
     * already stashed in the model cache. Caching helps to ensure that
     * multiple lookups for the same model identified by primary key will
     * fetch the exact same model. Therefore, changes made to the model
     * anywhere in the project will be reflected everywhere.
     *
     * For annotated models (models build from querysets with annotations),
     * the built or cached model is wrapped in an AnnotatedModel instance.
     * The annotated fields are in the AnnotatedModel instance and the
     * database-backed fields are managed by the Model instance.
     */
    function getOrBuild($modelClass, $fields, $cache=true) {
        // Check for NULL primary key, used with related model fetching. If
        // the PK is NULL, then consider the object to also be NULL
        foreach ($modelClass::$meta['pk'] as $pkf) {
            if (!isset($fields[$pkf])) {
                return null;
            }
        }
        $annotations = $this->queryset->annotations;
        $extras = array();
        // For annotations, drop them from the $fields list and add them to
        // an $extras list. The fields passed to the root model should only
        // be the root model's fields. The annotated fields will be wrapped
        // using an AnnotatedModel instance.
        if ($annotations && $modelClass == $this->model) {
            foreach ($annotations as $name=>$A) {
                if (isset($fields[$name])) {
                    $extras[$name] = $fields[$name];
                    unset($fields[$name]);
                }
            }
        }
        // Check the cache for the model instance first
        if (!($m = self::checkCache($modelClass, $fields))) {
            // Construct and cache the object
            $m = new $modelClass($fields);
            // XXX: defer may refer to fields not in this model
            $m->__deferred__ = $this->queryset->defer;
            $m->__onload();
            if ($cache)
                $this->cache($m);
        }
        // Wrap annotations in an AnnotatedModel
        if ($extras) {
            $m = new AnnotatedModel($m, $extras);
        }
        // TODO: If the model has deferred fields which are in $fields,
        // those can be resolved here
        return $m;
    }

    /**
     * buildModel
     *
     * This method builds the model including related models from the record
     * received. For related recordsets, a $map should be setup inside this
     * object prior to using this method. The $map is assumed to have this
     * configuration:
     *
     * array(array(<fieldNames>, <modelClass>, <relativePath>))
     *
     * Where $modelClass is the name of the foreign (with respect to the
     * root model ($this->model), $fieldNames is the number and names of
     * fields in the row for this model, $relativePath is the path that
     * describes the relationship between the root model and this model,
     * 'user__account' for instance.
     */
    function buildModel($row) {
        // TODO: Traverse to foreign keys
        if ($this->map) {
            if ($this->model != $this->map[0][1])
                throw new OrmException('Internal select_related error');

            $offset = 0;
            foreach ($this->map as $info) {
                @list($fields, $model_class, $path) = $info;
                $values = array_slice($row, $offset, count($fields));
                $record = array_combine($fields, $values);
                if (!$path) {
                    // Build the root model
                    $model = $this->getOrBuild($this->model, $record);
                }
                elseif ($model) {
                    $i = 0;
                    // Traverse the declared path and link the related model
                    $tail = array_pop($path);
                    $m = $model;
                    foreach ($path as $field) {
                        $m = $m->get($field);
                    }
                    $m->set($tail, $this->getOrBuild($model_class, $record));
                }
                $offset += count($fields);
            }
        }
        else {
            $model = $this->getOrBuild($this->model, $row);
        }
        return $model;
    }

    function fillTo($index) {
        $func = ($this->map) ? 'getRow' : 'getArray';
        while ($this->resource && $index >= count($this->cache)) {
            if ($row = $this->resource->{$func}()) {
                $this->cache[] = $this->buildModel($row);
            } else {
                $this->resource->close();
                $this->resource = null;
                break;
            }
        }
    }
}

class FlatArrayIterator extends ResultSet {
    function __construct($queryset) {
        $this->resource = $queryset->getQuery();
    }
    function fillTo($index) {
        while ($this->resource && $index >= count($this->cache)) {
            if ($row = $this->resource->getRow()) {
                $this->cache[] = $row;
            } else {
                $this->resource->close();
                $this->resource = null;
                break;
            }
        }
    }
}

class HashArrayIterator extends ResultSet {
    function __construct($queryset) {
        $this->resource = $queryset->getQuery();
    }
    function fillTo($index) {
        while ($this->resource && $index >= count($this->cache)) {
            if ($row = $this->resource->getArray()) {
                $this->cache[] = $row;
            } else {
                $this->resource->close();
                $this->resource = null;
                break;
            }
        }
    }
}

class InstrumentedList extends ModelInstanceManager {
    var $key;
    var $id;
    var $model;

    function __construct($fkey, $queryset=false) {
        list($model, $this->key, $this->id) = $fkey;
        if (!$queryset)
            $queryset = $model::objects()->filter(array($this->key=>$this->id));
        parent::__construct($queryset);
        $this->model = $model;
        if (!$this->id)
            $this->resource = null;
    }

    function add($object, $at=false) {
        if (!$object || !$object instanceof $this->model)
            throw new Exception(__('Attempting to add invalid object to list'));

        $object->set($this->key, $this->id);
        $object->save();

        if ($at !== false)
            $this->cache[$at] = $object;
        else
            $this->cache[] = $object;
    }
    function remove($object) {
        $object->delete();
    }

    function reset() {
        $this->cache = array();
    }

    // QuerySet delegates
    function count() {
        return $this->queryset->count();
    }
    function exists() {
        return $this->queryset->exists();
    }
    function expunge() {
        if ($this->queryset->delete())
            $this->reset();
    }
    function update(array $what) {
        return $this->queryset->update($what);
    }

    // Fetch a new QuerySet
    function objects() {
        return clone $this->queryset;
    }

    function offsetUnset($a) {
        $this->fillTo($a);
        $this->cache[$a]->delete();
    }
    function offsetSet($a, $b) {
        $this->fillTo($a);
        $this->cache[$a]->delete();
        $this->add($b, $a);
    }

    // QuerySet overriedes
    function filter() {
        return call_user_func_array(array($this->objects(), 'filter'), func_get_args());
    }
    function exclude() {
        return call_user_func_array(array($this->objects(), 'exclude'), func_get_args());
    }
    function order_by() {
        return call_user_func_array(array($this->objects(), 'order_by'), func_get_args());
    }
    function limit($how) {
        return $this->objects()->limit($how);
    }
}

class SqlCompiler {
    var $options = array();
    var $params = array();
    var $joins = array();
    var $aliases = array();
    var $alias_num = 1;

    static $operators = array(
        'exact' => '%$1s = %$2s'
    );

    function __construct($options=false) {
        if ($options)
            $this->options = array_merge($this->options, $options);
    }

    /**
     * Handles breaking down a field or model search descriptor into the
     * model search path, field, and operator parts. When used in a queryset
     * filter, an expression such as
     *
     * user__email__hostname__contains => 'foobar'
     *
     * would be broken down to search from the root model (passed in,
     * perhaps a ticket) to the user and email models by inspecting the
     * model metadata 'joins' property. The 'constraint' value found there
     * will be used to build the JOIN sql clauses.
     *
     * The 'hostname' will be the field on 'email' model that should be
     * compared in the WHERE clause. The comparison should be made using a
     * 'contains' function, which in MySQL, might be implemented using
     * something like "<lhs> LIKE '%foobar%'"
     *
     * This function will rely heavily on the pushJoin() function which will
     * handle keeping track of joins made previously in the query and
     * therefore prevent multiple joins to the same table for the same
     * reason. (Self joins are still supported).
     *
     * Comparison functions supported by this function are defined for each
     * respective SqlCompiler subclass; however at least these functions
     * should be defined:
     *
     *      function    a__function => b
     *      ----------+------------------------------------------------
     *      exact     | a is exactly equal to b
     *      gt        | a is greater than b
     *      lte       | b is greater than a
     *      lt        | a is less than b
     *      gte       | b is less than a
     *      ----------+------------------------------------------------
     *      contains  | (string) b is contained within a
     *      statswith | (string) first len(b) chars of a are exactly b
     *      endswith  | (string) last len(b) chars of a are exactly b
     *      like      | (string) a matches pattern b
     *      ----------+------------------------------------------------
     *      in        | a is in the list or the nested queryset b
     *      ----------+------------------------------------------------
     *      isnull    | a is null (if b) else a is not null
     *
     * If no comparison function is declared in the field descriptor,
     * 'exact' is assumed.
     *
     * Parameters:
     * $field - (string) name of the field to join
     * $model - (VerySimpleModel) root model for references in the $field
     *      parameter
     * $options - (array) extra options for the compiler
     *      'table' => return the table alias rather than the field-name
     *      'model' => return the target model class rather than the operator
     *      'constraint' => extra constraint for join clause
     *
     * Returns:
     * (mixed) Usually array<field-name, operator> where field-name is the
     * name of the field in the destination model, and operator is the
     * requestion comparison method.
     */
    function getField($field, $model, $options=array()) {
        // Break apart the field descriptor by __ (double-underbars). The
        // first part is assumed to be the root field in the given model.
        // The parts after each of the __ pieces are links to other tables.
        // The last item (after the last __) is allowed to be an operator
        // specifiction.
        $parts = explode('__', $field);
        $operator = static::$operators['exact'];
        if (!isset($options['table'])) {
            $field = array_pop($parts);
            if (array_key_exists($field, static::$operators)) {
                $operator = static::$operators[$field];
                $field = array_pop($parts);
            }
        }

        $path = array();

        // Determine the alias for the root model table
        $alias = (isset($this->joins['']))
            ? $this->joins['']['alias']
            : $this->quote($model::$meta['table']);

        // Call pushJoin for each segment in the join path. A new JOIN
        // fragment will need to be emitted and/or cached
        $self = $this;
        $push = function($p, $path, $extra=false) use (&$model, $self) {
            $model::_inspect();
            if (!($info = $model::$meta['joins'][$p])) {
                throw new OrmException(sprintf(
                   'Model `%s` does not have a relation called `%s`',
                    $model, $p));
            }
            $crumb = implode('__', $path);
            $path[] = $p;
            $tip = implode('__', $path);
            $alias = $self->pushJoin($crumb, $tip, $model, $info, $extra);
            // Roll to foreign model
            foreach ($info['constraint'] as $local => $foreign) {
                list($model, $f) = explode('.', $foreign);
                if (class_exists($model))
                    break;
            }
            return array($alias, $f);
        };

        foreach ($parts as $i=>$p) {
            list($alias) = $push($p, $path, @$options['constraint']);
            $path[] = $p;
        }

        // If comparing a relationship, join the foreign table
        // This is a comparison with a relationship — use the foreign key
        if (isset($model::$meta['joins'][$field])) {
            list($alias, $field) = $push($field, $path, @$options['constraint']);
        }

        if (isset($options['table']) && $options['table'])
            $field = $alias;
        elseif (isset($this->annotations[$field]))
            $field = $this->annotations[$field];
        elseif ($alias)
            $field = $alias.'.'.$this->quote($field);
        else
            $field = $this->quote($field);

        if (isset($options['model']) && $options['model'])
            $operator = $model;
        return array($field, $operator);
    }

    /**
     * Uses the compiler-specific `compileJoin` function to compile the join
     * statement fragment, and caches the result in the local $joins list. A
     * new alias is acquired using the `nextAlias` function which will be
     * associated with the join. If the same path is requested again, the
     * algorithm is short-circuited and the originally-assigned table alias
     * is returned immediately.
     */
    function pushJoin($tip, $path, $model, $info, $constraint=false) {
        // TODO: Build the join statement fragment and return the table
        // alias. The table alias will be useful where the join is used in
        // the WHERE and ORDER BY clauses

        // If the join already exists for the statement-being-compiled, just
        // return the alias being used.
        if (!$constraint && isset($this->joins[$path]))
            return $this->joins[$path]['alias'];

        // TODO: Support only using aliases if necessary. Use actual table
        // names for everything except oddities like self-joins

        $alias = $this->nextAlias();
        // Keep an association between the table alias and the model. This
        // will make model construction much easier when we have the data
        // and the table alias from the database.
        $this->aliases[$alias] = $model;

        // TODO: Stash joins and join constraints into local ->joins array.
        // This will be useful metadata in the executor to construct the
        // final models for fetching
        // TODO: Always use a table alias. This will further help with
        // coordination between the data returned from the database (where
        // table alias is available) and the corresponding data.
        $T = array('alias' => $alias);
        $this->joins[$path] = &$T;
        $T['sql'] = $this->compileJoin($tip, $model, $alias, $info, $constraint);
        return $alias;
    }

    /**
     * compileQ
     *
     * Build a constraint represented in an arbitrarily nested Q instance.
     * The placement of the compiled constraint is also considered and
     * represented in the resulting CompiledExpression instance.
     *
     * Parameters:
     * $Q - (Q) constraint represented in a Q instance
     * $model - (VerySimpleModel) root model for all the field references in
     *      the Q instance
     * $slot - (int) slot for inputs to be placed. Useful to differenciate
     *      inputs placed in the joins and where clauses for SQL engines
     *      which do not support named parameters.
     *
     * Returns:
     * (CompiledExpression) object containing the compiled expression (with
     * AND, OR, and NOT operators added). Furthermore, the $type attribute
     * of the CompiledExpression will allow the compiler to place the
     * constraint properly in the WHERE or HAVING clause appropriately.
     */
    function compileQ(Q $Q, $model, $slot=false) {
        $filter = array();
        $type = CompiledExpression::TYPE_WHERE;
        foreach ($Q->constraints as $field=>$value) {
            // Handle nested constraints
            if ($value instanceof Q) {
                $filter[] = $T = $this->compileQ($value, $model, $slot);
                // Bubble up HAVING constraints
                if ($T instanceof CompiledExpression
                        && $T->type == CompiledExpression::TYPE_HAVING)
                    $type = $T->type;
            }
            // Handle relationship comparisons with model objects
            elseif ($value instanceof VerySimpleModel) {
                $criteria = array();
                foreach ($value->pk as $f=>$v) {
                    $f = $field . '__' . $f;
                    $criteria[$f] = $v;
                }
                $filter[] = $this->compileQ(new Q($criteria), $model, $slot);
            }
            // Handle simple field = <value> constraints
            else {
                list($field, $op) = $this->getField($field, $model);
                if ($field instanceof SqlAggregate) {
                    // This constraint has to go in the HAVING clause
                    $field = $field->toSql($this, $model);
                    $type = CompiledExpression::TYPE_HAVING;
                }
                if ($value === null)
                    $filter[] = sprintf('%s IS NULL', $field);
                // Allow operators to be callable rather than sprintf
                // strings
                elseif (is_callable($op))
                    $filter[] = call_user_func($op, $field, $value, $model);
                else
                    $filter[] = sprintf($op, $field, $this->input($value, $slot));
            }
        }
        $glue = $Q->isOred() ? ' OR ' : ' AND ';
        $clause = implode($glue, $filter);
        if (count($filter) > 1)
            $clause = '(' . $clause . ')';
        if ($Q->isNegated())
            $clause = 'NOT '.$clause;
        return new CompiledExpression($clause, $type);
    }

    function compileConstraints($where, $model) {
        $constraints = array();
        foreach ($where as $Q) {
            $constraints[] = $this->compileQ($Q, $model);
        }
        return $constraints;
    }

    function getParams() {
        return $this->params;
    }

    function getJoins($queryset) {
        $sql = '';
        foreach ($this->joins as $j)
            $sql .= $j['sql'];
        // Add extra items from QuerySet
        if (isset($queryset->extra['tables'])) {
            foreach ($queryset->extra['tables'] as $S) {
                $join = ' JOIN ';
                // Left joins require an ON () clause
                if ($lastparen = strrpos($S, '(')) {
                    if (preg_match('/\bon\b/i', substr($S, $lastparen - 4, 4)))
                        $join = ' LEFT' . $join;
                }
                $sql .= $join.$S;
            }
        }
        return $sql;
    }

    function nextAlias() {
        // Use alias A1-A9,B1-B9,...
        $alias = chr(65 + (int)($this->alias_num / 9)) . $this->alias_num % 9;
        $this->alias_num++;
        return $alias;
    }
}

class CompiledExpression /* extends SplString */ {
    const TYPE_WHERE =   0x0001;
    const TYPE_HAVING =  0x0002;

    var $text = '';

    function __construct($clause, $type=self::TYPE_WHERE) {
        $this->text = $clause;
        $this->type = $type;
    }

    function __toString() {
        return $this->text;
    }
}

class DbEngine {

    static $compiler = 'MySqlCompiler';

    function __construct($info) {
    }

    function connect() {
    }

    // Gets a compiler compatible with this database engine that can compile
    // and execute a queryset or DML request.
    static function getCompiler() {
        $class = static::$compiler;
        return new $class();
    }

    static function delete(VerySimpleModel $model) {
        ModelInstanceManager::uncache($model);
        return static::getCompiler()->compileDelete($model);
    }

    static function save(VerySimpleModel $model) {
        $compiler = static::getCompiler();
        if ($model->__new__)
            return $compiler->compileInsert($model);
        else
            return $compiler->compileUpdate($model);
    }
}

class MySqlCompiler extends SqlCompiler {

    // Consts for ::input()
    const SLOT_JOINS = 1;
    const SLOT_WHERE = 2;

    protected $input_join_count = 0;

    static $operators = array(
        'exact' => '%1$s = %2$s',
        'contains' => array('self', '__contains'),
        'startswith' => array('self', '__startswith'),
        'endswith' => array('self', '__endswith'),
        'gt' => '%1$s > %2$s',
        'lt' => '%1$s < %2$s',
        'gte' => '%1$s >= %2$s',
        'lte' => '%1$s <= %2$s',
        'isnull' => array('self', '__isnull'),
        'like' => '%1$s LIKE %2$s',
        'hasbit' => '%1$s & %2$s != 0',
        'in' => array('self', '__in'),
        'intersect' => array('self', '__find_in_set'),
    );

    // Thanks, http://stackoverflow.com/a/3683868
    function like_escape($what, $e='\\') {
        return str_replace(array($e, '%', '_'), array($e.$e, $e.'%', $e.'_'), $what);
    }

    function __contains($a, $b) {
        # {%a} like %{$b}%
        # Escape $b
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("%$b%"));
    }
    function __startswith($a, $b) {
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("$b%"));
    }
    function __endswith($a, $b) {
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("%$b"));
    }

    function __in($a, $b) {
        if (is_array($b)) {
            $vals = array_map(array($this, 'input'), $b);
            $b = implode(', ', $vals);
        }
        else {
            $b = $this->input($b);
        }
        return sprintf('%s IN (%s)', $a, $b);
    }

    function __isnull($a, $b) {
        return $b
            ? sprintf('%s IS NULL', $a)
            : sprintf('%s IS NOT NULL', $a);
    }

    function __find_in_set($a, $b) {
        if (is_array($b)) {
            $sql = array();
            foreach (array_map(array($this, 'input'), $b) as $b) {
                $sql[] = sprintf('FIND_IN_SET(%s, %s)', $b, $a);
            }
            $parens = count($sql) > 1;
            $sql = implode(' OR ', $sql);
            return $parens ? ('('.$sql.')') : $sql;
        }
        return sprintf('FIND_IN_SET(%s, %s)', $b, $a);
    }

    function compileJoin($tip, $model, $alias, $info, $extra=false) {
        $constraints = array();
        $join = ' JOIN ';
        if (isset($info['null']) && $info['null'])
            $join = ' LEFT'.$join;
        if (isset($this->joins[$tip]))
            $table = $this->joins[$tip]['alias'];
        else
            $table = $this->quote($model::$meta['table']);
        foreach ($info['constraint'] as $local => $foreign) {
            list($rmodel, $right) = explode('.', $foreign);
            // Support a constant constraint with
            // "'constant'" => "Model.field_name"
            if ($local[0] == "'") {
                $constraints[] = sprintf("%s.%s = %s",
                    $alias, $this->quote($right),
                    $this->input(trim($local, '\'"'), self::SLOT_JOINS)
                );
            }
            else {
                $constraints[] = sprintf("%s.%s = %s.%s",
                    $table, $this->quote($local), $alias,
                    $this->quote($right)
                );
            }
        }
        // Support extra join constraints
        if ($extra instanceof Q) {
            $constraints[] = $this->compileQ($extra, $model, self::SLOT_JOINS);
        }
        // Support inline views
        $table = ($rmodel::$meta['view'])
            ? $rmodel::getQuery($this)
            : $this->quote($rmodel::$meta['table']);
        return $join.$table
            .' '.$alias.' ON ('.implode(' AND ', $constraints).')';
    }

    /**
     * input
     *
     * Generate a parameterized input for a database query. Input value is
     * received by reference to avoid copying.
     *
     * Parameters:
     * $what - (mixed) value to be sent to the database. No escaping is
     *      necessary. Pass a raw value here.
     * $slot - (int) clause location of the input in compiled SQL statement.
     *      Currently, SLOT_JOINS and SLOT_WHERE is supported. SLOT_JOINS
     *      inputs are inserted ahead of the SLOT_WHERE inputs as the joins
     *      come logically before the where claused in the finalized
     *      statement.
     *
     * Returns:
     * (string) token to be placed into the compiled SQL statement. For
     * MySQL, this is always the string '?'.
     */
    function input($what, $slot=false) {
        if ($what instanceof QuerySet) {
            $q = $what->getQuery(array('nosort'=>true));
            $this->params = array_merge($this->params, $q->params);
            return $q->sql;
        }
        elseif ($what instanceof SqlFunction) {
            return $what->toSql($this);
        }
        else {
            switch ($slot) {
            case self::SLOT_JOINS:
                // This should be inserted before the WHERE inputs
                array_splice($this->params, $this->input_join_count++, 0,
                    array($what));
                break;
            default:
                $this->params[] = $what;
            }
            return '?';
        }
    }

    function quote($what) {
        return "`$what`";
    }

    /**
     * getWhereClause
     *
     * This builds the WHERE ... part of a DML statement. This should be
     * called before ::getJoins(), because it may add joins into the
     * statement based on the relationships used in the where clause
     */
    protected function getWhereHavingClause($queryset) {
        $model = $queryset->model;
        $constraints = $this->compileConstraints($queryset->constraints, $model);
        $where = $having = array();
        foreach ($constraints as $C) {
            if ($C->type == CompiledExpression::TYPE_WHERE)
                $where[] = $C;
            else
                $having[] = $C;
        }
        if (isset($queryset->extra['where'])) {
            foreach ($queryset->extra['where'] as $S) {
                $where[] = '('.$S.')';
            }
        }
        if ($where)
            $where = ' WHERE '.implode(' AND ', $where);
        if ($having)
            $having = ' HAVING '.implode(' AND ', $having);
        return array($where ?: '', $having ?: '');
    }

    function compileCount($queryset) {
        $model = $queryset->model;
        $table = $model::$meta['table'];
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'SELECT COUNT(*) AS count FROM '.$this->quote($table).$joins.$where;
        $exec = new MysqlExecutor($sql, $this->params);
        $row = $exec->getArray();
        return $row['count'];
    }

    function compileSelect($queryset) {
        $model = $queryset->model;
        // Use an alias for the root model table
        $this->joins[''] = array('alias' => ($rootAlias = $this->nextAlias()));

        // Compile the WHERE clause
        $this->annotations = $queryset->annotations ?: array();
        list($where, $having) = $this->getWhereHavingClause($queryset);

        // Compile the ORDER BY clause
        $sort = '';
        if (($columns = $queryset->getSortFields()) && !isset($this->options['nosort'])) {
            $orders = array();
            foreach ($columns as $sort) {
                $dir = 'ASC';
                if ($sort instanceof SqlFunction) {
                    $field = $sort->toSql($this, $model);
                }
                else {
                    if ($sort[0] == '-') {
                        $dir = 'DESC';
                        $sort = substr($sort, 1);
                    }
                    list($field) = $this->getField($sort, $model);
                }
                // TODO: Throw exception if $field can be indentified as
                //       invalid
                if ($field instanceof SqlFunction)
                    $field = $field->toSql($this, $model);

                $orders[] = $field.' '.$dir;
            }
            $sort = ' ORDER BY '.implode(', ', $orders);
        }

        // Compile the field listing
        $fields = array();
        $table = $this->quote($model::$meta['table']).' '.$rootAlias;
        // Handle related tables
        if ($queryset->related) {
            $count = 0;
            $fieldMap = $theseFields = array();
            $defer = $queryset->defer ?: array();
            // Add local fields first
            $model::_inspect();
            foreach ($model::$meta['fields'] as $f) {
                // Handle deferreds
                if (isset($defer[$f]))
                    continue;
                $fields[$rootAlias . '.' . $this->quote($f)] = true;
                $theseFields[] = $f;
            }
            $fieldMap[] = array($theseFields, $model);
            // Add the JOINs to this query
            foreach ($queryset->related as $sr) {
                // XXX: Sort related by the paths so that the shortest paths
                //      are resolved first when building out the models.
                $full_path = '';
                $parts = array();
                // Track each model traversal and fetch data for each of the
                // models in the path of the related table
                foreach (explode('__', $sr) as $field) {
                    $full_path .= $field;
                    $parts[] = $field;
                    $theseFields = array();
                    list($alias, $fmodel) = $this->getField($full_path, $model,
                        array('table'=>true, 'model'=>true));
                    $fmodel::_inspect();
                    foreach ($fmodel::$meta['fields'] as $f) {
                        // Handle deferreds
                        if (isset($defer[$sr . '__' . $f]))
                            continue;
                        elseif (isset($fields[$alias.'.'.$this->quote($f)]))
                            continue;
                        $fields[$alias . '.' . $this->quote($f)] = true;
                        $theseFields[] = $f;
                    }
                    if ($theseFields) {
                        $fieldMap[] = array($theseFields, $fmodel, $parts);
                    }
                    $full_path .= '__';
                }
            }
        }
        // Support retrieving only a list of values rather than a model
        elseif ($queryset->values) {
            foreach ($queryset->values as $alias=>$v) {
                list($f) = $this->getField($v, $model);
                if ($f instanceof SqlFunction)
                    $fields[$f->toSql($this, $model, $alias)] = true;
                else {
                    if (!is_int($alias))
                        $f .= ' AS '.$this->quote($alias);
                    $fields[$f] = true;
                }
            }
        }
        // Simple selection from one table
        else {
            if ($queryset->defer) {
                $model::_inspect();
                foreach ($model::$meta['fields'] as $f) {
                    if (isset($queryset->defer[$f]))
                        continue;
                    $fields[$rootAlias .'.'. $this->quote($f)] = true;
                }
            }
            else {
                $fields[$rootAlias.'.*'] = true;
            }
        }
        $fields = array_keys($fields);
        $group_by = array();
        // Add in annotations
        if ($queryset->annotations) {
            foreach ($queryset->annotations as $alias=>$A) {
                // The root model will receive the annotations, add in the
                // annotation after the root model's fields
                $T = $A->toSql($this, $model, $alias);
                if ($fieldMap) {
                    array_splice($fields, count($fieldMap[0][0]), 0, array($T));
                    $fieldMap[0][0][] = $A->getAlias();
                }
                else {
                    // No field map — just add to end of field list
                    $fields[] = $T;
                }
            }
            foreach ($model::$meta['pk'] as $pk)
                $group_by[] = $rootAlias .'.'. $pk;
        }
        // Add in SELECT extras
        if (isset($queryset->extra['select'])) {
            foreach ($queryset->extra['select'] as $name=>$expr) {
                if ($expr instanceof SqlFunction)
                    $expr = $expr->toSql($this, false, $name);
                $fields[] = $expr;
            }
        }
        if (isset($queryset->distinct)) {
            foreach ($queryset->distinct as $d)
                list($group_by[]) = $this->getField($d, $model);
        }
        $group_by = $group_by ? ' GROUP BY '.implode(',', $group_by) : '';

        $joins = $this->getJoins($queryset);

        $sql = 'SELECT '.implode(', ', $fields).' FROM '
            .$table.$joins.$where.$group_by.$having.$sort;
        if ($queryset->limit)
            $sql .= ' LIMIT '.$queryset->limit;
        if ($queryset->offset)
            $sql .= ' OFFSET '.$queryset->offset;
        switch ($queryset->lock) {
        case QuerySet::LOCK_EXCLUSIVE:
            $sql .= ' FOR UPDATE';
            break;
        case QuerySet::LOCK_SHARED:
            $sql .= ' LOCK IN SHARE MODE';
            break;
        }

        return new MysqlExecutor($sql, $this->params, $fieldMap);
    }

    function __compileUpdateSet($model, array $pk) {
        $fields = array();
        foreach ($model->dirty as $field=>$old) {
            if ($model->__new__ or !in_array($field, $pk)) {
                $fields[] = sprintf('%s = %s', $this->quote($field),
                    $this->input($model->get($field)));
            }
        }
        return ' SET '.implode(', ', $fields);
    }

    function compileUpdate(VerySimpleModel $model) {
        $pk = $model::$meta['pk'];
        $sql = 'UPDATE '.$this->quote($model::$meta['table']);
        $sql .= $this->__compileUpdateSet($model, $pk);
        // Support PK updates
        $criteria = array();
        foreach ($pk as $f) {
            $criteria[$f] = @$model->dirty[$f] ?: $model->get($f);
        }
        $sql .= ' WHERE '.$this->compileQ(new Q($criteria), $model);
        $sql .= ' LIMIT 1';

        return new MySqlExecutor($sql, $this->params);
    }

    function compileInsert(VerySimpleModel $model) {
        $pk = $model::$meta['pk'];
        $sql = 'INSERT INTO '.$this->quote($model::$meta['table']);
        $sql .= $this->__compileUpdateSet($model, $pk);

        return new MySqlExecutor($sql, $this->params);
    }

    function compileDelete($model) {
        $table = $model::$meta['table'];

        $where = ' WHERE '.implode(' AND ',
            $this->compileConstraints(array(new Q($model->pk)), $model));
        $sql = 'DELETE FROM '.$this->quote($table).$where.' LIMIT 1';
        return new MySqlExecutor($sql, $this->params);
    }

    function compileBulkDelete($queryset) {
        $model = $queryset->model;
        $table = $model::$meta['table'];
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'DELETE '.$this->quote($table).'.* FROM '
            .$this->quote($table).$joins.$where;
        return new MysqlExecutor($sql, $this->params);
    }

    function compileBulkUpdate($queryset, array $what) {
        $model = $queryset->model;
        $table = $model::$meta['table'];
        $set = array();
        foreach ($what as $field=>$value)
            $set[] = sprintf('%s = %s', $this->quote($field), $this->input($value));
        $set = implode(', ', $set);
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'UPDATE '.$this->quote($table).' SET '.$set.$joins.$where;
        return new MysqlExecutor($sql, $this->params);
    }

    // Returns meta data about the table used to build queries
    function inspectTable($table) {
        static $cache = array();

        // XXX: Assuming schema is not changing — add support to track
        //      current schema
        if (isset($cache[$table]))
            return $cache[$table];

        $sql = 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            .'WHERE TABLE_NAME = '.db_input($table).' AND TABLE_SCHEMA = DATABASE() '
            .'ORDER BY ORDINAL_POSITION';
        $ex = new MysqlExecutor($sql, array());
        $columns = array();
        while (list($column) = $ex->getRow()) {
            $columns[] = $column;
        }
        return $cache[$table] = $columns;
    }
}

class MysqlExecutor {

    var $stmt;
    var $fields = array();
    var $sql;
    var $params;
    // Array of [count, model] values representing which fields in the
    // result set go with witch model.  Useful for handling select_related
    // queries
    var $map;

    function __construct($sql, $params, $map=null) {
        $this->sql = $sql;
        $this->params = $params;
        $this->map = $map;
    }

    function getMap() {
        return $this->map;
    }

    function _prepare() {
        $this->execute();
        $this->_setup_output();
    }

    function execute() {
        if (!($this->stmt = db_prepare($this->sql)))
            throw new Exception('Unable to prepare query: '.db_error()
                .' '.$this->sql);
        if (count($this->params))
            $this->_bind($this->params);
        if (!$this->stmt->execute() || ! $this->stmt->store_result()) {
            throw new OrmException('Unable to execute query: ' . $this->stmt->error);
        }
        return true;
    }

    function _bind($params) {
        if (count($params) != $this->stmt->param_count)
            throw new Exception(__('Parameter count does not match query'));

        $types = '';
        $ps = array();
        foreach ($params as &$p) {
            if (is_int($p) || is_bool($p))
                $types .= 'i';
            elseif (is_float($p))
                $types .= 'd';
            elseif (is_string($p))
                $types .= 's';
            // TODO: Emit error if param is null
            $ps[] = &$p;
        }
        unset($p);
        array_unshift($ps, $types);
        call_user_func_array(array($this->stmt,'bind_param'), $ps);
    }

    function _setup_output() {
        if (!($meta = $this->stmt->result_metadata()))
            throw new OrmException('Unable to fetch statment metadata: ', $this->stmt->error);
        $this->fields = $meta->fetch_fields();
        $meta->free_result();
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->stmt))
            $this->_prepare();
        $this->stmt->data_seek(0);
    }

    function next() {
        $status = $this->stmt->fetch();
        if ($status === false)
            throw new OrmException($this->stmt->error);
        elseif ($status === null) {
            $this->close();
            return false;
        }
        return true;
    }

    function getArray() {
        $output = array();
        $variables = array();

        if (!isset($this->stmt))
            $this->_prepare();

        foreach ($this->fields as $f)
            $variables[] = &$output[$f->name]; // pass by reference

        if (!call_user_func_array(array($this->stmt, 'bind_result'), $variables))
            throw new OrmException('Unable to bind result: ' . $this->stmt->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function getRow() {
        $output = array();
        $variables = array();

        if (!isset($this->stmt))
            $this->_prepare();

        foreach ($this->fields as $f)
            $variables[] = &$output[]; // pass by reference

        if (!call_user_func_array(array($this->stmt, 'bind_result'), $variables))
            throw new OrmException('Unable to bind result: ' . $this->stmt->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function close() {
        if (!$this->stmt)
            return;

        $this->stmt->close();
        $this->stmt = null;
    }

    function affected_rows() {
        return $this->stmt->affected_rows;
    }

    function insert_id() {
        return $this->stmt->insert_id;
    }

    function __toString() {
        $self = $this;
        $x = 0;
        return preg_replace_callback('/\?/', function($m) use ($self, &$x) {
            $p = $self->params[$x++];
            return db_real_escape($p, is_string($p));
        }, $this->sql);
    }
}

class Q implements Serializable {
    const NEGATED = 0x0001;
    const ANY =     0x0002;

    var $constraints;
    var $flags;
    var $negated = false;
    var $ored = false;

    function __construct($filter=array(), $flags=0) {
        if (!is_array($filter))
            $filter = array($filter);
        $this->constraints = $filter;
        $this->negated = $flags & self::NEGATED;
        $this->ored = $flags & self::ANY;
    }

    function isNegated() {
        return $this->negated;
    }

    function isOred() {
        return $this->ored;
    }

    function negate() {
        $this->negated = !$this->negated;
        return $this;
    }

    function union() {
        $this->ored = true;
    }

    function add($constraints) {
        if (is_array($constraints))
            $this->constraints = array_merge($this->constraints, $constraints);
        elseif ($constraints instanceof static)
            $this->constraints[] = $constraints;
        else
            throw new InvalidArgumentException('Expected an instance of Q or an array thereof');
        return $this;
    }

    static function not(array $constraints) {
        return new static($constraints, self::NEGATED);
    }

    static function any(array $constraints) {
        return new static($constraints, self::ANY);
    }

    function serialize() {
        return serialize(array(
            'f' =>
                ($this->negated ? self::NEGATED : 0)
              | ($this->ored ? self::ANY : 0),
            'c' => $this->constraints
        ));
    }

    function unserialize($data) {
        $data = unserialize($data);
        $this->constraints = $data['c'];
        $this->ored = $data['f'] & self::ANY;
        $this->negated = $data['f'] & self::NEGATED;
    }
}
?>
