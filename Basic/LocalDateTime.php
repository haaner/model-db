<?php

namespace BirdWorX\ModelDb\Basic;

use BirdWorX\ModelDb\Exceptions\DateTimeException;
use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;

/**
 * Class LocalDateTime
 *
 */
class LocalDateTime extends DateTime {

	const DATE_MYSQL = 'Y-m-d';
	const DATE_GERMAN = 'd.m.Y';

	const DATETIME_MYSQL = 'Y-m-d H:i:s';
	const DATETIME_GERMAN = 'd.m.Y H:i:s';

	const DATETIME_ATOM = 'Y-m-d\TH:i:sP';
	const DATETIME_EUROPACE = 'Y-m-d\TH:i:s.000P';

	const TIME_GERMAN = 'H:i';

	/**
	 * Liste mit Wochentagen
	 */
	public static array $WEEKDAY = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');

	/**
	 * Liste mit Monaten
	 */
	public static array $MONTH = array('Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');

	/**
	 * Das aktuelle Datum (Stunden, Minuten und Sekunden == 0)
	 */
	public static LocalDateTime $currentDate;

	/**
	 * Feiertags-Cache
	 */
	private static array $feastDays = array();

	/**
	 * Sekunden-Intervall
	 */
	public static DateInterval $oneSecond;

	/**
	 * Minuten-Intervall
	 */
	public static DateInterval $oneMinute;

	/**
	 * Stunden-Intervall
	 */
	public static DateInterval $oneHour;

	/**
	 * Tages-Intervall
	 */
	public static DateInterval $oneDay;

	/**
	 * Wochen-Intervall
	 */
	public static DateInterval $oneWeek;

	/**
	 * Monats-Intervall
	 */
	public static DateInterval $oneMonth;

	/**
	 * Bundeslandspezifische Feiertagsregelungen
	 */
	const FEASTDAY_MAPPING = array(
		'BW' => 'Baden-Württemberg',
		'BYA' => 'Bayern (Augsburg)',
		'BYK' => 'Bayern (katholisch)',
		'BYE' => 'Bayern (evangelisch)',
		'BE' => 'Berlin',
		'BB' => 'Brandenburg',
		'HB' => 'Bremen',
		'HH' => 'Hamburg',
		'HE' => 'Hessen',
		'MV' => 'Mecklenburg-Vorpommern',
		'NI' => 'Niedersachsen',
		'NW' => 'Nordrhein-Westfalen',
		'RP' => 'Rheinland-Pfalz',
		'SL' => 'Saarland',
		'SN' => 'Sachsen',
		'ST' => 'Sachsen-Anhalt',
		'SH' => 'Schleswig-Holstein',
		'TH' => 'Thüringen'
	);

	/**
	 * Erzeugt ein Date(-Time) Objekt in der lokalen Zeitzone ('Europe/Berlin')
	 *
	 * @param string $date
	 * @param string|null $format_string
	 * @param string $modify_string
	 *
	 * @throws DateTimeException
	 */
	public function __construct(string $date = 'now', $format_string = null, string $modify_string = '') {

		$zone = new DateTimeZone("Europe/Berlin");

		if ($format_string === null) {
			try {
				parent::__construct($date, $zone);
			} catch (Exception $ex) {
				throw new DateTimeException($ex);
			}

		} else {
			$date = DateTime::createFromFormat($format_string, $date, $zone);

			if ($date === false) {
				throw new DateTimeException('Datums-Objekt kann nicht angelegt werden!');
			}

			/** @noinspection PhpUnhandledExceptionInspection */
			parent::__construct();
			$this->setTimestamp($date->getTimestamp());
		}

		// Im Falle das die $date - Variable selbst eine Zeitzone beinhaltet, wurde die $zone - Zeitzone ignoriert,
		// weshalb diese nun nochmals abschliessend festgelegt wird.
		$this->setTimezone($zone);

		if ($modify_string != '') {
			$this->modify($modify_string);
		}
	}

	public static function createByTimestamp(int $timestamp): LocalDateTime {
		$dt = new LocalDateTime();
		$dt->setTimestamp($timestamp);

		return $dt;
	}

	/**
	 * @return int
	 */
	public function hours() {
		return intval($this->format('H'));
	}

	/**
	 * @return int
	 */
	public function minutes() {
		return intval($this->format('i'));
	}

	/**
	 * @return int
	 */
	public function seconds() {
		return intval($this->format('s'));
	}

	/**
	 * @return int
	 */
	public function day() {
		return intval($this->format('d'));
	}

	/**
	 * @param int $day
	 */
	public function setDay(int $day) {
		$this->setDate($this->year(), $this->month(), $day);
	}

	public function week(): int {
		return intval($this->format('W'));
	}

