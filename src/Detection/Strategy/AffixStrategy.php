<?php

namespace Ninja\Sentinel\Detection\Strategy;

use Ninja\Sentinel\Collections\MatchCollection;
use Ninja\Sentinel\Collections\OccurrenceCollection;
use Ninja\Sentinel\Enums\MatchType;
use Ninja\Sentinel\Support\Calculator;
use Ninja\Sentinel\ValueObject\Coincidence;
use Ninja\Sentinel\ValueObject\Position;

final class AffixStrategy extends AbstractStrategy
{
    /** @var array<string, array<string>> */
    private array $cache = [];

    /** @var array<string> */
    private array $prefixes;

    /** @var array<string> */
    private array $suffixes;

    public function __construct()
    {
        /** @var array<string> $prefixes */
        $prefixes = config('sentinel.prefixes', []);
        $this->prefixes = $prefixes;

        /** @var array<string> $suffixes */
        $suffixes = config('sentinel.suffixes', []);
        $this->suffixes = $suffixes;
    }

    public function detect(string $text, iterable $words): MatchCollection
    {
        $matches = new MatchCollection();

        $textWords = preg_split('/\b|\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ( ! $textWords) {
            return $matches;
        }

        $index = $this->buildIndex($words);

        foreach (array_unique($textWords) as $textWord) {
            /** @var string $textWord */
            $lowerTextWord = mb_strtolower($textWord);
            if (isset($index[$lowerTextWord])) {
                $positions = [];
                $pos = 0;

                while (($pos = mb_stripos($text, $textWord, $pos)) !== false) {
                    $positions[] = new Position($pos, mb_strlen($textWord));
                    $pos += mb_strlen($textWord);
                }

                if ( ! empty($positions)) {
                    $occurrences = new OccurrenceCollection($positions);

                    $matches->addCoincidence(
                        new Coincidence(
                            word: $textWord,
                            type: MatchType::Variation,
                            score: Calculator::score($text, $textWord, MatchType::Variation, $occurrences),
                            confidence: Calculator::confidence($text, $textWord, MatchType::Variation, $occurrences),
                            occurrences: $occurrences,
                            context: [
                                'original' => $index[$lowerTextWord],
                                'variation_type' => 'affix',
                            ],
                        ),
                    );
                }
            }
        }

        return $matches;
    }

    public function weight(): float
    {
        return MatchType::Variation->weight();
    }

    /**
     * @param  iterable<string>  $words
     * @return array<string, string>
     */
    private function buildIndex(iterable $words): array
    {
        $index = [];
        $dictionary = is_array($words) ? $words : iterator_to_array($words);

        foreach ($dictionary as $word) {
            $variants = $this->getCachedVariants($word);
            foreach ($variants as $variant) {
                $index[mb_strtolower($variant)] = $word;
            }
        }

        return $index;
    }

    /**
     * @return array<string>
     */
    private function generateVariants(string $word): array
    {
        $variants = [];
        $mb_word = mb_strtolower($word);

        foreach ($this->prefixes as $prefix) {
            $variants[] = $prefix . $mb_word;
            foreach ($this->generateSuffixVariants($mb_word) as $suffixVariant) {
                $variants[] = $prefix . $suffixVariant;
            }
        }

        return array_merge($variants, $this->generateSuffixVariants($mb_word));
    }

    /**
     * @return array<string>
     */
    private function generateSuffixVariants(string $word): array
    {
        $variants = [];

        if (preg_match('/[aeiou][bcdfghjklmnpqrstvwxz]$/i', $word)) {
            $doubles = $word . mb_substr($word, -1);
            foreach ($this->suffixes as $suffix) {
                if (in_array($suffix, ['ing', 'ed', 'er', 'est'])) {
                    $variants[] = $doubles . $suffix;
                }
            }
        }

        if ('e' === mb_substr($word, -1)) {
            $base = mb_substr($word, 0, -1);
            foreach ($this->suffixes as $suffix) {
                if (in_array($suffix, ['ing', 'er', 'est'])) {
                    $variants[] = $base . $suffix;
                }
            }
        }

        foreach ($this->suffixes as $suffix) {
            $variants[] = $word . $suffix;
        }

        if ('y' === mb_substr($word, -1)) {
            $base = mb_substr($word, 0, -1);
            $variants[] = $base . 'ies';
            $variants[] = $base . 'ied';
            $variants[] = $base . 'ier';
            $variants[] = $base . 'iest';
        }

        return $variants;
    }

    /**
     * @return array<string>
     */
    private function getCachedVariants(string $word): array
    {
        $key = mb_strtolower($word);
        if ( ! isset($this->cache[$key])) {
            $this->cache[$key] = $this->generateVariants($word);
        }

        return $this->cache[$key];
    }
}
