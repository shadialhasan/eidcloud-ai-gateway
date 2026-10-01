<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Server;

use EidCloud\AiGateway\Gateway;

class HttpServer
{
    private Gateway $gateway;
    private string $host;
    private int $port;

    public function __construct(Gateway $gateway, string $host = '127.0.0.1', int $port = 8000)
    {
        $this->gateway = $gateway;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Start the PHP built-in web server or process standard SAPI/CLI server requests.
     */
    public function run(): void
    {
        $scriptPath = __DIR__ . '/router_script.php';
        $cmd = sprintf('%s -S %s:%d %s', escapeshellarg(PHP_BINARY), escapeshellarg($this->host), $this->port, escapeshellarg($scriptPath));

        echo "🚀 EidCloud AI Gateway running on http://{$this->host}:{$this->port}\n";
        echo "📡 OpenAI API Compatible Endpoint: http://{$this->host}:{$this->port}/v1/chat/completions\n";
        echo "📊 Diagnostics & Health: http://{$this->host}:{$this->port}/health\n";
        echo "💰 Token Ledger & Accounting: http://{$this->host}:{$this->port}/v1/accounting\n";
        echo "Press Ctrl+C to stop.\n\n";

        passthru($cmd);
    }

    /**
     * Handle incoming request directly (invoked by router_script.php).
     */
    public function handleRequest(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        try {
            if ($uri === '/health') {
                $this->respondJson($this->gateway->getHealthMonitor()->checkAll());
                return;
            }

            if ($uri === '/v1/models' && $method === 'GET') {
                $aliases = $this->gateway->getRouter()->getAliases();
                $models = [];
                foreach ($aliases as $alias => $targets) {
                    $models[] = [
                        'id' => $alias,
                        'object' => 'model',
                        'created' => time(),
                        'owned_by' => 'eidcloud-gateway',
                        'targets' => $targets,
                    ];
                }
                $this->respondJson(['object' => 'list', 'data' => $models]);
                return;
            }

            if ($uri === '/v1/accounting' && $method === 'GET') {
                $this->respondJson($this->gateway->getLedger()->getSummary());
                return;
            }

            if ($uri === '/v1/chat/completions' && $method === 'POST') {
                $rawBody = file_get_contents('php://input');
                $payload = json_decode($rawBody ?: '{}', true);

                if (!is_array($payload) || empty($payload['messages'])) {
                    http_response_code(400);
                    $this->respondJson([
                        'error' => [
                            'message' => 'Invalid JSON payload or missing "messages" array',
                            'type' => 'invalid_request_error',
                            'code' => 400
                        ]
                    ]);
                    return;
                }

                $isStream = !empty($payload['stream']);
                if ($isStream) {
                    header('Content-Type: text/event-stream');
                    header('Cache-Control: no-cache');
                    header('Connection: keep-alive');
                    header('X-Accel-Buffering: no');

                    $this->gateway->handleStreamChatCompletion($payload, function(string $chunk) {
                        echo $chunk;
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    });
                    exit;
                }

                $response = $this->gateway->handleChatCompletion($payload);
                $this->respondJson($response);
                return;
            }

            // Root info
            if ($uri === '/' || $uri === '') {
                $this->respondJson([
                    'service' => 'EidCloud AI Gateway',
                    'version' => Gateway::VERSION,
                    'status' => 'operational',
                    'routes' => [
                        'POST /v1/chat/completions' => 'OpenAI-compatible chat completion proxy',
                        'GET /v1/models' => 'List available gateway models and aliases',
                        'GET /v1/accounting' => 'Token usage and financial accounting ledger',
                        'GET /health' => 'Live provider health check monitor',
                    ]
                ]);
                return;
            }

            http_response_code(404);
            $this->respondJson([
                'error' => [
                    'message' => "Route '{$method} {$uri}' not found",
                    'type' => 'not_found',
                    'code' => 404
                ]
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            $this->respondJson([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'gateway_error',
                    'code' => 500
                ]
            ]);
        }
    }

    private function respondJson(mixed $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
