<?php /** @noinspection PhpMissingParamTypeInspection */
/** @noinspection PhpMissingFieldTypeInspection */

// TODO: Bei SQL-Geschichten preparedStatements verwenden ...
// TODO: ModelDatabase - Ableitung von ModelBase definieren und dort das Tabellen-Handling unterbringen ...

/** @noinspection PhpUnhandledExceptionInspection */

namespace BirdWorX\ModelDb\Basic;

use BirdWorX\Env;
use BirdWorX\ModelDb\Exceptions\DateTimeException;
use BirdWorX\ModelDb\Exceptions\GeneralException;
use BirdWorX\ModelDb\Exceptions\MissingPrimaryKeyException;
use BirdWorX\ModelDb\Exceptions\PropertyException;
use BirdWorX\ModelDb\Exceptions\UniqueException;
use BirdWorX\ModelDb\Exceptions\WriteException;
use BirdWorX\Utils;
use Closure;
use DateTime;
use Exception;
use PDOException;

// TODO: In Globals.php enthaltene Funktionen, die von ModelBase genutzt werden, in Datei classes/shared/basic/Basic.php auslagern

/**
 * Class ModelBase
 *
 * Definiert eine 1:1 Abbildung auf eine Tabelle, deren Name dem Klassennamen in underscore-Schreibweise entspricht
 *
 * @property int|string|null id Id des Datensatzes
 * @property LocalDateTime created Zeitpunkt der Erstellung des Datensatzes
 * @property LocalDateTime updated Letzte Änderung des Datensatzes
 */
class ModelBase extends ClassBase {

	public static string $cacheFilePath;

	/**
	 * Property-Konstanten: Um alternative Zugriffe zu ermöglichen, sollte für jedes Property, das im einleitenden Klassen-Header definiert wird, auch eine Konstante definiert werden. Dann kann nämlich auch per get() und set() ein Zugriff erfolgen ... zusätzlich erleichert die Verwendung von Konstanten die spätere Suche mittels "Find Usages".
	 *
	 * a) $id = $this->id;
	 * b) $id = $this->get(self::ID);
	 * c) $this->id = $id;
	 * d) $this->set(self::ID, $id);
	 */

	const ID = 'id';
	const CREATED = 'created';
	const UPDATED = 'updated';

	/**
	 * Property-Typen // TODO: Die Typen aus inc.definitions.php hierüber migrieren
	 */
	const PROPERTY_TYPE_BOOL = 1;
	const PROPERTY_TYPE_ENUM = 2;
	const PROPERTY_TYPE_INT = 3;
	const PROPERTY_TYPE_FLOAT = 4;
	const PROPERTY_TYPE_FILE = 5;
	const PROPERTY_TYPE_DATE = 6;
	const PROPERTY_TYPE_DATETIME = 7;
	const PROPERTY_TYPE_STRING = 8;
	const PROPERTY_TYPE_TEXT = 9;
	const PROPERTY_TYPE_JSON = 10;
	const PROPERTY_TYPE_FOREIGN_KEY = 11;
	const PROPERTY_TYPE_NONE = 12;

	/**
	 * Tabellen-Alias (für Datenbank-Abfragen)
	 */
	const DEFAULT_ALIAS = 'm';

	/**
	 * Sämtliche Property-Konstanten fungieren als Indizes innerhalb dieses Arrays und hinterlegen darin, die
	 * ihnen zugeordneten Werte. Dies funktioniert allerdings nur für solche Konstanten, die auch als Tabellenfeld
	 * in der Tabelle existieren, die der Klasse zugeordnet ist.
	 *
	 * @var array
	 */
	private $fieldValues = array();

	/**
	 * Diese Array enthält Kopien der initialen Feldwerte und wird benötigt,
	 * um zu entscheiden ob Änderungen am Objekt gemacht wurden bzw. nicht.
	 *
	 * @var array
	 */
	private $initialFieldValues = array();

	/**
	 * Der Name der korrespondierenden Tabelle
	 *
	 * @var string
	 */
	private $tableName;

	/**
	 * @var ModelTableInfos
	 */
	private static $modelTableInfos;

	/**
	 * Enthält die Defaults sämtlicher Properties der von ModelBase abgeleiteten Klassen.
	 *
	 * @var array Aufbau analog wie $tableFields
	 */
	private static $propertyDefaults = array();

	/**
	 * Labels sämtlicher Properties der von ModelBase abgeleiteten Klassen (Listing-View).
	 *
	 * @var array Aufbau analog wie $tableFields
	 */
	private static $propertyListingLabel = array();

	/**
	 * Labels sämtlicher Properties der von ModelBase abgeleiteten Klassen (Detail-View).
	 *
	 * @var array Aufbau analog wie $tableFields
	 */
	private static $propertyDetailLabel = array();

	/**
	 * Titles sämtlicher Properties der von ModelBase abgeleiteten Klassen (Detail-View).
	 *
	 * @var array Aufbau analog wie $tableFields
	 */
	private static $propertyDetailTitle = array();

	/**
	 * Dummy Referenz-Variable
	 *
	 * @var null
	 */
	private static $dummyNullReference = null;

	/**
	 * Ermittelt aus einer PHP Annotation den Variablen-Typ und gibt diesen zurück
	 *
	 * @param string $annotation
	 * @param string|null $property_name
	 *
	 * @return string
	 */
	public static function getAnnotationTypeAsString($annotation, $property_name = null) {

		if ($property_name) {

			if (preg_match('/@(property|param)[ \t]+(.+)[ \t]+\$?' . $property_name . '[ \t\n]+/', $annotation, $matches)) {
				$annotation = $matches[2];
			} else {
				$annotation = '';
			}
		} else {
			$annotation = preg_replace('/(\n|.)*@var[ \t]+/', '', $annotation);
			$annotation = preg_replace('/[ \t\n]+(\n|.)*/', '', $annotation);
		}

		return $annotation;
	}

	/**
	 * Ermittelt aus dem per String übergebenen Variablen-Typ die zugeordnete Integer-Konstante
	 *
	 * @param string|null $type_string
	 *
	 * @return int
	 */
	public static function getType(?string $type_string): int {

		if (str_starts_with($type_string, 'int')) {
			$type = self::PROPERTY_TYPE_INT;
		} elseif (str_starts_with($type_string, 'bool')) {
			$type = self::PROPERTY_TYPE_BOOL;
		} elseif (str_starts_with($type_string, 'string')) {
			$type = self::PROPERTY_TYPE_STRING;
		} elseif (str_starts_with($type_string, 'float')) {
			$type = self::PROPERTY_TYPE_FLOAT;
		} elseif (str_contains($type_string, 'Date')) {
			$type = self::PROPERTY_TYPE_DATETIME;
		} elseif (str_starts_with($type_string, 'array')) {
			$type = self::PROPERTY_TYPE_JSON;
		} else {
			$type = self::PROPERTY_TYPE_NONE;
		}

		return $type;
	}

	/**
	 * Ermittelt aus einem PHP Annotation-Kommentar den Variablen-Typ und gibt diesen als Integer-Konstante zurück
	 *
	 * @param string $annotation
	 * @param string|null $property_name
	 *
	 * @return int
	 */
	public static function getTypeForAnnotation($annotation, $property_name = null) {
		return self::getType(self::getAnnotationTypeAsString($annotation, $property_name));
	}

	/**
	 * Ermittelt aus einer SQL-Definition den Variablen-Typ und gibt diesen als Integer-Konstante zurück
	 *
	 * @param string $sql_definition
	 *
	 * @return int
	 */
	public static function getTypeForSqlDefinition($sql_definition) {

		if (preg_match('/^(tiny|small|medium|big)?int/', $sql_definition)) {
			$type = self::PROPERTY_TYPE_INT;
		} elseif (str_starts_with($sql_definition, 'enum')) {
			$type = self::PROPERTY_TYPE_ENUM;
		} elseif (str_starts_with($sql_definition, 'tinytext')) {
			$type = self::PROPERTY_TYPE_STRING;
		} elseif (preg_match('/^(var)?char/', $sql_definition)) {
			if (preg_match('/\((.*)\)/', $sql_definition, $matches)) {
				if (intval($matches[1]) > 255) {
					$type = self::PROPERTY_TYPE_TEXT;
				} else {
					$type = self::PROPERTY_TYPE_STRING;
				}
			} else {
				$type = self::PROPERTY_TYPE_TEXT;
			}
		} elseif (preg_match('/^(medium|long)?text/', $sql_definition)) {
			$type = self::PROPERTY_TYPE_TEXT;
		} elseif (str_starts_with($sql_definition, 'float') || str_starts_with($sql_definition, 'double') || str_starts_with($sql_definition, 'decimal')) {
			$type = self::PROPERTY_TYPE_FLOAT;
		} elseif (str_starts_with($sql_definition, 'bool')) {
			$type = self::PROPERTY_TYPE_BOOL;
		} elseif (str_starts_with($sql_definition, 'datetime')) {
			$type = self::PROPERTY_TYPE_DATETIME;
		} elseif (str_starts_with($sql_definition, 'date')) {
			$type = ModelBase::PROPERTY_TYPE_DATE;
		} elseif (str_starts_with($sql_definition, 'json')) {
			$type = self::PROPERTY_TYPE_JSON;
		} else {
			$type = self::PROPERTY_TYPE_STRING;
		}

		return $type;
	}

