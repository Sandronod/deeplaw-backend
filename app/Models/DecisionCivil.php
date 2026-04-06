<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionCivil extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'DecisionCivil';
    public    $timestamps = false;
}
