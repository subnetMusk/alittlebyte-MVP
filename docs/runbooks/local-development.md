# Runbook dello sviluppo locale

Lo stack completo gira in Docker Compose, con LocalStack al posto di AWS. Tutti i comandi passano dal
`Makefile`; sull'host servono solo Docker e `make`.

## Avvio

```bash
make setup
```

Il target:

- genera il certificato TLS locale in un container, se mancano certificato e chiave;
- costruisce le immagini applicative;
- avvia PostgreSQL, Redis e LocalStack;
- esegue `terraform init` e `terraform apply` dal container Compose `terraform`;
- compila la SPA e la carica nel bucket S3 di LocalStack;
- esegue le migrazioni, dopo che l'healthcheck di Postgres risulta sano;
- avvia app, Nginx, `edge-cdn`, i due worker, Traefik, OTel Collector, Prometheus, Alertmanager,
  Grafana, Loki e Grafana Alloy.

A cache fredda richiede circa mezz'ora; con le immagini già presenti, una decina di minuti.

Endpoint:

| Servizio | Indirizzo |
| --- | --- |
| Applicazione | https://localhost:8443 |
| Health e readiness | https://localhost:8443/health, https://localhost:8443/ready |
| Grafana | https://grafana.localhost:8443 (login di Grafana, default `admin` / `admin`) |
| Prometheus | https://prometheus.localhost:8443 (basic auth `mvp` / `mvp-obs-local-password`) |
| Alertmanager | https://alertmanager.localhost:8443 (basic auth) |
| LocalStack | http://127.0.0.1:4566 (solo loopback) |

Le UI di osservabilità non hanno porte sull'host: si passa da Traefik. I browser risolvono
`*.localhost` da soli; con `curl` serve `--resolve grafana.localhost:8443:127.0.0.1`.

## Nome del progetto e più checkout

Il compose non fissa il nome del progetto: deriva dalla cartella del checkout, oppure da `-p` o
`COMPOSE_PROJECT_NAME`. Due checkout in cartelle diverse non condividono container, reti né volumi.
Per accenderli insieme vanno spostate le porte host del secondo, per esempio:

```bash
TRAEFIK_WEB_PORT=18080 TRAEFIK_WEBSECURE_PORT=18443 LOCALSTACK_PORT=14566 make setup
```

