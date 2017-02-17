<?php

namespace SilverStripe\ORM;

use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\VersionAwarePlatformDriver;
use BadMethodCallException;
use InvalidArgumentException;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Director;
use SilverStripe\Control\Cookie;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\Connect\Database;
use SilverStripe\ORM\Connect\DBConnector;
use SilverStripe\ORM\Connect\MySQLQueryBuilder;
use SilverStripe\ORM\Connect\Query;
use SilverStripe\ORM\Queries\SQLExpression;

/**
 * Global database interface, complete with static methods.
 * Use this class for interacting with the database.
 */
class DB
{
    /**
     * Session key for alternative database name
     */
    const ALT_DB_KEY = 'alternativeDatabaseName';

    /**
     * Allow alternative DB to be disabled.
     * Necessary for DB backed session store to work.
     *
     * @config
     * @var bool
     */
    private static $alternative_database_enabled = true;

    /**
     * The global database connection.
     *
     * @var Database
     */
    protected static $connections = [];

    /**
     * List of configurations for each connection
     *
     * @var array List of configs each in the $databaseConfig format
     */
    protected static $configs = [];



    /**
     * Internal flag to keep track of when db connection was attempted.
     */
    private static $connection_attempted = false;

    /**
     * Set the global database connection.
     * Pass an object that's a subclass of SS_Database.  This object will be used when {@link DB::query()}
     * is called.
     *
     * @param \Doctrine\DBAL\Connection $connection The connecton object to set as the connection.
     * @param string $name The name to give to this connection.  If you omit this argument, the connection
     * will be the default one used by the ORM.  However, you can store other named connections to
     * be accessed through DB::get_conn($name).  This is useful when you have an application that
     * needs to connect to more than one database.
     */
    public static function set_conn($connection, $name = 'default')
    {
        self::$connections[$name] = $connection;
    }

    /**
     * Get the global database connection.
     *
     * @param string $name An optional name given to a connection in the DB::setConn() call.  If omitted,
     * the default connection is returned.
     * @return \Doctrine\DBAL\Connection
     */
    public static function get_conn($name = 'default')
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        // lazy connect
        $config = static::getConfig($name);
        if ($config) {
            return static::connect($config, $name);
        }

