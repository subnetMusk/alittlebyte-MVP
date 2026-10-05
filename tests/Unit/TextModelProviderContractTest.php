<?php

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Ai\AiOutputValidator;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Ai\OllamaService;
use App\Mvp\Ai\TextModelPrompts;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use Aws\Sfn\SfnClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Contratto comune ai provider dei modelli testuali (ADR 0014): lo stesso
 * testo restituito dal modello deve produrre lo stesso risultato validato,
 * oppure lo stesso rifiuto, qualunque sia il trasporto. I prompt inviati sono
 * gli stessi.
 *
 * @return array{bedrock: BedrockService, ollama: OllamaService, prompts: ArrayObject<int, string>}
 */
function textModelProviders(string $modelText): array
{
    $prompts = new ArrayObject;

    $client = Mockery::mock(BedrockRuntimeClient::class);
    $client->shouldReceive('converse')->andReturnUsing(function (array $args) use ($modelText, $prompts) {
        $prompts->append($args['messages'][0]['content'][0]['text']);

        return new Result(['output' => ['message' => ['content' => [['text' => $modelText]]]]]);
    });

    Http::fake(function ($request) use ($modelText, $prompts) {
        $prompts->append($request['messages'][0]['content']);

        return Http::response(['message' => ['role' => 'assistant', 'content' => $modelText]]);
    });

    $heartbeat = new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class), app(MetricsRecorder::class));

    return [
        'bedrock' => new BedrockService($client, $client, 'model', null, new AiOutputValidator, $heartbeat),
        'ollama' => new OllamaService(app(HttpFactory::class), new AiOutputValidator, 'http://ollama.test', 'qwen3.5:9b', 5),
        'prompts' => $prompts,
    ];
}

dataset('model outputs', [
    'comunicazione in fence markdown' => [
        'generateCommunication', ['Ferie', 'Empatico', 'Testo informativo'],
        "```json\n{\"title\": \"Ferie\", \"body\": \"Riga uno\nRiga due\", \"imagePrompt\": \"calendar\"}\n```",
        fn () => TextModelPrompts::communication('Ferie', 'Empatico', 'Testo informativo'),
    ],
    'split con due destinatari' => [
        'splitDocument', ['testo', 2, 'n'],
        '[{"employee_name": "ROSSI MARIO", "start_page": 1, "end_page": 1}, {"employee_name": "BIANCHI LAURA", "start_page": 2, "end_page": 2}]',
        fn () => TextModelPrompts::splitDocument('testo', 2, 'n'),
    ],
    'estrazione con chiavi mancanti' => [
        'extractFields', ['testo'],
        'Ecco i campi: {"employee_first_name": "MARIO", "employee_last_name": "ROSSI", "confidence_score": 91}',
        fn () => TextModelPrompts::extractFields('testo'),
    ],
]);

test('Bedrock and Ollama return the same validated result for the same model output', function (string $method, array $args, string $modelText, Closure $expectedPrompt) {
    $providers = textModelProviders($modelText);

    $fromBedrock = $providers['bedrock']->{$method}(...$args);
    $fromOllama = $providers['ollama']->{$method}(...$args);

    expect($fromOllama)->toBe($fromBedrock)
        ->and($providers['prompts']->getArrayCopy())->toBe([$expectedPrompt(), $expectedPrompt()]);
})->with('model outputs');

test('Bedrock and Ollama reject the same invalid model output', function (string $method, array $args, string $modelText) {
    $providers = textModelProviders($modelText);

    expect(fn () => $providers['bedrock']->{$method}(...$args))->toThrow(InvalidAiOutputException::class)
        ->and(fn () => $providers['ollama']->{$method}(...$args))->toThrow(InvalidAiOutputException::class);
})->with([
    'non JSON' => ['generateCommunication', ['p', 'Empatico', 'Testo informativo'], 'Non posso aiutarti.'],
    'split con intervallo invertito' => ['splitDocument', ['t', 3, 'n'], '[{"employee_name": "A", "start_page": 3, "end_page": 1}]'],
    'estrazione con confidenza fuori scala' => ['extractFields', ['t'], '{"confidence_score": 140}'],
]);
