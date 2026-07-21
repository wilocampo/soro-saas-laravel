<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $table = 'branches';

    public $timestamps = false;

    protected $guarded = [];
}
