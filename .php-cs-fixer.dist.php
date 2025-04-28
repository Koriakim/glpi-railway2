<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/inc',
    ])
;

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setCacheFile(sys_get_temp_dir() . '/php-cs-fixer.glpi.cache')
    ->setRules([
        '@PSR12' => true,
        '@PER-CS' => true,
        '@PHP82Migration' => true,
        'trailing_comma_in_multiline' => false,
        'cast_spaces' => false,
        'single_line_empty_body' => false,
        'no_unused_imports' => true
    ])
    ->setFinder($finder)
;
