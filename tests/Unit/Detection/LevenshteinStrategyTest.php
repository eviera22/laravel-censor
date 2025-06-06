<?php

namespace Tests\Unit\Detection;

use Ninja\Sentinel\Collections\MatchCollection;
use Ninja\Sentinel\Detection\Strategy\LevenshteinStrategy;
use Ninja\Sentinel\Enums\MatchType;
use Ninja\Sentinel\ValueObject\Coincidence;

test('levenshtein strategy detects similar words', function (): void {
    config(['sentinel.services.local.levenshtein_threshold' => 2]);

    $strategy = new LevenshteinStrategy();
    $variations = [
        'fuk',
        'phuck',
        'fück',
    ];

    foreach ($variations as $text) {
        $result = $strategy->detect($text, ['fuck']);
        expect($result)
            ->toBeInstanceOf(MatchCollection::class)
            ->toHaveCount(1)
            ->sequence(
                fn($match) => $match
                    ->word()->toBe($text)
                    ->type()->toBe(MatchType::Levenshtein),
            );
    }
});

test('levenshtein strategy respects threshold', function (): void {
    config(['sentinel.services.local.levenshtein_threshold' => 1]);

    $strategy = new LevenshteinStrategy();
    $result = $strategy->detect('fuk this shet', ['fuck', 'shit']);

    expect($result)
        ->toHaveCount(2)  // Should only match 'fuk', 'shet' is too different with threshold 1
        ->and($result->first())
        ->toBeInstanceOf(Coincidence::class)
        ->and($result->first()->word())->toBe('fuk');
});

test('levenshtein strategy handles short words correctly', function (): void {
    config(['sentinel.services.local.levenshtein_threshold' => 1]);

    $strategy = new LevenshteinStrategy();
    $result = $strategy->detect('This is a text', ['shit']);

    // Should not match 'This' with 'shit' even though Levenshtein distance might be within threshold
    expect($result)->toBeEmpty();

});

test('levenshtein strategy ignores case in comparisons', function (): void {
    $strategy = new LevenshteinStrategy();
    $result = $strategy->detect('FuK', ['fuck']);

    expect($result)
        ->toHaveCount(1)
        ->and($result->first()->word())->toBe('FuK');
});

test('levenshtein strategy preserves original case in matches', function (): void {
    config(['sentinel.services.local.levenshtein_threshold' => 1]);

    $strategy = new LevenshteinStrategy();
    $text = 'FuK ThIs ShEt';
    $result = $strategy->detect($text, ['fuck', 'shit']);

    expect($result)
        ->toHaveCount(2)
        ->sequence(
            fn($match) => $match->word()->toBe('FuK'),
            fn($match) => $match->word()->toBe('ShEt'),
        );
});

test('levenshtein handles unicode chars', function (): void {
    $strategy = new LevenshteinStrategy();

    $variations = [
        'fück' => true,
        'føck' => true,
        'f♥ck' => true,
        'food' => false,
    ];

    foreach ($variations as $text => $shouldMatch) {
        $result = $strategy->detect($text, ['fuck']);
        expect($result->isEmpty())->toBe( ! $shouldMatch);
    }
});
