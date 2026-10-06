# ADR 0010: Architettura esagonale (ports & adapters) per i domini Documents e Communications

Status: Accepted, implemented
Date: 2026-08-07

## Context

Il backend (Laravel 12 / PHP 8.4, API-only) era organizzato per dominio applicativo
(`app/Mvp/{Ai,Ocr,Documents,Communications,Workflow,Identity,Audit,Observability,Support}`), ma
dentro le stesse classi convivevano regole di business, Eloquent, SDK AWS e HTTP. Nulla impediva la
mescolanza: era solo scoraggiata dall'uso. Esempi presi dal codice di allora:

- `DocumentController::index()` costruiva query Eloquent con `whereHas()` annidate per i quattro
  filtri (UC-35..UC-38), compreso l'operatore di confidenza calcolato inline.
- `DocumentController::stream()` conteneva un intero loop di polling SSE, circa 100 righe di logica
  applicativa nel livello HTTP.
- `CommunicationController` applicava regole di dominio (preferito già impostato → 422) e cancellava
  le copertine chiamando `Storage::disk()` direttamente.
- `DocumentProcessingService`, `CommunicationWorkflowService` e i due `WorkflowTaskHandler`
  combinavano scritture Eloquent, chiamate all'SDK AWS, regole di business, audit, metriche e
  storage.
- `AppServiceProvider` legava ogni servizio alla classe concreta: il codice digitava
  `BedrockService`, `TextractService` o `SfnClient`, mai un'astrazione definita dal dominio.

Tre elementi andavano già nella direzione giusta: `WorkflowTaskHandler`, un'interfaccia reale con
due implementazioni scelte a runtime da `WorkflowTaskRegistry`; `BedrockService` e
`TextractService`, adapter nella sostanza anche se non nella forma; la catena di middleware HTTP.

Le integrazioni esterne da isolare sono concrete: due client Bedrock (testo e immagine, anche in
regioni diverse), Textract, due state machine Step Functions con callback a task token, due coppie
di code SQS con DLQ, SSM Parameter Store e Secrets Manager. L'obiettivo "stesso codice su AWS reale
o emulato" era vero solo per endpoint e credenziali, non per adapter sostituibili.

## Decision

Adottare l'architettura esagonale (ports & adapters) applicando la **Dependency Rule**: le
dipendenze del codice sorgente puntano sempre verso il dominio, mai il contrario.

- Il **dominio** (entità, regole, porte) non importa `Illuminate\*`, Eloquent, l'SDK AWS o HTTP, ed
  è testabile con Pest senza avviare il framework.
- Il dominio definisce **porte primarie** (i casi d'uso, invocati dall'esterno) e **porte
  secondarie** (persistenza e servizi esterni).
- L'**applicazione** implementa le porte primarie e orchestra il dominio solo attraverso le porte
  secondarie: mai un model Eloquent, mai un client AWS diretto.
- Gli **adapter primari** traducono un ingresso esterno in una chiamata a una porta primaria:
  controller HTTP, comandi Artisan e i `WorkflowTaskHandler`, che sono adapter primari guidati da
  Step Functions e SQS invece che da HTTP. È il passaggio concettuale centrale: non introduce un
  meccanismo nuovo, generalizza quello già in produzione.
- Gli **adapter secondari** implementano le porte verso persistenza (repository Eloquent) e servizi
  esterni (Bedrock, Textract, Step Functions, storage), legati porta → adapter nel service provider.
- La Dependency Rule è **verificata in CI** da `scripts/ci/check-dependency-rule.sh`: una
  violazione è un errore di build, non un commento in code review.

### Perimetro

La struttura rigorosa vale per `Documents` (Co-Pilot CdL) e `Communications` (AI Assistant).
`Identity`, `Audit`, `Observability`, `Support` e l'infrastruttura di `Workflow` restano servizi
trasversali: nessuno ha un'implementazione alternativa da rendere sostituibile, e una porta per un
collaboratore che non varierà mai sarebbe solo decorativa.

