<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DicResult extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'dic_Result';
    public    $timestamps = false;
}
