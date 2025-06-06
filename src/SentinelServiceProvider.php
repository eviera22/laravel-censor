<?php

namespace Ninja\Sentinel;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Validator;
use Ninja\Sentinel\Cache\Contracts\PatternCache;
use Ninja\Sentinel\Checkers\AzureAI;
use Ninja\Sentinel\Checkers\Contracts\ProfanityChecker;
use Ninja\Sentinel\Checkers\PerspectiveAI;
use Ninja\Sentinel\Checkers\PrismAI;
use Ninja\Sentinel\Checkers\PurgoMalum;
use Ninja\Sentinel\Checkers\TisaneAI;
use Ninja\Sentinel\Dictionary\LazyDictionary;
use Ninja\Sentinel\Enums\Provider;
use Ninja\Sentinel\Factories\ProfanityCheckerFactory;
use Ninja\Sentinel\Index\TrieIndex;
use Ninja\Sentinel\Processors\AbstractProcessor;
use Ninja\Sentinel\Processors\Contracts\Processor;
use Ninja\Sentinel\Processors\DefaultProcessor;
use Ninja\Sentinel\Services\Adapters\AzureAdapter;
use Ninja\Sentinel\Services\Adapters\LocalAdapter;
use Ninja\Sentinel\Services\Adapters\PerspectiveAdapter;
use Ninja\Sentinel\Services\Adapters\PrismAdapter;
use Ninja\Sentinel\Services\Adapters\PurgoMalumAdapter;
use Ninja\Sentinel\Services\Adapters\TisaneAdapter;
use Ninja\Sentinel\Services\Contracts\ServiceAdapter;
use Ninja\Sentinel\Services\Pipeline\Stage\MatchesStage;
use Ninja\Sentinel\Services\Pipeline\Stage\MetadataStage;
use Ninja\Sentinel\Services\Pipeline\Stage\OffensiveStage;
use Ninja\Sentinel\Services\Pipeline\Stage\ScoreStage;
use Ninja\Sentinel\Services\Pipeline\Stage\TextStage;
use Ninja\Sentinel\Services\Pipeline\TransformationPipeline;
use Ninja\Sentinel\Support\PatternGenerator;

final class SentinelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sentinel.php' => config_path('sentinel.php'),
            ], 'sentinel-config');

            $this->publishes([
                __DIR__ . '/../resources/dict' => resource_path('dict'),
            ], 'sentinel-dictionaries');
        }

        app('validator')->extend(
            rule: 'offensive',
            extension: function ($attribute, $value, $parameters, Validator $validator) {
                if (null === $value) {
                    return true;
                }

                if ( ! is_string($value)) {
                    $validator->addReplacer('offensive', fn() => 'The :attribute must be a string.');

                    return false;
                }

                return ! Facades\Sentinel::check($value)->offensive();
            },
            message: 'The :attribute contains offensive language.',
        );

        $this->loadRoutesFrom(__DIR__ . '/../routes/sentinel.php');
    }

    public function register(): void
    {
        $this->app->bind(ServiceAdapter::class, function (Application $app) {
            return match (config('sentinel.default_service', Provider::Local)) {
                Provider::Azure => $app->make(AzureAdapter::class),
                Provider::Perspective => $app->make(PerspectiveAdapter::class),
                Provider::PurgoMalum => $app->make(PurgoMalumAdapter::class),
                Provider::Tisane => $app->make(TisaneAdapter::class),
                Provider::Prism => $app->make(PrismAdapter::class),
                default => $app->make(LocalAdapter::class),
            };
        });

        $this->app->singleton(LocalAdapter::class);
        $this->app->singleton(AzureAdapter::class);
        $this->app->singleton(PerspectiveAdapter::class);
        $this->app->singleton(PurgoMalumAdapter::class);
        $this->app->singleton(TisaneAdapter::class);
        $this->app->singleton(PrismAdapter::class);

        $this->app->when(AzureAI::class)->needs(ServiceAdapter::class)->give(AzureAdapter::class);
        $this->app->when(TisaneAI::class)->needs(ServiceAdapter::class)->give(TisaneAdapter::class);
        $this->app->when(Checkers\Local::class)->needs(ServiceAdapter::class)->give(LocalAdapter::class);
        $this->app->when(PerspectiveAI::class)->needs(ServiceAdapter::class)->give(PerspectiveAdapter::class);
        $this->app->when(PurgoMalum::class)->needs(ServiceAdapter::class)->give(PurgoMalumAdapter::class);
        $this->app->when(PrismAI::class)->needs(ServiceAdapter::class)->give(PrismAdapter::class);

        $this->app->singleton(TransformationPipeline::class, fn() => (new TransformationPipeline())
            ->addStage(new ScoreStage())
            ->addStage(new MatchesStage())
            ->addStage(new TextStage(app(Whitelist::class)))
            ->addStage(new MetadataStage())
            ->addStage(new OffensiveStage()));

        $this->registerProfanityProviders();

        /** @var Provider $default */
        $default = config('sentinel.default_service', Provider::Local);
        $this->app->bind(ProfanityChecker::class, function () use ($default): ProfanityChecker {
            /** @var ProfanityChecker $service */
            $service = app($default->value);

            return $service;
        });

        $this->app->singleton(PatternCache::class, function () {
            /** @var string $cache */
            $cache = config('sentinel.cache', 'file');

            return match ($cache) {
                'redis' => new Cache\RedisPatternCache(),
                'octane' => new Cache\OctanePatternCache(),
                default => new Cache\MemoryPatternCache(),
            };
        });

        $this->app->singleton(PatternGenerator::class, function () {
            /** @var LazyDictionary $dictionary */
            $dictionary = app(LazyDictionary::class);

            return PatternGenerator::withDictionary($dictionary);
        });

        $this->app->singleton(LazyDictionary::class, function (): LazyDictionary {
            /** @var string[] $languages */
            $languages = config('sentinel.languages', [config('app.locale')]);

            return LazyDictionary::withLanguages($languages);
        });

        $this->app->singleton(Whitelist::class, function (): Whitelist {
            /** @var string[] $whitelist */
            $whitelist = config('sentinel.whitelist', []);

            return (new Whitelist())->add($whitelist);
        });

        $this->app->singleton(TrieIndex::class, function (): TrieIndex {
            /** @var LazyDictionary $dictionary */
            $dictionary = app(LazyDictionary::class);

            return new TrieIndex($dictionary->getWords());
        });

        $this->app->singleton(Processor::class, function (): AbstractProcessor {
            /** @var class-string<AbstractProcessor> $processorClass */
            $processorClass = config('sentinel.services.local.processor', DefaultProcessor::class);

            return new $processorClass(
                app(Whitelist::class),
                app(LazyDictionary::class),
            );

        });

        $this->app->bind('sentinel', fn() => new Sentinel());

        $this->mergeConfigFrom(__DIR__ . '/../config/sentinel.php', 'sentinel');
    }

    private function registerProfanityProviders(): void
    {
        $services = Provider::values();
        foreach ($services as $service) {
            /** @var array<string,mixed> $config */
            $config = config(sprintf('sentinel.services.%s', $service->value));

            if (null !== $config) {
                $this->app->singleton($service->value, fn(): ProfanityChecker => ProfanityCheckerFactory::create($service, $config));
            }
        }


        $this->app->singleton(Provider::Local->value, function () {
            /** @var Processor $processor */
            $processor = app(Processor::class);

            /** @var ServiceAdapter $adapter */
            $adapter = app(ServiceAdapter::class);

            return new Checkers\Local(
                processor: $processor,
                adapter: $adapter,
                pipeline: app(TransformationPipeline::class),
            );
        });
    }
}
