<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Router;

use EidCloud\AiGateway\Providers\ProviderInterface;

class ProviderRouter
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /** @var array<string, list<string>> Model alias mappings, e.g. 'coder' => ['ollama/qwen2.5-coder', 'openai/gpt-4o-mini'] */
    private array $modelAliases = [];

    /** @var array<string, int> Provider load-balancing weights, e.g. ['ollama' => 5, 'openai' => 1] */
    private array $weights = [];

    /** @var array<string, int> Round robin counters per target */
    private array $roundRobinIndex = [];

    public function __construct()
    {
    }

    public function registerProvider(ProviderInterface $provider, int $weight = 1): self
    {
        $this->providers[$provider->getName()] = $provider;
        $this->weights[$provider->getName()] = max(1, $weight);
        return $this;
    }

    public function getProvider(string $name): ?ProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    public function setAlias(string $alias, array $targets): self
    {
        $this->modelAliases[$alias] = $targets;
        return $this;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getAliases(): array
    {
        return $this->modelAliases;
    }

    public function setWeight(string $providerName, int $weight): self
    {
        $this->weights[$providerName] = max(1, $weight);
        return $this;
    }

    public function getWeight(string $providerName): int
    {
        return $this->weights[$providerName] ?? 1;
    }

    /**
     * Resolve requested model/alias into ordered list of candidate targets:
     * Each candidate is array{provider: ProviderInterface, model: string, targetKey: string}
     *
     * @param string $requestedModel
     * @param string $strategy 'failover' | 'round-robin' | 'weighted'
     * @return list<array{provider: ProviderInterface, model: string, targetKey: string}>
     */
    public function resolveCandidates(string $requestedModel, string $strategy = 'failover'): array
    {
        // Check if requestedModel is an alias
        $targets = $this->modelAliases[$requestedModel] ?? [$requestedModel];

        // Expand targets into valid provider + model pairs
        $candidates = [];
        foreach ($targets as $target) {
            $parsed = $this->parseTarget($target);
            if ($parsed !== null) {
                $candidates[] = $parsed;
            }
        }

        if (empty($candidates)) {
            // Fallback: try finding any registered provider
            foreach ($this->providers as $p) {
                $candidates[] = [
                    'provider' => $p,
                    'model' => $requestedModel,
                    'targetKey' => $p->getName() . '/' . $requestedModel,
                ];
            }
        }

        if (count($candidates) <= 1) {
            return $candidates;
        }

        return match ($strategy) {
            'round-robin' => $this->applyRoundRobin($requestedModel, $candidates),
            'weighted' => $this->applyWeighted($candidates),
            default => $candidates, // Standard ordered failover
        };
    }

    private function parseTarget(string $target): ?array
    {
        $parts = explode('/', $target, 2);
        if (count($parts) === 2 && isset($this->providers[$parts[0]])) {
            return [
                'provider' => $this->providers[$parts[0]],
                'model' => $parts[1],
                'targetKey' => $target,
            ];
        }

        // Target might just be a provider name or default model
        if (isset($this->providers[$target])) {
            return [
                'provider' => $this->providers[$target],
                'model' => 'default',
                'targetKey' => $target . '/default',
            ];
        }

        // Or model specified without provider, try to match registered provider
        foreach ($this->providers as $provider) {
            return [
                'provider' => $provider,
                'model' => $target,
                'targetKey' => $provider->getName() . '/' . $target,
            ];
        }

        return null;
    }

    /**
     * @param string $aliasKey
     * @param list<array{provider: ProviderInterface, model: string, targetKey: string}> $candidates
     * @return list<array{provider: ProviderInterface, model: string, targetKey: string}>
     */
    private function applyRoundRobin(string $aliasKey, array $candidates): array
    {
        $count = count($candidates);
        $curr = $this->roundRobinIndex[$aliasKey] ?? 0;
        $offset = $curr % $count;
        $this->roundRobinIndex[$aliasKey] = ($curr + 1) % $count;

        // Rotate array so offset is first, remaining follow (for failover)
        return array_merge(array_slice($candidates, $offset), array_slice($candidates, 0, $offset));
    }

    /**
     * @param list<array{provider: ProviderInterface, model: string, targetKey: string}> $candidates
     * @return list<array{provider: ProviderInterface, model: string, targetKey: string}>
     */
    private function applyWeighted(array $candidates): array
    {
        $pool = [];
        foreach ($candidates as $cand) {
            $provName = $cand['provider']->getName();
            $w = $this->weights[$provName] ?? 1;
            for ($i = 0; $i < $w; $i++) {
                $pool[] = $cand;
            }
        }

        if (empty($pool)) {
            return $candidates;
        }

        $chosen = $pool[array_rand($pool)];
        // Put chosen candidate first, keep remainder for fallback
        $ordered = [$chosen];
        foreach ($candidates as $cand) {
            if ($cand['targetKey'] !== $chosen['targetKey']) {
                $ordered[] = $cand;
            }
        }
        return $ordered;
    }
}
