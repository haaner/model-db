<?php

namespace BirdWorX\ModelDb\Basic;

use PDOException;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

class ModelTableInfos {

	/**
	 * @var string
	 */
	private string $cacheFilePath;

	/**
	 * Enthält die Tabellennamen der abgebildeten Tabellen
	 *
	 * Die Array-Keys entsprechen dem Namen der jeweiligen Klasse
	 *
	 * @var string[]
	 */
	private array $tableNames;

	/**
	 * Enthält die Tabellenfelder der abgebildeten Tabellen
	 *
	 * Die Array-Keys entsprechen dem Namen der jeweiligen Klasse - die Values sind wiederum Arrays, deren Keys den Feldbezeichnern
	 * der jeweiligen Tabelle entsprechen und deren Values den zugehörigen MySQL-Typ enthalten.
	 *
	 * @var string[]
	 */
	private array $tableFields;

	/**
	 * Enthält die Indizes der abgebildeten Tabellen
	 *
	 * Die Array-Keys entsprechen dem Namen der jeweiligen Klasse - die Values sind wiederum Arrays, deren Keys den Indexbezeichnern entsprechen und deren Values die zugehörigen Feldnamen enthalten.
	 *
	 * @var string[]
	 */
	private array $tableIndices;

	/**
	 * In der Datenbank hinterlegte Default-Werte zu den Tabellenspalten.
	 *
	 * @var string[] Aufbau analog wie $tableFields
	 */
	private array $fieldDefaults;

	/**
	 * Enthält die Pflichtfelder der abgebildeten Tabellen
	 *
	 * @var string[] Aufbau analog wie $tableFields
	 */
	private array $mandatoryFields;

	/**
	 * Enthält die Fremdschlüssel der abgebildeten Tabellen
	 *
	 * @var string[] Aufbau analog wie $tableFields
	 */
	private array $foreignKeyClassOrTable;

	/**
	 * Enthält die (nicht-statischen) Property-Bezeichner aller abgebildeter Klassen
	 *
	 * Die Array-Keys entsprechen dem Tabellennamen - die Values sind wiederum Arrays, deren Keys den Property-Bezeichnern der jeweiligen Objektklasse entsprechen und deren Values den zugehörigen \Type enthalten.
	 *
	 * @var string[]
	 */
	private array $reflectedProperties;

	/**
	 * In der Datenbank hinterlegte Enumerations-Werte für die als ENUM gekennzeichneten Tabellenspalten
	 *
	 * @var string[]
	 */
	private array $fieldEnumValues;

	/**
	 * Enthält die Primärschlüssel der abgebildeten Tabellen
	 *
	 * @var string[] Aufbau analog wie $tableFields
	 */
	private array $primaryKeyList;

	/**
	 * Enthält den Bezeichner der AUTO_INCREMENT-Spalte der abgebildeten Tabellen
	 *
	 * @var string[] Aufbau analog wie $tableFields
	 */
	private array $autoincrementField;

	private function __construct(string $cache_file_path) {
		$this->cacheFilePath = $cache_file_path;

		$this->tableNames = array();
		$this->tableFields = array();
		$this->tableIndices = array();
		$this->fieldDefaults = array();
		$this->mandatoryFields = array();
		$this->foreignKeyClassOrTable = array();
		$this->reflectedProperties = array();
		$this->fieldEnumValues = array();
		$this->primaryKeyList = array();
		$this->uniqueTuplesList = array();
		$this->autoincrementField = array();
	}

	/**
	 * Gibt ein ModelTableInfos-Objekt zurück, das mithilfe einer zuvor gespeicherten Caching-Datei initialisiert wird.
	 *
	 * @param string $cache_file_path
	 *
	 * @return ModelTableInfos
	 */
	public static function init(string $cache_file_path): ModelTableInfos {
		$model_meta = null;

		if (file_exists($cache_file_path)) {
			$fp = fopen($cache_file_path, 'r');

			if (flock($fp, LOCK_SH | LOCK_NB)) {
				clearstatcache();

				if (($size = filesize($cache_file_path)) > 0) {
					$content = fread($fp, $size);
					flock($fp, LOCK_UN);

					$model_meta = unserialize($content);
				} else {
					flock($fp, LOCK_UN);
				}
			}

			fclose($fp);
		}

		if ($model_meta === null) {
			return new ModelTableInfos($cache_file_path);
		} else {
			/** @var ModelTableInfos $model_meta */
			$model_meta->cacheFilePath = $cache_file_path;
		}

		return $model_meta;
	}

