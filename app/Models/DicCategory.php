<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DicCategory extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'dic_Category';
    public    $timestamps = false;
}
