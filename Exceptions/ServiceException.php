<?php

namespace BirdWorX\ModelDb\Exceptions;

class ServiceException extends GeneralException {

	private array $errors = array();

	public function getErrors(): array {
		return $this->errors;
	}

	public function setErrors(array $errors) {
		$this->errors = $errors;
	}
}