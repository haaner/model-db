<?php

namespace BirdWorX\ModelDb\Basic;

/**
 * Class SqlCondition
 */
class SqlCondition {

    /**
     * Verküpfungsarten
     */
    const CHAINING_TYPE_AND = 'AND';
    const CHAINING_TYPE_OR = 'OR';

    const COMPARISON_EQ = '=';
    const COMPARISON_NEQ = '!=';
    const COMPARISON_LT = '<';
    const COMPARISON_GT = '>';
    const COMPARISON_LTE = '<=';
    const COMPARISON_GTE = '>=';
    const COMPARISON_LIKE = 'LIKE';
    const COMPARISON_REGEXP = 'REGEXP';
    const COMPARISON_IS = 'IS';
    const COMPARISON_ISNOT = 'IS NOT';
    const COMPARISON_IN = 'IN';
    const COMPARISON_NOTIN = 'NOT IN';

    /**
     * Feldname das verglichen werden soll
     *
     * @var string
     */
    private $key;

    /**
     * Der Alias-Prefix des Key-Feldes
     *
     * @var string
     */
    private $keyAliasPrefix;

    /**
     * Wrapping-Funktion des Keys
     *
     * @var SqlWrap
     */
    private $keyWrapper;

    /**
     * Vergleichsoperation / -operator
     *
     * @var string
     */
    private $comparison;

    /**
     * Vergleichswert
     *
     * @var string
     */
    private $value;

    /**
     * Verknüpfte SqlWhere-Objekte, Verkettungstypen und Klammerungen
     */
    private $chainedSqlCondition = array();
    private $chainingType = array();
    private $inParentheses = array();
    private $useNot = array();

    /**
     * SqlCondition Konstruktor
     *
     * @param string|PrefixedField $key Der Name des zu vergleichenden Feldes oder '0' bzw. '1' für ein None- bzw. All-Matching
     * @param bool|string|array $value Ein einzelner Wert bzw. eine Liste von Werten
     * @param string $comparison Zu verwendende Vergleichoperation
     * @param string|null $key_wrap Wenn !== null wird der $key mit dem Wrapper ummantelt. Wichtig dabei: Die Zeichenkette "%s" fungiert als Platzhalter für den Key
     */
    public function __construct($key, $value, $comparison = self::COMPARISON_EQ, $key_wrap = null) {

        if ($key instanceof PrefixedField) {
            $this->keyAliasPrefix = $key->getPrefixAlias();
            $this->key = $key->getFieldName();

	        $key = $key->aliasedName();
        } else {
            $this->keyAliasPrefix = ModelBase::DEFAULT_ALIAS;
            $this->key = $key;
        }

        if(is_bool($value)) {
            $this->value = $value ? 1 : 0;
        } else {
            $this->value = $value;
        }

        $this->comparison = $comparison;

        if($key_wrap !== null) {
            $this->keyWrapper = new SqlWrap($key, $key_wrap);
        }
    }

    /**
     * Verkettet das aktuelle Vergleichsobjekt mit einem weiteren Vergleichsobjekt
     *
     * @param SqlCondition $sql_condition
     * @param string $chaining_type SqlWhere::CHAINING_TYPE_AND bzw. SqlWhere::CHAINING_TYPE_OR
     * @param bool $in_parentheses Soll das hinzufügende Vergleichobjekt geklammert verkettet werden
     *
     * @return SqlCondition
     */
    public function chainWith($sql_condition, $chaining_type = self::CHAINING_TYPE_AND, $in_parentheses = false, $use_not = false) {

        $this->chainedSqlCondition[] = $sql_condition;
        $this->chainingType[] = $chaining_type;
        $this->inParentheses[] = $in_parentheses;
        $this->useNot[] = $use_not;

        return $this;
    }

    public function chainLength() {
        return count($this->chainedSqlCondition);
    }

