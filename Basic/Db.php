<?php

namespace BirdWorX\ModelDb\Basic;

use BirdWorX\ModelDb\Exceptions\GeneralException;
use PDO;
use PDOException;
use PDOStatement;

defined('DB_DSN') || die('Please define DB_DSN before using ' . __FILE__);
@define('DB_DSN', ''); // The IDE should still be able to resolve the variable

class Db {

	/**
	 * Der Name der Datenbank
	 *
	 * @var string
	 */
	private static string $dbName;

	/**
	 * Statische Instanz, die für atomare SQL-Abfragen (ohne weitere Rückfragen für z.B. Escaping, INSERTs oder UPDATEs) verwendet werden kann
	 */
	private static ?Db $atomicInstance = null;

	/**
	 * Sollen Prepares für SQL-Abfragen emuliert werden, oder vom DBMS durchgeführt werden?
	 *
	 * @var bool
	 */
	const EMULATE_PREPARES = true;

	/**
	 * Mögliche Table-Locking Typen
	 */
	const TABLE_LOCKING_TYPES = array('WRITE');

	/**
	 * Datenbank-Verbindung
	 *
	 * @var false|PDO
	 */
	private PDO|false $connection;

	/**
	 * Resultat der zuletzt durchgeführten Query
	 *
	 * @var false|PDOStatement
	 */
	private PDOStatement|false $pdoStatement = false;

	/**
	 * Klassenweite Initialisierungsroutine
	 */
	public static function init(): void {
		Db::$atomicInstance = new Db();
		Db::$dbName = Db::queryValue('SELECT DATABASE()');
	}

	public function __construct(bool $use_utf8mb4 = true, bool $use_strict_sql = false) { // TODO: Defaultmässig $use_strict_sql = true verwenden

		$init_command = '';

		// TODO: Generelle Umstellung auf utf8mb4 und sämtliche Tabellen konvertieren: "ALTER TABLE tabelle CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

		if ($use_utf8mb4) { // Der Fall wird wohl nicht benötigt, weil im Aufruf des PDO-Konstruktors bereits das Default-Charset angegeben wird, d.h. mit dem init_command kann man den Default wahrscheinlich wieder ändern ...
			$init_command .= "names 'utf8mb4', ";
		} else {
			$init_command .= "names 'utf8', ";
		}

		if (!$use_strict_sql) {
			$init_command .= "sql_mode = '', ";
		}

		$init_command = rtrim($init_command, ', ');

		if ($init_command != '') {
			$options = array(
				PDO::MYSQL_ATTR_INIT_COMMAND => 'SET ' . $init_command
			);
		} else {
			$options = null;
		}

		try {
			$this->connection = new PDO(DB_DSN, null, null, $options);

			$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, self::EMULATE_PREPARES);
			$this->connection->setAttribute(PDO::ATTR_PERSISTENT, false);
			$this->connection->setAttribute(PDO::MYSQL_ATTR_FOUND_ROWS, true); // damit rowCount auch bei SELECTs korrekt funktioniert

		} catch (PDOException $ex) {
			$code = intval($ex->getCode());

			if ($code === 1040 /* Too many connections*/ || $code === 2002 /* Connection timed out */) {
				throw $ex;
			}
		}
	}

	public static function getDbName(): string {
		return Db::$dbName;
	}

	/**
	 * Fehler-Code der letzten Datenbank-Operation zurückgeben
	 *
	 * @return mixed
	 */
	public function errorCode(): mixed {
		return $this->connection->errorCode();
	}

	/**
	 * Fehler-Informationen zur letzten Datenbank-Operation zurückgeben
	 *
	 * @return array
	 */
	public function errorInfo(): array {
		return $this->connection->errorInfo();
	}

	/**
	 * Lockt die angegebenen Tabellen mit dem jeweils spezifizierten Locking-Typ.
	 *
	 * @param array $table_locktype
	 *
	 * @throws GeneralException|PDOException
	 */
	public function lockTables(array $table_locktype = array()): void {

		$lock_string = '';

		foreach ($table_locktype as $table => $locktype) {
			if (!in_array($locktype, self::TABLE_LOCKING_TYPES)) {
				throw new GeneralException('Unbekannter Locking-Typ!');
			}

			$lock_string .= $table . ' ' . $locktype . ', ';
		}

		$lock_string = rtrim($lock_string, ', ');

		if ($lock_string != '') {
			$this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); // Wichtig: Anderfalls ist TABLE-Locking nicht möglich!
			$this->query('LOCK TABLES ' . $lock_string);
		}
	}

	/**
	 * Löst sämtliche Tabellen-Locks, die von dieser Instanz vorgenommen wurden
	 */
	public function unlockTables(): void {

		$this->query('UNLOCK TABLES');
		$this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, self::EMULATE_PREPARES); // Zurück zum ursprünglichen Emulations-Setting
	}

	public static function tableExists(string $table_name) {
		return self::$atomicInstance->queryValue("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME = '". $table_name . "'") === $table_name;
	}

	/**
	 * Datenbankabfrage durchführen
	 *
	 * @param string $query
	 *
	 * @throws PDOException
	 */
	public function query(string $query): void {
		$this->pdoStatement = $this->connection->query($query);
	}

	/**
	 * Ermöglicht die Absendung einer einfachen SQL-Anweisung, die keine weiteren Rückfragen erfordert (z.B.: INSERT oder UPDATE)
	 *
	 * @param $query
	 *
	 * @throws PDOException
	 */
	public static function execute($query): void {
		Db::$atomicInstance->query($query);
	}

	/**
	 * Ermittelt einen einzelnen Wert eines Datensatzes
	 *
	 * @param string $query
	 *
	 * @return string|null
	 * @throws PDOException
	 */
	public static function queryValue(string $query): ?string {
		if (stripos($query, ' LIMIT ') === false) {
			$query .= ' LIMIT 1';
		}

		Db::$atomicInstance->query($query);
		$record = Db::$atomicInstance->nextRecord();

		if ($record === false) {
			return null;
		}

		return array_values($record)[0];
	}

	/**
	 * Den nächsten Datensatz ermitteln
	 */
	public function nextRecord(): array|false {

		if ($this->pdoStatement) {
			$result = $this->pdoStatement->fetch(PDO::FETCH_ASSOC);

			if (is_array($result)) {
				return $result;
			} else {
				return false;
			}

		} else {
			return false;
		}
	}

	/**
	 * Gibt die Anzahl der betroffenen Datensätze zurück
	 *
	 * @return int
	 */
	public function affectedRows(): int {
		return $this->pdoStatement->rowCount();
	}

	/**
	 * Gibt die zuletzt vergebene ID zurück
	 *
	 * @return int
	 */
	public function lastInsertId(): int {
		return intval($this->connection->lastInsertId());
	}

	/**
	 * Maskiert diverse Zeichen, die in einer SQL-Anfrage zu Problemen bzw. SQL-Injections führen würden
	 *
	 * @param string $str
	 * @return string
	 */
	public static function escapeString(string $str): string {
		$escaped_and_quoted = Db::$atomicInstance->connection->quote($str);
		return substr($escaped_and_quoted, 1, -1);
	}
}

Db::init();
