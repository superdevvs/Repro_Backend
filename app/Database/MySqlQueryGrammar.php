<?php

namespace App\Database;

use Illuminate\Database\Query\Builder;

/** Preserve case-insensitive application searches with binary identifier columns. */
final class MySqlQueryGrammar extends \Illuminate\Database\Query\Grammars\MySqlGrammar
{
    protected function whereBasic(Builder $query, $where)
    {
        $operator = strtolower($where['operator']);
        if (in_array($operator, ['like', 'not like'], true)) {
            return 'LOWER('.$this->wrap($where['column']).') '.$operator.' LOWER('.$this->parameter($where['value']).')';
        }

        return parent::whereBasic($query, $where);
    }
}
