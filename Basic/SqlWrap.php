<?php

namespace BirdWorX\ModelDb\Basic;

class SqlWrap {

    private string $fieldName;
    private string $wrapping;
    private string $replaceSearch;

    public function __construct(string $field_name, string $wrapping, string $replace_search = '%s') {
        $this->fieldName = $field_name;
        $this->wrapping = $wrapping;
        $this->replaceSearch = $replace_search;
    }

    public function wrap(string $inner_alias): string {
	    $aliased_field = (strpos($this->fieldName, '.') === false) ? $inner_alias . '.`' . $this->fieldName . '`' : $this->fieldName;
        return str_replace($this->replaceSearch, $aliased_field, $this->wrapping);
    }
}