Le stesse variabili vanno ripetute in ogni comando `docker compose` lanciato in quel checkout:
altrimenti Compose vede una configurazione diversa e ricrea i container. Come si comportano i log con
due stack accesi è descritto in [`observability.md`](observability.md#flusso-dei-log).

## TLS locale trusted

`make local-tls` genera in un container un certificato self-signed per Traefik. Basta per gli smoke
test e la CLI, ma i browser mostrano l'avviso di identità non riconosciuta. Per evitarlo, con
`mkcert` installato sull'host:

```bash
make trusted-local-tls
docker compose restart traefik
```

Il target esegue `mkcert -install` e crea un certificato valido per `localhost`, `mvp.localhost`,
`*.localhost`, `127.0.0.1` e `::1`, negli stessi file montati da Traefik. Gira fuori da Docker perché
deve aggiornare il trust store della macchina.

## Terraform

Terraform vive in `infra/localstack` e si esegue solo tramite Docker Compose:

```bash
make infra-up
make infra-init
make infra-plan
make infra-apply
make infra-destroy
```

Il servizio `terraform` usa l'endpoint interno `http://localstack:4566` e crea S3 (con KMS), SQS e
DLQ, Step Functions, EventBridge, SES, IAM, SSM Parameter Store e Secrets Manager. Lo stato è in
`infra/localstack/terraform.tfstate`, escluso da Git.

## Configurazione runtime

I container applicativi ricevono solo i parametri di bootstrap `CONFIG_*`. I valori runtime vengono
letti da:

- SSM Parameter Store: `/mvp/app`
- Secrets Manager: `/mvp/app/runtime`

Se manca una chiave obbligatoria, il bootstrap di Laravel fallisce con l'elenco delle chiavi
mancanti: un errore di provisioning è visibile subito invece di diventare una configurazione
implicita. I valori risolti sono messi in cache in `bootstrap/cache/runtime-config.php`, dentro il
container, per non rifare le chiamate a ogni richiesta PHP-FPM. La cache vive quanto il container:
`make refresh-runtime` riscrive SSM e Secrets dai valori del `.env` e ricrea `app` e i worker.

## Worker delle pipeline

Lo stack avvia un worker per pipeline: `queue` consuma la coda documentale, `queue-communications`
quella delle comunicazioni. `make workers WORKERS=n` li scala entrambi; più repliche sono sicure
grazie al claim atomico e all'idempotenza dei task.

```bash
docker compose logs -f queue-communications
docker compose exec app php artisan mvp:dlq:list --queue=communications
```

## Osservabilità

```bash
make observability-config
make observability-up
```

Metriche, log, dashboard e alert sono descritti in [`observability.md`](observability.md).

## AWS reale per OCR e AI

La configurazione standard usa S3 di LocalStack e `TEXTRACT_ENABLED=false`. Per provare il percorso
con S3 e Textract reali vanno impostati esplicitamente nel `.env`:

```bash
MVP_DOCUMENT_DISK=real_s3
AWS_REAL_REGION=...
AWS_REAL_S3_BUCKET=...
AWS_REAL_S3_PREFIX=documents/
TEXTRACT_ENABLED=true
TEXTRACT_REGION=...   # stessa regione del bucket S3
```

Dopo ogni modifica al `.env`:

```bash
make refresh-runtime
```

Le credenziali `AWS_REAL_*` sono condivise da S3, Textract e Bedrock e non vanno mai salvate nel
repository. Bedrock richiede `BEDROCK_REGION` e `BEDROCK_MODEL_ID` con l'accesso al modello già
abilitato nell'account. Le copertine usano un client Bedrock dedicato, perché i modelli immagine sono
disponibili in altre regioni: `BEDROCK_IMAGE_MODEL_ID` (default `stability.sd3-5-large-v1:0`;
alternative `stability.stable-image-core-v1:0` e `amazon.nova-canvas-v1:0`) e `BEDROCK_IMAGE_REGION`
(default `us-west-2`). Il payload della richiesta dipende dal modello configurato, quindi cambiare
famiglia non richiede modifiche al codice. Senza un modello immagini il testo viene generato
normalmente e ogni copertina risulta degradata con il motivo: è il comportamento atteso.

`make aws-smoke` è un **controllo di configurazione**: verifica che il `.env` contenga le chiavi
necessarie e stampa l'ambiente con `php artisan about`, senza chiamare i servizi. Lo smoke vero su
S3, Textract e Bedrock è il workflow manuale `aws-smoke.yml` ([`ci-cd.md`](ci-cd.md)).

## Verifiche

| Target | Cosa controlla |
| --- | --- |
| `make verify-fast` | Le cinque verifiche qui sotto |
| `make verify-backend` | `composer validate`, elenco delle rotte, Pest, Pint, Larastan, Dependency Rule |
| `make verify-frontend` | Generazione del client OpenAPI, lint, typecheck, test Jest e build |
| `make verify-infra` | Configurazione Compose, `terraform fmt` e `terraform validate` |
| `make verify-observability` | Configurazioni di Collector, Prometheus con le regole, Alertmanager, Loki e Alloy |
| `make verify-docs` | Link relativi e anchor dei file Markdown |
| `make verify` | `verify-fast`, lint del contratto OpenAPI e audit npm delle dipendenze di produzione |
| `make verify-ci-local` | `verify-fast` e lint del contratto OpenAPI |
| `make backend-coverage`, `make frontend-coverage` | Copertura con le soglie globali di `coverage-thresholds.json` |
| `make frontend-a11y` | axe, Pa11y e smoke della CSP sulle tre pagine, contro lo stack avviato |

Nessuna di queste verifiche richiede AWS reale. I test usano `CONFIG_SOURCE=env`, per restare
indipendenti da LocalStack, e sostituiscono Bedrock, Textract e S3 con mock: la CI ordinaria non
chiama mai servizi AWS reali. Jest va eseguito con `--runInBand`, come fanno la CI e
`make frontend-coverage`: in parallelo la memoria del container si esaurisce.

Audit delle dipendenze di produzione, come in CI:

```bash
docker compose run --rm --no-deps app composer audit --locked --no-dev --abandoned=report --format=json | node scripts/ci/check-composer-advisories.mjs
docker compose --profile tools run --rm node npm audit --omit=dev --audit-level=high
```

## Reset completo

```bash
make reset-all          # chiede conferma
make reset-all FORCE=1  # senza conferma
```

Elimina tutti i volumi locali (PostgreSQL, Redis, LocalStack, osservabilità), svuota il prefisso del
bucket S3 reale se `AWS_REAL_S3_BUCKET` è configurato nel `.env` e riesegue `make setup`. È
distruttivo per costruzione: riporta la MVP allo stato iniziale.

## Struttura del repository

| Percorso | Contenuto |
| --- | --- |
| `app/Mvp/Documents`, `app/Mvp/Communications` | I due domini, in architettura esagonale ([`backend-hexagonal.md`](../architecture/backend-hexagonal.md)) |
| `app/Mvp/Workflow` | Infrastruttura comune alle pipeline: contratto degli handler, registry, runner, heartbeat, contesto di correlazione |
| `app/Mvp/Ai` | Integrazione Bedrock |
| `app/Mvp/Audit`, `app/Mvp/Identity`, `app/Mvp/Observability` | Audit, identità risolta a runtime, metriche ed exporter Prometheus |
| `app/Mvp/Support` | Servizi trasversali: stato esposto alla SPA, caricamento della configurazione runtime, piè di pagina dei PDF |
| `app/Http`, `app/Console/Commands`, `app/Models` | Controller, middleware e validazione; comandi Artisan (compreso il worker `mvp:workflow:consume`); model Eloquent |
| `apps/frontend` | SPA Angular ([`frontend.md`](../architecture/frontend.md)) |
| `openapi/v1` | Contratto API versionato |
| `infra/localstack` | Terraform per LocalStack e definizioni ASL delle state machine |
| `docker` | Immagini e configurazione dei servizi |
| `scripts` | Script della CI, degli audit a11y e del TLS locale |
| `demo` | PDF e prompt di prova con dati inventati |
