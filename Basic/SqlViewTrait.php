<?php

namespace BirdWorX\ModelDb\Basic;

/**
 * Ein ViewTrait sollte innerhalb von ModelBase-Klassen Verwendung finden, die nicht mit einer Tabelle, sondern mit einer SQL-View in Verbindung stehen.
 *
 * Auf SQL-Views sollte normalerweise nicht schreibend zugegriffen werden, weshalb dieser Trait den entsprechenden ModelBase-Code durch Dummy-Funktionen ersetzt.
 */
trait SqlViewTrait {

	public function write($skip_read_after_insert = false) { }

	public static function updateMultiple($key_value_updates, $sql_condition = null, $ignore_errors = false) { }

	public function delete() { }

	public static function deleteMultiple($sql_condition = null) { }
}