    /**
     * Erzeugt die resultierende SQL-Bedingung
     *
     * @return string
     */
    public function build($use_key_alias_prefix = true) {

        if($this->key === '0' || $this->key === '1') {

            $alias_unwrapped = '';
            $key = $this->key;
            $this->comparison = self::COMPARISON_EQ;
            $value_string = '1';

        } else {

            if($use_key_alias_prefix) {
                $alias_unwrapped = $this->keyAliasPrefix;
            } else {
                $alias_unwrapped = '';
            }

            $key = '`' . $this->key . '`';

            if(is_array($this->value)) {
                $value_string = "('" . implode("','", array_map(function($data) {
                        return Db::escapeString($data);
                    }, $this->value)) . "')";

                if($this->comparison === self::COMPARISON_EQ) {
                    $this->comparison = self::COMPARISON_IN;
                } elseif($this->comparison === self::COMPARISON_NEQ) {
                    $this->comparison = self::COMPARISON_NOTIN;
                }

            } else {
                if($this->value === null || $this->value === 'NULL') {
                    $value_string = 'NULL';

                    if($this->comparison === self::COMPARISON_EQ) {
                        $this->comparison = self::COMPARISON_IS;
                    } elseif($this->comparison === self::COMPARISON_NEQ) {
                        $this->comparison = self::COMPARISON_ISNOT;
                    }
                } else {
                    if($this->keyWrapper) {
                        $key = $this->keyWrapper->wrap($alias_unwrapped);
                        $alias_unwrapped = '';
                    }

                    $value_string = "'" . Db::escapeString($this->value) . "'";
                }
            }
        }

        $where = '';

        if($alias_unwrapped !== '') {
            $where .= $alias_unwrapped . '.';
        }

        $where .= $key . ' ' . $this->comparison . ' ' . $value_string;

        foreach($this->chainedSqlCondition as $idx => $sql_condition) {
            $in_parentheses = $this->inParentheses[$idx];
            $use_not = $this->useNot[$idx];
            $where .= ' ' . $this->chainingType[$idx] . ($use_not ? ' NOT' : '') . ' ' . ($in_parentheses ? '(' : '') . $sql_condition->build($use_key_alias_prefix) . ($in_parentheses ? ')' : '');
        }

        return $where;
    }

    /**
     * Gibt eine Sql-Bedingung zurück, die IMMER zutrifft.
     *
     * @return SqlCondition
     */
    public static function matchAll() {
        return new SqlCondition('1', null);
    }

    /**
     * Gibt eine Sql-Bedingung zurück, die NIEMALS zutrifft.
     *
     * @return SqlCondition
     */
    public static function matchNone() {
        return new SqlCondition('0', null);
    }

    /**
     * Erzeugt aus den übergebenen Key-Value Paaren eine SqlCondition, in der die Key-Value Paare per AND und '=' miteinander verknüpft werden.
     * Wenn ein Value ein Array enthält, dann werden die darin enthalten Values mit dem Key per OR und '=' verknüpft.
     *
     * Beispielsweise ergibt:
     *
     *  $key_value = array(
     *      'ort' => 'Neudrossenfeld',
     *      'vorname' => 'Hans',
     *      'name' => array( 'Meier', 'Meyer')
     *  );
     *
     * die Bedingung: WHERE ort = 'Neudrossenfeld' AND vorname = 'Hans' AND ( name = 'Meier' OR name = 'Meyer' )
     *
     * @param array $key_value
     *
     * @return SqlCondition
     */
    public static function createByArray($key_value = array()) {

        // Das Array von Key-Value Paaren in eine SQL-Bedingung umwandeln
        $sql_condition = SqlCondition::matchAll();
        foreach($key_value as $key => $value) {
            if(($in_parentheses = is_array($value))) {
                $sql_condition2 = SqlCondition::matchNone();
                foreach($value as $val) {
                    $sql_condition2->chainWith(new SqlCondition($key, $val), SqlCondition::CHAINING_TYPE_OR);
                }
            } else {
                $sql_condition2 = new SqlCondition($key, $value);
            }

            $sql_condition->chainWith($sql_condition2, SqlCondition::CHAINING_TYPE_AND, $in_parentheses);
        }

        return $sql_condition;
    }
}