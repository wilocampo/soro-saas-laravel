<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Landlord catalog of tenant dump artifacts (docs/specs/10 §1;
 * label + software name/version per RR 9-2009 §6.1).
 */
class BackupCatalogEntry extends Model
{
    protected $table = 'backup_catalog';

    protected $fillable = [
        'tenant_id',
        'disk',
        'path',
        'label',
        'software_name',
        'software_version',
        'size_bytes',
        'checksum_sha256',
        'tier',
        'legal_hold',
        'status',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'legal_hold' => 'boolean',
        'size_bytes' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