	/**
	 * Konvertiert den übergebenen String bzw. Datums-Typ Wert in den gewünschten Daten-Typ
	 *
	 * @param string|DateTime $value
	 * @param int $type BIT|BOOL|INT|FLOAT|STRING|ENUM|TEXT|DATETIME|DATE|JSON|NONE
	 *
	 * @return string|bool|float|int|LocalDateTime
	 *
	 * @throws GeneralException
	 */
	public static function convertToType($value, $type) {

		if ($type == self::PROPERTY_TYPE_INT) {
			$prop_val = intval($value);
		} elseif ($type == self::PROPERTY_TYPE_ENUM || $type == self::PROPERTY_TYPE_STRING || $type == self::PROPERTY_TYPE_TEXT) {
			$prop_val = $value;
		} elseif ($type == self::PROPERTY_TYPE_FLOAT) {
			$prop_val = floatval($value);
		} elseif ($type == self::PROPERTY_TYPE_BOOL) {
			$prop_val = (bool)filter_var($value, FILTER_VALIDATE_BOOLEAN);
		} elseif ($type == self::PROPERTY_TYPE_DATETIME || $type == self::PROPERTY_TYPE_DATE) {

			if (is_object($value)) {
				$object_class = get_class($value);
				if ($object_class != DateTime::class && !in_array(DateTime::class, class_parents($object_class))) {
					throw new GeneralException('DateTime-Zuweisung nicht möglich');
				}

				$prop_val = new LocalDateTime();
				$prop_val->setDateTime($value);

			} elseif (str_starts_with($value, '0000-00-00')) {
				$prop_val = null;
			} else {
				if ($type == self::PROPERTY_TYPE_DATETIME) {
					$format = LocalDateTime::DATETIME_MYSQL;
				} else {
					$format = LocalDateTime::DATE_MYSQL;
				}

				try {
					$prop_val = new LocalDateTime($value, $format);

					if ($type == ModelBase::PROPERTY_TYPE_DATE) {
						$prop_val->setTime(0, 0);
					}

				} catch (DateTimeException) {
					$prop_val = null;
				}
			}

		} elseif ($type === self::PROPERTY_TYPE_FOREIGN_KEY) {

			if (is_object($value)) {
				$prop_val = clone $value;
			} else {
				$prop_val = $value;
			}

		} elseif ($type == self::PROPERTY_TYPE_JSON) {

			if (is_array($value)) {
				$prop_val = $value;
			} else {
				$prop_val = json_decode($value, true);
			}

		} elseif ($type == self::PROPERTY_TYPE_NONE) {
			$prop_val = $value;
		} else {
			throw new GeneralException('Unbekannter Daten-Typ: "' . $type . '"!');
		}

		return $prop_val;
	}

	/**
	 * ModelBase Konstruktor
	 *
	 * @param int|string|null $id
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	final public function __construct(int|string|null $id = null) {

		static::initModelTableInfos();

		$this->tableName = static::getTableName();

		// Die Default-Werte aller Properties hinterlegen
		$this->initPropertyDefaults();

		if (($id = trim(strval($id))) !== '') {
			$this->read([static::ID => $id], true);
		} else {
			$this->initialize();
		}
	}

	/**
	 * Initialisiert ein Model mittels des übergebenen Key-Value Arrays.
	 *
	 * WICHTIG: Sämtliche Member-Variable des Objekts, die explizit angelegt wurden (also nicht mithilfe der property-Syntax im Klassenkopf), müssen innerhalb dieser bzw. einer überschreibenden Funktion explizit initialisiert werden und NICHT bereits beim Anlegen (also nicht via "private|protected|public $xy = false"), anderfalls wird {@see ModelBase::reset()} für diese Variable nicht sauber funktionieren!
	 *
	 * @param array $key_value Initialisierungs-Array mit Key-Value Paaren. Die Keys entsprechen Property-Bezeichnern - die Values beinhalten die zuzuweisenden Werte.
	 *
	 * @throws PropertyException
	 */
	protected function initialize($key_value = array()) {

		//try {
			foreach ($key_value as $key => $value) {
				$this->internalSet($key, $value);
			}
		//} catch (PropertyException) { }

		$this->initFieldValueBackup();
	}

	/**
	 * Magic Getter
	 *
	 * @param string $name
	 *
	 * @return mixed|string
	 * @throws PropertyException
	 */
	public function __get($name) {
		return $this->get($name);
	}

	/**
	 * Magic Setter
	 *
	 * @param string $name
	 * @param $value
	 *
	 * @throws PropertyException
	 */
	public function __set($name, $value) {
		$this->set($name, $value);
	}

	/**
	 * Überschrift der Modelklasse
	 *
	 * @return string
	 */
	public static function getCaption() {
		return static::className();
	}

	/**
	 * Gibt eine Referenz auf das Property zurück, das per $property-Key spezifiziert wurde. Es wird zunächst überprüft, ob die Property als reguläre Objekt-Property existiert und gegebenenfalls (wenn vorhanden und zugreifbar) als Referenz zurückgegeben. Andernfalls, wird lediglich eine Referenz auf einen Eintrag in $this->fieldValues zurückgegeben, der dem Key entspricht.
	 *
	 * @param string $property_key Name der Objekt-Property
	 * @param bool $is_internal Wenn FALSE, wird bei einem Zugriff auf eine geschützte (private, protected) Property eine PropertyException ausgelöst
	 * @param bool $foreign_key_as_model Wenn FALSE, werden Fremd-Schlüssel Properties nicht automatisch als fertig-initialisierte Objekte zurückgegeben, allerdings nur insofern ein solches Objekt nicht bereits zuvor angelegt wurde
	 *
	 * @return mixed|string
	 *
	 * @throws PropertyException
	 */
	private function &getProperty($property_key, $is_internal = true, $foreign_key_as_model = true) {

		if (!array_key_exists($property_key, self::$modelTableInfos->getTableFields(static::class))) {
			if ($property_key == ModelBase::ID || $property_key == ModelBase::CREATED || $property_key == ModelBase::UPDATED) {
				self::$dummyNullReference = null;
				return self::$dummyNullReference;
			} elseif (!$is_internal) {
				throw new PropertyException('ModelBase::getProperty ist nur für Felder konzipiert, die einer Tabellenspalte entsprechen!');
			}
		}

		$owning_class = $this->owningClass($property_key);

		if ($owning_class) {

			if ($this->isInAccessibleProperty($property_key)) {
				if (!$is_internal) {
					throw new PropertyException('Auf das Feld "' . $property_key . '" kann nicht zugegriffen werden!');
				}

				$property_reference = &ModelBase::privatePropertyAccesssor($this, $property_key, $owning_class);
			} else {
				$property_reference = &$this->{$property_key};
			}

		} else {
			$property_reference = &$this->fieldValues[$property_key];
		}

		// Fremdschlüssel-Referenzen im Normalfall als initialisiertes Objekt zurückgeben
		if ($this->getPropertyType($property_key) === self::PROPERTY_TYPE_FOREIGN_KEY) {

			if ($foreign_key_as_model && !is_object($property_reference)) {
				$model_class = $this->getForeignClassOrTable($property_key);
				$property_reference = new $model_class($property_reference);
			} else {
				$property_reference = &$this->fieldValues[$property_key];
			}
		}

		return $property_reference;
	}

	/**
	 * Gibt die Property-Keys zurück, die mit File-Upload Funktionalität verknüpft sind / werden sollen
	 *
	 * @return string[]
	 */
	public static function getFilePropertyKeys(): array {
		return array();
	}

	/**
	 * Property-Label ermitteln (Listing-View)
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	protected static function getPropertyListingLabel($key) {

		if ($key == ModelBase::ID) {
			return 'Id';
		} elseif ($key == ModelBase::CREATED) {
			return 'Erstellt am';
		} elseif ($key == ModelBase::UPDATED) {
			return 'Geändert am';
		}

		return '';
	}

	/**
	 * Property-Label ermitteln (Detail-View)
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	protected static function getPropertyDetailLabel($key) {
		return static::getPropertyListingLabel($key);
	}

	protected static function getPropertyDetailTitle($key): ?string {
		return null;
	}

	/**
	 * Gibt die Labels aller Properties zurück (Listing-View)
	 *
	 * @return string[]
	 */
	public static function getPropertyListingLabels() {
		if (!array_key_exists(static::class, self::$propertyListingLabel)) {
			static::initPropertyLabelsAndTitles();
		}

		return self::$propertyListingLabel[static::class];
	}

