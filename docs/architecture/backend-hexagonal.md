# Backend esagonale: struttura e pattern

Come è organizzato il backend dei due domini principali, `Documents` (Co-Pilot CdL) e
`Communications` (AI Assistant), secondo la decisione dell'[ADR 0010](../architecture-decisions/0010-hexagonal-architecture-documents-communications.md).
Qui c'è il *come*; il *perché* sta nell'ADR.

## Struttura dei package

I controller HTTP restano in `app/Http/Controllers/Api/V1/`, per convenzione di routing Laravel, ma
fanno da adapter primari: traducono la richiesta in una chiamata a una porta primaria e non
contengono regole di dominio. Gli adapter primari guidati da Step Functions e SQS
(`*WorkflowTaskHandler`) stanno invece dentro il dominio, in `Adapters/Primary/Workflow/`.

```
app/Mvp/Documents/
├── Domain/
│   ├── Entities/          OriginalDocument, SubDocument
│   ├── Ports/Inbound/     12 porte primarie: Upload, StartDocumentWorkflow, RunOcr, ProcessDocument,
│   │                      ExtractSubDocumentFields, FinalizeDocumentWorkflow, Review, SendMessage,
│   │                      Preview, Delete, ListDocuments, PollDocumentProgress
│   ├── Ports/Outbound/    DocumentRepository, OcrGatewayPort, DocumentAiGatewayPort,
│   │                      DocumentStoragePort, SendMessageRendererPort, DocumentEventDispatcherPort
│   ├── ValueObjects/, Commands/, Enums/, Events/ (14), Exceptions/, Support/
├── Application/
│   ├── UseCases/          un servizio per porta primaria (12)
│   └── Listeners/         14 listener: un evento di dominio → audit e metriche
└── Adapters/
    ├── Primary/Workflow/  DocumentWorkflowTaskHandler
    └── Outbound/          Persistence/EloquentDocumentRepository, Ocr/TextractOcrAdapter,
                           Ai/BedrockDocumentAiAdapter, Storage/FlysystemDocumentStorageAdapter,
                           Pdf/DompdfSendMessageRenderer, Events/LaravelDocumentEventDispatcher

app/Mvp/Communications/
├── Domain/
│   ├── Entities/          Communication
│   ├── Ports/Inbound/     14 porte primarie (generazione, testo, copertina, finalizzazione, bozza,
│   │                      export, rating, prompt, elenco, avanzamento, eliminazione, ...)
│   ├── Ports/Outbound/    CommunicationRepository, PromptConfigurationRepository,
│   │                      CommunicationAiGatewayPort, CommunicationCoverStoragePort,
│   │                      CommunicationPdfRendererPort, CommunicationEventDispatcherPort
│   ├── ValueObjects/, Commands/, Enums/, Events/ (21), Exceptions/
├── Application/
│   ├── UseCases/          un servizio per porta primaria (14)
│   └── Listeners/         21 listener
└── Adapters/
    ├── Primary/Workflow/  CommunicationWorkflowTaskHandler
    └── Outbound/          Persistence/, Ai/, Pdf/, Storage/, Events/

app/Mvp/Workflow/          infrastruttura condivisa dalle due pipeline
├── Contracts/             WorkflowTaskHandler, WorkflowSubject
├── Ports/Outbound/        WorkflowEnginePort, WorkflowHeartbeatPort
├── Adapters/Outbound/     SfnWorkflowEngineAdapter
├── Services/              WorkflowTaskRegistry, WorkflowTaskRunner, WorkflowTaskHeartbeat
└── Support/               StateMachineName, WorkflowContext
```

`WorkflowEnginePort` è l'unica porta condivisa fra i due domini. Prima del refactor due classi
avvolgevano `SfnClient` nello stesso modo; ora c'è un solo adapter, coerente con `Workflow` come
infrastruttura comune alle pipeline.

`Ai`, `Audit`, `Identity`, `Observability` e `Support` restano servizi di infrastruttura, fuori dal
perimetro esagonale. `MvpStateService` è un read model: le letture che compongono la UI interrogano
Eloquent direttamente, mentre ogni scrittura passa dai casi d'uso (vedi l'ADR).

Le entità (`OriginalDocument`, `SubDocument`, `Communication`) governano le proprie transizioni:
ogni metodo verifica la sua guardia e accumula il delta in un oggetto `*Changes` che l'adapter di
persistenza legge. Per esempio `Communication::applyGeneratedCover()` lancia
`CoverPrecedesTextException` se il testo non è ancora stato generato: l'invariante "prima il testo,
poi la copertina" sta nell'entità, non nei servizi. In una prima versione la teneva un
`CommunicationDraftBuilder`, poi assorbito dall'entità.

