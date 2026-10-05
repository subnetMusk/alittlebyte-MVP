# Runbook di osservabilità

Lo stack locale copre metriche, alert e log centralizzati. Non c'è tracing distribuito:
l'applicazione non include un SDK OpenTelemetry, quindi nessun componente produce trace.

## Servizi locali

| Servizio | Accesso | Ruolo |
| --- | --- | --- |
| Prometheus | https://prometheus.localhost:8443 (basic auth) | Archivio delle metriche e valutazione delle regole di alert. |
| Alertmanager | https://alertmanager.localhost:8443 (basic auth) | Instradamento degli alert verso un receiver dimostrativo. |
| Grafana | https://grafana.localhost:8443 (login di Grafana) | Dashboard provisionate da file, metriche e log. |
| OTel Collector | interno, `otel-collector:9464` | Gateway delle metriche: raschia app, Traefik e se stesso, espone a Prometheus. |
| Loki | interno, `loki:3100` | Archivio dei log interrogato da Grafana. |
| Grafana Alloy | interno, `alloy:12345` | Raccoglie i log dei container etichettati e li invia a Loki. |

Nessuna porta sull'host: le UI sono raggiungibili solo da Traefik, sulla rete interna
`observability`. Le credenziali di basic auth sono in `docker/traefik/usersfile` (`mvp` /
`mvp-obs-local-password`, solo per l'ambiente locale); quelle di Grafana vengono da
`GRAFANA_ADMIN_USER` e `GRAFANA_ADMIN_PASSWORD` (default `admin` / `admin`). I browser risolvono
`*.localhost` da soli; con `curl` serve `--resolve prometheus.localhost:8443:127.0.0.1`.

Prometheus, Alertmanager, Grafana e Loki hanno un healthcheck HTTP nel compose. Alloy e il Collector
no: le loro immagini non contengono un client HTTP, e il Collector non ha nemmeno una shell.

## Avvio e validazione

```bash
make observability-config
make observability-up
```

`make observability-config` valida, senza avviare lo stack:

- la configurazione del Collector (`otelcol-contrib validate`);
- la configurazione di Prometheus e i file di regole (`promtool check config`);
- la configurazione di Alertmanager (`amtool check-config`);
- la configurazione di Loki (`loki -verify-config`);
- la configurazione di Alloy (`alloy fmt --test`, che fallisce anche su un file non formattato in
  modo canonico).

La CI esegue lo stesso target nel job `stack` e, a stack avviato, verifica che Loki riceva log con le
label `service` e `project`.

## Flusso delle metriche

1. Laravel espone `/internal/metrics` su un listener Nginx dedicato, `:8081`, distinto da quello
   raggiunto da Traefik (`:8080`). Da fuori, `https://localhost:8443/internal/metrics` risponde 404.
2. `app`, `queue` e `queue-communications` condividono il volume `observability-metrics`: le metriche
   di dominio registrate dai worker (Textract, SQS, completamento dei workflow) escono dallo stesso
   endpoint.
3. Il Collector raschia `nginx:8081/internal/metrics`, `traefik:9100/metrics` e la propria telemetria
   (`:8888`).
4. Il Collector espone tutte le metriche su `:9464`.
5. Prometheus raschia solo il Collector e valuta le regole di alert.
6. Alertmanager riceve gli alert da Prometheus.
7. Grafana interroga Prometheus e Loki, provisionati da `docker/grafana/provisioning`.

**Limite dell'exporter.** Le metriche applicative stanno in un file JSON
(`storage/app/private/observability/metrics.json`) condiviso dai tre container. Ogni scrittura prende
un `flock` esclusivo e riscrive l'intero file; la lettura per lo scrape usa un lock condiviso. Basta
per il carico di un ambiente locale, ma serializza le richieste sotto carico. Se il file risulta
corrotto, `MetricsRecorder` riparte da un insieme vuoto e i contatori si azzerano: Prometheus lo
vede come un reset di counter, che `rate()` gestisce.

**Correlation ID.** `CorrelateRequests` legge `X-Correlation-ID` (o `X-Request-ID`, o ne genera uno),
lo mette nel contesto dei log e lo restituisce nella risposta. All'avvio di un workflow entra
nell'input della state machine come `correlation_id`, l'ASL lo copia nei messaggi SQS e il worker lo
rilega al contesto prima di eseguire il task; gli audit lo registrano. È un identificativo di
correlazione dei log, non un contesto W3C Trace Context.

## Flusso dei log

1. I servizi da raccogliere dichiarano la label `com.alittlebyte.observability.logs: "true"`,
   definita una volta sola come `x-collected-logs` in `docker-compose.yml`. Ce l'hanno tutti i servizi
   di runtime; i tool del profilo `tools` (Node, Terraform, AWS CLI, audit) no.
2. Alloy scopre i container dal socket Docker filtrando su quella label, non sul nome del progetto
   Compose (`docker/alloy/config.alloy`).
3. Alloy legge il flusso di log di ogni container e lo invia a Loki con le label `service`,
   `container` e `project` (il progetto Compose del checkout).
4. Loki conserva i log su un volume locale per 7 giorni.
5. Grafana interroga Loki nei pannelli di log e nella dashboard `Logs and Errors`. Le dashboard hanno
   una variabile `project` popolata dai valori della label: `All` mostra tutti i checkout.

Per aggiungere un servizio alla raccolta basta dargli `labels: *collected-logs`.

**Nome del progetto e più checkout.** Il nome del progetto Compose non è fissato nel compose: deriva
dalla cartella, oppure da `-p` o `COMPOSE_PROJECT_NAME`. Così due checkout dello stesso repository
non condividono container, reti e volumi. Il demone Docker però è uno solo: con due stack accesi, ogni
Alloy vede anche i container etichettati dell'altro e li invia al proprio Loki. Ogni riga resta
attribuita al checkout di origine dalla label `project`, che le dashboard permettono di filtrare. Un
isolamento stretto dell'ingestione (ogni Loki riceve solo il proprio stack) non è configurato.

Query LogQL utili (`<progetto>` è il nome del progetto Compose, di default la cartella in minuscolo):

```logql
{project="<progetto>", service="queue"}
{project="<progetto>", service=~"queue|queue-communications|app"} |~ "(?i)level_name.{0,6}(error|critical|emergency)"
{project=~".+"} |~ "(?i)(level_name.{0,6}(error|critical|emergency)|level=(error|critical|fatal))" != "No such container"
```

I log applicativi di Monolog sono JSON con `level_name`; i container di infrastruttura usano logfmt
con `level=`. I filtri sugli errori coprono entrambi. La clausola `!= "No such container"` scarta gli
errori transitori di Alloy mentre i container vengono ricreati.

Verifica a stack avviato:

```bash
curl -sk --resolve grafana.localhost:8443:127.0.0.1 -u admin:admin https://grafana.localhost:8443/api/datasources/proxy/uid/loki/loki/api/v1/labels
curl -sk --resolve grafana.localhost:8443:127.0.0.1 -u admin:admin https://grafana.localhost:8443/api/datasources/proxy/uid/loki/loki/api/v1/label/project/values
```

## Contratto delle metriche

Le metriche di dominio sono **dichiarate** in `app/Mvp/Observability/DomainMetricCatalog.php`, non
dedotte da ciò che il file di accumulo contiene. Il catalogo definisce nome, tipo, help, label e —
dove i valori sono un insieme chiuso — i valori ammessi, presi dagli enum di dominio.

I nomi delle state machine fanno eccezione: non sono un enum ma portano il `name_prefix`
dell'ambiente, quindi il catalogo li legge dalla configurazione con `StateMachineName::forPipeline()`,
la stessa funzione che etichetta le metriche a runtime. Una pipeline il cui ARN non è configurato
resta fuori dalle serie seminate: `unknown` descriverebbe una pipeline che non può nemmeno partire.

Tre conseguenze operative:

- una metrica dichiarata compare nell'esposizione **anche a zero**, prima di essere emessa la prima
  volta. I pannelli mostrano `0` invece di "No data" e le regole con `== 0` hanno una serie su cui
  valutare (è la ragione per cui `QueueBacklogHigh` prima non poteva scattare);
- una metrica ritirata dal codice smette davvero di essere esposta, anche se la sua chiave resta nel
  volume condiviso `observability-metrics`;
- `MetricsRecorder` rifiuta in `local`/`testing` una metrica non a catalogo o con label diverse da
  quelle dichiarate; in esercizio degrada a warning e registra comunque, perché un problema di
  strumentazione non deve abbattere il percorso di business.

`tests/Feature/ObservabilityContractTest.php` confronta il catalogo con il PromQL di **tutte** le
dashboard e le rule: nome inesistente, label non dichiarata, valore fuori enum e metrica che nessuno
guarda fanno fallire la suite. È l'unico controllo che copre il PromQL dentro i JSON delle
dashboard — `promtool check config` valida solo la sintassi delle regole.

### Convenzioni di naming, e perché contano qui

I counter terminano in `_total`; i gauge no. Non è formalismo: il `prometheusexporter` del Collector
appende `_total` ai sum monotoni che non ce l'hanno, quindi un counter chiamato `..._sum` esce come
`..._sum_total` e ogni query sul nome originale smette di trovarlo. È esattamente ciò che ha reso
vuoti i pannelli di confidenza e durata OCR pur essendoci il dato. Due difese:

- `add_metric_suffixes: false` in `docker/otel-collector/config.yml` — il Collector qui è un gateway
  di trasporto, non un normalizzatore di nomi;
- le due misure OCR sono dichiarate come famiglie `summary` (`mvp_textract_confidence`,
  `mvp_textract_duration_seconds`), così `_sum` e `_count` appartengono formalmente alla stessa
  metrica e restano corretti anche se qualcuno riabilitasse i suffissi.

### Profondità delle DLQ

`mvp_dlq_messages{queue}` è letta da SQS (`ApproximateNumberOfMessages`) a ogni scrape da
`DlqDepthProbe`, con timeout di 2-3 secondi. Se la lettura fallisce **non viene emessa alcuna serie
di profondità** e `mvp_dlq_probe_up{queue}` vale 0: uno zero inventato spegnerebbe in silenzio
`DLQNotEmpty`, che è severity critical. L'alert `DlqProbeDown` copre proprio questo caso.

Una coda **senza URL configurato** è trattata allo stesso modo di una lettura fallita: la pipeline
compare comunque con `mvp_dlq_probe_up{queue} 0`. Prima veniva saltata del tutto, quindi non usciva
né la profondità né il probe, e una DLQ mai configurata era indistinguibile da una DLQ vuota.

`MVP_DLQ_PROBE_ENABLED=false` spegne il probe dove SQS non è raggiungibile: sparisce anche
`mvp_dlq_probe_up`, quindi `DLQNotEmpty` e `DlqProbeDown` smettono entrambi di valutare. È una
rinuncia dichiarata, non la stessa cosa di un probe che fallisce.

### Saturazione del trasporto

`mvp_workflow_tasks{status}` conta i task di workflow per stato di claim
(`pending`, `running`, `succeeded`, `skipped`, `failed`), con una sola query aggregata per scrape.
Serve a rispondere a una domanda che la profondità DLQ non copre: quella conta ciò che ha **smesso**
di essere ritentato, questa ciò che **attende** o è in corso.

Due letture utili in incidente:

- `pending` che cresce senza scendere → i worker non stanno consumando (controllare `queue` e
  `queue-communications`, e l'alert `QueueBacklogHigh`);
- `running` che non torna a zero → worker terminati senza rilasciare il claim. Il runner li recupera
  da solo dopo `MVP_WORKFLOW_RUNNING_CLAIM_TTL_SECONDS`, quindi il valore va letto su una finestra
  più lunga di quel TTL prima di concludere che c'è un problema.

### Label attese in Prometheus

Le metriche applicative arrivano a Prometheus attraverso il Collector, quindi portano
`job="mvp-otel-collector-exporter"` e `instance="otel-collector:9464"`; il job e l'istanza originali
sopravvivono come `exported_job` / `exported_instance`. Analogamente `mvp_app_info` espone
`exported_service_name` accanto a `service_name`, perché le label statiche del job `mvp-app` nel
Collector hanno lo stesso nome di quelle emesse dall'applicazione. È il comportamento atteso di
`honor_labels: false` e va lasciato così: attivare `honor_labels` farebbe collidere i target del
Collector con quelli di Prometheus e romperebbe `TargetDown` (`up == 0`).

### Diagnosi rapida

```bash
docker compose exec -T app curl -s http://nginx:8081/internal/metrics | grep -E '^# TYPE'
```

```bash
docker compose exec -T app curl -s http://otel-collector:9464/metrics | grep -E '^# TYPE mvp_'
```

Confrontare i due elenchi: i nomi devono coincidere. Una differenza significa che il Collector sta
riscrivendo i nomi e che le dashboard interrogano metriche che non esistono più.

```bash
curl -sk -u mvp:mvp-obs-local-password --resolve prometheus.localhost:8443:127.0.0.1 "https://prometheus.localhost:8443/api/v1/label/__name__/values"
```

Se un collector di gauge fallisce (per esempio una colonna mancante dopo una migrazione a metà),
la sua famiglia sparisce ma il resto dell'esposizione continua a essere servito, e
`mvp_metrics_collection_failures_total{collector}` dice quale ha ceduto.

## Dashboards

Le dashboard sono JSON in `docker/grafana/dashboards`.

Ogni dashboard apre con un pannello di testo che dichiara la domanda a cui risponde e gli alert
correlati, e ogni pannello porta una `description` visibile sull'icona informativa: sono le due
raccomandazioni con cui si apre la guida Grafana, ed è anche il criterio per decidere se un pannello
nuovo appartiene o no a quella pagina.

- `api-golden-signals.json` — *triage in testa*. Prima fascia: stato del servizio, alert in firing,
  errori nell'ultima ora, per capire in due secondi se c'è un problema **adesso**. Seconda: i quattro
  segnali d'oro con i rispettivi andamenti. Terza: tabella per route e saturazione (connessioni edge,
  backlog pipeline, memoria del Collector).
- `document-pipeline.json` — *imbuto*. Il pannello portante mostra la dispersione fra i passi
  (rilevati → con esito → validati → scaricati): dice **dove** la pipeline perde documenti, che prima
  andava ricostruito confrontando due tabelle. Seguono stato corrente, throughput per task e cause
  dei fallimenti.
- `communication-pipeline.json` — *stessa struttura della pipeline documenti*, deliberatamente: due
  pipeline con la stessa forma si leggono con la stessa abitudine. L'imbuto va da richieste ad
  approvate; il passo della copertina può restare indietro senza che sia un guasto.
- `queues-and-dlq.json` — *metodo USE*, che descrive lo stato di una risorsa: **Utilization** (lavoro
  che scorre), **Saturation** (DLQ, task in attesa, bloccati oltre timeout), **Errors** (messaggi
  falliti, heartbeat, callback rifiutati). È il complemento del metodo RED usato per l'API.
- `ai-ocr-quality.json` — qualità di Textract (confidenza e durata sulla finestra selezionata,
  esiti, fallimenti per codice) ed esito dell'estrazione AI. Lo stato delle comunicazioni è stato
  spostato nella dashboard delle comunicazioni, a cui appartiene.
- `logs-and-errors.json` — *triage temporale senza perdere il dettaglio*. Prima fascia: errori negli
  ultimi 5 minuti (finestra fissa) accanto al totale del periodo selezionato, servizio più rumoroso e
  alert attivi — durante un incidente serve distinguere un picco in corso da uno già rientrato.
  Seguono il confronto fra servizi a piena larghezza, le righe complete e un pannello per ciascuno dei
  tre servizi. Apre su `now-1h`, più corta delle altre perché è la scala giusta per i log.

I datasource (Prometheus e Loki) sono provisionati da `docker/grafana/provisioning`.

## Regole di alert

Le 16 regole stanno in `docker/prometheus/rules`:

- `api-alerts.yml`
- `pipeline-alerts.yml`
- `queue-alerts.yml` (include `DlqProbeDown`)
- `communication-alerts.yml`
- `ai-alerts.yml`

Ogni alert ha un'annotazione `runbook` che punta al runbook pertinente in `docs/runbooks/` su GitHub. Gli alert sulle DLQ (`DLQNotEmpty`, `CommunicationDLQNotEmpty`) e `CommunicationCoverStorageFailing` sono `critical`, perché segnalano percorsi di fallimento terminali; gli altri sono `warning`, tranne `TargetDown` (`critical`). `CommunicationCoverGenerationDegraded` scatta oltre tre degradazioni in trenta minuti: una copertina degradata è un esito previsto e un singolo evento non richiede un intervento.

Il receiver di Alertmanager è volutamente dimostrativo: gli alert si consultano nella UI di Alertmanager. Non vanno configurati in questo repository segreti reali di email, Slack o paging.