	/**
	 * Speichert die Daten des Objekts innerhalb der Caching-Datei
	 */
	public function save() {
		$old = umask(0);

		touch($this->cacheFilePath);
		$fp = fopen($this->cacheFilePath, 'r+');

		if (flock($fp, LOCK_EX | LOCK_NB)) {
			clearstatcache();

			if (($size = filesize($this->cacheFilePath)) > 0) {
				$content = fread($fp, $size);
				rewind($fp);

				/** @var ModelTableInfos|null $stored_info */
				$stored_info = unserialize($content);

				if ($stored_info !== false) {
					$this->merge($stored_info);
				}
			}

			fwrite($fp, serialize($this));
			fflush($fp);
			flock($fp, LOCK_UN);
		}

		fclose($fp);
		@chmod($this->cacheFilePath, 0664);

		umask($old);
	}

	/**
	 * Gibt TRUE zurück, wenn die Tabellen-Informationen für die übergebene Klasse bereits gehandelt werden
	 *
	 * @param string $class_name
	 *
	 * @return bool
	 */
	public function handles(string $class_name): bool {
		return isset($this->tableNames[$class_name]);
	}

	/**
	 * Ermittelt sämtliche (auch geerbte) Properties der übergebenen Klasse
	 *
	 * @param $class_name
	 *
	 * @return ReflectionProperty[]
	 * @throws ReflectionException
	 */
	private static function getClassProperties($class_name) {

		$reflect = new ReflectionClass($class_name);

		$props = $reflect->getProperties();

		if ($class_name === ModelBase::class) {
			foreach ($props as $key => $prop) {
				if ($prop->isPrivate()) {
					unset($props[$key]);
				}
			}
		}

		if ($parent_class = $reflect->getParentClass()) {
			$parent_class_name = $parent_class->getName();
			$parent_props = self::getClassProperties($parent_class_name);

			$props = array_merge($parent_props, $props);
		}

		return $props;
	}

	private function getClassComment($class_name, array &$reflected_properties) {

		try {
			$props = self::getClassProperties($class_name);

			foreach ($props as $prop) {
				if (!$prop->isStatic() && !array_key_exists(($prop_name = $prop->getName()), $reflected_properties)) {
					$reflected_properties[$prop->getName()] = ModelBase::getTypeForAnnotation($prop->getDocComment());
				}
			}

			$reflect = new ReflectionClass($class_name);

			if (($class_comment = $reflect->getDocComment()) === false) {
				$class_comment = '';
			}

		} catch (ReflectionException) {
			$class_comment = '';
		}

		return $class_comment;
	}

