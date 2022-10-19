<?php

namespace BirdWorX\ModelDb\Basic;

class SqlJoin {

    /**
     * @var int
     */
    private static $joinCtr = 0;

    /**
     * @var string
     */
    private $joinTable;

    /**
     * @var string[]|PrefixedField[]
     */
    private $onJoinFieldValues;

    /**
     * @var string
     */
    private $joinAlias;

    /**
     * SqlJoin Konstruktor
     *
     * @param string $join_table
     * @param string[]|PrefixedField[] $on_join_field_values
     */
    public function __construct($join_table, array $on_join_field_values) {

        $this->joinTable = $join_table;
        $this->onJoinFieldValues = $on_join_field_values;

        self::$joinCtr++;

        $this->joinAlias = 'j' . self::$joinCtr;
    }

    public function getAlias(): string {
        return $this->joinAlias;
    }

    public function join(): string {
        $on_clause = ' ON ';

        foreach ($this->onJoinFieldValues as $join_field => $value) {
            if($value instanceof PrefixedField) {
                $aliased_value = $value->aliasedName();
            } else {
                $aliased_value = "'" . $value . "'";
            }

            $on_clause .= $this->joinAlias . '.`' . $join_field . '` = ' . $aliased_value . ' AND ';
        }

        return 'LEFT JOIN ' . $this->joinTable . ' ' . $this->joinAlias . rtrim($on_clause, ' AND ');
    }
}
