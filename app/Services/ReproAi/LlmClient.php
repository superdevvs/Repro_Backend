<?php

namespace App\Services\ReproAi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class LlmClient
{
    private Client $client;
    private ?string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?: env('OPENAI_API_KEY');
        $this->model = config('services.openai.model', env('OPENAI_MODEL', 'gpt-4o'));
        
        // Log if API key is missing (but don't throw yet, let chatCompletion handle it)
        if (empty($this->apiKey)) {
            Log::warning('OpenAI API key is not configured');
        }
        
        // Configure SSL verification
        // On Windows, cURL may not find the CA bundle, so we handle this gracefully
        $verify = true;
        $caBundlePath = null;
        
        // Try to find CA bundle in common locations
        $possiblePaths = [
            __DIR__ . '/../../cacert.pem', // Laravel project root
            base_path('cacert.pem'),
            ini_get('curl.cainfo'),
            ini_get('openssl.cafile'),
        ];
        
        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                $caBundlePath = $path;
                break;
            }
        }
        
        // If no CA bundle found and we're in development, allow insecure connections
        // (Only for development - NEVER in production!)
        $appEnv = config('app.env', env('APP_ENV', 'production'));
        if (!$caBundlePath && in_array($appEnv, ['local', 'development', 'dev', 'testing'])) {
            $verify = false;
            Log::warning('SSL verification disabled for development environment. CA bundle not found. This should NOT be used in production!');
        } elseif ($caBundlePath) {
            $verify = $caBundlePath;
            Log::info('Using CA bundle for SSL verification', ['path' => $caBundlePath]);
        }
        
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . ($this->apiKey ?? ''),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120, // Longer timeout for streaming
            'verify' => $verify, // SSL certificate verification
        ]);
    }

    /**
     * Send a chat completion request to OpenAI
     * 
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $tools Array of tool definitions for function calling
     * @param bool $stream Whether to stream the response
     * @return array|string Response from OpenAI (array for non-streaming, string for streaming chunks)
     * @throws \Exception
     */
    public function chatCompletion(array $messages, array $tools = [], bool $stream = true): array|string
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
        }

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2000,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        if ($stream) {
            $payload['stream'] = true;
            return $this->streamCompletion($payload);
        }

        try {
            $response = $this->client->post('chat/completions', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['choices'][0])) {
                throw new \Exception('Invalid response from OpenAI API');
            }

            return $data;
        } catch (GuzzleException $e) {
            $responseBody = '';
            if ($e->hasResponse()) {
                try {
                    $responseBody = $e->getResponse()->getBody()->getContents();
                } catch (\Exception $bodyError) {
                    $responseBody = 'Could not read response body';
                }
            }
            
            Log::error('OpenAI API request failed', [
                'error' => $e->getMessage(),
                'response_body' => $responseBody,
                'model' => $this->model,
                'has_api_key' => !empty($this->apiKey),
                'api_key_length' => strlen($this->apiKey ?? ''),
            ]);
            throw new \Exception('Failed to communicate with OpenAI: ' . $e->getMessage() . ($responseBody ? ' - ' . $responseBody : ''));
        }
    }

    /**
     * Stream completion response using Server-Sent Events
     * 
     * @param array $payload Request payload
     * @return string Streamed response chunks
     */
    private function streamCompletion(array $payload): string
    {
        try {
            $response = $this->client->post('chat/completions', [
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $fullContent = '';
            $toolCalls = [];

            while (!$stream->eof()) {
                $line = $stream->readLine();
                
                if (empty(trim($line))) {
                    continue;
                }

                // SSE format: "data: {...}"
                if (strpos($line, 'data: ') === 0) {
                    $data = substr($line, 6);
                    
                    if ($data === '[DONE]') {
                        break;
                    }

                    $decoded = json_decode($data, true);
                    
                    if (isset($decoded['choices'][0]['delta'])) {
                        $delta = $decoded['choices'][0]['delta'];
                        
                        // Handle content
                        if (isset($delta['content'])) {
                            $fullContent .= $delta['content'];
                        }

                        // Handle tool calls
                        if (isset($delta['tool_calls'])) {
                            foreach ($delta['tool_calls'] as $toolCall) {
                                $index = $toolCall['index'] ?? 0;
                                
                                if (!isset($toolCalls[$index])) {
                                    $toolCalls[$index] = [
                                        'id' => $toolCall['id'] ?? '',
                                        'type' => 'function',
                                        'function' => [
                                            'name' => $toolCall['function']['name'] ?? '',
                                            'arguments' => '',
                                        ],
                                    ];
                                }
                                
                                if (isset($toolCall['function']['arguments'])) {
                                    $toolCalls[$index]['function']['arguments'] .= $toolCall['function']['arguments'];
                                }
                            }
                        }
                    }
                }
            }

            // Return structured response
            $result = [
                'content' => $fullContent,
                'tool_calls' => !empty($toolCalls) ? array_values($toolCalls) : null,
            ];

            return json_encode($result);
        } catch (GuzzleException $e) {
            Log::error('OpenAI streaming request failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to stream from OpenAI: ' . $e->getMessage());
        }
    }

    /**
     * Parse streaming response chunks
     * 
     * @param string $chunk Raw SSE chunk
     * @return array|null Parsed chunk data or null if invalid
     */
    public function parseStreamChunk(string $chunk): ?array
    {
        if (strpos($chunk, 'data: ') === 0) {
            $data = substr($chunk, 6);
            
            if ($data === '[DONE]') {
                return ['done' => true];
            }

            $decoded = json_decode($data, true);
            
            if (isset($decoded['choices'][0]['delta'])) {
                return $decoded['choices'][0]['delta'];
            }
        }

        return null;
    }
}




