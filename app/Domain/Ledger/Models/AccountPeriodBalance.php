<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class AccountPeriodBalance extends Model
{
    protected $table = 'account_period_balances';

    public $timestamps = false;

    protected $guarded = [];
}
