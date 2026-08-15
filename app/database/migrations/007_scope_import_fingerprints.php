<?php

declare(strict_types=1);

return [
    'up' => [
        'ALTER TABLE imports
         DROP INDEX uniq_imports_source_sha256,
         ADD UNIQUE KEY uniq_imports_company_sha256 (company_id, source_sha256)',
    ],
];
