<?php

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Ai\AiOutputValidator;
use App\Mvp\Ai\OllamaService;
use App\Mvp\Ai\TextModelPrompts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeOllamaService(string $model = 'qwen3.5:9b', string $baseUrl = 'http://ollama.test:11434'): OllamaService
{
    return new OllamaService(app(HttpFactory::class), new AiOutputValidator, $baseUrl, $model, 5);
}

function ollamaReply(mixed $content): array
{
    return ['model' => 'qwen3.5:9b', 'message' => ['role' => 'assistant', 'content' => is_string($content) ? $content : json_encode($content)], 'done' => true];
}

test('generateCommunication sends the shared prompt and returns the validated draft', function () {
    Http::fake(['ollama.test:11434/api/chat' => Http::response(ollamaReply([
        'title' => 'Nuovo calendario ferie',
        'body' => "Primo paragrafo.\n\nSecondo paragrafo.",
        'imagePrompt' => 'calm office with a calendar',
    ]))]);

    $result = makeOllamaService()->generateCommunication('Ferie 2027', 'Empatico', 'Testo informativo');

    expect($result)->toBe([
        'title' => 'Nuovo calendario ferie',
        'body' => "Primo paragrafo.\n\nSecondo paragrafo.",
        'image_prompt' => 'calm office with a calendar',
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ollama.test:11434/api/chat'
        && $request['model'] === 'qwen3.5:9b'
        && $request['stream'] === false
        && $request['think'] === false
        && $request['messages'][0]['content'] === TextModelPrompts::communication('Ferie 2027', 'Empatico', 'Testo informativo')
        && $request['format'] === (new AiOutputValidator)->schema('generate-communication'));
});

test('splitDocument constrains the output with the array schema of the contract', function () {
    Http::fake(['*' => Http::response(ollamaReply([
        ['employee_name' => 'ROSSI MARIO', 'start_page' => 1, 'end_page' => 1],
        ['employee_name' => 'BIANCHI LAURA', 'start_page' => 2, 'end_page' => 2],
    ]))]);

    $segments = makeOllamaService()->splitDocument('testo', 2, 'nonce');

    expect($segments)->toHaveCount(2)
        ->and($segments[1]['employee_name'])->toBe('BIANCHI LAURA');

    // Con la modalita' JSON generica Ollama impone un oggetto alla radice e lo
    // split perde i destinatari successivi al primo: serve lo schema.
    Http::assertSent(fn (Request $request) => ($request['format']['type'] ?? null) === 'array');
});

test('an output that breaks the contract is rejected by the same validator as Bedrock', function () {
    Http::fake(['*' => Http::response(ollamaReply(['title' => 'Solo titolo']))]);

    expect(fn () => makeOllamaService()->generateCommunication('p', 'Empatico', 'Testo informativo'))
        ->toThrow(InvalidAiOutputException::class);
});

test('an unreachable Ollama surfaces as a RuntimeException with an operator message', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    try {
        makeOllamaService()->extractFields('testo');
        $this->fail('Ollama non raggiungibile doveva sollevare un errore.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('non raggiungibile')
            ->and(OllamaService::formatUserError($e, 'default'))->toContain('verifica che Ollama sia avviato');
    }
});

test('a model missing from Ollama is reported with the pull hint', function () {
    Http::fake(['*' => Http::response(['error' => "model 'qwen3.5:9b' not found"], 404)]);

    try {
        makeOllamaService()->extractFields('testo');
        $this->fail('Un modello assente doveva sollevare un errore.');
    } catch (RuntimeException $e) {
        expect(OllamaService::formatUserError($e, 'default'))->toContain('ollama pull');
    }
});

test('a missing model or an invalid base url fails before any request', function (string $model, string $baseUrl) {
    Http::fake();

    expect(fn () => makeOllamaService($model, $baseUrl)->extractFields('testo'))
        ->toThrow(RuntimeException::class, 'Modello locale non configurato');

    Http::assertNothingSent();
})->with([
    'modello vuoto' => ['', 'http://ollama.test:11434'],
    'url senza schema' => ['qwen3.5:9b', 'ollama.test:11434'],
]);