	/**
	 * Gibt die Labels aller Properties zurück (Detail-View)
	 *
	 * @return string[]
	 */
	public static function getPropertyDetailLabels() {
		if (!array_key_exists(static::class, self::$propertyDetailLabel)) {
			static::initPropertyLabelsAndTitles();
		}

		return self::$propertyDetailLabel[static::class];
	}

	public static function getPropertyDetailTitles() {
		if (!array_key_exists(static::class, self::$propertyDetailTitle)) {
			static::initPropertyLabelsAndTitles();
		}

		return self::$propertyDetailTitle[static::class];
	}

	/**
	 * Den Feldnamen ermitteln, der in der per Nummer spezifizierten Tabellenspalte verwendet wird
	 *
	 * @param int $column_number
	 *
	 * @return null|string
	 */
	public static function getTableFieldName($column_number): ?string {
		return array_keys(static::getTableFields())[$column_number];
	}

	/**
	 * Die Spaltennummer ermitteln, an dem sich die per Namen spezifizierte Tabellenspalte befindet
	 *
	 * @param string $field_name
	 *
	 * @return null|int
	 */
	public static function getTableFieldNumber($field_name): ?int {
		return array_search($field_name, array_keys(static::getTableFields()));
	}

	/**
	 * Gibt die Keys der Tabellenfelder zurück
	 *
	 * @return string[]
	 */
	public static function getTablePropertyKeys() {
		return array_keys(static::getTableFields());
	}

	/**
	 * Gibt den den Variablen-Typ des spezifizierten Properties zurück
	 *
	 * @param string $key
	 * #
	 * @return int
	 */
	public static function getPropertyType($key) {
		return static::getReflectedProperties()[$key];
	}

	/**
	 * Gibt alle (nicht-statischen) Property-Bezeichner des Objekts zurück
	 *
	 * @return string[]
	 */
	public static function getPropertyKeys() {
		return array_keys(static::getReflectedProperties());
	}

	/**
	 * Ermittelt sämtliche Property-Werte des Objekts und gibt diese als Strings innerhalb eines Arrays zurück, dessen Keys den Feldnamen entsprechen.
	 *
	 * @param bool $only_table_properties Wenn TRUE, werden nur die Werte von Properties zurückgegeben, die Tabellenfeldern entsprechen
	 *
	 * @return array
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	public function getPropertyStrings($only_table_properties = false) {

		$properties = array();

		if ($only_table_properties) {
			$keys = $this->getTablePropertyKeys();
		} else {
			$keys = $this->getPropertyKeys();
		}

		foreach ($keys as $key) {

			$val = $this->internalGet($key, true);

			if ($val === null) {
				$val = '';
			} elseif ($val != '') {
				$property_type = $this->getPropertyType($key);
				if ($property_type == self::PROPERTY_TYPE_DATETIME) {
					$val = (new LocalDateTime($val, LocalDateTime::DATETIME_MYSQL))->format(LocalDateTime::DATETIME_GERMAN);
				} else {
					if ($property_type == self::PROPERTY_TYPE_DATE) {
						$val = (new LocalDateTime($val, LocalDateTime::DATE_MYSQL))->format(LocalDateTime::DATE_GERMAN);
					}
				}
			}

			$properties[$key] = $val;
		}

		return $properties;
	}

	/**
	 * Gibt das PathUrl-Objekt für das Default-Uploadverzeichnis zurück.
	 *
	 * @param string $subpath Zusätzlicher Sub-Pfad
	 *
	 * @return PathUrl
	 */
	public function getUploadPathUrl(string $subpath = ''): PathUrl {
		return new PathUrl(PathUrl::UPLOAD_DIR . $subpath);
	}

	/**
	 * Löscht den mit dem Datensatz verknüpften Upload und setzt das zugehörige Model-Property gleich NULL.
	 *
	 * @param string $property_key
	 * @param bool $write_model Wenn TRUE, wird das Model nach dem Löschvorgang direkt gespeichert
	 *
	 * @throws PropertyException
	 */
	final public function deleteUpload(string $property_key, bool $write_model = false) {
		if (($path_url = $this->getUploadPathUrl()) !== null) {

			$filename = $this->get($property_key);
			if ($filename && file_exists(($absolute_filename = $path_url->absolutePath . $filename))) {
				@unlink($absolute_filename);
			}
		}

		$this->set($property_key, null);

		if ($write_model) {
			$this->write();
		}
	}

	/**
	 * Ermittelt den zur Klasse gehörigen Tabellennamen
	 *
	 * @return string
	 */
	public static function getTableName() { // TODO: Umbenennen zu getTableViewName
		return Utils::camelCaseToUnderscore(static::className());
	}

	/**
	 * Gibt die Id des Mandanten zurück, dem der Datensatz zugeordnet ist
	 *
	 * @return int|null
	 */
	public function getMandatorId(): ?int {
		return null;
	}

	/**
	 * Initialisiert das statische Objekt, das die Meta-Daten der Tabellen von ModelBase abgeleiteter Klassen enthält.
	 */
	private static function initModelTableInfos() {

		if (self::$modelTableInfos === null || !file_exists(self::$cacheFilePath)) {
			self::$modelTableInfos = ModelTableInfos::init(self::$cacheFilePath);
		}

		if (self::$modelTableInfos->handles(($class_name = static::class)) === true) {
			return;
		}

		self::$modelTableInfos->extend($class_name, static::getTableName());
	}

	/**
	 * Ermittelt sämtliche Property-Labels und -Titles
	 */
	private static function initPropertyLabelsAndTitles() {

		if (!array_key_exists(static::class, self::$propertyListingLabel)) {

			self::$propertyListingLabel[static::class] = array();
			$property_listing_label = &self::$propertyListingLabel[static::class];

			self::$propertyDetailLabel[static::class] = array();
			$property_detail_label = &self::$propertyDetailLabel[static::class];

			self::$propertyDetailTitle[static::class] = array();
			$property_detail_title = &self::$propertyDetailTitle[static::class];

			$keys = static::getPropertyKeys();

			// Die privaten ModelBase - Properties auslassen ...
			$ignore_properties = array('fieldValues', 'initialFieldValues', 'tableName');
			$property_listing_label = $property_detail_label = array();

			foreach ($keys as $key) {
				if (!in_array($key, $ignore_properties)) {
					if (($label = static::getPropertyListingLabel($key)) != '') {
						$property_listing_label[$key] = $label;
					}
					if (($label = static::getPropertyDetailLabel($key)) != '') {
						$property_detail_label[$key] = $label;
					}
					if (($title = static::getPropertyDetailTitle($key)) !== null) {
						$property_detail_title[$key] = $title;
					}
				}
			}
		}
	}

	/**
	 * Ermittelt sämtliche Property-Defaults des Objekts
	 */
	private function initPropertyDefaults() {

		if (!array_key_exists(static::class, self::$propertyDefaults)) {
			self::$propertyDefaults[static::class] = array();
			$property_defaults = &self::$propertyDefaults[static::class];

			$keys = static::getPropertyKeys();

			// Die privaten ModelBase - Properties auslassen ...
			$ignore_properties = array('fieldValues', 'initialFieldValues', 'tableName');
			$property_defaults = array();

			$this->initialize();

			foreach ($keys as $key) {
				if (!in_array($key, $ignore_properties)) {
					$property_defaults[$key] = Utils::deepCopy($this->internalGet($key, false, true, false));
				}
			}
		}
	}

	public static function getAutoincrementField() {
		static::initModelTableInfos();
		return self::$modelTableInfos->getAutoincrementField(static::class);
	}

	public static function getEnumValues(string $key) {
		static::initModelTableInfos();
		return self::$modelTableInfos->getFieldEnumValues(static::class)[$key];
	}

	public static function getFieldDefaults() {
		static::initModelTableInfos();
		return self::$modelTableInfos->getFieldDefaults(static::class);
	}

	/**
	 * Gibt den Namen der durch die Property referenzierte Tabelle zurück. Insofern es sich um eine ModelBase-Tabelle handelt, wird stattdessen der (vollständige) Name der zuständigen ModeBase-Klasse zurückgegeben.
	 *
	 * @param string $key Property-Bezeichner
	 *
	 * @return string|null
	 */
	public static function getForeignClassOrTable($key) {
		static::initModelTableInfos();
		return self::$modelTableInfos->getForeignKeyClassOrTable(static::class)[$key];
	}

	/**
	 * Gibt die verpflichtenden Felder zurück
	 *
	 * @return array
	 */
	public static function getMandatoryFields() {
		static::initModelTableInfos();
		return self::$modelTableInfos->getMandatoryFields(static::class);
	}

	private static function getPrimaryKeyList() {
		static::initModelTableInfos();
		return self::$modelTableInfos->getPrimaryKeyList(static::class);
	}

	private static function getReflectedProperties() {
		static::initModelTableInfos();
		return self::$modelTableInfos->getReflectedProperties(static::class);
	}

	private static function getTableFields() {
		static::initModelTableInfos();
		return self::$modelTableInfos->getTableFields(static::class);
	}

