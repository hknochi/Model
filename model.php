<?php
/**
*  Model class which provides Data Mapping, CRUD, and finders on a database table uses PDO for data access
*  table columns are auto detected and made available as public members of the class
*  class members used to do the magic are preceeded with an underscore, becareful of column names starting with _ in your db
*  requires php >=5.3 as uses "Late Static Binding"
*
*    In your bootstrap
*    db\Model::connectDb('mysql:dbname=test;host=127.0.0.1','testuser','testpassword');
*
*    Extend the model and your set to go
*    class Category extends db\Model {
*      static protected $_tableName = 'categories';   // database table name
*      static protected $_primary_column_name = 'id'; // primary key column name if not id
*
*      // add other methods appropriate for your class
*
*    }
*    $category = Category::getById(1);
*/
namespace db;

class Model
{

  // Class configuration

  public static $_db;  // all models inherit this db connection
                      // but can overide in a sub-class by calling subClass::connectDB(...)

  protected static $_identifier_quote_character = null;  // character used to quote table & columns names
  protected static $_tableColumns = null;                // columns in database table populated dynamically
  // objects public members are created for each table columns dynamically


  // ** OVERIDE THE FOLLOWING as appropriate in your sub-class
  protected static $_primary_column_name = 'id'; // primary key column
  protected static $_tableName = null;           // database table name

  function __construct(array $data = array()) {
    if (static::$_tableColumns == null) {
      static::getFieldnames();  // only called once first time an object is created
    }
    if (is_array($data)) {
      $this->hydrate($data);
    }
  }