	/**
	 * Erweitert das Objekt mit Meta-Daten für das spezifizierte Tabelle-Klasse Paar.
	 *
	 * @param string $class_name
	 * @param string $table_name
	 */
	public function extend(string $class_name, string $table_name) {

		$this->tableNames[$class_name] = $table_name;
		$this->tableIndices[$class_name] = array();
		$this->tableFields[$class_name] = array();
		$this->fieldDefaults[$class_name] = array();
		$this->mandatoryFields[$class_name] = array();
		$this->foreignKeyClassOrTable[$class_name] = array();
		$this->reflectedProperties[$class_name] = array();
		$this->fieldEnumValues[$class_name] = array();
		$this->primaryKeyList[$class_name] = array();
		$this->uniqueTuplesList[$class_name] = array();
		$this->autoincrementField[$class_name] = null;

		$table_fields = &$this->tableFields[$class_name];
		$table_indices = &$this->tableIndices[$class_name];
		$field_defaults = &$this->fieldDefaults[$class_name];
		$field_enums_values = &$this->fieldEnumValues[$class_name];
		$autoincrement_field = &$this->autoincrementField[$class_name];
		$mandatory_fields = &$this->mandatoryFields[$class_name];

		$db = new Db();

		try {

			$db->query('SHOW FULL COLUMNS FROM ' . $table_name);

			while (($record = $db->nextRecord())) {
				$key = $record['Field'];
				$table_fields[$key] = $record['Type'];

				$field_defaults[$key] = $record['Default'];

				if (str_starts_with($table_fields[$key], 'enum')) {
					$field_enums_values[$key] = explode(',', str_replace('enum(', '', $table_fields[$key]));
					foreach ($field_enums_values[$key] as &$value) {
						$value = trim($value, "')");
					}
				}

				$is_autoincrement = (stripos($record['Extra'], 'auto_increment') !== false);
				if ($is_autoincrement) {
					$autoincrement_field = $key;
				}

				// Handelt es sich um ein Pflichtfeld, das nicht "automatisch" befüllt wird?
				if (strtoupper($record['Null']) == 'NO' && !$is_autoincrement && !in_array($key, [ModelBase::CREATED, ModelBase::UPDATED])) {
					$mandatory_fields[] = $key;
				}
			}

			$db->query('SHOW INDEX FROM ' . $table_name);

			while (($record = $db->nextRecord())) {
				$index = $record['Key_name'];

				if (!array_key_exists($index, $table_indices)) {
					$table_indices[$index] = array();
				}

				$table_indices[$index][] = $record['Column_name'];
			}

		} catch (PDOException) { }

		// Fremdschlüssel - Felder bestimmen
		$foreign_key_class_or_table = &$this->foreignKeyClassOrTable[$class_name];

		$db->query("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_COLUMN_NAME='id' AND TABLE_NAME='" . $table_name . "' AND TABLE_SCHEMA='" . Db::getDbName() . "'");
		while (($record = $db->nextRecord())) {
			$foreign_key_class_or_table[$record['COLUMN_NAME']] = $record['REFERENCED_TABLE_NAME'];
		}

		// Sämtliche (nicht-statischen) Properties der Klasse ermitteln
		$reflected_properties = &$this->reflectedProperties[$class_name];

		$fields = array_keys($table_fields);
		foreach ($fields as $field) {

			if (array_key_exists($field, $foreign_key_class_or_table)) {
				$type = null;
				$current_class_name = $class_name;

				do {
					$class_comment = $this->getClassComment($current_class_name, $reflected_properties);

					$foreign_class_name = \Type\getAnnotationTypeAsString($class_comment, $field);
					// Das Foreign-Key Handling macht nur dann Sinn, wenn die beteiligte Tabelle einer anderen ModelBase-Klasse entspricht

					// Foreign-Klassen (deren Type-Hint keinen Namespace enthält) müssen im Models-Namespace sein
					if (!str_contains($foreign_class_name, '\\')) {
						$foreign_class_name = PROJECT_NAME . '\\Models\\' . $foreign_class_name;
					}

					if (is_subclass_of($foreign_class_name, ModelBase::class) && ($foreign_class_name)::getTableName() === $foreign_key_class_or_table[$field]) {
						$foreign_key_class_or_table[$field] = $foreign_class_name;
						$type = ModelBase::PROPERTY_TYPE_FOREIGN_KEY;
					}

				} while (($current_class_name = get_parent_class($current_class_name)));

				if ($type === null) {
					unset($foreign_key_class_or_table[$field]);
					$type = ModelBase::PROPERTY_TYPE_INT;
				}

			} else {
				$class_comment = $this->getClassComment($class_name, $reflected_properties);

				if (preg_match('/^tinyint\(1\)/', $table_fields[$field])) {
					// Da MySql BOOL(EAN) intern als TINYINT(1) verwaltet, prüfe nochmal explizit die @var / @property Annotation
					if ((array_key_exists($field, $reflected_properties) && $reflected_properties[$field] == ModelBase::PROPERTY_TYPE_BOOL) || ModelBase::getTypeForAnnotation($class_comment, $field) == ModelBase::PROPERTY_TYPE_BOOL) {
						$type = ModelBase::PROPERTY_TYPE_BOOL;
					} else {
						$type = ModelBase::getTypeForSqlDefinition($table_fields[$field]);
					}

				} elseif (str_starts_with($table_fields[$field], 'longtext')) { // MariaDB verwendet LONGTEXT anstatt JSON, deshalb muss bei einem derartigem Datentyp die Annotation näher inspiziert werden

					if (($type = ModelBase::getTypeForAnnotation($class_comment, $field)) !== ModelBase::PROPERTY_TYPE_JSON) {
						$type = ModelBase::getTypeForSqlDefinition($table_fields[$field]);
					}

				} else {
					$type = ModelBase::getTypeForSqlDefinition($table_fields[$field]);
				}

				if ($type == ModelBase::PROPERTY_TYPE_ENUM && count(($enum_values = $field_enums_values[$field])) == 2 && in_array('0', $enum_values) && in_array('1', $enum_values)) {
					$type = ModelBase::PROPERTY_TYPE_BOOL;
				}
			}

			$reflected_properties[$field] = $type;
		}

		$primary_key_list = &$this->primaryKeyList[$class_name];

		$db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_NAME='PRIMARY' AND TABLE_NAME='" . $table_name . "' AND TABLE_SCHEMA='" . Db::getDbName() . "'");
		while (($record = $db->nextRecord())) {
			$primary_key_list[] = $record['COLUMN_NAME'];
		}

		$unique_tuples_list = &$this->uniqueTuplesList[$class_name];

		$db->query("SHOW INDEX FROM " . $table_name . " WHERE Non_unique = 0 AND Key_name != 'PRIMARY'");
		while (($record = $db->nextRecord())) {
			if (!array_key_exists(($key_name = $record['Key_name']), $unique_tuples_list)) {
				$unique_tuples_list[$key_name] = array();
			}

			$unique_tuples_list[$key_name][] = $record['Column_name'];
		}

		$this->save();
	}

