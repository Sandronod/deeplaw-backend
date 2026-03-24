<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DicKind extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'dic_Kind';
    public    $timestamps = false;
}