	protected static function getTableIndexFields($index_name) {
		static::initModelTableInfos();
		return self::$modelTableInfos->getTableIndices(static::class)[$index_name];
	}

	/**
	 * Ermöglicht den Zugriff auf jegliches Property innerhalb eines beliebigen Objekts
	 *
	 * @param $object
	 * @param string $property
	 * @param string $class
	 *
	 * @return mixed
	 */
	private static function &privatePropertyAccesssor($object, $property, $class) {

		$value = &Closure::bind(function & () use ($property) {
			return $this->$property;
		}, $object, $class)->__invoke();

		return $value;
	}

	/**
	 * Initialisiert das Property-Backup des Objekts
	 */
	private function initFieldValueBackup() {
		$this->initialFieldValues = array();

		$table_fields = static::getTableFields();

		foreach ($table_fields as $key => $val) {
			$this->initialFieldValues[$key] = Utils::deepCopy($this->internalGet($key, true, true, false));
		}
	}

	/**
	 * Handelt es sich um einen zugreifbare Property?
	 *
	 * @param $property_key
	 *
	 * @return bool
	 */
	private function isInAccessibleProperty($property_key) {
		$object_vars = get_object_vars($this);
		return !array_key_exists($property_key, $object_vars);
	}

	/**
	 * Gibt den Wert der Objekt-Property zurück
	 *
	 * @param string $key Name der Objekt-Property
	 * @param bool $as_string Wenn TRUE wird der Property-Wert als Zeichenkette zurückgegeben
	 *
	 * @param bool $is_internal {@see ModelBase::getProperty}
	 * @param bool $foreign_key_as_model {@see ModelBase::getProperty}
	 *
	 * @return mixed|string|null
	 * @throws PropertyException
	 */
	private function internalGet($key, $as_string = false, $is_internal = true, $foreign_key_as_model = true) {

		try {
			$value = $this->getProperty($key, $is_internal, $foreign_key_as_model);
		} catch (PropertyException $ex) {
			// Verwende einen spezifischen Getter, wenn dieser existiert und das Property auf generische Weise definiert wurde
			$method_name = 'get' . ucfirst(Utils::underscoreToCamelCase($key));

			if ($this->owningClass($key) !== false && method_exists($this, $method_name)) {
				$value = $this->{$method_name}();
			} else {
				throw $ex;
			}
		}

		if ($as_string) {
			if ($value !== null) {
				$property_type = $this->getPropertyType($key);

				if ($property_type == self::PROPERTY_TYPE_INT) {
					$value = sprintf('%d', $value);
				} elseif ($property_type == self::PROPERTY_TYPE_ENUM || $property_type == self::PROPERTY_TYPE_STRING || $property_type == self::PROPERTY_TYPE_TEXT) {
					$value = strval($value);
				} elseif ($property_type == self::PROPERTY_TYPE_FLOAT) {
					$value = sprintf('%F', $value);
				} elseif ($property_type == self::PROPERTY_TYPE_BOOL) {
					$value = sprintf('%d', $value);
				} elseif ($property_type == self::PROPERTY_TYPE_DATETIME) {
					/** @var DateTime $dt */
					$dt = $value;
					$value = $dt->format(LocalDateTime::DATETIME_MYSQL);
				} elseif ($property_type == self::PROPERTY_TYPE_DATE) {
					/** @var DateTime $dt */
					$dt = $value;
					$value = $dt->format(LocalDateTime::DATE_MYSQL);
				} elseif ($property_type === self::PROPERTY_TYPE_FOREIGN_KEY) {

					if (is_object($value)) {
						$value = $value->id;
					}

				} elseif ($property_type == self::PROPERTY_TYPE_JSON) {
					$value = json_encode($value);
				} elseif ($property_type != self::PROPERTY_TYPE_NONE) {
					throw new PropertyException('Unbekannter Property-Typ: "' . $property_type . '"!');
				}
			}
		}

		return $value;
	}

	/**
	 * @param string $key
	 * @param bool $as_string
	 *
	 * @return mixed|string|null
	 * @throws PropertyException
	 * @see ModelBase::internalGet
	 *
	 */
	public function get($key, $as_string = false) {
		return $this->internalGet($key, $as_string, false);
	}

	/**
	 * Gibt die Keys und Values der Tabellen-Felder zurück, die sich seit dem Einlesen aus der Datenbank
	 * bzw. seit der Erstellung des Objekts geändert haben.
	 *
	 * @return array
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	public function getFieldsChanged() {
		$changed_fields = array();

		$table_fields = static::getTableFields();

		foreach ($table_fields as $key => $type) {
			$value = $this->internalGet($key, true, true, false);

			// Nur Werte berücksichtigen, die sich tatsächlich geändert haben
			if (!array_key_exists($key, $this->initialFieldValues) || ($value != $this->initialFieldValues[$key]) || ($value !== null && $this->initialFieldValues[$key] === null) || ($value === null && $this->initialFieldValues[$key] !== null)) {
				$changed_fields[$key] = $value;
			}
		}

		return $changed_fields;
	}

	/**
	 * Gibt den Property-Inhalt zurück, den das Objekt nach dem letzten @param string $key
	 *
	 * @return mixed
	 * @see read(), write() Aufruf hatte
	 *
	 */
	public function getInitialValue($key) {
		return $this->initialFieldValues[$key];
	}

	/**
	 * Generiert aus den Property-Daten des Objekts einen MD5-Hash
	 *
	 * @param array $ignore_keys Keys, die bei der Generierung des Hashes nicht berücksichtigt werden sollen.
	 *
	 * @return string
	 */
	public function computeMd5Hash($ignore_keys = array()) {

		$ignore_keys[] = self::CREATED;
		$ignore_keys[] = self::UPDATED;

		// Hash aus den Daten generieren
		$property_strings = $this->getPropertyStrings();
		foreach ($ignore_keys as $ignore_key) {
			unset($property_strings[$ignore_key]);
		}

		$hashing_string = implode('', $property_strings);

		return md5($hashing_string);
	}

	/**
	 * Kopiert das Objekt und gibt es zurück. Eine Speicherung in der Datenbank wird nicht durchgeführt - diese muss durch einen separaten write()-Aufruf erfolgen
	 *
	 * @return static
	 */
	public function copy() {

		$key_values = $this->getPropertyStrings();

		unset($key_values[self::CREATED]);
		unset($key_values[self::UPDATED]);

		$model_class = static::class;
		$model = new $model_class(0, $key_values);
		$model->unsetPrimaryKeyValues();

		return $model;
	}

	/**
	 * Erstellt ein ModelBase-Objekt mittels der übergebenen Daten.
	 *
	 * @param array $model_data
	 * @return static
	 */
	public static function createObject($model_data = array()) {

		$model_class = static::class;
		$model = new $model_class();

		foreach ($model_data as $key => $value) {
			try {
				$model->internalSet($key, $value);
			} catch (PropertyException) {
			}
		}

		return $model;
	}

	/**
	 * Erstellt einen neuen Model-Datensatz in der Datenbank und gibt die zugehörige ID zurück.
	 *
	 * @param array $model_data
	 *
	 * @return int
	 *
	 * @throws PropertyException
	 */
	public static function create($model_data = array()): int {

		$model = static::createObject($model_data);
		$model->write();

		return intval($model->internalGet(ModelBase::ID));
	}

	/**
	 * Erzeugt aus dem den aktuellen Objekt-Daten einen String der für einen SQL-SET
	 * verwendet werden kann.
	 *
	 * @param bool $use_prefix_alias Wenn TRUE, werden die im SET-String verwendeten Feldbezeichner mit dem Default-Alias geprefixt
	 * @param array $ignore_fields Feldbezeichner, die bei der Generierung des SET-Strings ingoriert werden
	 *
	 * @return string
	 */
	private function createWriteSetString($use_prefix_alias = false, $ignore_fields = array()) {

		if ($use_prefix_alias) {
			$alias_prefix = self::DEFAULT_ALIAS . '.';
		} else {
			$alias_prefix = '';
		}

		$query_string = '';

		$changed_fields = $this->getFieldsChanged();

		foreach ($changed_fields as $key => $value) {

			if (in_array($key, $ignore_fields)) {
				continue;
			}

			$prefixed_key = $alias_prefix . '`' . $key . '`';

			if ($value === null) {
				$query_string .= $prefixed_key . ' = NULL, ';
			} else {
				$query_string .= $prefixed_key . ' = \'' . Db::escapeString($value) . '\', ';
			}
		}

		$query_string = rtrim($query_string, ', ');

		return $query_string;
	}

