<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $table = 'document_sequences';

    public $timestamps = false;

    protected $guarded = [];
}
