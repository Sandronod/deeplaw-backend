<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DicClaimType extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'dic_ClaimType';
    public    $timestamps = false;
}
