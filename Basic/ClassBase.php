<?php

namespace BirdWorX\ModelDb\Basic;

use ReflectionClass;
use ReflectionException;

class ClassBase {

	public static function className(): string {
		$pos = strrpos(static::class, '\\');

		if ($pos !== false) {
			return substr(static::class, $pos + 1);
		} else {
			return static::class;
		}
	}

	public static function classSpace(): string {
		$pos = strrpos(static::class, '\\');

		if ($pos !== false) {
			return substr(static::class, 0, $pos);
		} else {
			return '';
		}
	}

	public static function classPostfix(): string {
		$class_name = static::className();
		$class_name_without_last_lower = rtrim($class_name, 'abcdefghijklmnopqrstuvwxyz123456789');

		return substr_replace($class_name, '', 0, strlen($class_name_without_last_lower) - 1);
	}

	public static function classPrefix(): string {
		$class_name = static::className();
		$postfix_len = strlen(static::classPostfix());

		return substr_replace($class_name, '', strlen($class_name) - $postfix_len, $postfix_len);
	}

	/**
	 * Gibt zurück, ob eine der Elternklassen der aufrufenden Klasse, die spezifizierte Methode der Basis-Klasse überschreibt.
	 *
	 * @param string $method_name
	 * @param string $base_class
	 *
	 * @return bool
	 */
	public static function overwritesMethod(string $method_name, string $base_class): bool {
		$current = new ReflectionClass(static::class);

		try {
			$parent = new ReflectionClass($base_class);
			$parent_method = $parent->getMethod($method_name);

			$parent_method_name = $parent_method->getDeclaringClass()->getName();
			$declaring_class_name = $current->getMethod($method_name)->getDeclaringClass()->getName();

			return ($declaring_class_name !== $parent_method_name);
		} catch (ReflectionException) {
			return false;
		}
	}
}