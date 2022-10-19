<?php

namespace BirdWorX\ModelDb\Exceptions;

use Exception;

class GeneralException extends Exception {

	/**
	 * Soll die Exception nicht geloggt werden?
	 *
	 * @var bool
	 */
	public bool $skipLogging = false;

	public function setCode($code) {
		$this->code = $code;
	}

	public function setMessage(string $msg) {
		$this->message = $msg;
	}

	public function appendMessage($msg) {
		if ($this->message != '') {
			$this->message .= ' | ';
		}
		$this->message .= $msg;
	}

	/**
	 * Ausgabefunktion für die Exception
	 *
	 * @return string
	 */
	public function __toString() {
		return __CLASS__ . ' [' . $this->code . ']: | ' . $this->message . ' | ';
	}
}