	public function merge(ModelTableInfos $model_meta) {
		$this->tableNames = array_merge($this->tableNames, $model_meta->tableNames);
		$this->tableFields = array_merge($this->tableFields, $model_meta->tableFields);
		$this->tableIndices = array_merge($this->tableIndices, $model_meta->tableIndices);
		$this->fieldDefaults = array_merge($this->fieldDefaults, $model_meta->fieldDefaults);
		$this->mandatoryFields = array_merge($this->mandatoryFields, $model_meta->mandatoryFields);
		$this->foreignKeyClassOrTable = array_merge($this->foreignKeyClassOrTable, $model_meta->foreignKeyClassOrTable);
		$this->reflectedProperties = array_merge($this->reflectedProperties, $model_meta->reflectedProperties);
		$this->fieldEnumValues = array_merge($this->fieldEnumValues, $model_meta->fieldEnumValues);
		$this->primaryKeyList = array_merge($this->primaryKeyList, $model_meta->primaryKeyList);
		$this->uniqueTuplesList = array_merge($this->uniqueTuplesList, $model_meta->uniqueTuplesList);
		$this->autoincrementField = array_merge($this->autoincrementField, $model_meta->autoincrementField);
	}

	public function getTableFields(string $class_name): array {
		return $this->tableFields[$class_name];
	}

	public function getTableIndices(string $class_name): array {
		return $this->tableIndices[$class_name];
	}

	public function getFieldDefaults(string $class_name): array {
		return $this->fieldDefaults[$class_name];
	}

	public function getMandatoryFields(string $class_name): array {
		return $this->mandatoryFields[$class_name];
	}

	public function getForeignKeyClassOrTable(string $class_name): array {
		return $this->foreignKeyClassOrTable[$class_name];
	}

	public function getReflectedProperties(string $class_name): array {
		return $this->reflectedProperties[$class_name];
	}

	public function getFieldEnumValues(string $class_name): array {
		return $this->fieldEnumValues[$class_name];
	}

	public function getPrimaryKeyList(string $class_name): array {
		return $this->primaryKeyList[$class_name];
	}

	public function getUniqueTuplesList(string $class_name): array {
		return $this->uniqueTuplesList[$class_name];
	}

	public function getAutoincrementField(string $class_name): ?string {
		return $this->autoincrementField[$class_name];
	}
}