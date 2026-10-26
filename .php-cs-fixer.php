<?php
// 仅做排版，不改语义；兼容 PHP 7.0（常量不加可见性、不加尾逗号）
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src/core/application')
    ->in(__DIR__ . '/src/core/install')
    ->exclude(['admin/view', 'index/view', 'common/view'])
    ->notPath('#/view/#')
    ->name('*.php');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'visibility_required' => ['elements' => ['property', 'method']],
        'modifier_keywords' => ['elements' => ['property', 'method']],
        'no_multiple_statements_per_line' => true,
        'array_indentation' => true,
        'whitespace_after_comma_in_array' => true,
        'no_whitespace_before_comma_in_array' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'no_extra_blank_lines' => true,
        'single_quote' => false,
        'trailing_comma_in_multiline' => false,
        'blank_line_before_statement' => ['statements' => ['return']],
    ])
    ->setFinder($finder)
    ->setUsingCache(false);
