<?php

namespace StarDust\Database;

use CodeIgniter\Database\BaseBuilder;

final class EntriesBuilder extends BaseBuilder
{

    /**
     * The custom chainable method.
     */
    public function likeFields(array $conditions): self
    {
        $this->groupStart();

        foreach ($conditions as $condition) {
            $conditionField = $condition['field'];
            $conditionValue = strtolower($condition['value']);

            $sql = <<<SQL
                LOWER(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(fields, '$."{$conditionField}"')
                    )
                ) LIKE '%{$conditionValue}%'
                SQL;

            $this->where($sql, null, false);
        }

        $this->groupEnd();

        // Return $this (the wrapper) to allow further chaining
        return $this;
    }
}