        return null;
    }

    public static function get_server_version($name = 'default')
    {
        $conn = self::get_conn($name);

        // Driver does not support version specific platforms.
        if ( ! $conn->getDriver() instanceof VersionAwarePlatformDriver) {
            return null;
        }

        $params = $conn->getParams();
        // Explicit platform version requested (supersedes auto-detection).
        if (isset($params['serverVersion'])) {
            return $params['serverVersion'];
        }

        // Automatic platform version detection.
        if ($conn->getWrappedConnection() instanceof ServerInfoAwareConnection &&
            ! $conn->getWrappedConnection()->requiresQueryForServerVersion()
        ) {
            return $conn->getWrappedConnection()->getServerVersion();
        }

        // Unable to detect platform version.
        return null;
    }

    /**
     * Retrieves the schema manager for the current database
     *
     * @param string $name An optional name given to a connection in the DB::setConn() call.  If omitted,
     * the default connection is returned.
     * @return \Doctrine\DBAL\Schema\AbstractSchemaManager
     */
    public static function get_schema($name = 'default')
    {
        $connection = self::get_conn($name);
        if ($connection) {
            return $connection->getSchemaManager();
        }
        return null;
    }

    /**
     * Builds a sql query with the specified connection
     *
     * @param SQLExpression $expression The expression object to build from
     * @param array $parameters Out parameter for the resulting query parameters
     * @param string $name An optional name given to a connection in the DB::setConn() call.  If omitted,
     * the default connection is returned.
     * @return string The resulting SQL as a string
     */
    public static function build_sql(SQLExpression $expression, &$parameters, $name = 'default')
    {
        $connection = self::get_conn($name);
        if ($connection) {
            // @todo work out how to use the proper builder
            return (new MySQLQueryBuilder())->buildSQL($expression, $parameters);
        } else {
            $parameters = array();
            return null;
        }
    }

    /**
     * Set an alternative database in a browser cookie,
     * with the cookie lifetime set to the browser session.
     * This is useful for integration testing on temporary databases.
     *
     * There is a strict naming convention for temporary databases to avoid abuse:
     * <prefix> (default: 'ss_') + tmpdb + <7 digits>
     * As an additional security measure, temporary databases will
     * be ignored in "live" mode.
     *
     * Note that the database will be set on the next request.
     * Set it to null to revert to the main database.
     *
     * @param string $name
     */
    public static function set_alternative_database_name($name = null)
    {
        // Ignore if disabled
        if (!Config::inst()->get(static::class, 'alternative_database_enabled')) {
            return;
        }
        // Skip if CLI
        if (Director::is_cli()) {
            return;
        }
        // Validate name
        if ($name && !self::valid_alternative_database_name($name)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid alternative database name: "%s"',
                $name
            ));
        }

        // Set against session
        if (!Injector::inst()->has(HTTPRequest::class)) {
            return;
        }
        /** @var HTTPRequest $request */
        $request = Injector::inst()->get(HTTPRequest::class);
        if ($name) {
            $request->getSession()->set(self::ALT_DB_KEY, $name);
        } else {
            $request->getSession()->clear(self::ALT_DB_KEY);
        }
    }

    /**
     * Get the name of the database in use
     *
     * @return string|false Name of temp database, or false if not set
     */
    public static function get_alternative_database_name()
    {
        // Ignore if disabled
        if (!Config::inst()->get(static::class, 'alternative_database_enabled')) {
            return false;
        }
        // Skip if CLI
        if (Director::is_cli()) {
            return false;
        }
        // Skip if there's no request object yet
        if (!Injector::inst()->has(HTTPRequest::class)) {
            return null;
        }
        /** @var HTTPRequest $request */
        $request = Injector::inst()->get(HTTPRequest::class);
        // Skip if the session hasn't been started
        if (!$request->getSession()->isStarted()) {
            return null;
        }

        $name = $request->getSession()->get(self::ALT_DB_KEY);
        if (self::valid_alternative_database_name($name)) {
            return $name;
        }

        return false;
    }

    /**
     * Determines if the name is valid, as a security
     * measure against setting arbitrary databases.
     *
     * @param string $name
     * @return bool
     */
    public static function valid_alternative_database_name($name)
    {
        if (Director::isLive() || empty($name)) {
            return false;
        }

        $prefix = Environment::getEnv('SS_DATABASE_PREFIX') ?: 'ss_';
        $pattern = strtolower(sprintf('/^%stmpdb\d{7}$/', $prefix));
        return (bool)preg_match($pattern, $name);
    }

    /**
     * Specify connection to a database
     *
     * Given the database configuration, this method will create the correct
     * subclass of {@link SS_Database}.
     *
     * @param array $databaseConfig A map of options. The 'type' is the name of the
     * driver to use. For the rest of the options, see the specific class.
     * @param string $label identifier for the connection
     * @return \Doctrine\DBAL\Connection
     */
    public static function connect($databaseConfig, $label = 'default')
    {
        $databaseConfig['charset'] = 'UTF8';
        // This is used by the "testsession" module to test up a test session using an alternative name
        if ($name = self::get_alternative_database_name()) {
            $databaseConfig['dbname'] = $name;
        }

        if (!isset($databaseConfig['type']) || empty($databaseConfig['type'])) {
            throw new InvalidArgumentException("DB::connect: Not passed a valid database config");
        }

        $conn = DriverManager::getConnection($databaseConfig);

        self::set_conn($conn, $label);

        return $conn;
    }

    /**
     * Set config for a lazy-connected database
     *
     * @param array $databaseConfig
     * @param string $name
     */
    public static function setConfig($databaseConfig, $name = 'default')
    {
        static::$configs[$name] = $databaseConfig;
    }

    /**
     * Get the named connection config
     *
     * @param string $name
     * @return mixed
     */
    public static function getConfig($name = 'default')
    {
        if (isset(static::$configs[$name])) {
            return static::$configs[$name];
        }
    }

    /**
     * Execute the given SQL query.
     * @param string $sql The SQL query to execute
     * @param int $errorLevel The level of error reporting to enable for the query
     * @return \Doctrine\DBAL\Driver\Statement
     */
    public static function query($sql)
    {
        return self::get_conn()->query($sql);
    }

    /**
     * Helper function for generating a list of parameter placeholders for the
     * given argument(s)
     *
     * @param array|integer $input An array of items needing placeholders, or a
     * number to specify the number of placeholders
     * @param string $join The string to join each placeholder together with
     * @return string|null Either a list of placeholders, or null
     */
    public static function placeholders($input, $join = ', ')
    {
        if (is_array($input)) {
            $number = count($input);
        } elseif (is_numeric($input)) {
            $number = intval($input);
        } else {
            return null;
        }
        if ($number === 0) {
            return null;
        }
        return implode($join, array_fill(0, $number, '?'));
    }

    /**
     * @param string $sql The parameterised query
     * @param array $parameters The parameters to inject into the query
     *
     * @return string
     */
    public static function inline_parameters($sql, $parameters)
    {
        $segments = preg_split('/\?/', $sql);
        $joined = '';
        $inString = false;
        $numSegments = count($segments);
        for ($i = 0; $i < $numSegments; $i++) {
            $input = $segments[$i];
            // Append next segment
            $joined .= $segments[$i];
            // Don't add placeholder after last segment
            if ($i === $numSegments - 1) {
                break;
            }
            // check string escape on previous fragment
            // Remove escaped backslashes, count them!
            $input = preg_replace('/\\\\\\\\/', '', $input);
            // Count quotes
            $totalQuotes = substr_count($input, "'"); // Includes double quote escaped quotes
            $escapedQuotes = substr_count($input, "\\'");
            if ((($totalQuotes - $escapedQuotes) % 2) !== 0) {
                $inString = !$inString;
            }
            // Append placeholder replacement
            if ($inString) {
                // Literal question mark
                $joined .= '?';
                continue;
            }

            // Encode and insert next parameter
            $next = array_shift($parameters);
            if (is_array($next) && isset($next['value'])) {
                $next = $next['value'];
            }
            if (is_bool($next)) {
                $value = $next ? '1' : '0';
            } elseif (is_int($next)) {
                $value = $next;
            } else {
                $value = DB::is_active() ? Convert::raw2sql($next, true) : $next;
            }
            $joined .= $value;
        }
        return $joined;
    }

    /**
     * Execute the given SQL parameterised query with the specified arguments
     *
     * @param string $sql The SQL query to execute. The ? character will denote parameters.
     * @param array $parameters An ordered list of arguments.
     * @param int $errorLevel The level of error reporting to enable for the query
     * @return \Doctrine\DBAL\Driver\Statement
     */
    public static function prepared_query($sql, $parameters, $errorLevel = E_USER_ERROR)
    {
        return self::get_conn()->executeQuery($sql, $parameters);
    }

    /**
     * Get the autogenerated ID from the previous INSERT query.
     *
     * @param string $table
     * @return int
     */
    public static function get_generated_id($table)
    {
        return self::get_conn()->lastInsertId($table);
    }

    /**
     * Check if the connection to the database is active.
     *
     * @return boolean
     */
    public static function is_active()
    {
        return ($conn = self::get_conn()) && $conn->isConnected();
    }

    /**
     * Create the database and connect to it. This can be called if the
     * initial database connection is not successful because the database
     * does not exist.
     *
     * @param string $database Name of database to create
     * @return boolean Returns true if successful
     */
    public static function create_database($database)
    {
        return self::get_conn()->selectDatabase($database, true);
    }

    /**
     * Create a new table.
     * @param string $table The name of the table
     * @param array$fields A map of field names to field types
     * @param array $indexes A map of indexes
     * @param array $options An map of additional options.  The available keys are as follows:
     *   - 'MSSQLDatabase'/'MySQLDatabase'/'PostgreSQLDatabase' - database-specific options such as "engine"
     *     for MySQL.
     *   - 'temporary' - If true, then a temporary table will be created
     * @param array $advancedOptions Advanced creation options
     * @return string The table name generated.  This may be different from the table name, for example with
     * temporary tables.
     */
    public static function create_table(
        $table,
        $fields = null,
        $indexes = null,
        $options = null,
        $advancedOptions = null
    ) {
        return self::get_schema()->createTable($table, $fields, $indexes, $options, $advancedOptions);
    }

    /**
     * Create a new field on a table.
     * @param string $table Name of the table.
     * @param string $field Name of the field to add.
     * @param string $spec The field specification, eg 'INTEGER NOT NULL'
     */
    public static function create_field($table, $field, $spec)
    {
        return self::get_schema()->createField($table, $field, $spec);
    }

    /**
     * Generate the following table in the database, modifying whatever already exists
     * as necessary.
     *
     * @param string $table The name of the table
     * @param string $fieldSchema A list of the fields to create, in the same form as DataObject::$db
     * @param string $indexSchema A list of indexes to create.  The keys of the array are the names of the index.
     * The values of the array can be one of:
     *   - true: Create a single column index on the field named the same as the index.
     *   - array('fields' => array('A','B','C'), 'type' => 'index/unique/fulltext'): This gives you full
     *     control over the index.
     * @param boolean $hasAutoIncPK A flag indicating that the primary key on this table is an autoincrement type
     * @param string $options SQL statement to append to the CREATE TABLE call.
     * @param array $extensions List of extensions
     */
    public static function require_table(
        $table,
        $fieldSchema = null,
        $indexSchema = null,
        $hasAutoIncPK = true,
        $options = null,
        $extensions = null
    ) {
        self::get_schema()->requireTable($table, $fieldSchema, $indexSchema, $hasAutoIncPK, $options, $extensions);
    }

    /**
     * Generate the given field on the table, modifying whatever already exists as necessary.
     *
     * @param string $table The table name.
     * @param string $field The field name.
     * @param string $spec The field specification.
     */
    public static function require_field($table, $field, $spec)
    {
        self::get_schema()->requireField($table, $field, $spec);
    }

    /**
     * Generate the given index in the database, modifying whatever already exists as necessary.
     *
     * @param string $table The table name.
     * @param string $index The index name.
     * @param string|boolean $spec The specification of the index. See requireTable() for more information.
     */
    public static function require_index($table, $index, $spec)
    {
        self::get_schema()->requireIndex($table, $index, $spec);
    }

    /**
     * If the given table exists, move it out of the way by renaming it to _obsolete_(tablename).
     *
     * @param string $table The table name.
     */
    public static function dont_require_table($table)
    {
        self::get_schema()->dontRequireTable($table);
    }

    /**
     * See {@link SS_Database->dontRequireField()}.
     *
     * @param string $table The table name.
     * @param string $fieldName The field name not to require
     */
    public static function dont_require_field($table, $fieldName)
    {
        self::get_schema()->dontRequireField($table, $fieldName);
    }

    /**
     * Checks a table's integrity and repairs it if necessary.
     *
     * @param string $table The name of the table.
     * @return boolean Return true if the table has integrity after the method is complete.
     */
    public static function check_and_repair_table($table)
    {
        return self::get_schema()->checkAndRepairTable($table);
    }

    /**
     * Return the number of rows affected by the previous operation.
     *
     * @return integer The number of affected rows
     */
    public static function affected_rows()
    {
        return self::get_conn()->affectedRows();
    }

    /**
     * Returns a list of all tables in the database.
     * The table names will be in lower case.
     *
     * @return array The list of tables
     */
    public static function table_list()
    {
        return self::get_schema()->tableList();
    }

    /**
     * Get a list of all the fields for the given table.
     * Returns a map of field name => field spec.
     *
     * @param string $table The table name.
     * @return array The list of fields
     */
    public static function field_list($table)
    {
        return self::get_schema()->fieldList($table);
    }

    /**
     * Enable supression of database messages.
     */
    public static function quiet()
    {
        self::get_schema()->quiet();
    }

    /**
     * Show a message about database alteration
     *
     * @param string $message to display
     * @param string $type one of [created|changed|repaired|obsolete|deleted|error]
     */
    public static function alteration_message($message, $type = "")
    {
        if (Director::is_cli()) {
            switch ($type) {
                case "created":
                case "changed":
                case "repaired":
                    $sign = "+";
                    break;
                case "obsolete":
                case "deleted":
                    $sign = '-';
                    break;
                case "notice":
                    $sign = '*';
                    break;
                case "error":
                    $sign = "!";
                    break;
                default:
                    $sign = " ";
            }
            $message = strip_tags($message);
            echo "  $sign $message\n";
        } else {
            switch ($type) {
                case "created":
                    $color = "green";
                    break;
                case "obsolete":
                    $color = "red";
                    break;
                case "notice":
                    $color = "orange";
                    break;
                case "error":
                    $color = "red";
                    break;
                case "deleted":
                    $color = "red";
                    break;
                case "changed":
                    $color = "blue";
                    break;
                case "repaired":
                    $color = "blue";
                    break;
                default:
                    $color = "";
            }
            echo "<li style=\"color: $color\">$message</li>";
        }
    }
}
