<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseAdmin extends Model
{
    protected $connection = 'sqlsrv';
    protected $table      = 'CaseAdmin';
    public    $timestamps = false;
    public function DecisionAdmin()
    {
        return $this->hasOne(DecisionAdmin::class, 'CaseID', 'CaseID');
    }
    public function dic_Category()
    {
        return $this->hasOne(DicCategory::class,  'dic_CategoryID', 'dic_CaseCategoryID');
    }

    public function dic_Chamber()
    {
        return $this->hasOne(DicChamber::class, 'dic_ChamberID', 'dic_ChamberID');
    }
    public function dic_Result()
    {
        return $this->hasOne(DicResult::class, 'dic_ResultID', 'dic_ResultID');
    }
    public function dic_ClaimType()
    {
        return $this->hasOne(DicClaimType::class,  'dic_ClaimTypeID', 'dic_ClaimID');
    }


    public function dic_Kind()
    {
        return $this->hasOne(DicKind::class,  'dic_KindID', 'dic_CaseTypeID');
    }
}
