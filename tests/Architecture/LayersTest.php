<?php

declare(strict_types=1);

const FRAMEWORK_NAMESPACES = [
    'Illuminate',
    'Laravel',
    'Orchestra',
    'GuzzleHttp',
    'OpenTelemetry',
];

arch('source files declare strict types')
    ->expect('Sysborg\LaravelJevai')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('domain is framework agnostic and depends on nothing else in the package')
    ->expect('Sysborg\LaravelJevai\Domain')
    ->not->toUse([
        ...FRAMEWORK_NAMESPACES,
        'Sysborg\LaravelJevai\Application',
        'Sysborg\LaravelJevai\Ports',
        'Sysborg\LaravelJevai\Adapters',
    ]);

arch('ports depend only on domain')
    ->expect('Sysborg\LaravelJevai\Ports')
    ->not->toUse([
        ...FRAMEWORK_NAMESPACES,
        'Sysborg\LaravelJevai\Application',
        'Sysborg\LaravelJevai\Adapters',
    ]);

arch('ports are interfaces')
    ->expect('Sysborg\LaravelJevai\Ports')
    ->toBeInterfaces();

arch('application depends only on domain and ports')
    ->expect('Sysborg\LaravelJevai\Application')
    ->not->toUse([
        ...FRAMEWORK_NAMESPACES,
        'Sysborg\LaravelJevai\Adapters',
    ]);
