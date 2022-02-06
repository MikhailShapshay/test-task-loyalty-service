<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPointsRule extends Model
{
    protected $table = 'loyalty_points_rule';

    protected $fillable = [
        'points_rule',
        'accrual_type',
        'accrual_value',
    ];
}
