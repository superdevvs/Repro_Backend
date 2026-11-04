<?php

namespace App\Database;

final class MySqlConnection extends \Illuminate\Database\MySqlConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new MySqlQueryGrammar($this);
    }
}
