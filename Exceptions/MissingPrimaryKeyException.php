<?php

namespace BirdWorX\ModelDb\Exceptions;

use Throwable;

class MissingPrimaryKeyException extends GeneralException {
	public function __construct($message = "", $code = 0, Throwable $previous = null) {
		if ($message === '') {
			$message =  'Fehlende Primärschlüssel-Werte!';
		}
		parent::__construct($message, $code, $previous);
	}
}