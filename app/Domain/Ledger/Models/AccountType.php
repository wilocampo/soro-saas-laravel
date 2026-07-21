<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class AccountType extends Model
{
    protected $table = 'account_types';

    public $timestamps = false;

    protected $guarded = [];
}
