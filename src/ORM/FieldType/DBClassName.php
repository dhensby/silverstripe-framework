<?php

namespace SilverStripe\ORM\FieldType;

use Doctrine\DBAL\Types\Type;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Represents a classname selector, which respects obsolete clasess.
 */
class DBClassName extends DBEnum
{

    /**
     * Base classname of class to enumerate.
     * If 'DataObject' then all classes are included.
     * If empty, then the baseClass of the parent object will be used
     *
     * @var string|null
     */
    protected $baseClass = null;

    /**
     * Parent object
     *
     * @var DataObject|null
     */
    protected $record = null;

    /**
     * Classname spec cache for obsolete classes. The top level keys are the table, each of which contains
     * nested arrays with keys mapped to field names. The values of the lowest level array are the classnames
     *
     * @var array
     */
    protected static $classname_cache = array();

    /**
     * Clear all cached classname specs. It's necessary to clear all cached subclassed names
     * for any classes if a new class manifest is generated.
     */
    public static function clear_classname_cache()
    {
        self::$classname_cache = array();
    }

    /**
     * Create a new DBClassName field
     *
     * @param string $name Name of field
     * @param string|null $baseClass Optional base class to limit selections
     */
    public function __construct($name = null, $baseClass = null)
    {
        $this->setBaseClass($baseClass);
        parent::__construct($name);
    }

    public function getDBType()
    {
        return Type::STRING;
    }

    /**
     * Get the base dataclass for the list of subclasses
     *
     * @return string
     */
    public function getBaseClass()
    {
        // Use explicit base class
        if ($this->baseClass) {
            return $this->baseClass;
        }
        // Default to the basename of the record
        $schema = DataObject::getSchema();
        if ($this->record) {
            return $schema->baseDataClass($this->record);
        }
        // During dev/build only the table is assigned
        $tableClass = $schema->tableClass($this->getTable());
        if ($tableClass && ($baseClass = $schema->baseDataClass($tableClass))) {
            return $baseClass;
        }
        // Fallback to global default
        return DataObject::class;
    }

    /**
     * Assign the base class
     *
     * @param string $baseClass
     * @return $this
     */
    public function setBaseClass($baseClass)
    {
        $this->baseClass = $baseClass;
        return $this;
    }

    /**
     * Get list of classnames that should be selectable
     *
     * @return array
     */
    public function getEnum()
    {
        $classNames = ClassInfo::subclassesFor($this->getBaseClass());
        unset($classNames[DataObject::class]);
        return $classNames;
    }

    /**
     * Get the list of classnames, including obsolete classes.
     *
     * If table or name are not set, or if it is not a valid field on the given table,
     * then only known classnames are returned.
     *
     * Values cached in this method can be cleared via `DBClassName::clear_classname_cache();`
     *
     * @return array
     */
    public function getEnumObsolete()
    {
        // Without a table or field specified, we can only retrieve known classes
        $table = $this->getTable();
        $name = $this->getName();
        if (empty($table) || empty($name)) {
            return $this->getEnum();
        }

        // Ensure the table level cache exists
        if (empty(self::$classname_cache[$table])) {
            self::$classname_cache[$table] = array();
        }

        // Check existing cache
        if (!empty(self::$classname_cache[$table][$name])) {
            return self::$classname_cache[$table][$name];
        }

        // Get all class names
        $classNames = $this->getEnum();
        if (in_array($name, DB::get_conn()->getSchemaManager()->listTableColumns($table))) {
            $existing = DB::get_conn()->createQueryBuilder()
                ->select(Convert::symbol2sql($name))
                ->from(Convert::symbol2sql($table))
                ->groupBy(Convert::symbol2sql($name))
                ->execute()->fetchAll(\PDO::FETCH_COLUMN);
            $classNames = array_unique(array_merge($classNames, $existing));
        }

        // Cache and return
        self::$classname_cache[$table][$name] = $classNames;
        return $classNames;
    }

    public function setValue($value, $record = null, $markChanged = true)
    {
        parent::setValue($value, $record, $markChanged);

        if ($record instanceof DataObject) {
            $this->record = $record;
        }
    }

    public function getDefault()
    {
        // Check for assigned default
        $default = parent::getDefault();
        if ($default) {
            return $default;
        }

        // Allow classes to set default class
        $baseClass = $this->getBaseClass();
        $defaultClass = Config::inst()->get($baseClass, 'default_classname');
        if ($defaultClass &&  class_exists($defaultClass)) {
            return $defaultClass;
        }

        // Fallback to first option
        $enum = $this->getEnum();
        return reset($enum);
    }
}