  // set the db connection for the called class so that sub-classes can declare $db and have their own db connection if required
  // params are as new PDO(...)
  public static function connectDb($dsn, $username, $password, $driverOptions = array()) {
    static::$_db = new \PDO($dsn,$username,$password,$driverOptions);
    static::$_db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); // Set Errorhandling to Exception
    static::_setup_identifier_quote_character();
  }

  /**
   * Detect and initialise the character used to quote identifiers
   * (table names, column names etc).
   */
  public static function _setup_identifier_quote_character() {
    if (is_null(static::$_identifier_quote_character)) {
      static::$_identifier_quote_character = static::_detect_identifier_quote_character();
    }
  }

  /**
   * Return the correct character used to quote identifiers (table
   * names, column names etc) by looking at the driver being used by PDO.
   */
  protected static function _detect_identifier_quote_character() {
    switch(static::$_db->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
      case 'pgsql':
      case 'sqlsrv':
      case 'dblib':
      case 'mssql':
      case 'sybase':
          return '"';
      case 'mysql':
      case 'sqlite':
      case 'sqlite2':
      default:
          return '`';
    }
  }

  /**
   * Quote a string that is used as an identifier
   * (table names, column names etc). This method can
   * also deal with dot-separated identifiers eg table.column
   */
  protected function _quote_identifier($identifier) {
    $class = get_called_class();
    $parts = explode('.', $identifier);
    $parts = array_map(array($class, '_quote_identifier_part'), $parts);
    return join('.', $parts);
  }

  /**
   * This method performs the actual quoting of a single
   * part of an identifier, using the identifier quote
   * character specified in the config (or autodetected).
   */
  protected function _quote_identifier_part($part) {
    if ($part === '*') {
      return $part;
    }
    return static::$_identifier_quote_character . $part . static::$_identifier_quote_character;
  }

  protected static function getFieldnames() {
    $st = static::$_db->prepare("DESCRIBE `".static::$_tableName."`");
    $st->execute();
    static::$_tableColumns = $st->fetchAll(\PDO::FETCH_COLUMN);
  }

  // populate member vars if they are in $tableColumns
  public function hydrate($data) {
    foreach(static::$_tableColumns as $fieldname) {
      if (isset($data[$fieldname])) {
        $this->$fieldname = $data[$fieldname];
      } else if (!isset($this->$fieldname)) { // PDO pre populates fields before calling the constructor, so dont null unless not set
        $this->$fieldname = null;
      }
    }
  }
  
  // set all public members to null
  public function clear() {
    foreach(static::$_tableColumns as $fieldname) {
      $this->$fieldname = null;
    }
  }

  public function __sleep() {
    return static::$_tableColumns;
  }

  public function toArray() {
    $a = array();
    foreach(static::$_tableColumns as $fieldname) {
      $a[$fieldname] = $this->$fieldname;
    }
    return $a;
  }
  
  static public function getById($id) {
    return static::fetchOneWhere(static::_quote_identifier(static::$_primary_column_name).' = ?',array($id));
  }
  
  static public function first() {
    return static::fetchOneWhere('1=1 ORDER BY '.static::_quote_identifier(static::$_primary_column_name).' ASC');
  }
  
  static public function last() {
    return static::fetchOneWhere('1=1 ORDER BY '.static::_quote_identifier(static::$_primary_column_name).' DESC');
  }
  
  static public function find($id) {
    $find_by_method = 'find_by_'.(static::$_primary_column_name);
    static::$find_by_method($id);
  }

  // handles calls to non-existant static methods
  // used to dynamically handle calls like
  // find_by_name('tom')
  // find_by_title('a great book')
  // count_by_name('tom')
  // count_by_title('a great book')
  // etc...
  //
  // returns same as ::fetchAllWhere();
  //
  static public function __callStatic($name, $arguments) {
    // Note: value of $name is case sensitive.
    if (preg_match('/^find_by_/',$name) == 1) {
      // it's a find_by_{fieldname} dynamic method
      $fieldname = substr($name,8); // remove find by
      $match = $arguments[0];
      if (is_array($match)) {
        $csv = implode(',',$match);
        echo "***passed array: $csv \n";
        return static::fetchAllWhere(static::_quote_identifier($fieldname). ' IN (?)', array($csv));
      } else {
        return static::fetchAllWhere(static::_quote_identifier($fieldname). ' = ?', array($match));
      }
    } else if (preg_match('/^count_by_/',$name) == 1) {
      // it's a count_by_{fieldname} dynamic method
      $fieldname = substr($name,9); // remove find by
      $match = $arguments[0];
      if (is_array($match)) {
        $csv = implode(',',$match);
        echo "***passed array: $csv \n";
        return static::countWhere(static::_quote_identifier($fieldname). ' IN (?)', array($csv));
      } else {
        return static::countWhere(static::_quote_identifier($fieldname). ' = ?', array($match));
      }
    }
    throw new Exception(__CLASS__.' not such static method['.$name.']');
  }

  /**
   * run a SELECT count(*) FROM WHERE ...
   * returns an integer count of matching rows
   *
   * @param string $SQLfragment conditions, grouping to apply (to right of WHERE keyword)
   * @param array $params optional params to be escaped and injected into the SQL query (standrd PDO syntax)
   * @return integer count of rows matching conditions
   */
  static public function countWhere($SQLfragment='',$params = array()) {
    if ($SQLfragment) {
      $SQLfragment = ' WHERE '.$SQLfragment;
    }
    $st = static::$_db->prepare('SELECT COUNT(*) FROM '.static::_quote_identifier(static::$_tableName).$SQLfragment);
    $st->execute($params);
    return $st->fetchColumn();
  }

  /**
   * run a SELECT * FROM WHERE ...
   * returns an array of objects of the sub-class
   *
   * @param string $SQLfragment conditions, sorting, grouping and limit to apply (to right of WHERE keywords)
   * @param array $params optional params to be escaped and injected into the SQL query (standrd PDO syntax)
   * @return array of objects of calling class
   */
  static public function fetchAllWhere($SQLfragment='',$params = array()) {
    $class = get_called_class();
    if ($SQLfragment) {
      $SQLfragment = ' WHERE '.$SQLfragment;
    }
    $st = static::$_db->prepare('SELECT * FROM '.static::_quote_identifier(static::$_tableName).$SQLfragment);
    $st->execute($params);
    // $st->debugDumpParams();
    $st->setFetchMode(\PDO::FETCH_CLASS, $class);
    return $st->fetchAll();
  }
  
  /**
   * run a SELECT * FROM WHERE ... LIMIT 1
   * returns an object of the sub-class
   *
   * @param string $SQLfragment conditions, sorting, grouping and limit to apply (to right of WHERE keywords)
   * @param array $params optional params to be escaped and injected into the SQL query (standrd PDO syntax)
   * @return an object of calling class
   */
  static public function fetchOneWhere($SQLfragment='',$params = array()) {
    $class = get_called_class();
    if ($SQLfragment) {
      $SQLfragment = ' WHERE '.$SQLfragment;
    }
    $st = static::$_db->prepare('SELECT * FROM '.static::_quote_identifier(static::$_tableName).$SQLfragment.' LIMIT 1');
    $st->execute($params);
    $st->setFetchMode(\PDO::FETCH_CLASS, $class);
    return $st->fetch();
  }
  
  static public function deleteById($id) {
    $st = static::$_db->prepare('DELETE FROM '.static::_quote_identifier(static::$_tableName).' WHERE '.static::_quote_identifier(static::$_primary_column_name).' = ? LIMIT 1');
    $st->execute(
      array($id)
    );
  }
  
  // do any validation in this function called before update and insert
  // should throw errors on validation failure.
  static public function validate() {
    return true;
  }
  
  public function delete() {
    self::deleteById($this->id);
  }
  
  public function insert($autoTimestamp = true) {
    $pk = static::$_primary_column_name;
    $timeStr = gmdate( 'Y-m-d H:i:s');
    if ($autoTimestamp && in_array('created_at',static::$_tableColumns)) {
      $this->created_at = $timeStr;
    }
    if ($autoTimestamp && in_array('updated_at',static::$_tableColumns)) {
      $this->updated_at = $timeStr;
    }
    $this->validate();
    $this->$pk = null; // ensure id is null
    $query = 'INSERT INTO '.static::_quote_identifier(static::$_tableName).' SET '.$this->setString();
    static::$_db->exec($query);
    $this->id = static::$_db->lastInsertId();
  }

  public function update($autoTimestamp = true) {
    if ($autoTimestamp && in_array('updated_at',static::$_tableColumns)) {
      $this->updated_at = gmdate( 'Y-m-d H:i:s');
    }
    $this->validate();
    $query = 'UPDATE '.static::_quote_identifier(static::$_tableName).' SET '.$this->setString().' WHERE '.static::_quote_identifier(static::$_primary_column_name).' = ? LIMIT 1';
    $st = static::$_db->prepare($query);
    $st->execute(array(
      $this->id
    ));
  }
  
  public function save() {
    if ($this->id) {
      $this->update();
    } else {
      $this->insert();
    }
  }
  
  protected function setString($ignorePrimary = true) {
    // escapes and builds mysql SET string returning false, empty string or `field` = 'val'[, `field` = 'val']...
    $sqlFragment = false;
    $fragments = array();
    foreach(static::$_tableColumns as $field) {
      if ($ignorePrimary && $field == static::$_primary_column_name) continue;
      if (isset($this->$field)) {
        if (empty($this->$field)) {
          // if empty set to NULL
          $fragments[] = static::_quote_identifier($field).' = NULL';
        } else {
          // Just set value normally as not empty string with NULL allowed
          $fragments[] = static::_quote_identifier($field).' = '.static::$_db->quote($this->$field);
        }
      }
    }
    $sqlFragment = implode(", ",$fragments);
    return $sqlFragment;
  }
}
?>