	/**
	 * Generiert einen SQL-Vergleichsstring, der den zum Model gehörigen Tabellen-Datensatz eindeutig identifiziert.
	 *
	 * @return array Es wird ein zwei-elementiges Array zurückgegeben - das erste Elemente enthält ein Array mit sämtlichen Key-Value Paaren, die innerhalb des zweiten Elements zu einer SqlCondition zusammengefügt wurden.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	private function getPrimaryTupleAndSqlCondition() {

		$primary_key_list = static::getPrimaryKeyList();

		if (count($primary_key_list)) {
			$sql_condition = SqlCondition::matchAll();
		} else {
			$sql_condition = null;
		}

		$primary_tuple = array();
		foreach ($primary_key_list as $primary_key) {

			if ($this->{$primary_key} === null) {
				$primary_tuple = array();
				$sql_condition = null;
				break;
			} else {
				$primary_value = $this->internalGet($primary_key, true);
				$primary_tuple[$primary_key] = $primary_value;

				$sql_condition->chainWith(new SqlCondition($primary_key, $primary_value));
			}
		}

		return array($primary_tuple, $sql_condition);
	}

	private static function internalDelete(?SqlCondition $sql_condition) {

		if ($sql_condition === null) {
			$where = '1 = 1'; // MariaDB mag eine einzelne 1 nicht ....
		} else {
			$where = $sql_condition->build(false);
		}

		static::getDbInstance()->query('DELETE FROM ' . static::getTableName() . ' WHERE ' . $where);
	}

	/**
	 * Löscht den zugehörigen Datensatz aus der Tabelle
	 */
	public function delete() {

		foreach (static::getFilePropertyKeys() as $file_property_key) {
			$this->deleteUpload($file_property_key);
		}

		list(, $sql_condition) = $this->getPrimaryTupleAndSqlCondition();

		if ($sql_condition !== null) {
			static::internalDelete($sql_condition);
		}

		$this->reset();
	}

	/**
	 * Minimiert den Auto-Increment Wert der Tabelle
	 */
	public static function minimizeAutoincrement() {

		$db = static::getDbInstance();
		$tablename = static::getTableName();

		$autoincrement_field = static::getAutoincrementField();
		if ($autoincrement_field !== null) { // Den Wert des Autoincrement-Feldes möglichst klein halten
			$db->query('SELECT MAX(' . $autoincrement_field . ') AS auto_inc FROM ' . $tablename);
			if (($record = $db->nextRecord())) {
				$auto_inc = intval($record['auto_inc']) + 1;
				try {
					$db->query(' ALTER TABLE ' . $tablename . ' AUTO_INCREMENT = ' . $auto_inc);
				} catch (PDOException) {
				} // Das Kommando kann Probleme machen, wenn Default-Values mancher Spalten nicht korrekt sind, etwa '0000-00-00' ... :(
			}
		}
	}

	/**
	 * Löscht die anhand des übergebenen SqlCondition-Objekts bzw. WHERE-Arrays (das Key-Value Paare enthält, die per AND und '=' verknüpft werden)
	 * spezifizierten Datensätze aus der Tabelle.
	 *
	 * Insofern die betreffende ModelBase-Subklasse keine File-Properties besitzt und auch (keine ihrer Eltern-Klassen) @param SqlCondition|array $sql_condition
	 * @see ModelBase::delete überschreibt,
	 * wird der Löschvorgang direkt mittels entsprechender SQL-Befehle initiiert, andernfalls werden API-Methoden genutzt.
	 *
	 */
	public static function deleteMultiple($sql_condition = null) {

		if (count(static::getFilePropertyKeys()) || static::overwritesMethod('delete', self::class)) {
			foreach (static::readMultiple($sql_condition) as $model) {
				$model->delete();
			}

		} else {

			if ($sql_condition === null) {
				try {
					static::truncate();
					return;
				} catch (PDOException) {
				}
			}

			if (is_array($sql_condition)) {
				$sql_condition = SqlCondition::createByArray($sql_condition);
			}

			static::internalDelete($sql_condition);
		}

		static::minimizeAutoincrement();
	}

	/**
	 * Updated die anhand des übergebenen SqlCondition-Objekts bzw. WHERE-Arrays (das Key-Value Paare enthält, die per AND und '=' verknüpft werden) spezifizierten Datensätze.
	 *
	 * Insofern die betreffende ModelBase-Subklasse keine File-Properties besitzt und auch (keine ihrer Eltern-Klassen) @param array $key_value_updates Die Keys entsprechen den Tabellenfeldern, die Values enthalten die gewünschten Werte
	 * @param SqlCondition|array $sql_condition Legt fest, welche Datensätze geupdatet werden sollen
	 * @param bool $ignore_errors Wenn TRUE, werden Fehler die während eines Datensatz-Updates auftreten, ignoriert
	 * @see ModelBase::write überschreibt,
	 * wird der Updatevorgang direkt mittels entsprechender SQL-Befehle initiiert, andernfalls werden API-Methoden genutzt.
	 *
	 */
	public static function updateMultiple($key_value_updates, $sql_condition = null, $ignore_errors = false) {

		if (count(static::getFilePropertyKeys()) || static::overwritesMethod('write', self::class)) {
			foreach (static::readMultiple($sql_condition) as $model) {
				try {
					foreach ($key_value_updates as $key => $value) {
						$model->set($key, $value);
						$model->write(true);
					}
				} catch (Exception) {
					if (!$ignore_errors) {
						break;
					}
				}
			}

		} else {

			$db = static::getDbInstance();

			$setting = '';
			foreach ($key_value_updates as $key => $value) {
				$setting .= self::DEFAULT_ALIAS . '.`' . $key . "` = '" . /*$db->escape_string*/Db::escapeString($value) . "', ";
			}

			$setting = rtrim($setting, ', ');

			if ($setting != '') {

				$update = 'UPDATE ';
				if ($ignore_errors) {
					$update .= 'IGNORE ';
				}

				list($sink, $where) = static::sqlSourceSinkAndWhere($sql_condition);

				$db->query($update . $sink . ' SET ' . $setting . $where);
			}
		}
	}

	/**
	 * Generiert ein Array aus den Objekt-Daten
	 *
	 * @param array $ignore_keys
	 *
	 * @return array
	 */
	public function toArray($ignore_keys = array()) {

		foreach ($this->fieldValues as $key => $val) {
			/** @noinspection PhpExpressionResultUnusedInspection */
			$this->{$key}; // bewirkt, das Fremdschlüssel-Referenzen via Magic-Getter-Systematik aufgelöst werden
		}

		$ignore_keys = array_merge($ignore_keys, array('initialFieldValues', 'tableName'));
		$raw = Utils::object2Array($this, $ignore_keys, false);

		// Die Inhalte von fieldValues flach in das resultierende Array integrieren
		$arr = $raw['fieldValues'];
		unset($raw['fieldValues']);

		$arr = array_merge($arr, $raw);

		return $arr;
	}

	/**
	 * Gibt eine einfache String-Repräsentation des Objekts zurück und wird u.a. für die Fremdschlüssel-Verknüpfung via Web-Interface verwendet)
	 *
	 * @return string
	 */
	public function toString() {
		return static::getCaption() . ' ' . $this->id;
	}

	/**
	 * Entfernt sämtliche Datensätze aus der Tabelle
	 */
	public static function truncate() {

		$db = static::getDbInstance();
		$db->query('TRUNCATE ' . static::getTableName());
	}

	/**
	 * Prüft, ob ein Datensatz existiert, der die angegebenen Bedingungen erfüllt
	 *
	 * @param array $key_value
	 *
	 * @return bool
	 */
	public static function exists(array $key_value) {
		return (static::recordCount($key_value) > 0);
	}

	/**
	 * Gibt TRUE zurück, falls sich das Objekt seit dem Einlesen / Speichern des Objekts aus / in der Datenbank
	 * bzw. seit seiner Erstellung geändert hat.
	 *
	 * @param array $ignore_fields Feldbezeichner, die bei der Ermittlung von Änderungen ingoriert werden
	 *
	 * @return bool
	 */
	public function hasChanged($ignore_fields = array()) {
		return (count(array_diff_key($this->getFieldsChanged(), $ignore_fields)) > 0);
	}

	/**
	 * Gibt TRUE zurück, wenn sich das spezifizierte Property seit dem Einlesen / Speichern des Objekts aus / in der Datenbank
	 * bzw. seit seiner Erstellung geändert hat.
	 *
	 * @param $property_key
	 *
	 * @return bool
	 * @throws PropertyException
	 */
	public function propertyHasChanged($property_key) {
		return ($this->initialFieldValues[$property_key] != $this->internalGet($property_key));
	}

	/**
	 * Dummy-Methode, die durch Verwendung von TableLockTrait innerhalb von ModelBase abgeleiteter Klassen überschrieben wird - @param array $tablename_locktype
	 * @see TableLockTrait::_lockTables().
	 *
	 */
	protected static function _lockTables($tablename_locktype = array()) {
	}

	/**
	 * Realisiert das Locking für alle von ModelBase abgeleiteten Klassen, die TableLockTrait verwenden.
	 *
	 * @see TableLockTrait::lockTables()
	 */
	public static function lockTables() {

		static::_lockTables(
			array(
				static::getTableName() => 'WRITE',
				static::getTableName() . ' ' . self::DEFAULT_ALIAS => 'WRITE'
			)
		);
	}

