<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof-of-restore artifact (docs/specs/10 §1) — BIR expects the log,
 * not just the backups. Written by tenants:backup-restore-test.
 */
class RestoreTestLog extends Model
{
    protected $table = 'restore_test_log';

    protected $fillable = [
        'tenant_id',
        'backup_catalog_id',
        'checksum_verified',
        'outcome',
        'details',
        'duration_ms',
        'operator',
    ];

    protected $casts = [
        'checksum_verified' => 'boolean',
        'duration_ms' => 'integer',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<BackupCatalogEntry, $this> */
    public function backupCatalogEntry(): BelongsTo
    {
        return $this->belongsTo(BackupCatalogEntry::class, 'backup_catalog_id');
    }
}
