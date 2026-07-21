<?php

// Compliance knobs (docs/specs/01 §7, 04, 10 §5).
return [

    // Stamped on the mandatory BIR report header/footer (RMC 5-2021 Annex B
    // item 4) and on backup-catalog labels. Bump per release with a
    // major/minor enhancement classification (RMO 9-2021).
    'software_name' => env('COMPLIANCE_SOFTWARE_NAME', env('APP_NAME', 'Soro SaaS')),
    'software_version' => env('COMPLIANCE_SOFTWARE_VERSION', env('APP_VERSION', '0.1.0-dev')),

    // Password policy (spec 04): periodic rotation, with notices before
    // expiry. Set enforce=false to notify without blocking (e.g. during a
    // migration window).
    'password_rotation_days' => (int) env('PASSWORD_ROTATION_DAYS', 30),
    'password_expiry_notice_days' => (int) env('PASSWORD_EXPIRY_NOTICE_DAYS', 7),
    'enforce_password_rotation' => (bool) env('ENFORCE_PASSWORD_ROTATION', true),
];
