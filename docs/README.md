# Documentazione

Punto d'ingresso alla documentazione tecnica della MVP. Per il contesto del progetto, l'avvio rapido
e le estensioni del fork vedi il [README del repository](../README.md). Chi arriva per la prima volta
può partire da perimetro funzionale, architettura runtime e sviluppo locale.

## Perimetro

- [Perimetro funzionale](mvp-scope.md): cosa fa e cosa non fa la MVP, area per area.

## Architettura

- [Architettura runtime](architecture/final-architecture.md): container, ingresso, LocalStack e AWS
  reale, profilo di esecuzione locale, limiti rispetto alla produzione.
- [Backend esagonale](architecture/backend-hexagonal.md): porte, adapter, pattern e verifica della
  Dependency Rule.
- [Frontend](architecture/frontend.md): ViewModel, client SSE, sistema visivo.
- [AWS Well-Architected](architecture/aws-well-architected-mapping.md): pilastri e relative evidenze.
- [Valutazione della Row-Level Security](architecture/postgres-rls-assessment.md).
- [Diagrammi](architecture/diagrams/): sorgenti D2 e SVG delle viste.
- [Architecture Decision Records](architecture-decisions/README.md), compreso il
  [profilo di esecuzione locale](architecture-decisions/0014-local-execution-profile.md) aggiunto dal
  fork.

## Runbook e guide operative

- [Sviluppo locale](runbooks/local-development.md): avvio, configurazione runtime, worker,
  verifiche, reset, struttura del repository.
- [Pipeline documentale](runbooks/document-pipeline.md): flusso Co-Pilot, configurazione, fallimenti.
- [Pipeline delle comunicazioni](runbooks/communication-pipeline.md): flusso AI Assistant,
  copertine, PDF finale.
- [Osservabilità](runbooks/observability.md): metriche, log, dashboard, alert.
- [DLQ e recupero](runbooks/dlq-recovery.md): significato dei messaggi in DLQ, ispezione, riavvio dei
  flussi falliti.
- [Backup e restore locale](runbooks/backup-restore-local.md): PostgreSQL.
- [CI/CD](runbooks/ci-cd.md): job, sicurezza della pipeline, gate di copertura e di audit.

## Sicurezza

- [Confine di autenticazione e autorizzazione](security/auth-boundary.md): identità simulata, ruoli,
  tenant.
- [Permessi IAM e accesso ad AWS reale](security/iam-and-aws-permissions.md): matrice di privilegio
  minimo, OIDC, modalità ibrida.
- [OWASP ASVS](security/owasp-asvs-mapping.md): controlli, rischi residui, scelte dell'ambiente
  locale.

## Fuori da `docs/`

- [`openapi/v1/`](../openapi/v1/README.md): contratto OpenAPI, fonte del client generato.
- [`infra/localstack/`](../infra/localstack/README.md): Terraform e risorse AWS emulate.
- [`apps/frontend/`](../apps/frontend/README.md): SPA Angular.
- [`docker/traefik/certs/`](../docker/traefik/certs/README.md): certificati TLS locali.
- [`demo/`](../demo/README.md): PDF e prompt di prova con dati inventati.
- [`.github/workflows/`](../.github/workflows/): pipeline CI.

## Archivio

- [Materiale del corso](archive/README.md): panoramica implementativa datata, tracciabilità verso il
  Capitolato, evidenze dell'ADR 0010, diagrammi draw.io.

## Terminologia

- **MVP**: l'applicazione di questo repository, eseguita in un ambiente locale riproducibile che
  emula AWS con LocalStack; non è un deploy di produzione.
- **Emulato**: servizio AWS riprodotto in locale da LocalStack (SQS, S3, KMS, Step Functions, ...),
  con lo stesso modello di interazione ma senza infrastruttura gestita.
- **ADR**: *Architecture Decision Record*, in [`architecture-decisions/`](architecture-decisions/README.md).
- **Capitolato**: il documento dei requisiti di business proposto da Eggon (`[NEXUM]
  BRD-FASE02-2025`). Gli identificativi UC-* e RF-* citati nel codice vengono dall'Analisi dei
  Requisiti del corso.