`WorkflowTaskHandler` resta in `Workflow` ma diventa una porta primaria invocata dal motore di
workflow; le sue implementazioni passano nei rispettivi domini come adapter primari e delegano ai
casi d'uso tramite le stesse porte usate dai controller. `WorkflowEnginePort` è l'unica porta
condivisa fra i domini e sostituisce due classi che avvolgevano `SfnClient` nello stesso modo.

`MvpStateService` è un **read model** (CQRS-lite): le scritture sui due domini passano sempre dai
casi d'uso; le letture che compongono la UI (`GET /state`, liste, stream) interrogano Eloquent
direttamente e producono lo shape JSON del frontend. Farle passare dai repository di dominio
legherebbe le porte di persistenza ai bisogni di una schermata, cioè il problema opposto a quello
che l'esagonale risolve.

Struttura dei package, pattern adottati e limiti dichiarati sono descritti in
[`../architecture/backend-hexagonal.md`](../architecture/backend-hexagonal.md).

## Consequences

- Dominio e applicazione dei due domini sono testabili senza Laravel, database o AWS: i test dei casi
  d'uso usano fake delle porte (`InMemoryDocumentRepository`, `FakeWorkflowEngine`, ...).
- Le classi aumentano (un'interfaccia e un'implementazione per porta): è un costo accettato in cambio
  di confini verificabili e testabilità.
- Controller e `WorkflowTaskHandler` si riducono a traduzione: richiesta → caso d'uso → risposta. Le
  regole che stavano nei controller (preferito idempotente, filtri, transizione di `send_status`)
  vivono nei casi d'uso e nelle entità.
- Le liste paginate costano un round-trip in più: la porta restituisce gli id della pagina, poi il
  controller ricarica i record con le relazioni per `MvpStateService`. Lo stesso vale per il polling
  SSE. Il costo è una query per pagina o iterazione, non per record.
- `store()` dei controller di upload e generazione chiama ancora due porte in sequenza (carica, poi
  avvia il workflow). Non sono fuse perché l'avvio del workflow deve restare invocabile da solo, per
  esempio dalla rigenerazione di una comunicazione.
- Il contratto OpenAPI e il comportamento osservabile non cambiano: è un refactor interno.
- Lo script di verifica della Dependency Rule è un costo di manutenzione accettato: rende la regola
  reale invece che convenzionale.
- Se un servizio trasversale avrà bisogno di un'implementazione alternativa, servirà un nuovo ADR
  che estenda il perimetro con la stessa motivazione.

## Alternatives considered

- **Architettura a strati esplicita** (Controller → Service → Repository → Model): era già di fatto
  la struttura del progetto, e la mescolanza descritta sopra mostra che non basta. Il layering
  vincola chi può chiamare chi, non chi può conoscere cosa: un service può dipendere da Eloquent e
  dall'SDK AWS senza violare alcuna regola.
- **Lasciare la struttura per dominio senza formalizzarla**: nessun confine verificabile
  automaticamente; l'organizzazione per dominio è necessaria ma non sufficiente.
- **Estendere l'esagonale a tutti i domini**: porte introdotte per simmetria e non per necessità.

## Related documents

- [`../architecture/backend-hexagonal.md`](../architecture/backend-hexagonal.md): struttura dei
  package, pattern e verifica della Dependency Rule.
- [`../archive/adr-0010-implementation-evidence.md`](../archive/adr-0010-implementation-evidence.md):
  registro passo per passo del refactor, archiviato.
- [`../architecture/final-architecture.md`](../architecture/final-architecture.md): architettura
  runtime.
- [`0003-sqs-instead-of-redis-queue.md`](0003-sqs-instead-of-redis-queue.md): pattern callback a task
  token che i `WorkflowTaskHandler` implementano.
- [`0004-localstack-terraform.md`](0004-localstack-terraform.md): stesse classi per LocalStack e AWS
  reale, con endpoint diversi.
- [`0005-no-automatic-fallbacks.md`](0005-no-automatic-fallbacks.md): motivo per cui un Proxy davanti
  ai gateway AI è stato scartato.
- [`0009-communication-async-pipeline-and-cover-storage.md`](0009-communication-async-pipeline-and-cover-storage.md):
  pipeline di cui `CommunicationWorkflowTaskHandler` è adapter primario.
