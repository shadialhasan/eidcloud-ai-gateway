<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Tests;

use EidCloud\AiGateway\Accounting\TokenLedger;
use EidCloud\AiGateway\Gateway;
use EidCloud\AiGateway\Health\HealthMonitor;
use EidCloud\AiGateway\Providers\MockProvider;
use EidCloud\AiGateway\Router\ProviderRouter;

class GatewayTest
{
    private int $passes = 0;
    private int $failures = 0;

    public function runAll(): void
    {
        echo "=========================================================\n";
        echo " 🧪 EidCloud AI Gateway - Zero-Dependency Test Suite\n";
        echo "=========================================================\n\n";

        $this->testBasicRouting();
        $this->testAliasResolution();
        $this->testAutomaticFailover();
        $this->testAllProvidersFailoverExhaustion();
        $this->testLoadBalancingRoundRobin();
        $this->testLoadBalancingWeighted();
        $this->testTokenLedgerAccounting();
        $this->testBudgetEnforcement();
        $this->testHealthMonitoring();
        $this->testStreamingSimulation();

        echo "\n---------------------------------------------------------\n";
        printf(" Test Results: %d Passed, %d Failed\n", $this->passes, $this->failures);
        echo "---------------------------------------------------------\n";

        if ($this->failures > 0) {
            exit(1);
        }
    }

    private function assert(string $description, bool $condition, string $failureDetails = ''): void
    {
        if ($condition) {
            $this->passes++;
            echo "  ✅ PASS: {$description}\n";
        } else {
            $this->failures++;
            echo "  ❌ FAIL: {$description} - {$failureDetails}\n";
        }
    }

