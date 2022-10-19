<?php

namespace BirdWorX\ModelDb\Basic;

class PrefixedField {

    /**
     * @var string
     */
    private string $fieldName;

    /**
     * @var string
     */
    private $prefixAlias;

    /**
     * @return string
     */
    public function getFieldName(): string {
        return $this->fieldName;
    }

    /**
     * @return string
     */
    public function getPrefixAlias(): string {
        return $this->prefixAlias;
    }

    public function __construct($field_name, $prefix_alias = ModelBase::DEFAULT_ALIAS) {
        $this->fieldName = $field_name;
        $this->prefixAlias = $prefix_alias;
    }

    public function aliasedName(): string {
        return $this->prefixAlias . '.`' . $this->fieldName . '`';
    }
}