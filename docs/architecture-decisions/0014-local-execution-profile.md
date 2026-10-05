# ADR 0014: Profilo di esecuzione locale per AI e OCR

Status: Accepted, implemented
Date: 2026-10-05

Decisione presa sul fork personale dopo la chiusura del progetto di gruppo: non fa parte dell'MVP
consegnato dal team.

## Context

L'MVP usa servizi AWS reali per tre passi: Bedrock (Amazon Nova Lite) per la generazione delle
comunicazioni, lo split e l'estrazione dei campi; Textract per l'OCR; Bedrock per le copertine.
Senza credenziali AWS e accesso ai modelli, i due flussi principali non arrivano in fondo: l'OCR è
spento di default e la pipeline documentale si ferma prima dell'analisi.

Le integrazioni stanno già dietro porte di dominio ([ADR 0010](0010-hexagonal-architecture-documents-communications.md)):
`OcrGatewayPort`, `DocumentAiGatewayPort` e `CommunicationAiGatewayPort`. Il layer applicativo
dipende solo da queste, e il binding porta → adapter avviene in `AppServiceProvider`.

## Decision

Introdurre un profilo di esecuzione, `MVP_EXECUTION_PROFILE`, con due valori:

| Porta | `standard` (default, MVP) | `local` |
| --- | --- | --- |
| `DocumentAiGatewayPort` | `BedrockDocumentAiAdapter` | `OllamaDocumentAiAdapter` (Qwen tramite Ollama) |
| `CommunicationAiGatewayPort` | `BedrockCommunicationAiAdapter` | `LocalCommunicationAiAdapter`: testo da Ollama, copertina da `CoverImageGenerator` |
| `OcrGatewayPort` | `TextractOcrAdapter` | `LocalPdfOcrAdapter`: `pdftotext` per il text layer, `pdftoppm` più Tesseract per le scansioni |

- **Il profilo si sceglie solo nel composition root.** Dominio, applicazione e adapter non lo
  leggono; un test lo verifica cercando i riferimenti nel codice.
- **Stesso contratto per i modelli testuali.** I prompt (`TextModelPrompts`), la decodifica del JSON
  (`ModelJsonResponse`) e la validazione (`AiOutputValidator`) sono condivisi fra Bedrock e Ollama.
  Ollama riceve come `format` lo stesso JSON Schema che il validatore applica dopo: la modalità JSON
  generica impone un oggetto alla radice e lo split, che è un array, perdeva i destinatari successivi
  al primo. Nello schema passato a Ollama tutte le chiavi dichiarate sono obbligatorie, anche se
  annullabili: con le chiavi facoltative la decodifica vincolata permette al modello di ometterle, e
  il modello saltava email, codice fiscale e matricola pur presenti nel testo.
- **Stessa forma per l'OCR.** L'adapter locale restituisce pagine e righe con confidenza come
  Textract, così la confidenza per campo ([ADR 0013](0013-per-field-ocr-confidence.md)) funziona
  invariata. Il testo di un text layer non è riconosciuto e porta confidenza 100.
- **Copertina con una strategia di adapter.** `LOCAL_COVER_PROVIDER=mock` (default) produce una
  copertina deterministica, con palette per tono e motivo per stile; `comfyui` la genera con un server
  ComfyUI locale eseguendo un workflow in formato API, modelli compresi, che è configurazione di
  deployment: il repository ne versiona uno per Z-Image-Turbo in GGUF. Il profilo locale funziona per
  intero anche senza ComfyUI.
- **Nessun ripiego fra provider.** Un profilo o un provider di copertina non validi fermano l'avvio;
  un errore di Ollama fa fallire la generazione come un errore di Bedrock
  ([ADR 0005](0005-no-automatic-fallbacks.md)); un errore di ComfyUI degrada la copertina con un
  motivo esplicito, come oggi per Bedrock.
- Step Functions, SQS, DLQ, task token, heartbeat, idempotenza, SSE, persistenza e contratto HTTP
  non cambiano: cambiano solo gli adapter esterni.

## Consequences

- I flussi AI Assistant e Co-Pilot girano senza credenziali AWS per AI e OCR, con un modello
  testuale e un OCR eseguiti sulla macchina.
- Per non duplicare prompt e parsing, `BedrockService` delega a `TextModelPrompts`,
  `ModelJsonResponse` e `CoverImagePrompts`; un test verifica che Bedrock e Ollama producano lo stesso
  risultato validato dallo stesso testo del modello.
- L'immagine di sviluppo include `poppler-utils` e Tesseract (italiano e inglese); quella di
  produzione no.
- L'OCR locale ha metriche proprie (`mvp_local_ocr_*`) e una sezione nella dashboard `AI and OCR
  Quality`; le metriche Textract restano di Textract.
- Il provider ComfyUI ha un tempo massimo di 270 secondi, sotto il timeout del task `GenerateCover`
  (300 secondi, con un retry su timeout): una generazione più lunga degrada la copertina invece di
  farne partire una seconda.
- Limiti noti: il messaggio di fallimento dell'OCR scritto dal caso d'uso cita ancora Textract; una
  copertina mock è etichettata "Generata dall'AI", perché il dominio distingue solo copertine
  generate e caricate a mano; la qualità dell'estrazione dipende dal modello locale scelto.

## Alternatives considered

- **Qwen dietro l'emulazione Bedrock di LocalStack**: scartata, perché non usa la GPU in modo
  efficiente e aggiunge uno strato senza valore. L'adapter parla con Ollama e implementa la stessa
  porta.
- **Una nuova porta di dominio per la copertina**: scartata, perché avrebbe richiesto di modificare il
  caso d'uso `GenerateCommunicationCoverService`. La generazione dell'immagine resta un'operazione di
  `CommunicationAiGatewayPort`, e la scelta del generatore locale è una strategia interna all'adapter.
- **Un modello multimodale per l'OCR**: scartata, perché l'OCR deve essere deterministico e
  verificabile.
- **Ripiego automatico su un provider locale quando AWS non risponde**: scartato, perché contraddice
  l'[ADR 0005](0005-no-automatic-fallbacks.md).

## Implementation evidence

- Composition root: `app/Providers/AppServiceProvider.php`; configurazione in `config/mvp.php`
  (`execution_profile`) e `config/services.php` (`local_llm`, `local_ocr`, `local_cover`); enum
  `app/Mvp/Support/ExecutionProfile.php` e `LocalCoverProvider.php`.
- Client e strategie: `app/Mvp/Ai/OllamaService.php`, `TextModelPrompts.php`, `ModelJsonResponse.php`,
  `CoverImagePrompts.php`, `app/Mvp/Ai/Cover/`.
- Adapter: `app/Mvp/Documents/Adapters/Outbound/Ai/OllamaDocumentAiAdapter.php`,
  `app/Mvp/Documents/Adapters/Outbound/Ocr/LocalPdfOcrAdapter.php`,
  `app/Mvp/Communications/Adapters/Outbound/Ai/LocalCommunicationAiAdapter.php`.
- Workflow ComfyUI versionato: `resources/ai/comfyui/z-image-turbo.json`.
- Test: `tests/Unit/ExecutionProfileBindingTest.php`, `ExecutionProfileBoundaryTest.php`,
  `TextModelProviderContractTest.php`, `OllamaServiceTest.php`, `LocalPdfOcrAdapterTest.php`,
  `CoverImageGeneratorTest.php`.

## Related documents

- [`../runbooks/local-development.md`](../runbooks/local-development.md#profilo-di-esecuzione-locale):
  come attivarlo.
- [`../architecture/backend-hexagonal.md`](../architecture/backend-hexagonal.md): porte e adapter.
