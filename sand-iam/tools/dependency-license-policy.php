<?php

declare(strict_types=1);

/**
 * Audited SPDX policy for lockfile-only dependencies that do not carry license
 * metadata in their lock format. Keys are exact package coordinates so an
 * upgrade cannot silently inherit an earlier license conclusion.
 *
 * @return array{npm:array<string,array{id?:string,expression?:string,evidence:string}>,pub:array<string,array{id?:string,expression?:string,evidence:string}>}
 */
return (static function (): array {
    $npm = [
        'typescript@5.9.3' => ['id' => 'Apache-2.0', 'evidence' => 'https://github.com/microsoft/TypeScript/blob/v5.9.3/LICENSE.txt'],
        'esbuild@0.25.10' => ['id' => 'MIT', 'evidence' => 'https://github.com/evanw/esbuild/blob/v0.25.10/LICENSE.md'],
    ];
    foreach ([
        'aix-ppc64', 'android-arm', 'android-arm64', 'android-x64', 'darwin-arm64', 'darwin-x64',
        'freebsd-arm64', 'freebsd-x64', 'linux-arm', 'linux-arm64', 'linux-ia32', 'linux-loong64',
        'linux-mips64el', 'linux-ppc64', 'linux-riscv64', 'linux-s390x', 'linux-x64', 'netbsd-arm64',
        'netbsd-x64', 'openbsd-arm64', 'openbsd-x64', 'openharmony-arm64', 'sunos-x64', 'win32-arm64',
        'win32-ia32', 'win32-x64',
    ] as $platform) {
        $npm['@esbuild/' . $platform . '@0.25.10'] = [
            'id' => 'MIT',
            'evidence' => 'https://github.com/evanw/esbuild/blob/v0.25.10/LICENSE.md',
        ];
    }

    $pub = [];
    $bsd = [
        '_fe_analyzer_shared' => '99.0.0', 'analyzer' => '12.1.0', 'args' => '2.7.0',
        'async' => '2.13.1', 'boolean_selector' => '2.1.2', 'cli_config' => '0.2.0',
        'collection' => '1.19.1', 'convert' => '3.1.2', 'coverage' => '1.15.1',
        'crypto' => '3.0.7', 'file' => '7.0.1', 'frontend_server_client' => '4.0.0',
        'glob' => '2.1.3', 'http' => '1.6.0', 'http_multi_server' => '3.2.2',
        'http_parser' => '4.1.2', 'io' => '1.0.5', 'lints' => '6.1.0', 'logging' => '1.3.0',
        'matcher' => '0.12.20', 'meta' => '1.19.0', 'mime' => '2.0.0',
        'package_config' => '2.2.0', 'path' => '1.9.1', 'pool' => '1.5.2',
        'pub_semver' => '2.2.0', 'shelf' => '1.4.2', 'shelf_packages_handler' => '3.0.2',
        'shelf_static' => '1.1.3', 'shelf_web_socket' => '3.0.0',
        'source_map_stack_trace' => '2.1.2', 'source_maps' => '0.10.14',
        'source_span' => '1.10.2', 'stack_trace' => '1.12.1', 'stream_channel' => '2.1.4',
        'string_scanner' => '1.4.1', 'term_glyph' => '1.2.2', 'test' => '1.31.1',
        'test_api' => '0.7.12', 'test_core' => '0.6.18', 'typed_data' => '1.4.0',
        'vm_service' => '15.3.0', 'watcher' => '1.2.1', 'web' => '1.1.1',
        'web_socket' => '1.0.1', 'web_socket_channel' => '3.0.3',
        'webkit_inspection_protocol' => '1.2.1',
    ];
    foreach ($bsd as $name => $version) {
        $pub[$name . '@' . $version] = [
            'id' => 'BSD-3-Clause',
            'evidence' => 'https://pub.dev/packages/' . $name . '/versions/' . $version . '/license',
        ];
    }
    $pub['node_preamble@2.0.2'] = [
        'expression' => 'BSD-3-Clause AND MIT',
        'evidence' => 'https://pub.dev/packages/node_preamble/versions/2.0.2/license',
    ];
    $pub['yaml@3.1.3'] = [
        'id' => 'MIT',
        'evidence' => 'https://pub.dev/packages/yaml/versions/3.1.3/license',
    ];

    return ['npm' => $npm, 'pub' => $pub];
})();
