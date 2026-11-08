<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'general_phpdoc_annotation_remove' => [
            'annotations' => ['author', 'package', 'subpackage'],
        ],
        'general_phpdoc_tag_rename' => [
            'replacements' => ['inheritDocs' => 'inheritDoc'],
        ],
        'phpdoc_no_alias_tag' => ['replacements' => ['type' => 'var']],
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                __DIR__.'/app',
                __DIR__.'/config',
                __DIR__.'/database',
                __DIR__.'/routes',
                __DIR__.'/tests',
            ])
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true),
    );
