<?php

namespace BirdWorX\ModelDb\Basic;

use BirdWorX\Env;

class PathUrl {

	/**
	 * Pfad zum Upload-Verzeichnis, relativ zu @see Env::getBaseUrl(), Env::getPublicPath()
	 */
	const UPLOAD_DIR = 'upload';

	/**
	 * Der (Datei-)Pfad relativ zu @see Env::getBaseUrl(), Env::getPublicPath()
	 *
	 * @var string
	 */
	public string $relativePath;

	/**
	 * Der absolute Dateipfad
	 *
	 * @var string
	 */
	public string $absolutePath;

	/**
	 * Die zum Dateipfad korrespondierende absolute URL
	 *
	 * @var string
	 */
	public string $absoluteUrl;

	/**
	 * PathUrl Konstruktor
	 *
	 * @param $path_relative_to_public_dir
	 * @param bool $is_absolute_path Wenn ein absoluter Pfad übergeben wurde, auf TRUE setzen
	 */
	public function __construct($path_relative_to_public_dir, bool $is_absolute_path = false) {

		if ($is_absolute_path) {
			$this->relativePath = preg_replace('#^' . Env::getPublicPath() . '#', '', realpath($path_relative_to_public_dir));
		} else {
			$this->relativePath = $path_relative_to_public_dir;
		}

		$this->relativePath = trim($this->relativePath, '/') . '/';

		$this->absolutePath = realpath(Env::getPublicPath() . $this->relativePath) . '/';

		if (!file_exists($this->absolutePath)) {
			mkdir($this->absolutePath, 0775, true); // Mode-Angabe bewirkt nichts, wahrscheinlich wegen umask ...
			chmod($this->absolutePath, 0775); // Der Mode bei mkdir wird anscheinend ignoriert, deshalb nochmal separat
		}

		$this->absoluteUrl = $this->determineAbsoluteUrl();
	}

	/**
	 * Ermittelt die absolute URL des Objekts und gibt diese zurück
	 *
	 * @return string
	 */
	private function determineAbsoluteUrl(): string {

		if (preg_match('#^' . Env::getPublicPath(), $this->absoluteUrl) === false) {
			die('path is not underneath public dir - special handling needed!');
		}

		// Default-Handling für absolute-URLs
		return Env::getBaseUrl() . $this->relativePath;
	}

	private function uploadErrorString(string $name, int $error): string {
		return 'Der Upload von "' . $name . '"  ist fehlgeschlagen' . ($error ? ' -- (Error-Code: ' . $error . ')' : '!');
	}

	/**
	 * Schiebt die im $_FILES-Array befindlichen Uploads in das übergebene Zielverzeichnis und benennt sie entsprechend der
	 * optionalen Wunschvorgaben.
	 *
	 * @param string[] $desired_names Die zu verwendeten Dateinamen ohne Endung. Für jede Datei, die erfolgreich ins Zielverzeichnis verschoben werden konnte, wird der entsprechende Key-Value durch den vollständigen Dateinamen - der aus dem Value und der Endung der usrprünglichen Datei resultiert - ersetzt.
	 * @param string|null $naming_prefix
	 * @param string[] $extensions Erlaubte bzw. nicht-erlaubte Datei-Endungen
	 * @param bool $extensions_lists_allowed Wenn FALSE listet $extensions alle nicht-erlaubten Dateiendungen, bzw. bei TRUE alle erlaubten
	 *
	 * @return bool FALSE, wenn kein Upload vorlag; TRUE, wenn ALLE Uploads erfolgreich waren - andernfalls (also wenn wenigstens ein Upload fehlgeschlagen ist, wird eine Exception geworfen)
	 */
	public function handleUploadFiles(array &$desired_names = array(), string $naming_prefix = null, array $extensions = array('php'), bool $extensions_lists_allowed = false): bool {

		$no_files = true;

		$success = (count($_FILES) > 0);
		$errors = '';

		foreach ($_FILES as $name => $file) {

			if (($error = $file['error']) === UPLOAD_ERR_OK) {
				$no_files = false;

				$path_info = pathinfo($file['name']);
				$extension = strtolower($path_info['extension']);

				if (($extensions_lists_allowed && in_array($extension, $extensions))
					|| (!$extensions_lists_allowed && !in_array($extension, $extensions))) {

					$filename = $desired_names[$name] ?? $path_info['filename']; // Dateinamen-Bereinigung bitte nicht innerhalb dieser Funktion durchführen, sonst gibts Probleme mit clientseitigen JS-Libraries (z.B. plUpload), die davon ausgehen, das die Datei genauso heisst, wie die ursprünglich hochgeladene ...

					if ($naming_prefix) {
						$filename = $naming_prefix . $filename;
					}

					// Evtl. bereits existierende Endung entfernen
					$filename = preg_replace('/\.' . $extension . '$/i', '', $filename);

					$filename_with_extension = $filename . '.' . $extension;
					$target_path = $this->absolutePath . $filename_with_extension;

					if (!isset($desired_names[$name])) { // Wenn ein Name nicht explizit vorgegeben wurde,
						// prüfe, ob eine Datei gleichen Namens bereits existiert und hänge gegebenenfalls
						// ein Zahlen-Inkrement an den Dateinamen an.
						$cnt = 1;
						while (file_exists($target_path)) {
							$filename_with_extension = $filename . '_' . $cnt . '.' . $extension;
							$target_path = $this->absolutePath . $filename_with_extension;
							$cnt++;
						}
					}

					if (move_uploaded_file($file['tmp_name'], $target_path)) {
						$desired_names[$name] = $filename_with_extension;
						chmod($target_path, 0664);
						continue;
					} else {
						$error = $this->uploadErrorString($file['name'], $error);
					}
				}

			} elseif ($error === UPLOAD_ERR_NO_FILE) {
				$error = '';
			} else { // TODO: Unterscheidung der diversen UPLOAD_ERR_* Möglichkeiten
				$no_files = false;

				$error = $this->uploadErrorString($file['name'], $error);
			}

			$errors .= $error . "\n";
			$success = false;
		}

		if ($errors !== '') {
			die($errors);
		}

		if ($no_files) {
			return false;
		} else {
			return $success;
		}
	}
}