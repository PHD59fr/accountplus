<?php

declare(strict_types=1);

use App\Http\Response;

return [
    'internal redirects reject normalized external URLs and control characters' => static function (): void {
        foreach (['/companies', '/dashboard', '/reports?search=hello%20world&account=2'] as $valid) {
            if (Response::internalLocation($valid) !== $valid) { throw new RuntimeException('Internal path rejected: ' . $valid); }
        }
        foreach ([null, [], 'https://evil.example', '//evil.example', '/\\evil.example', '/%5Cevil.example',
            '/%255cevil.example', '/%25252fevil.example', 'javascript:alert(1)', "/\r\nevil", '/%0devil',
            '/%250aevil', "/\x00evil", '/%7fevil', '/%2fexample', ' /companies'] as $invalid) {
            if (Response::internalLocation($invalid) !== '/companies') { throw new RuntimeException('Unsafe destination accepted'); }
        }
    },
];
