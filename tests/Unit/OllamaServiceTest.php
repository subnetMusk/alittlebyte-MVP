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
        && $request['format']['properties'] === (new AiOutputValidator)->schema('generate-communication')['properties']
        && $request['format']['required'] === ['title', 'body', 'imagePrompt']);
});

test('extractFields asks for every declared key, still nullable, and keeps the tolerant validation', function () {
    // Con le chiavi facoltative la decodifica vincolata permette al modello di
    // ometterle, e il modello saltava email, codice fiscale e matricola.
    Http::fake(['*' => Http::response(ollamaReply(['employee_first_name' => 'MARIO']))]);

    $fields = makeOllamaService()->extractFields('testo');

    $contract = (new AiOutputValidator)->schema('extract-fields');

    expect($contract)->not->toHaveKey('required')
        ->and($fields['employee_first_name'])->toBe('MARIO')
        ->and($fields['fiscal_code'])->toBeNull();

    Http::assertSent(fn (Request $request) => $request['format']['required'] === array_keys($contract['properties'])
        && $request['format']['properties']['fiscal_code']['type'] === ['string', 'null']);
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

test('keep_alive is sent only when configured, as seconds or as a duration', function (?string $keepAlive, mixed $expected) {
    Http::fake(['*' => Http::response(ollamaReply(['employee_first_name' => 'MARIO']))]);

    (new OllamaService(app(HttpFactory::class), new AiOutputValidator, 'http://ollama.test:11434', 'qwen3.5:9b', 5, $keepAlive))->extractFields('testo');

    Http::assertSent(fn (Request $request) => $expected === null
        ? ! array_key_exists('keep_alive', $request->data())
        : $request['keep_alive'] === $expected);
})->with([
    'default di Ollama' => [null, null],
    'vuoto' => ['', null],
    'scarica subito' => ['0', 0],
    'secondi' => ['30', 30],
    'durata' => ['5m', '5m'],
]);

test('a missing model or an invalid base url fails before any request', function (string $model, string $baseUrl) {
    Http::fake();

    expect(fn () => makeOllamaService($model, $baseUrl)->extractFields('testo'))
        ->toThrow(RuntimeException::class, 'Modello locale non configurato');

    Http::assertNothingSent();
})->with([
    'modello vuoto' => ['', 'http://ollama.test:11434'],
    'url senza schema' => ['qwen3.5:9b', 'ollama.test:11434'],
]);
