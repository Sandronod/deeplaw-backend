<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DicChamber extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'dic_Chamber';
    public    $timestamps = false;
}
