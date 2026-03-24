<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionAdmin extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'DecisionAdmin';
    public    $timestamps = false;
}
