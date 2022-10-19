<?php

namespace BirdWorX\ModelDb\Basic;

use BadMethodCallException;

/**
 * Class Globals
 *
 * Diese Klasse kapselt sämtliche Funktionen (und Variablen), die global verfügbar sein sollen.
 *
 * Der Vorteil globale Variable / Funktionen als statische Variable in einer Klasse zu kapseln besteht darin,
 * das einerseits der globale Namespace nicht "polluted" wird und andererseits  - selbst wenn die Klassendatei
 * inerhalb einer Funktion included wurde ... bsp.weise mithilfe eines Autoloaders ... - die (statischen)
 * Klassen-Funktionen / Variable trotzdem global verfügbar sind.
 */
abstract class Utils {

	/**
	 * Erstellt eine tatsächliche Kopie des übergebenen Wertes
	 */
	public static function deepCopy(mixed $value): mixed {

		if (is_object($value)) {
			$cloned_value = clone $value;
		} else {
			if (is_array($value)) {
				$cloned_value = array();
				foreach ($value as $key => $val) {
					$cloned_value[$key] = self::deepCopy($val);
				}
			} else {
				$cloned_value = $value;
			}
		}

		return $cloned_value;
	}

	/**
	 * Konvertiert sämtliche Objekte, die in der übergebenen Variable enthalten sind, zu Arrays und gibt das Resultat zurück.
	 * Die resultierenden (Objekt-)Arrays enthalten sämtliche Member-Variable (public, protected, private) und sind mit dem
	 * Variablen-Namen als Key hinterlegt.
	 *
	 * @param mixed $mixed_var
	 * @param array $ignore_keys Zu ignorierende Keys (werden allerdings nur auf der ersten Rekursionsstufe berücksichtigt)
	 * @param bool $use_to_array Wenn TRUE wird die toArray()-Methode des Objekts aufgerufen - insofern diese existiert. Ein Wert != TRUE, wird nur auf der ersten Rekursionsstufe berücksichtigt.
	 *
	 * @return array
	 */
	public static function object2Array(mixed $mixed_var, array $ignore_keys = array(), bool $use_to_array = true): mixed {

		if (is_object($mixed_var)) {
			if ($use_to_array && method_exists($mixed_var, 'toArray')) {
				return $mixed_var->toArray($ignore_keys);
			}

			$raw = (array)$mixed_var;

			$result = array();
			foreach ($raw as $key => $val) {
				$aux = explode("\0", $key); // to handle private / protected member variable names
				$newkey = $aux[count($aux) - 1];

				if (in_array($newkey, $ignore_keys)) {
					continue;
				}

				$getter = 'get' . ucfirst($newkey);

				if (is_callable(array($mixed_var, $getter))) { // Nach Möglichkeit die Standard getter-Methode des Objekts verwenden
					try {
						$result[$newkey] = Utils::object2Array($mixed_var->$getter());
					} catch (BadMethodCallException) { // Wenn die Methode nicht ohne Angabe von Parametern aufgerufen werden kann
						$result[$newkey] = Utils::object2Array($val);
					}
				} else {
					$result[$newkey] = Utils::object2Array($val);
				}
			}

		} else if (is_array($mixed_var)) {

			$result = array();
			foreach ($mixed_var as $key => $val) {
				$result[$key] = Utils::object2Array($val, $ignore_keys);
			}

		} else {
			$result = $mixed_var;
		}

		return $result;
	}

	/**
	 * Erzeugt aus einen String in camelCase-Notation einen String dessen logische Einheiten durch einen vorgegebenen Separator voneinander getrennt sind.
	 *
	 * @param string $camel_cased_string
	 * @param string $separator
	 *
	 * @return string
	 */
	public static function camelCaseToSeparator(string $camel_cased_string, string $separator = '-'): string {
		return strtolower(preg_replace(
			['/([A-Z]+)/', '/' . $separator . '([A-Z]+)([A-Z][a-z])/'],
			[$separator . '$1', $separator . '$1' . $separator . '$2'],
			lcfirst($camel_cased_string)));
	}

	/**
	 * Erzeugt aus einen String in camelCase-Notation einen String in Underscore-Notation
	 *
	 * @param string $camel_cased_string
	 *
	 * @return string
	 */
	public static function camelCaseToUnderscore(string $camel_cased_string): string {
		return self::camelCaseToSeparator($camel_cased_string, '_');
	}

	/**
	 * Erzeugt aus einen String, dessen logische Einheiten durch einen vorgegebenen Separator voneinander getrennt sind, einen String in camelCase-Notation
	 *
	 * @param string $separator_string
	 * @param string $separator
	 *
	 * @return string
	 */
	public static function separatorToCamelCase(string $separator_string, string $separator = '_'): string {
		return lcfirst(str_replace($separator, '', ucwords($separator_string, $separator)));
	}

	/**
	 * Erzeugt aus einen String in Underscore-Notation einen String in camelCase-Notation
	 *
	 * @param string $underscore_string
	 *
	 * @return string
	 */
	public static function underscoreToCamelCase(string $underscore_string): string {
		return self::separatorToCamelCase($underscore_string);
	}
}