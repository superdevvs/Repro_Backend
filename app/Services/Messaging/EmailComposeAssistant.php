<?php

namespace App\Services\Messaging;

use App\Services\ReproAi\LlmClient;

class EmailComposeAssistant
{
    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, body: string}
     */
    public function draft(string $mode, ?string $instruction, string $subject, string $body, array $variables): array
    {
        $response = $this->llm->chatCompletion([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($mode, $instruction, $subject, $body, $variables)],
        ], [], false, [
            'temperature' => 0.4,
            'usage_feature' => 'email_assistant',
            'max_tokens' => 900,
        ]);

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');

        $draft = $this->parse($content, $instruction);
        if ($mode === 'subject' && trim($body) !== '') {
            $draft['body'] = $body;

            return $draft;
        }
        $greeting = app(EmailRecipientGreeting::class);
        $draft['body'] = in_array($mode, ['write', 'from_shoot'], true)
            ? $greeting->forNewDraft($draft['body'], $instruction)
            : $greeting->preserveOpening($body, $draft['body']);

        return $draft;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You write outbound email for R/E Pro Photos, a real-estate photography company.
The user message is an instruction to you. Never paste that instruction into the email.
Return only JSON with this shape: {"subject":"...","body":"..."}
body is plain text the recipient will read, with a greeting, the point, and a short close. Use blank lines between paragraphs.
For write and from_shoot, open with exactly Hi {{recipient_first_name}}, on its own line. This field is filled separately for every recipient at delivery. Never invent a name, use an example name from context, or write [First Name]. Omit the greeting when explicitly requested, and honor an explicitly requested generic greeting without adding a name.
For shorter, warmer, clearer, and subject, preserve the existing greeting and every {{personalization_field}} exactly. Keep subject changes focused on the subject.
This composer writes plain email copy. Do not generate HTML, CSS, or a designed layout; custom layouts are created in the Email Template Workspace.
Do not use markdown, labels, or commentary.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function userPrompt(string $mode, ?string $instruction, string $subject, string $body, array $variables): string
    {
        $context = json_encode([
            'mode' => $mode,
            'instruction' => $instruction,
            'current_subject' => $subject,
            'current_body' => $body,
            'variables' => $variables,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "Write the email for this request:\n".$context;
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function parse(string $content, ?string $instruction): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Robbie did not return an email.');
        }

        $subject = trim((string) ($decoded['subject'] ?? ''));
        $body = trim((string) ($decoded['body'] ?? ''));
        if ($subject === '' || $body === '') {
            throw new \RuntimeException('Robbie did not return an email.');
        }

        $request = trim((string) $instruction);
        if ($request !== '' && strcasecmp($body, $request) === 0) {
            throw new \RuntimeException('Robbie repeated the request instead of writing the email.');
        }

        return ['subject' => $subject, 'body' => $body];
    }
}