    private function testBasicRouting(): void
    {
        echo "• Testing Direct Provider Routing...\n";
        $gateway = new Gateway();
        $mock = new MockProvider('ollama', false, 'Hello from Ollama!');
        $gateway->registerProvider($mock);

        $response = $gateway->handleChatCompletion([
            'model' => 'ollama/llama3.2',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);

        $this->assert("Returns 200 OK OpenAI format", ($response['object'] ?? '') === 'chat.completion');
        $this->assert("Contains choice content", ($response['choices'][0]['message']['content'] ?? '') === 'Hello from Ollama!');
        $this->assert("Metadata matches provider", ($response['gateway']['provider'] ?? '') === 'ollama');
        $this->assert("Call count was exactly 1", $mock->getCallCount() === 1);
    }

    private function testAliasResolution(): void
    {
        echo "• Testing Model Alias Resolution...\n";
        $router = new ProviderRouter();
        $mockOllama = new MockProvider('ollama');
        $mockOpenAi = new MockProvider('openai');
        $router->registerProvider($mockOllama);
        $router->registerProvider($mockOpenAi);

        $router->setAlias('coder', ['ollama/qwen2.5-coder', 'openai/gpt-4o-mini']);
        $candidates = $router->resolveCandidates('coder');

        $this->assert("Resolves 2 candidate targets", count($candidates) === 2);
        $this->assert("Target 1 is ollama/qwen2.5-coder", $candidates[0]['targetKey'] === 'ollama/qwen2.5-coder');
        $this->assert("Target 2 is openai/gpt-4o-mini", $candidates[1]['targetKey'] === 'openai/gpt-4o-mini');
    }

    private function testAutomaticFailover(): void
    {
        echo "• Testing Automatic Failover...\n";
        $gateway = new Gateway();
        $primary = new MockProvider('ollama', true, '', 'Connection timeout');
        $secondary = new MockProvider('openai', false, 'Fallback succeeded');

        $gateway->registerProvider($primary);
        $gateway->registerProvider($secondary);
        $gateway->setAlias('general', ['ollama/llama3.2', 'openai/gpt-4o-mini']);

        $response = $gateway->handleChatCompletion([
            'model' => 'general',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $this->assert("Primary failed and secondary succeeded", ($response['choices'][0]['message']['content'] ?? '') === 'Fallback succeeded');
        $this->assert("Gateway routed to fallback provider", ($response['gateway']['provider'] ?? '') === 'openai');
        $this->assert("Primary was attempted once", $primary->getCallCount() === 1);
        $this->assert("Secondary was executed once", $secondary->getCallCount() === 1);
    }

    private function testAllProvidersFailoverExhaustion(): void
    {
        echo "• Testing Full Failover Exhaustion Exception...\n";
        $gateway = new Gateway();
        $p1 = new MockProvider('p1', true, '', 'p1 down');
        $p2 = new MockProvider('p2', true, '', 'p2 rate limit');

        $gateway->registerProvider($p1);
        $gateway->registerProvider($p2);
        $gateway->setAlias('test', ['p1/m1', 'p2/m2']);

        $threw = false;
        try {
            $gateway->handleChatCompletion(['model' => 'test', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assert("Exception details explain candidate exhaustion", str_contains($e->getMessage(), 'All failover targets exhausted'));
        }

        $this->assert("Throws RuntimeException on total failure", $threw);
    }

    private function testLoadBalancingRoundRobin(): void
    {
        echo "• Testing Round-Robin Load Balancing...\n";
        $router = new ProviderRouter();
        $p1 = new MockProvider('node1');
        $p2 = new MockProvider('node2');
        $router->registerProvider($p1);
        $router->registerProvider($p2);

        $router->setAlias('cluster', ['node1/m1', 'node2/m2']);

        $res1 = $router->resolveCandidates('cluster', 'round-robin');
        $res2 = $router->resolveCandidates('cluster', 'round-robin');
        $res3 = $router->resolveCandidates('cluster', 'round-robin');

        $this->assert("Turn 1 leads with node1", $res1[0]['provider']->getName() === 'node1');
        $this->assert("Turn 2 leads with node2", $res2[0]['provider']->getName() === 'node2');
        $this->assert("Turn 3 wraps back to node1", $res3[0]['provider']->getName() === 'node1');
    }

    private function testLoadBalancingWeighted(): void
    {
        echo "• Testing Weighted Load Balancing...\n";
        $router = new ProviderRouter();
        $heavy = new MockProvider('heavy');
        $light = new MockProvider('light');

        $router->registerProvider($heavy, 9999);
        $router->registerProvider($light, 1);
        $router->setAlias('weighted-cluster', ['heavy/m', 'light/m']);

        $heavyWins = 0;
        for ($i = 0; $i < 50; $i++) {
            $cand = $router->resolveCandidates('weighted-cluster', 'weighted');
            if ($cand[0]['provider']->getName() === 'heavy') {
                $heavyWins++;
            }
        }

        $this->assert("Heavy provider receives vast majority of queries (>40/50)", $heavyWins >= 40, "Heavy wins: {$heavyWins}");
    }

    private function testTokenLedgerAccounting(): void
    {
        echo "• Testing Token Ledger Accounting...\n";
        $tempFile = sys_get_temp_dir() . '/test_ledger_' . uniqid() . '.json';
        $ledger = new TokenLedger($tempFile);

        $ledger->recordUsage('openai', 'gpt-4o-mini', 1000, 500);
        $summary = $ledger->getSummary();

        $this->assert("Records 1 request", $summary['total_requests'] === 1);
        $this->assert("Records 1500 total tokens", $summary['total_tokens'] === 1500);
        $this->assert("Calculates cost > 0", $summary['total_cost_usd'] > 0);
        $this->assert("Persists to file correctly", file_exists($tempFile));

        @unlink($tempFile);
    }

    private function testBudgetEnforcement(): void
    {
        echo "• Testing Budget Limit Enforcement...\n";
        $gateway = new Gateway(['budget_limit_usd' => 0.0001]);
        $mock = new MockProvider('openai', false, 'Answer');
        $gateway->registerProvider($mock);

        // Record high usage to trip budget
        $gateway->getLedger()->recordUsage('openai', 'gpt-4o-mini', 2000, 2000);

        $blocked = false;
        try {
            $gateway->handleChatCompletion([
                'model' => 'openai/gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ]);
        } catch (\RuntimeException $e) {
            $blocked = str_contains($e->getMessage(), 'Budget limit reached');
        }

        $this->assert("Blocks request when budget is exceeded", $blocked);
    }

    private function testHealthMonitoring(): void
    {
        echo "• Testing Provider Health Monitoring...\n";
        $monitor = new HealthMonitor();
        $okProvider = new MockProvider('ok_node', false);
        $deadProvider = new MockProvider('dead_node', true);

        $monitor->addProvider($okProvider);
        $monitor->addProvider($deadProvider);

        $all = $monitor->checkAll();

        $this->assert("OK node reports healthy", $all['ok_node']['status'] === 'healthy');
        $this->assert("Dead node reports unreachable", $all['dead_node']['status'] === 'unreachable');
        $this->assert("Contains latency in ms", isset($all['ok_node']['latency_ms']));
    }

    private function testStreamingSimulation(): void
    {
        echo "• Testing Streaming Output Simulation...\n";
        $gateway = new Gateway();
        $mock = new MockProvider('ollama', false, 'First Second Third');
        $gateway->registerProvider($mock);

        $chunks = [];
        $gateway->handleStreamChatCompletion([
            'model' => 'ollama/llama3',
            'messages' => [['role' => 'user', 'content' => 'Say 3 words']],
        ], function(string $chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        $this->assert("Received SSE chunks", count($chunks) > 0);
        $lastChunk = end($chunks);
        $this->assert("SSE terminates with [DONE]", str_contains($lastChunk, 'data: [DONE]'));
    }
}
