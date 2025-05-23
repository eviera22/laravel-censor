<?php

namespace Ninja\Sentinel\Checkers;

use GuzzleHttp\ClientInterface;
use Ninja\Sentinel\Result\Contracts\Result;
use Ninja\Sentinel\Services\Contracts\ServiceAdapter;
use Ninja\Sentinel\Services\Pipeline\TransformationPipeline;

final class AzureAI extends AbstractProfanityChecker
{
    public const DEFAULT_API_VERSION = '2024-09-01';

    public function __construct(
        private readonly string $endpoint,
        private readonly string $key,
        private readonly string $version,
        private readonly ServiceAdapter $adapter,
        private readonly TransformationPipeline $pipeline,
        ?ClientInterface $httpClient = null,
    ) {
        parent::__construct($httpClient);
    }

    public function check(string $text): Result
    {
        $endpoint = sprintf('/content/safety/text:analyze?api-version=%s', $this->version);
        $response = $this->post($endpoint, [
            'text' => $text,
            'categories' => [
                'Hate',
                'SelfHarm',
                'Sexual',
                'Violence',
            ],
            'outputType' => 'FourSeverityLevels',
            'blocklistsEnabled' => true,
        ], [
            'Ocp-Apim-Subscription-Key' => $this->key,
            'Content-Type' => 'application/json',
        ]);

        return $this->pipeline->process(
            $this->adapter->adapt($text, $response),
        );
    }

    protected function baseUri(): string
    {
        return mb_rtrim($this->endpoint, '/');
    }
}
