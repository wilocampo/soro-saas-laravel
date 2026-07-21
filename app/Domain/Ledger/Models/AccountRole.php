<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class AccountRole extends Model
{
    protected $table = 'account_roles';

    public $timestamps = false;

    protected $guarded = [];
}
