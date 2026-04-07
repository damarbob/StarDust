<?php

namespace StarDust\Database\Traits;

/**
 * Trait LegacyAliasTrait
 *
 * Provides functionality to map legacy column aliases to their actual table-prefixed
 * counterparts in the new Builder implementation.
 */
trait LegacyAliasTrait
{
    /**
     * Whether legacy alias mapping is enabled.
     * @var bool
     */
    protected $useLegacyAliases = false;



    /**
     * Enable or disable legacy alias mapping.
     *
     * @param bool $enabled
     * @return $this
     */
    public function withLegacyAliases(bool $enabled = true)
    {
        $this->useLegacyAliases = $enabled;
        return $this;
    }

    /**
     * Resolves a column name to its aliased duplicate if enabled.
     *
     * @param string $key
     * @return string
     */
    protected function mapLegacyAlias(string $key): string
    {
        if (!$this->useLegacyAliases) {
            return $key;
        }

        return $this->legacyAliasMapping[$key] ?? $key;
    }

    //--------------------------------------------------------------------
    // Overridden BaseBuilder Methods
    //--------------------------------------------------------------------

    public function where($key, $value = null, ?bool $escape = null)
    {
        // If $key is an array, we need to map keys inside it
        if (is_array($key)) {
            $newKey = [];
            foreach ($key as $k => $v) {
                // Determine if key is numeric (not an associative array key)
                // If it is numeric, the value is the condition string, so we skip mapping
                if (is_int($k)) {
                    $newKey[$k] = $v;
                } else {
                    $newMap = $this->mapLegacyAlias($k);
                    $newKey[$newMap] = $v;
                }
            }
            return parent::where($newKey, null, $escape);
        }

        // If $key is a string, map it
        $key = $this->mapLegacyAlias($key);
        return parent::where($key, $value, $escape);
    }

    public function orWhere($key, $value = null, ?bool $escape = null)
    {
        if (is_array($key)) {
            $newKey = [];
            foreach ($key as $k => $v) {
                if (is_int($k)) {
                    $newKey[$k] = $v;
                } else {
                    $newMap = $this->mapLegacyAlias($k);
                    $newKey[$newMap] = $v;
                }
            }
            return parent::orWhere($newKey, null, $escape);
        }

        $key = $this->mapLegacyAlias($key);
        return parent::orWhere($key, $value, $escape);
    }

    public function whereIn(?string $key = null, $values = null, ?bool $escape = null)
    {
        if ($key) {
            $key = $this->mapLegacyAlias($key);
        }
        return parent::whereIn($key, $values, $escape);
    }

    public function orWhereIn(?string $key = null, $values = null, ?bool $escape = null)
    {
        if ($key) {
            $key = $this->mapLegacyAlias($key);
        }
        return parent::orWhereIn($key, $values, $escape);
    }

    public function whereNotIn(?string $key = null, $values = null, ?bool $escape = null)
    {
        if ($key) {
            $key = $this->mapLegacyAlias($key);
        }
        return parent::whereNotIn($key, $values, $escape);
    }

    public function orWhereNotIn(?string $key = null, $values = null, ?bool $escape = null)
    {
        if ($key) {
            $key = $this->mapLegacyAlias($key);
        }
        return parent::orWhereNotIn($key, $values, $escape);
    }

    public function like($field, string $match = '', string $side = 'both', ?bool $escape = null, bool $insensitiveSearch = false)
    {
        $field = $this->mapLegacyAlias($field);
        return parent::like($field, $match, $side, $escape, $insensitiveSearch);
    }

    public function orLike($field, string $match = '', string $side = 'both', ?bool $escape = null, bool $insensitiveSearch = false)
    {
        $field = $this->mapLegacyAlias($field);
        return parent::orLike($field, $match, $side, $escape, $insensitiveSearch);
    }

    public function notLike($field, string $match = '', string $side = 'both', ?bool $escape = null, bool $insensitiveSearch = false)
    {
        $field = $this->mapLegacyAlias($field);
        return parent::notLike($field, $match, $side, $escape, $insensitiveSearch);
    }

    public function orNotLike($field, string $match = '', string $side = 'both', ?bool $escape = null, bool $insensitiveSearch = false)
    {
        $field = $this->mapLegacyAlias($field);
        return parent::orNotLike($field, $match, $side, $escape, $insensitiveSearch);
    }

    public function orderBy(string $orderBy, string $direction = '', ?bool $escape = null)
    {
        $orderBy = $this->mapLegacyAlias($orderBy);
        return parent::orderBy($orderBy, $direction, $escape);
    }

    public function groupBy($by, ?bool $escape = null)
    {
        // $by can be array or string
        if (is_array($by)) {
            $newBy = [];
            foreach ($by as $val) {
                $newBy[] = $this->mapLegacyAlias($val);
            }
            return parent::groupBy($newBy, $escape);
        }

        $by = $this->mapLegacyAlias($by);
        return parent::groupBy($by, $escape);
    }
}
