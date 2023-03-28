<?php

namespace BirdWorX\ModelDb\Basic;

use BirdWorX\Env;
use BirdWorX\Utils;
use Exception;

/**
 * @property string fileName
 */
class Migration extends ModelBase {
	const FILE_NAME = 'fileName';

	const MIGRATIONS_DIR = Env::PROJECT_PATH . 'src/migrations/';

	/**
	 * Ermittelt die SQL-Dateien innerhalb des Migration-Verzeichnisses, die noch nicht eingespielt wurden.
	 *
	 * @return array
	 */
	public static function filesToBeImported(): array {

		$files = array();

		foreach (Utils::getDirContents(Migration::MIGRATIONS_DIR) as $file) {

			if (!preg_match('/.*\.sql$/', $file)) {
				continue;
			}

			if (self::readSingle([self::FILE_NAME => basename($file)]) === null) {
				$files[] = $file;
			}
		}

		return $files;
	}

	private static function createMigrationTableIfNotExists() {

		static $migration_table_exists = null;

		if ($migration_table_exists === null) {
			$migration_table_exists = Db::tableExists(self::getTableName());

			if (!$migration_table_exists) {

				Db::execute('
					CREATE TABLE migration (
						created DATETIME NOT NULL,
    					fileName VARCHAR(63) NOT NULL PRIMARY KEY
					) ENGINE = MyISAM CHARSET = utf8mb3;

					CREATE INDEX imported ON migration (created);'
				);

				if (file_exists(ModelBase::$cacheFilePath)) {
					unlink(ModelBase::$cacheFilePath);
				}
			}
		}
	}

	/**
	 * Spielt sämtliche Migrationen ein, die noch nicht eingespielt wurden. Im übergebenen Array werden sämtliche Queries eingetragen, die ausgeführt wurden. Sollte eine Query fehlschlagen, wird der Import abgebrochen - anschließend werden die fehlgeschlagene Query, der zugehörige Fehler und der Dateiname zurückgegeben.
	 */
	public static function importFiles(array &$executed_queries): ?string {
		self::createMigrationTableIfNotExists();

		$files = Migration::filesToBeImported();

		$executed_queries = array();

		foreach ($files as $file) {
			$sql = file_get_contents($file);
			$file = basename($file);

			$executed_queries[$file] = array();

			// Sicherstellen das unterschiedliche Statements gesplittet werden (aber: eingebettete Semikolons nicht berücksichtigen!)
			$sql = preg_replace("/;[ \t]*$/", "", $sql);
			$sql = preg_replace("/;[ \t]*[\r\n]+/", ";\n", $sql);

			$queries = explode(";\n", $sql);

			foreach ($queries as $query) {
				$queries2 = explode("\n", $query);
				$query = '';

				foreach ($queries2 as $splitquery) {

					// Leere Zeilen + Kommentare überspringen
					if (preg_match('/^[ \t\r\n]*$/', $splitquery) || preg_match('/^[ \t\r\n]*--/', $splitquery)) {
						continue;
					}

					$query .= $splitquery;
				}

				if ($query == '') {
					continue;
				}

				$executed_queries[$file][] = $query;

				try {
					Db::execute($query);
				} catch (Exception $e) {
					return $file . ": " . $query . "\n\n" . $e->getMessage();
				}
			}

			// Import vermerken
			$log = new Migration();
			$log->fileName = $file;
			$log->write();
		}

		if (count($executed_queries)) {
			unlink(ModelBase::$cacheFilePath);
		}

		return null;
	}
}