Terminologia usata nel codice e nei documenti: dominio, applicazione, adapter primario, adapter
secondario, porta primaria, porta secondaria.

## Verifica della Dependency Rule

`scripts/ci/check-dependency-rule.sh` gira nel job `backend` della CI e in `make verify-backend`.
Fallisce se un file sotto `app/Mvp/{Documents,Communications}/Domain/` cita `Illuminate\`, `Aws\`,
`App\Models\` o il namespace dell'altro dominio. Il livello `Application` ha un vincolo più
permissivo: può usare `Illuminate\` per trasformazioni pure, ma mai un model Eloquent, l'SDK AWS o
l'altro dominio. Una regressione verso il codice misto diventa un errore di build, non un commento
in code review.

## Pattern

Solo pattern con un collaboratore reale dietro.

| Pattern | Dove | Problema che risolve lì | Senza |
| --- | --- | --- | --- |
| **Adapter** | `EloquentDocumentRepository`, `BedrockDocumentAiAdapter`, `TextractOcrAdapter`, `SfnWorkflowEngineAdapter` e gli equivalenti di Communications | Il caso d'uso parla con `DocumentRepository`, non con `SubDocument::query()`: il dominio non conosce Eloquent né l'SDK AWS. | Il dominio dipende da Eloquent e AWS, e i test di dominio richiedono Laravel e LocalStack. |
| **Strategy** | `WorkflowTaskHandler`, scelto da `WorkflowTaskRegistry::for($taskType)` | `WorkflowTaskRunner` resta uguale per tutti i domini (dedup, claim, audit, metriche); cambia solo il passo di business. | Il runner avrebbe un `match` sul dominio e conoscerebbe Documents e Communications. |
| **Factory Method** | `WorkflowTaskRegistry::for()` | La selezione dell'handler a runtime sta in un punto solo. | Ogni punto di invocazione sceglierebbe l'handler da sé. |
| **Facade** | I servizi applicativi (`UploadDocumentService`, `GenerateCommunicationService`, ...) | L'adapter primario chiama un metodo e non sa quanti collaboratori servono (repository, gateway AI, storage, regole). | Il controller orchestrerebbe 4-5 servizi, com'era prima del refactor. |
| **Observer** | Eventi di dominio, 14 in Documents e 21 in Communications, pubblicati tramite le porte `*EventDispatcherPort`, ognuno con il proprio listener | Audit e metriche reagiscono agli eventi invece di essere chiamati da ogni caso d'uso. La coppia audit+metrica della copertina degradata, prima duplicata in due punti, ora sta in un listener. | Ogni nuova reazione richiede di toccare tutti i casi d'uso che generano l'evento. |
| **Command** | Un servizio applicativo per porta primaria | Una responsabilità per classe, testabile con fake delle porte secondarie. | Servizi "fat" con più responsabilità, com'erano `DocumentProcessingService` e `CommunicationWorkflowService`. |
| **Singleton** | Binding porta → adapter in `AppServiceProvider` | I client AWS restano condivisi; il binding è il punto in cui si sceglie l'adapter. | Cambiare adapter richiederebbe toccare ogni type-hint concreto. |

Limiti dichiarati:

- **Command non è puro.** Alcune porte raggruppano transizioni dello stesso aggregato:
  `CommunicationDraftUseCase` ne ha cinque (`favorite`, `unfavorite`, `update`, `save`, `discard`).
  È una scelta, non una classe per transizione.
- **Casi d'uso pass-through.** `ListDocumentsService` e `ListCommunicationsService` delegano al
  repository in una riga. Esistono per uniformità del confine: nessun controller chiama un
  repository direttamente.
- **Sostituibilità non provata con un secondo provider.** Ogni porta secondaria ha un solo adapter
  di produzione. L'argomento dimostrato è la testabilità: ogni porta ha un secondo implementatore
  reale, il fake dei test di dominio (`InMemoryDocumentRepository`, `FakeWorkflowEngine`, ...).

Pattern valutati e scartati:

- **Proxy** davanti ai gateway AI (cache, circuit breaker): contraddirebbe l'[ADR 0005](../architecture-decisions/0005-no-automatic-fallbacks.md),
  che vieta di mascherare un fallimento dei servizi AI.
- **Abstract Factory**: non esistono famiglie di adapter da creare insieme. LocalStack e AWS reale
  usano le stesse classi con endpoint e credenziali diversi ([ADR 0004](../architecture-decisions/0004-localstack-terraform.md)).