	/**
	 * @return int
	 */
	public function month() {
		return intval($this->format('m'));
	}

	public function year() {
		return intval($this->format('Y'));
	}

	/**
	 * Das Datum im dt. Format ausgeben
	 *
	 * @return string
	 */
	public function getGermanDateString() {
		return $this->format(self::DATE_GERMAN);
	}

	public function getGermanTimeString() {
		return $this->format(self::TIME_GERMAN);
	}

	/**
	 * Datum und Uhrzeit im dt. Format ausgeben
	 *
	 * @return string
	 */
	public function getGermanDateTimeString(): string {
		return $this->format(self::DATETIME_GERMAN);
	}

	/**
	 * Das intern verwendete DateTime-Objekt zurückgeben
	 */
	public function getDateTime(): DateTime {

		/** @noinspection PhpUnhandledExceptionInspection */
		$dt = new DateTime('now', $this->getTimezone());
		$dt->setTimestamp($this->getTimestamp());

		return $dt;
	}

	/**
	 * Das Datum im MySQL-Format ausgeben
	 *
	 * @return string
	 */
	public function getMysqlDate() {
		return $this->format(self::DATE_MYSQL);
	}

	/**
	 * Gibt sämtliche Feiertage des gewünschten Jahres im Rahmen des gewählten Mappings zurück.
	 *
	 * Mögliche Werte für $feastday_mapping_key: @param int $year Gewünschtes Jahr im Format 'JJJJ'
	 * @param string $feastday_mapping_key
	 *
	 * @return array Die Keys entsprechen dem jeweiligem Datum im Format 'd.m.Y' - die Werte enthalten den Namen des jeweiligen Feiertags
	 * @see LocalDateTime::FEASTDAY_MAPPING (die Keys)
	 *
	 */
	public static function feastDays(int $year, string $feastday_mapping_key) {

		$feastday_mapping_key = strtoupper($feastday_mapping_key);

		if (self::$feastDays[$year] && self::$feastDays[$year][$feastday_mapping_key]) {
			return self::$feastDays[$year][$feastday_mapping_key];
		}

		$augsburg = false;
		$catholic = false;

		$key = $feastday_mapping_key;

		if (str_starts_with($feastday_mapping_key, 'BY')) {
			if ($feastday_mapping_key == 'BYA') {
				$augsburg = true;
				$catholic = true;
			} elseif ($feastday_mapping_key == 'BYK') {
				$catholic = true;
			}

			$feastday_mapping_key = 'BY';
		}

		$feast_days = array();

		$insert = function ($day, $month, $name) use ($year, &$feast_days) {
			$feast_days[str_pad($day, 2, '0', STR_PAD_LEFT) . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.' . $year] = $name;
		};

		$insert(1, 1, 'Neujahr');

		if ($feastday_mapping_key == 'BW' || $feastday_mapping_key == 'BY' || $feastday_mapping_key == 'ST') {
			$insert(6, 1, 'Heilige Drei Könige');
		}

		if ($feastday_mapping_key == 'BE') {
			$insert(8, 3, 'Internationaler Frauentag');
		}

		$easter_dt = new LocalDateTime();
		$easter_dt->setTimestamp(easter_date($year));

		$days1 = self::$oneDay;
		$days2 = DateInterval::createFromDateString('2 days');
		$days39 = DateInterval::createFromDateString('39 days');
		$days49 = DateInterval::createFromDateString('49 days');
		$days50 = DateInterval::createFromDateString('50 days');

		$dt = clone $easter_dt;
		$dt->sub($days2);

		$insert($dt->day(), $dt->month(), 'Karfreitag');
		$insert($easter_dt->day(), $easter_dt->month(), 'Ostersonntag');

		$dt = clone $easter_dt;
		$dt->add($days1);

		$insert($dt->day(), $dt->month(), 'Ostermontag');
		$insert(1, 5, 'Tag der Arbeit');

		$dt = clone $easter_dt;
		$dt->add($days39);

		$insert($dt->day(), $dt->month(), 'Christi Himmelfahrt');

		$dt = clone $easter_dt;
		$dt->add($days49);

		$insert($dt->day(), $dt->month(), 'Pfingstsonntag');

		$dt = clone $easter_dt;
		$dt->add($days50);

		$insert($dt->day(), $dt->month(), 'Pfingstmonntag');

		if ($feastday_mapping_key == 'BW' || $feastday_mapping_key == 'BY' || $feastday_mapping_key == 'HE' || $feastday_mapping_key == 'NW' || $feastday_mapping_key == 'RP' || $feastday_mapping_key == 'SL' || $feastday_mapping_key == 'SN' || $feastday_mapping_key == 'TH') {

			$days60 = DateInterval::createFromDateString('60 days');

			$dt = clone $easter_dt;
			$dt->add($days60);

			$insert($dt->day(), $dt->month(), 'Fronleichnam');
		}

		if ($feastday_mapping_key == 'BY' && $augsburg) {
			$insert(8, 8, 'Augsburger Hohes Friedensfest');
		}

		if ($feastday_mapping_key == 'SL' || ($feastday_mapping_key == 'BY' && $catholic)) {
			$insert(15, 8, 'Mariä Himmelfahrt');
		}

		$insert(3, 10, 'Tag der Deutschen Einheit');

		if ($feastday_mapping_key == 'BB' || $feastday_mapping_key == 'MV' || $feastday_mapping_key == 'SN' || $feastday_mapping_key == 'ST' || $feastday_mapping_key == 'TH') {
			$insert(31, 10, 'Reformationstag');
		}

		if ($feastday_mapping_key == 'BW' || $feastday_mapping_key == 'BY' || $feastday_mapping_key == 'NW' || $feastday_mapping_key == 'RP' || $feastday_mapping_key == 'SL') {
			$insert(1, 11, 'Allerheiligen');
		}

		if ($feastday_mapping_key == 'SN') {
			$ts = strtotime("last wednesday", mktime(0, 0, 0, 11, 23, $year));
			$dt->setTimestamp($ts);

			$insert($dt->day(), $dt->month(), 'Buß- und Bettag');
		}

		$insert(25, 12, '1. Weihnachtsfeiertag');
		$insert(26, 12, '2. Weihnachtsfeiertag');

		if (!self::$feastDays[$year]) {
			self::$feastDays[$year] = array();
		}

		self::$feastDays[$year][$key] = $feast_days;

		return $feast_days;
	}

	/**
	 * Initialisiert die statischen Klassenvariable
	 */
	public static function init() {
		self::$oneSecond = DateInterval::createFromDateString('1 second');
		self::$oneMinute = DateInterval::createFromDateString('1 minute');
		self::$oneHour = DateInterval::createFromDateString('1 hour');
		self::$oneDay = DateInterval::createFromDateString('1 day');
		self::$oneWeek = DateInterval::createFromDateString('7 days');
		self::$oneMonth = DateInterval::createFromDateString('1 month');

		self::$currentDate = new self();
		self::$currentDate->setTime(0, 0);
	}

	/**
	 * Prüft, ob es sich um ein Feiertags-Datum handelt.
	 *
	 * Mögliche Werte für $feastday_mapping_key: @param string $feastday_mapping_key
	 *
	 * @return false|string
	 * @see LocalDateTime::FEASTDAY_MAPPING (die Keys)
	 *
	 */
	public function isFeastDay(string $feastday_mapping_key) {
		$feast_day = LocalDateTime::feastDays($this->year(), $feastday_mapping_key)[$this->format(self::DATE_GERMAN)];
		return $feast_day ?: false;
	}

	/**
	 * Prüft, ob es sich um ein Wochenend-Datum handelt.
	 *
	 * @return bool
	 */
	public function isWeekend() {
		$weekday = $this->format('w');
		return ($weekday == 0 || $weekday == 6);
	}

	public function toArray(): array {
		return array(
			'date' => $this->getGermanDateString(),
			'time' => $this->getGermanTimeString()
		);
	}

	/**
	 * Ermittle ob ein spezifisches Datum auf einen Feiertag bzw. Wochenende fällt
	 *
	 * Mögliche Werte: @param string $datum Datum im Format 'Y-m-d'
	 * @param string $feastday_mapping_key
	 *
	 * @return false|string 'Wochenende' bzw. Name des Feiertags
	 * @see LocalDateTime::FEASTDAY_MAPPING (die Keys)
	 *
	 */
	public static function feastOrWeekendDay(string $datum, string $feastday_mapping_key) {

		try {
			$date = new LocalDateTime($datum, self::DATE_MYSQL);

			if (($feast_day = $date->isFeastDay($feastday_mapping_key))) {
				return $feast_day;
			}

			return $date->isWeekend() ? "Wochenende" : false;

		} catch (DateTimeException) {
			return false;
		}
	}

	/**
	 * Emittelt die Anzahl an Feier- und Wochendtagen die innerhalb des angegebenen Zeitraums liegen
	 *
	 * @param DateTime $start_dt
	 * @param DateTime $end_dt
	 * @param string $feastday_mapping_key
	 *
	 * @return int
	 */
	public static function feastOrWeekendDayAmount(DateTime $start_dt, DateTime $end_dt, string $feastday_mapping_key) {
		$amount = 0;

		$start_dt = clone $start_dt;

		if ($start_dt <= $end_dt) {
			$end_date_mysql = $end_dt->format(LocalDateTime::DATE_MYSQL);

			while (($start_date_mysql = $start_dt->format(LocalDateTime::DATE_MYSQL)) <= $end_date_mysql) {
				if (LocalDateTime::feastOrWeekendDay($start_date_mysql, $feastday_mapping_key)) {
					$amount++;
				}

				$start_dt->add(self::$oneDay);
			}
		}

		return $amount;
	}

	/**
	 * Korrigiert nicht-vierstellige Jahres-Angaben
	 *
	 * @param string $year
	 *
	 * @return string
	 */
	public static function fixYear(string $year) {
		$current_year = self::$currentDate->year();

		if (($len = strlen($year)) < 4) {
			$year = substr($current_year, 0, 4 - $len) . $year;
		}

		return $year;
	}

	/**
	 * Ermittelt den Montag der Woche innerhalb derer das übergebene Datum zu finden ist.
	 *
	 * @param DateTime|null $dt
	 *
	 * @return LocalDateTime
	 */
	public static function getMondayInSameWeek(?DateTime $dt = null) {
		return LocalDateTime::getWeekdayInSameWeek(1, $dt ?: self::$currentDate);
	}

	/**
	 * Ermittle den gewünschten Wochtentag, der sich in derselben Woche befindet, wie der übergebene Zeitpunkt.
	 *
	 * @param int $weekday_number
	 * @param DateTime $dt
	 *
	 * @return LocalDateTime
	 */
	public static function getWeekdayInSameWeek(int $weekday_number, DateTime $dt) {

		$outdate = new LocalDateTime();
		$outdate->setTimestamp($dt->getTimestamp());
		$day = $dt->format("w"); // Wochentag ermitteln (Sonntag == 0)

		if ($day) {
			$outdate->sub(DateInterval::createFromDateString($day . ' days')); // Den Wochentag abziehen => man landet beim Sonntag
		} else {
			$outdate->sub(LocalDateTime::$oneWeek);
		}

		$outdate->add(DateInterval::createFromDateString($weekday_number . ' day'));

		return $outdate;
	}

	/**
	 * Ermittelt die nächstgrößere bzw. -kleinere Uhrzeit im Vergleich zur übergebenen DateTime,
	 * deren Minutenanteil sich exakt durch das vorgegebene Minuten-Intervall teilen lässt.
	 *
	 * @param DateTime $dt
	 * @param int $minute_interval
	 * @param bool $round_up Wenn FALSE wird die nächstkleinere Uhrzeit ermittelt
	 *
	 * @return DateTime
	 */
	public static function roundToMinuteInterval(DateTime $dt, int $minute_interval = 5, bool $round_up = true) {

		$new_dt = clone $dt;
		$secs_interval = $minute_interval * 60;

		$ts = $new_dt->getTimestamp();
		$ts = ($ts / $secs_interval);

		if ($round_up) {
			$ts = ceil($ts);
		} else {
			$ts = floor($ts);
		}

		$new_dt->setTimestamp($ts * $secs_interval);
		return $new_dt;
	}

	/**
	 * Setzt das Objekt gleich der übergebenen DateTime
	 *
	 * @param DateTime $dt
	 */
	public function setDateTime(DateTime $dt) {
		$tz = $this->getTimezone();

		$this->setTimezone($dt->getTimezone());
		$this->setTimestamp($dt->getTimestamp());

		$this->setTimezone($tz);
	}

	/**
	 * Bestimmt die Gesamtanzahl von Minuten, die das übergebene Intervall umfasst
	 *
	 * @param DateInterval $date_interval
	 *
	 * @return int
	 */
	public static function totalMinutes(DateInterval $date_interval) {

		$total_minutes = $date_interval->days * 24 * 60;
		$total_minutes += $date_interval->h * 60;
		$total_minutes += $date_interval->i;

		return $total_minutes;
	}

	/**
	 * Erzeugt eine String der Form "X Stunden Y Minuten" aus einer Minutenangabe
	 *
	 * @param int $minutes
	 *
	 * @return string
	 */
	public static function minutesToHourString(int $minutes) {

		$ret = '';

		if ($minutes < 0) {
			$minutes = -$minutes;
			$prefix = '-';
		} else {
			$prefix = '';
		}

		$hours = floor($minutes / 60);
		$minutes = $minutes - $hours * 60;

		if ($hours) {
			$ret .= $hours . ' Stunde';
			if ($hours > 1) {
				$ret .= 'n';
			}

			if ($minutes) {
				$ret .= ' ';
			}
		}

		if ($minutes) {
			$ret .= $minutes . ' Minute';
			if ($minutes > 1) {
				$ret .= 'n';
			}
		}

		if ($ret == '') {
			$ret = '0';
		} else {
			$ret = $prefix . $ret;
		}

		return $ret;
	}
}

LocalDateTime::init();