<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Health;

use EidCloud\AiGateway\Providers\ProviderInterface;

class HealthMonitor
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /** @var array<string, array{status: string, latency_ms: float, last_checked: int, error: ?string}> */
    private array $statusCache = [];

    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    public function addProvider(ProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    /**
     * Run health checks on all registered providers.
     *
     * @return array<string, array{status: string, latency_ms: float, last_checked: int, error: ?string}>
     */
    public function checkAll(): array
    {
        $results = [];
        foreach ($this->providers as $name => $provider) {
            $results[$name] = $this->checkOne($name);
        }
        $this->statusCache = $results;
        return $results;
    }

    /**
     * Check health of a single provider.
     */
    public function checkOne(string $name): array
    {
        if (!isset($this->providers[$name])) {
            return [
                'status' => 'unknown',
                'latency_ms' => 0.0,
                'last_checked' => time(),
                'error' => "Provider [{$name}] not registered",
            ];
        }

        $provider = $this->providers[$name];
        $start = microtime(true);
        $error = null;
        $healthy = false;

        try {
            $healthy = $provider->checkHealth();
            if (!$healthy) {
                $error = 'Service reported unhealthy or unauthenticated';
            }
        } catch (\Throwable $e) {
            $healthy = false;
            $error = $e->getMessage();
        }

        $latency = round((microtime(true) - $start) * 1000, 2);

        $record = [
            'status' => $healthy ? 'healthy' : 'unreachable',
            'latency_ms' => $latency,
            'last_checked' => time(),
            'error' => $error,
        ];

        $this->statusCache[$name] = $record;
        return $record;
    }

    public function getCachedStatus(): array
    {
        return $this->statusCache;
    }
}
