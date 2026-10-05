<?php

/**
 * Il profilo di esecuzione e' una scelta di composizione (ADR 0014): solo il
 * composition root e le due enum di configurazione possono nominarlo. Dominio,
 * applicazione e adapter lavorano sulle porte senza sapere quale provider e'
 * attivo.
 */
test('the execution profile is read only by the composition root', function () {
    $markers = ['ExecutionProfile', 'LocalCoverProvider', 'execution_profile', 'MVP_EXECUTION_PROFILE', 'LOCAL_COVER_PROVIDER'];
    $allowed = [
        'app/Providers/AppServiceProvider.php',
        'app/Mvp/Support/ExecutionProfile.php',
        'app/Mvp/Support/LocalCoverProvider.php',
    ];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        $contents = (string) file_get_contents($file->getPathname());

        foreach ($markers as $marker) {
            if (str_contains($contents, $marker) && ! in_array($relative, $allowed, true)) {
                $offenders[] = "{$relative} ({$marker})";
            }
        }
    }

    expect($offenders)->toBe([], "Il profilo di esecuzione compare fuori dal composition root:\n - ".implode("\n - ", $offenders));
});
