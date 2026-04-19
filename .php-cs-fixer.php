<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer — configuración de estilo de código szm-core.
 *
 * Ejecutar:
 *   vendor/bin/php-cs-fixer fix          # aplicar cambios
 *   vendor/bin/php-cs-fixer fix --dry-run # solo mostrar diferencias
 *
 * O vía script de Composer:
 *   composer format
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/core',
    ])
    ->name('*.php')
    ->notPath('vendor')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // Estándar base
        '@PSR12'                              => true,

        // PHP moderno
        'declare_strict_types'                => true,
        'strict_param'                        => true,
        'array_syntax'                        => ['syntax' => 'short'],
        'list_syntax'                         => ['syntax' => 'short'],

        // Imports
        'ordered_imports'                     => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                   => true,
        'global_namespace_import'             => [
            'import_classes'   => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // Strings
        'single_quote'                        => true,
        'heredoc_to_nowdoc'                   => true,

        // Comas y espaciado
        'trailing_comma_in_multiline'         => ['elements' => ['arrays', 'parameters', 'match']],
        'no_trailing_comma_in_singleline'     => true,
        'binary_operator_spaces'              => ['default' => 'single_space'],
        'unary_operator_spaces'               => true,
        'concat_space'                        => ['spacing' => 'one'],

        // Líneas en blanco
        'blank_line_before_statement'         => [
            'statements' => ['return', 'throw', 'try', 'yield'],
        ],
        'no_extra_blank_lines'                => [
            'tokens' => ['extra', 'return', 'throw', 'use', 'use_trait'],
        ],
        'single_blank_line_at_eof'            => true,

        // PHPDoc
        'phpdoc_scalar'                       => true,
        'phpdoc_trim'                         => true,
        'phpdoc_no_empty_return'              => true,
        'phpdoc_align'                        => ['align' => 'vertical'],

        // Control de flujo
        'no_useless_else'                     => true,
        'no_useless_return'                   => true,
        'early_return'                        => true,
        'simplified_if_return'                => true,

        // Tipos y casts
        'cast_spaces'                         => ['space' => 'single'],
        'modernize_types_casting'             => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // Miscelánea
        'native_function_invocation'          => [
            'include' => ['@compiler_optimized'],
            'scope'   => 'namespaced',
        ],
        'no_superfluous_phpdoc_tags'          => ['allow_mixed' => true],
        'self_accessor'                       => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/storage/cache/.php-cs-fixer.cache');