	/**
	 * Stub-Methode, die eine Datenbank-Instanz zurückgibt. Falls eine von ModelBase abgeleitete Klassen TableLockTrait verwendet, lassen sich unter
	 * Verwendung dieser Methode auch mit einer Tabelle, die (im Verlauf dieser PHP-Ausführung) gelockt wurde, Datenbankoperationen durchführen.
	 *
	 * @return Db
	 * @see TableLockTrait::getDbInstance()
	 *
	 */
	protected static function getDbInstance() {
		return new Db(true, true);
	}

	/**
	 * Gibt die (Eltern-Klasse) zurück, innerhalb derer die Property definiert wurde.
	 *
	 * @param string $property_key
	 *
	 * @return string|bool
	 */
	private function owningClass($property_key) {

		$current_class = static::class;

		while ($current_class) {
			if (property_exists($current_class, $property_key)) {
				return $current_class;
			}

			$current_class = get_parent_class($current_class);
		}

		return false;
	}

	/**
	 * Setzt die Objekt-Property - deren Namen per $key übergeben wird - gleich dem übergebenen $value.
	 *
	 * Beachte: Der Wert wird zuvor am Anfang und am Ende von Whitespaces bereinigt und bevor er dem
	 * jeweiligen Property zugewiesen wird, entsprechend des zugehörigen Datenbank-Typs in eine/n
	 * passendes/passenden PHP-Objekt/Datentyp konvertiert.
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param bool $is_internal Wenn FALSE, wird bei einem Zugriff auf eine geschützte (private, protected) Property eine PropertyException ausgelöst
	 *
	 * @throws PropertyException
	 */
	private function internalSet($key, $value, $is_internal = true) {

		$property_type = $this->getPropertyType($key);

		/*      // Das wird nun in set() erledigt, sollte nicht internal passieren ...
				if($value === null && in_array($key, $this->getMandatoryFields())) {
					$value = static::getFieldDefaults()[$key];
				}
		*/
		if ($value === null || (($value == '' || (is_array($value) && !count($value))) && !in_array($key, static::getMandatoryFields())) && in_array($key, static::getTablePropertyKeys())) { // Präferiert NULL-Werte abspeichern
			$prop_val = null;
		} else {

			try {
				$prop_val = self::convertToType($value, $property_type);
			} catch (GeneralException $ex) {
				throw new PropertyException($ex);
			}
		}

		// Verwende einen spezifischen Setter, wenn dieser existiert und das Property auf generische Weise definiert wurde
		$method_name = 'set' . ucfirst(Utils::underscoreToCamelCase($key));

		if (!$is_internal && property_exists($this, $key) && method_exists($this, $method_name)) {
			$this->{$method_name}($prop_val);
		} else {
			$prop_ref = &$this->getProperty($key, $is_internal, ($property_type === self::PROPERTY_TYPE_FOREIGN_KEY && is_object($prop_val)));
			$prop_ref = $prop_val;
		}
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * @param bool $check_mandatory Wenn TRUE, wird ein null-Wert nur dann erlaubt, wenn es sich um kein Pflichtfeld handelt
	 * @see ModelBase::internalSet
	 *
	 * @throws PropertyException
	 */
	public function set($key, $value, $check_mandatory = true) {

		if ($value === null && in_array($key, $this->getMandatoryFields())) {
			$value = static::getFieldDefaults()[$key];

			if ($value === null && $check_mandatory) {
				throw new PropertyException(PropertyException::MANDATORY_MISSING);
			}
		}

		$this->internalSet($key, $value, false);
	}

	/**
	 * Generiert eine SQL Tabellen-Selektion, sowie eine WHERE-Anweisung und gibt die resultierenden Strings in einem Array zurück
	 *
	 * @param SqlCondition|array $sql_condition SqlCondition-Objekt bzw. WHERE-Array (das Key-Value Paare enthält, die per AND und '=' verknüpft werden)
	 * @param SqlJoin[] $joins
	 *
	 * @return string[]
	 */
	private static function sqlSourceSinkAndWhere($sql_condition = null, $joins = array()) {

		if ($sql_condition !== null) {
			// Das Array von Key-Value Paaren in eine SQL-Bedingung umwandeln
			if (is_array($sql_condition)) {
				$sql_condition = SqlCondition::createByArray($sql_condition);
			}

			$where = $sql_condition->build();
		} else {
			$where = '';
		}

		$source_sink = static::getTableName() . ' ' . self::DEFAULT_ALIAS;

		foreach ($joins as $join) {
			$source_sink .= ' ' . $join->join();
		}

		if ($where != '') {
			$where = ' WHERE ' . $where;
		}

		return array($source_sink, $where);
	}

	/**
	 * @param string|SqlWrap $fieldname
	 *
	 * @return string
	 */
	private static function getAliasedField($fieldname) {

		if ($fieldname instanceof SqlWrap) {
			$aliased_field = $fieldname->wrap(self::DEFAULT_ALIAS);
		} elseif ($fieldname instanceof PrefixedField) {
			$aliased_field = $fieldname->aliasedName();
		} else {
			$aliased_field = ((!str_contains($fieldname, '.')) ? self::DEFAULT_ALIAS . '.' : '') . $fieldname;
		}

		return $aliased_field;
	}

	/**
	 * Liest mehrere Datensätze aus der relevanten Tabelle.
	 *
	 * @param SqlCondition|array|null $where_condition Einschränkung der Ergebnisliste via WHERE in Form eines SqlCondition-Objekts bzw. Arrays (das Key-Value Paare enthält, die per AND und '=' verknüpft werden)
	 * @param array $order_key_direction Ein Array das die Sortierung festlegt. Die Keys entsprechen Feldern der Model-Tabelle, mögliche Values sind "ASC" bzw. "DESC".
	 * @param int $offset Wieviele Datensätze sollen bei der Datenbankabfrage übersprungen werden?
	 * @param int $limit Wieviele Datensätze sollen maximal ermittelt werden?
	 * @param SqlJoin[] $joins Die Keys des Arrays enthalten die Namen der zu verknüpfenden Tabellen, mit deren Hilfe die Selektion weiter eingeschränkt wird.
	 * @param string[] $group_keys
	 * @param SqlCondition|array|null $having_condition Einschränkung der Ergebnisliste via HAVING in Form eines SqlCondition-Objekts bzw. Arrays (das Key-Value Paare enthält, die per AND und '=' verknüpft werden)
	 * @param bool|string|SqlWrap $count_only_field Wenn TRUE wird nur die Anzahl der Treffer zurückgeben. Es kann auch ein Feldname spezifiziert werden, um damit gezielt nur solche Datensätze zu zählen, bei denen der jeweilige Feld-Wert != NULL ist
	 * @param array $fieldnames Welche Felder sollen ausgelesen werden? Möglich Array-Werte: Strings bzw. SqlWrap-Objekte, bei komplexeren Aggregierungen
	 *
	 * @return Db
	 *
	 * @throws PDOException
	 */
	private static function select($where_condition = null, $order_key_direction = array(), $limit = 0, $offset = 0, $joins = array(), $group_keys = array(), $having_condition = null, $count_only_field = false, $fieldnames = array()) {

		$db = static::getDbInstance();

		$table_fields = static::getTableFields();

		list($source, $where) = static::sqlSourceSinkAndWhere($where_condition, $joins);

		$order_by = '';
		$use_distinct = true;

		if ($count_only_field) {
			if ($count_only_field === true) {
				$field = '*';
			} else {
				$field = self::getAliasedField($count_only_field);
			}
			$select = 'COUNT(' . $field . ') AS anzahl';

		} else {
			if (($field_cnt = count($fieldnames))) {
				$select = '';
				foreach ($fieldnames as $outer_alias => $fieldname) {
					$select .= self::getAliasedField($fieldname);

					if (!is_numeric($outer_alias)) {
						$select .= ' AS ' . $outer_alias;
					}

					$select .= ', ';
				}
				$select = rtrim($select, ', ');
			} else {
				$select = self::DEFAULT_ALIAS . '.*';
			}

			foreach ($order_key_direction as $key => $direction) {

				if (is_int($key)) {
					$key = $direction;
					$direction = '';
				}

				if ($use_distinct === true && $field_cnt && !in_array($key, $fieldnames)) {
					$use_distinct = false;
				}

				if ($direction !== '') {
					$direction = ' ' . $direction;
				}

				if (in_array($key, $table_fields)) {
					$order_by .= self::DEFAULT_ALIAS . '.`' . $key . '`' . $direction . ', ';
				} else {
					$order_by .= $key . $direction . ', ';
				}
			}

			if ($order_by != '') {
				$order_by = ' ORDER BY ' . rtrim($order_by, ', ');
			}
		}

		$group_by = '';
		foreach ($group_keys as $key) {
			$group_by .= self::getAliasedField($key) . ', ';
		}

		if ($group_by != '') {
			$group_by = ' GROUP BY ' . rtrim($group_by, ', ');
		}

		if ($having_condition !== null) {
			// Das Array von Key-Value Paaren in eine SQL-Bedingung umwandeln
			if (is_array($having_condition)) {
				$having_condition = SqlCondition::createByArray($having_condition);
			}

			$having = ' HAVING ' . $having_condition->build();
		} else {
			$having = '';
		}

		if ($use_distinct) {
			$select = 'DISTINCT ' . $select;
		}

		$query = 'SELECT ' . $select . ' FROM ' . $source . $where . $group_by . $having . $order_by;

		if ($limit) {
			$query .= ' LIMIT ' . $limit;
		}

		if ($offset) {
			$query .= ' OFFSET ' . $offset;
		}

		$db->query($query);

		return $db;
	}

	/**
	 * Liest mehrere Datensätze aus der relevanten Tabelle und gibt initialisierte Objekte zurück.
	 *
	 * Parameter-Dokumentation: @param SqlCondition|array|null $where_condition
	 * @param array $order_key_direction
	 * @param int $limit
	 * @param int $offset
	 * @param SqlJoin[] $joins
	 * @param string[] $group_keys
	 * @param SqlCondition|array|null $having_condition
	 *
	 * @return static[] Es werden voll-initialisierte Objekte der jeweiligen ModelBase-Subklasse zurückgegeben, die der Suche entsprechen
	 * @see ModelBase::select
	 *
	 */
	final public static function readMultiple($where_condition = null, $order_key_direction = array(), $limit = 0, $offset = 0, $joins = array(), $group_keys = array(), $having_condition = null) {

		$models = array();

		$db = static::select($where_condition, $order_key_direction, $limit, $offset, $joins, $group_keys, $having_condition);

		while (($record = $db->nextRecord())) {
			$classname = static::class;
			$model = new $classname();
			$model->initialize($record);
			$models[] = $model;
		}

		return $models;
	}

	/**
	 * Liest einen Datensatz aus der relevanten Tabelle und gibt - in Abhängigkeit davon, ob ein Datensatz existiert - ein initialisiertes Objekt oder NULL zurück
	 *
	 * Parameter-Dokumentation: @param SqlCondition|array $where_condition
	 * @param array $order_key_direction
	 * @param SqlJoin[] $joins
	 * @param static|null $model_object Falls ein Objekt übergeben wird, dann wird das übergebene Objekt ebenfalls mit den ermittelten Objektdaten initialisiert. WICHTIG: Dieses Objekt wird keiner Typ-Prüfung unterzogen und vorher auch nicht resetted - das muss zuvor manuell durchgeführt werden!
	 *
	 * @see ModelBase::select
	 *
	 * @return static|null
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	final public static function readSingle($where_condition = null, $order_key_direction = array(), $joins = array(), ?ModelBase &$model_object = null) {

		if ($model_object === null) {
			$class_name = static::class;
			$model_object = new $class_name();
		}

		$db = static::select($where_condition, $order_key_direction, 1, 0, $joins);

		if (($record = $db->nextRecord())) {
			$model_object->initialize($record);
		} else {
			$model_object = null;
		}

		return $model_object;
	}

	final public static function readySingle(array $where_condition = null, $order_key_direction = array(), $joins = array(), &$model_object = null) {

		$model_object = static::readSingle($where_condition, $order_key_direction, $joins);

		if ($model_object === null) {
			$class = static::class;
			$model_object = new $class();
			foreach ($where_condition as $key => $val) {
				$model_object->{$key} = $val;
			}
		}

		return $model_object;
	}

	/**
	 * Liest mehrere Datensätze aus der relevanten Tabelle.
	 *
	 * Parameter-Dokumentation: @param array $fieldnames Gewünschte Feldnamen
	 * @param SqlCondition|array $where_condition
	 * @param array $order_key_direction
	 * @param int $limit
	 * @param int $offset
	 * @param SqlJoin[] $joins
	 * @param array $group_keys
	 * @param SqlCondition|array $having_condition
	 *
	 * @return array Es werden lediglich die gewünschten Felder der Tabellen-Datensätze zurückgegeben, die der Suche entsprechen
	 * @see ModelBase::select
	 *
	 */
	final public static function readFields($fieldnames = array(), $where_condition = null, $order_key_direction = array(), $limit = 0, $offset = 0, $joins = array(), $group_keys = array(), $having_condition = null) {

		$tuples = array();

		$db = static::select($where_condition, $order_key_direction, $limit, $offset, $joins, $group_keys, $having_condition, false, $fieldnames);

		while (($record = $db->nextRecord())) {
			$tuples[] = $record;
		}

		return $tuples;
	}

	/**
	 * Liest mehrere Datensätze aus der relevanten Tabelle.
	 *
	 * Parameter-Dokumentation: @param SqlCondition|array $sql_condition
	 * @param array $order_key_direction
	 * @param int $limit
	 * @param int $offset
	 * @param SqlJoin[] $joins
	 *
	 * @return array Es werden lediglich die Primary-Key Tupel der Tabellen-Datensätze zurückgegeben, die der Suche entsprechen
	 * @see ModelBase::select
	 *
	 */
	final public static function readPrimaryTuples($sql_condition = null, $order_key_direction = array(), $limit = 0, $offset = 0, $joins = array()) {
		return static::readFields(static::getPrimaryKeyList(), $sql_condition, $order_key_direction, $limit, $offset, $joins, array());
	}

	/**
	 * Gibt alle Objekte zurück, die exakt dieselben (Tabellen-)Werte, wie das gg.wärtige Objekt haben.
	 *
	 * Per Default werden {@see ModelBase::ID, ModelBase::CREATED, ModelBase::UPDATED} ignoriert.
	 *
	 * @param string[] $ignore_fields Die Bezeichner der zu ignorierenden Felder
	 *
	 * @return static[]
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 */
	final public function readSame($ignore_fields = array()) {

		$sql_condition = null;

		$table_fields = static::getTableFields();
		foreach ($table_fields as $key => $type) {

			$exclude_keys = array_merge(array(self::ID, self::CREATED, self::UPDATED), $ignore_fields);
			if (in_array($key, $exclude_keys)) {
				continue;
			}

			$val = $this->internalGet($key);
			if ($val === null) {
				$comparison = SqlCondition::COMPARISON_IS;
				$val = 'NULL';
			} else {
				$comparison = SqlCondition::COMPARISON_EQ;
			}

			$sql_condition_add = new SqlCondition($key, $val, $comparison);

			if ($sql_condition === null) {
				$sql_condition = $sql_condition_add;
			} else {
				$sql_condition->chainWith($sql_condition_add);
			}
		}

		return static::readMultiple($sql_condition);
	}

	/**
	 * Ermittelt die Anzahl der Datensätze, welche die übergebenen Bedingungen erfüllen
	 *
	 * Parameter-Dokumentation: @param SqlCondition|array|null $where_condition
	 * @param null|string|SqlWrap $count_field Wenn hier ein Feldname spezifiziert wird, dann werden gezielt nur solche Datensätze gezählt, bei denen der jeweilige Feld-Wert != NULL ist
	 * @param array $order_key_direction
	 * @param int $limit
	 * @param int $offset
	 * @param SqlJoin[] $joins
	 * @param string[] $group_keys
	 * @param SqlCondition|array|null $having_condition
	 *
	 * @return int
	 * @see ModelBase::select
	 *
	 */
	final public static function recordCount($where_condition = null, $count_field = null, $order_key_direction = array(), $limit = 0, $offset = 0, $joins = array(), $group_keys = array(), $having_condition = null) {

		$db = static::select($where_condition, $order_key_direction, $limit, $offset, $joins, $group_keys, $having_condition, ($count_field === null ? true : $count_field));

		$anzahl = 0;
		while (($record = $db->nextRecord())) {
			$anzahl += intval($record['anzahl']);
		}

		return $anzahl;
	}

	/**
	 * Setzt das Objekt auf die Default-Werte eines frisch konstruierten Objekts zurück
	 *
	 * @param string[] $skip_property_keys Property-Keys, deren Werte nicht zurückgesetzt werden sollen
	 *
	 * @throws PropertyException
	 */
	final public function reset($skip_property_keys = array()) {

		// Den Erstellungszeitpunkt auf alle Fälle erstmal vermerken
		$created = $this->internalGet(self::CREATED, true);

		$skip_values = array();
		foreach ($skip_property_keys as $key) {
			$skip_values[$key] = Utils::deepCopy($this->internalGet($key, false, true, false));
		}

		$this->fieldValues = array();

		$reset_values = Utils::deepCopy(self::$propertyDefaults[static::class]);
		foreach ($skip_values as $key => $val) {
			$reset_values[$key] = $val;
		}

		if ($created !== null) { // Falls ein created-Property existiert, dann den Wert übernehmen, insofern die Primärschlüssel-Werte nicht zurückgesetzt werden
			$primary_key_list = static::getPrimaryKeyList();

			if (count($primary_key_list)) {
				$is_new = false;

				foreach ($primary_key_list as $primary_key) {
					if (!in_array($primary_key, $skip_property_keys)) {
						$is_new = true;
						break;
					}
				}

				if (!$is_new) {
					$reset_values[self::CREATED] = $created;
				}
			}
		}

		$this->initialize($reset_values);

		foreach (array_keys($skip_values) as $skip_key) {
			unset($this->initialFieldValues[$skip_key]); // Bei einem Reset, sollen ge-skippte Werte trotzdem als "geändert" gehandelt werden ...
		}
	}

	/**
	 * Datensatz aus der relevanten Tabelle auslesen und den Tabellenfeld-Typ dabei berücksichtigen.
	 *
	 * ACHTUNG: Diese Funktion belässt sämtliche Properties des Objektes, die keinem(!) Tabellenfeld entsprechen, in ihrem gegenwärtigem Zustand.
	 * Derartige Properties müssen mithilfe der initialize-Methode() gesetzt werden.
	 *
	 * @param int|array $primary_tuple Array mit Primärschlüssel Key-Value Paaren, bzw. ID des einzulesenden Datensatzes
	 * @param bool $skip_reset
	 *
	 * @return bool TRUE, wenn ein passender Datensatz in der Tabelle gefunden wurde
	 *
	 * @throws PropertyException
	 */
	final public function read($primary_tuple = array(), bool $skip_reset = false): bool {

		if (!is_array($primary_tuple)) {
			$primary_tuple = array(self::ID => $primary_tuple);
		}

		$tuple_given = (count($primary_tuple) !== 0);

		if ($tuple_given === false) {
			list($primary_tuple,) = $this->getPrimaryTupleAndSqlCondition();

			if (count($primary_tuple) === 0) {
				throw new MissingPrimaryKeyException();
			}
		}

		$sql_condition = SqlCondition::matchAll();
		foreach ($primary_tuple as $key => $value) {
			$sql_condition->chainWith(new SqlCondition($key, $value));
		}

		if ($skip_reset === false) {
			$this->reset();
		}

		$record_exists = (static::readSingle($sql_condition, array(), array(), $this) !== null);

		if ($tuple_given === true) {

			foreach ($primary_tuple as $key => $val) {
				$this->internalSet($key, $val);
			}
		}

		return $record_exists;
	}

	/**
	 * Setzt die Primärschlüsselwerte des Objekts gleich NULL. Dies führt letzlich dazu, das das Objekt beim Speichern als neuer(!) Datensatz in die zugehörige Tabelle gespeichert wird.
	 */
	final public function unsetPrimaryKeyValues() {
		$primary_keys = static::getPrimaryKeyList();

		try {
			foreach ($primary_keys as $primary_key) {
				$this->internalSet($primary_key, null);
			}
		} catch (PropertyException) {
		}

		$this->initialFieldValues = array(); // Das Array mit den zuvor hinterlegten Initialwerten auch leeren
	}

	private function setAndAddUpdatedFieldToSetString(string $set_string): string {

		if (array_key_exists(self::UPDATED, static::getTableFields())) {
			$current_dt_string = (new LocalDateTime())->format(LocalDateTime::DATETIME_MYSQL);
			$this->internalSet(self::UPDATED, $current_dt_string);

			$update_postfix = "updated = '" . $current_dt_string . "'";

			// Den "updated" - Vermerk an den Anfang stellen, um die Möglichkeit zu haben
			// auch eine zuvor spezifizierte Änderung des update-Feldes berücksichtigen zu können
			$set_string = $update_postfix . ', ' . $set_string;
		}

		return $set_string;
	}

	/**
	 * Erzeugt eine @param array $errors Die Keys entsprechen den Property-Keys, die Werte enthalten die Fehlermeldung
	 * @param bool $is_unique_exception
	 *
	 * @throws WriteException
	 * @see WriteException (bzw. {@see UniqueException})
	 *
	 */
	protected function throwWriteException(array $errors, bool $is_unique_exception = false) {

		list($primary_tuple) = $this->getPrimaryTupleAndSqlCondition();
		$primary_string = json_encode($primary_tuple);

		$msg = 'Fehler beim Speichern in ' . static::getTableName() . ' ' . $primary_string;

		if ($is_unique_exception) {
			$exception = new UniqueException($msg);
		} else {
			$exception = new WriteException($msg);
		}

		$exception->setErrors($errors);

		throw $exception;
	}

	/**
	 * Einen Datensatz in der relevanten Tabelle speichern / updaten
	 *
	 * @param bool $skip_read_after_insert Wenn TRUE, wird das Neu-Einlesen des Objekts nach einem INSERT nicht durchgeführt
	 *
	 * @throws MissingPrimaryKeyException|PDOException|WriteException|PropertyException
	 */
	public function write(bool $skip_read_after_insert = false) {

		$errors = array();
		$mandatory_keys = $this->getMandatoryFields();
		$field_defaults = static::getFieldDefaults();

		foreach ($mandatory_keys as $key) { // Prüfe die Plicht-Felder
			if ($this->internalGet($key, false, true, false) === null && $field_defaults[$key] === null) {
				$errors[$key] = PropertyException::MANDATORY_MISSING;
			}
		}

		if (count($errors)) {
			$this->throwWriteException($errors);
		}

		$db = static::getDbInstance();

		list($primary_tuple, $sql_condition) = $this->getPrimaryTupleAndSqlCondition();

		$primary_key_list = static::getPrimaryKeyList();
		$record_where = false;

		if ($sql_condition) {
			$where = $sql_condition->build();

			$query = 'SELECT `' . implode('`, `', $primary_key_list) . '` FROM ' . $this->tableName . ' ' . self::DEFAULT_ALIAS . ' WHERE ' . $where;
			$db->query($query);

			if ($db->nextRecord()) {
				$record_where = $where;
			}
		}

		if ($record_where) {
			$original_set_string = $this->createWriteSetString(true, array_merge([self::CREATED], $primary_key_list));

			if ($original_set_string == '') {
				return; // Wenn kein UPDATE notwendig ist, tue nichts ...
			}

			$query_prefix = 'UPDATE ';
			$source = $this->tableName . ' ' . self::DEFAULT_ALIAS;

			$set_string = ' SET ' . $this->setAndAddUpdatedFieldToSetString($original_set_string);

			$query_postfix = ' WHERE ' . $record_where;

		} else {
			$table_fields = static::getTableFields();

			if (array_key_exists(self::CREATED, $table_fields)) {
				$this->internalSet(self::CREATED, (new LocalDateTime())->format(LocalDateTime::DATETIME_MYSQL));
			}

			$original_set_string = $this->createWriteSetString();

			$query_prefix = 'INSERT INTO' . ' ';
			$source = $this->tableName;

			if ($original_set_string == '') {
				$set_string = ' () VALUES()';
			} else {
				$set_string = ' SET ' . $original_set_string;
			}

			$query_postfix = '';
		}

		try {
			$db->query($query_prefix . $source . $set_string . $query_postfix);
		} catch (PDOException $ex) {

			if (intval($ex->getCode()) === 23000) {
				static::minimizeAutoincrement();

				$index_name = preg_replace('/.* for key \'(.*)\'$/', '$1', $ex->getMessage());
				$index_name = preg_replace('/^[^.]*\./', '', $index_name);// Neuerdings schreibt MySQL8 den Tabellennamen noch mit Punkt abgetrennt vorne dran

				$index_fields = static::getTableIndexFields($index_name);

				if (is_array($index_fields)) {
					$errors = array();

					foreach ($index_fields as $field) {
						$errors[$field] = 'Dieses Feld verletzt eine Eindeutigkeitsbedingung!';
					}

					$this->throwWriteException($errors, true);

				} else {
					throw $ex;
				}

			} else {
				throw $ex;
			}
		}

		$skip_backup = false;

		if ($record_where === false && $db->affectedRows() === 1) { // Nach einem tatsächlichem INSERT das gg.wärtige Objekt noch etwas "updaten"

			// Das updated-Property, das für den "ON DUPLICATE KEY UPDATE" - Fall gesetzt wurde, wieder unsetten
			if (array_key_exists(self::UPDATED, $table_fields)) {
				$this->internalSet(self::UPDATED, null);
			}

			// Auto-Increment bei IDs berücksichtigen
			if (in_array(self::ID, $primary_key_list) && !$this->internalGet(self::ID)) {
				$this->internalSet(self::ID, $db->lastInsertId());
				$primary_tuple[self::ID] = $this->id;
			}

			if (!$skip_read_after_insert) { // Die Daten des Objekts nochmals einlesen, damit die Default-Werte,
				// die evtl. per Tabellen-Definition gesetzt wurden, ins Objekt übertragen werden
				$this->read($primary_tuple, true);

				$skip_backup = true; // Die initFieldValueBackup-Methode wurde bereits implizit via readSingle -> readMultiple -> initialize -> initFieldValueBackup aufgerufen ...
			}
		}

		if (!$skip_backup) {
			$this->initFieldValueBackup();
		}
	}

	public static function init() {
		self::$cacheFilePath = Env::getCachePath() . 'model_table.infos';
	}
}

ModelBase::init();