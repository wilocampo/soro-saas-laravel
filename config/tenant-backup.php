<?php

// Per-tenant backup settings (docs/specs/10 §1). WORM/Object-Lock and the
// PH-resident replica are properties of the target disk's bucket policy —
// point TENANT_BACKUP_DISK at an s3 disk configured that way in production.
return [

    'disk' => env('TENANT_BACKUP_DISK', 'local'),

    // Artifacts land at {prefix}/{tenant-slug}/{timestamp}.sql.gz
    'prefix' => env('TENANT_BACKUP_PREFIX', 'tenant-backups'),

    // Operational-tier retention. Compliance-tier and legal-hold rows are
    // NEVER pruned by age (10-yr horizon, docs/specs/03 retention conflict).
    'keep_days' => (int) env('TENANT_BACKUP_KEEP_DAYS', 90),

    'dump_binary' => env('TENANT_BACKUP_DUMP_BINARY', 'mariadb-dump'),
    'client_binary' => env('TENANT_BACKUP_CLIENT_BINARY', 'mariadb'),
];
