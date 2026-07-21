<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'accounts';

    public $timestamps = false;

    protected $guarded = [];
}
