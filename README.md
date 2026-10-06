# aLittleByte MVP: AI Assistant e Co-Pilot CdL

<p align="center">
  <img src="https://img.shields.io/badge/Docker-2496ED?logo=docker&logoColor=white" alt="Docker">
  <img src="https://img.shields.io/badge/Laravel-FF2D20?logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/Angular-DD0031?logo=angular&logoColor=white" alt="Angular">
  <img src="https://img.shields.io/badge/PostgreSQL-4169E1?logo=postgresql&logoColor=white" alt="PostgreSQL">
  <img src="https://img.shields.io/badge/LocalStack-4D0DCF?logo=data:image%2Fsvg%2Bxml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAzMiAzMiI%2BPHBhdGggZmlsbD0iI2ZmZiIgZD0iTTMxLjYgMEgxOS4zMUMxOS4wNiAwIDE4Ljg2IDAuMiAxOC44NiAwLjQ1VjEyLjcyQzE4Ljg2IDEyLjk3IDE5LjA2IDEzLjE4IDE5LjMxIDEzLjE4SDMxLjZDMzEuODUgMTMuMTggMzIuMDYgMTIuOTcgMzIuMDYgMTIuNzJWMC40NUMzMi4wNiAwLjIgMzEuODUgMCAzMS42IDBaIE0xMS43NyAyMC43MUgyNy44M0MyOC4wOCAyMC43MSAyOC4yOSAyMC41IDI4LjI5IDIwLjI1VjE1LjUxQzI4LjI5IDE1LjI2IDI4LjA4IDE1LjA2IDI3LjgzIDE1LjA2SDE3LjQyQzE3LjE3IDE1LjA2IDE2Ljk3IDE0Ljg2IDE2Ljk3IDE0LjYxVjQuMjJDMTYuOTcgMy45NyAxNi43NyAzLjc2IDE2LjUyIDMuNzZIMTEuNzdDMTEuNTIgMy43NiAxMS4zMSAzLjk3IDExLjMxIDQuMjJWMjAuMjVDMTEuMzEgMjAuNSAxMS41MiAyMC43MSAxMS43NyAyMC43MVogTTkuNDMgNS4xMVYxMy43MkM5LjQzIDEzLjk0IDkuMjUgMTQuMTIgOS4wMyAxNC4xMkgwLjRDMC4wNCAxNC4xMiAtMC4xMyAxMy42OSAwLjEyIDEzLjQ0TDguNzUgNC44MkM5IDQuNTcgOS40MyA0Ljc1IDkuNDMgNS4xMVogTTE3LjkxIDMxLjZWMjIuOTlDMTcuOTEgMjIuNzcgMTguMDkgMjIuNTkgMTguMzEgMjIuNTlIMjYuOTRDMjcuMyAyMi41OSAyNy40OCAyMy4wMiAyNy4yMiAyMy4yN0wxOC42IDMxLjg4QzE4LjM0IDMyLjEzIDE3LjkxIDMxLjk2IDE3LjkxIDMxLjZaIi8%2BPC9zdmc%2B" alt="LocalStack">
  <img src="https://img.shields.io/badge/Terraform-844FBA?logo=terraform&logoColor=white" alt="Terraform">
  <img src="https://img.shields.io/badge/AWS-232F3E?logo=data:image%2Fsvg%2Bxml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjUuNiAxMS44IDI4LjggMTcuOCI%2BPHBhdGggZmlsbD0iI2ZmZiIgZD0iTTMyLjcsMjMuOSBDMzMuMSwyNC40IDMyLjMsMjYuMyAzMiwyNy4yIEMzMS45LDI3LjQgMzIuMSwyNy41IDMyLjMsMjcuMyBDMzMuOCwyNiAzNC4yLDIzLjQgMzMuOSwyMyBDMzMuNiwyMi42IDMxLDIyLjMgMjkuMywyMy40IEMyOS4xLDIzLjYgMjkuMSwyMy44IDI5LjQsMjMuOCBDMzAuMywyMy43IDMyLjMsMjMuNCAzMi43LDIzLjkgTDMyLjcsMjMuOSBaIE0zMS45LDI1LjkgQzMyLjQsMjUuNSAzMS45LDI1IDMxLjQsMjUuMiBDMjcuOSwyNi43IDI0LjEsMjcuNCAyMC42LDI3LjQgQzE1LjUsMjcuNCAxMC41LDI2IDYuNCwyMy43IEM2LjEsMjMuNSA1LjgsMjMuOCA2LjEsMjQuMSBDOS45LDI3LjQgMTQuOCwyOS40IDIwLjMsMjkuNCBDMjQuMiwyOS40IDI4LjcsMjguMiAzMS45LDI1LjkgTDMxLjksMjUuOSBaIE0yOS43LDIwLjcgQzI5LjIsMjAuNyAyOC43LDIwLjYgMjguMywyMC41IEMyNy44LDIwLjQgMjcuNCwyMC4zIDI3LjIsMjAuMiBDMjcsMjAuMSAyNi45LDIwIDI2LjksMTkuOSBDMjYuOCwxOS44IDI2LjgsMTkuNyAyNi44LDE5LjYgTDI2LjgsMTkuMiBDMjYuOCwxOSAyNi45LDE4LjkgMjcsMTguOSBDMjcuMSwxOC45IDI3LjEsMTguOSAyNy4yLDE4LjkgQzI3LjMsMTguOSAyNy4zLDE5IDI3LjQsMTkgQzI3LjgsMTkuMiAyOC4xLDE5LjMgMjguNSwxOS4zIEMyOC45LDE5LjQgMjkuMiwxOS40IDI5LjYsMTkuNCBDMzAuMiwxOS40IDMwLjcsMTkuMyAzMSwxOS4xIEMzMS4zLDE4LjkgMzEuNSwxOC42IDMxLjUsMTguMyBDMzEuNSwxOCAzMS40LDE3LjggMzEuMiwxNy42IEMzMS4xLDE3LjQgMzAuNywxNy4zIDMwLjMsMTcuMSBMMjguOSwxNi43IEMyOC4yLDE2LjUgMjcuNywxNi4yIDI3LjQsMTUuOCBDMjcuMSwxNS40IDI2LjksMTQuOSAyNi45LDE0LjQgQzI2LjksMTQgMjcsMTMuNyAyNy4yLDEzLjQgQzI3LjMsMTMuMSAyNy42LDEyLjkgMjcuOCwxMi42IEMyOC4xLDEyLjQgMjguNSwxMi4zIDI4LjgsMTIuMiBDMjkuMiwxMi4xIDI5LjYsMTIgMzAsMTIgQzMwLjIsMTIgMzAuNCwxMiAzMC43LDEyIEMzMC45LDEyLjEgMzEuMSwxMi4xIDMxLjMsMTIuMSBDMzEuNSwxMi4yIDMxLjcsMTIuMiAzMS44LDEyLjMgQzMyLDEyLjMgMzIuMSwxMi40IDMyLjIsMTIuNSBDMzIuMywxMi41IDMyLjQsMTIuNiAzMi41LDEyLjcgQzMyLjYsMTIuOCAzMi42LDEyLjkgMzIuNiwxMyBMMzIuNiwxMy40IEMzMi42LDEzLjYgMzIuNSwxMy43IDMyLjQsMTMuNyBDMzIuMywxMy43IDMyLjIsMTMuNyAzMiwxMy42IEMzMS41LDEzLjQgMzAuOCwxMy4yIDMwLjIsMTMuMiBDMjkuNiwxMy4yIDI5LjIsMTMuMyAyOC45LDEzLjUgQzI4LjYsMTMuNyAyOC41LDE0IDI4LjUsMTQuMyBDMjguNSwxNC42IDI4LjYsMTQuOCAyOC44LDE1IEMyOC45LDE1LjIgMjkuMywxNS4zIDI5LjgsMTUuNSBMMzEuMSwxNS45IEMzMS44LDE2LjEgMzIuMywxNi40IDMyLjYsMTYuOCBDMzIuOSwxNy4yIDMzLDE3LjYgMzMsMTguMSBDMzMsMTguNSAzMywxOC45IDMyLjgsMTkuMiBDMzIuNiwxOS41IDMyLjQsMTkuOCAzMi4xLDIwIEMzMS44LDIwLjIgMzEuNSwyMC40IDMxLjEsMjAuNSBDMzAuNiwyMC42IDMwLjIsMjAuNyAyOS43LDIwLjcgTDI5LjcsMjAuNyBaIE0xNy41LDIwLjUgQzE3LjQsMjAuNSAxNy4zLDIwLjQgMTcuMiwyMC40IEMxNy4xLDIwLjMgMTcsMjAuMiAxNywyMCBMMTQuOCwxMi44IEMxNC43LDEyLjcgMTQuNywxMi41IDE0LjcsMTIuNSBDMTQuNywxMi4zIDE0LjgsMTIuMiAxNC45LDEyLjIgTDE1LjgsMTIuMiBDMTYsMTIuMiAxNi4xLDEyLjMgMTYuMiwxMi4zIEMxNi4zLDEyLjQgMTYuMywxMi41IDE2LjQsMTIuNyBMMTgsMTguOCBMMTkuNSwxMi43IEMxOS41LDEyLjUgMTkuNiwxMi40IDE5LjcsMTIuMyBDMTkuNywxMi4zIDE5LjksMTIuMiAyMCwxMi4yIEwyMC44LDEyLjIgQzIxLDEyLjIgMjEuMSwxMi4zIDIxLjIsMTIuMyBDMjEuMywxMi40IDIxLjMsMTIuNSAyMS40LDEyLjcgTDIyLjksMTguOSBMMjQuNSwxMi43IEMyNC41LDEyLjUgMjQuNiwxMi40IDI0LjcsMTIuMyBDMjQuOCwxMi4zIDI0LjksMTIuMiAyNS4xLDEyLjIgTDI1LjksMTIuMiBDMjYuMSwxMi4yIDI2LjIsMTIuMyAyNi4yLDEyLjUgQzI2LjIsMTIuNSAyNi4yLDEyLjYgMjYuMSwxMi42IEMyNi4xLDEyLjcgMjYuMSwxMi43IDI2LjEsMTIuOCBMMjMuOCwyMCBDMjMuNywyMC4yIDIzLjcsMjAuMyAyMy42LDIwLjQgQzIzLjUsMjAuNCAyMy40LDIwLjUgMjMuMiwyMC41IEwyMi40LDIwLjUgQzIyLjIsMjAuNSAyMi4xLDIwLjQgMjIsMjAuNCBDMjIsMjAuMyAyMS45LDIwLjIgMjEuOSwyMCBMMjAuNCwxNCBMMTguOSwyMCBDMTguOSwyMC4yIDE4LjgsMjAuMyAxOC43LDIwLjQgQzE4LjcsMjAuNCAxOC41LDIwLjUgMTguNCwyMC41IEwxNy41LDIwLjUgWiBNOS45LDE5LjQgQzEwLjMsMTkuNCAxMC42LDE5LjQgMTAuOSwxOS4zIEMxMS4zLDE5LjIgMTEuNiwxOSAxMS44LDE4LjcgQzEyLDE4LjUgMTIuMSwxOC4zIDEyLjEsMTguMSBDMTIuMiwxNy45IDEyLjIsMTcuNiAxMi4yLDE3LjMgTDEyLjIsMTYuOSBDMTIsMTYuOCAxMS43LDE2LjggMTEuNCwxNi44IEMxMS4xLDE2LjcgMTAuOCwxNi43IDEwLjUsMTYuNyBDOS44LDE2LjcgOS40LDE2LjggOS4xLDE3LjEgQzguOCwxNy4zIDguNiwxNy43IDguNiwxOC4xIEM4LjYsMTguNiA4LjcsMTguOSA4LjksMTkuMSBDOS4yLDE5LjMgOS41LDE5LjQgOS45LDE5LjQgTDkuOSwxOS40IFogTTEzLjgsMTggQzEzLjgsMTguNCAxMy44LDE4LjcgMTMuOSwxOC45IEMxNCwxOS4xIDE0LjEsMTkuMyAxNC4yLDE5LjUgQzE0LjIsMTkuNiAxNC4zLDE5LjcgMTQuMywxOS43IEMxNC4zLDE5LjggMTQuMiwxOS45IDE0LjEsMjAgTDEzLjUsMjAuNCBDMTMuNCwyMC41IDEzLjMsMjAuNSAxMy4yLDIwLjUgQzEzLjEsMjAuNSAxMywyMC40IDEzLDIwLjQgQzEyLjgsMjAuMiAxMi43LDIwLjEgMTIuNiwxOS45IEMxMi41LDE5LjggMTIuNCwxOS42IDEyLjMsMTkuNCBDMTEuNiwyMC4yIDEwLjcsMjAuNyA5LjUsMjAuNyBDOC43LDIwLjcgOC4xLDIwLjQgNy43LDIwIEM3LjIsMTkuNiA3LDE5IDcsMTguMiBDNywxNy40IDcuMiwxNi44IDcuOCwxNi4zIEM4LjQsMTUuOCA5LjIsMTUuNiAxMC4xLDE1LjYgQzEwLjUsMTUuNiAxMC44LDE1LjYgMTEuMSwxNS43IEMxMS41LDE1LjcgMTEuOSwxNS44IDEyLjIsMTUuOSBMMTIuMiwxNS4yIEMxMi4yLDE0LjUgMTIuMSwxNCAxMS44LDEzLjcgQzExLjUsMTMuNCAxMSwxMy4zIDEwLjIsMTMuMyBDOS45LDEzLjMgOS42LDEzLjMgOS4yLDEzLjQgQzguOSwxMy41IDguNSwxMy42IDguMiwxMy43IEM4LDEzLjggNy45LDEzLjggNy45LDEzLjggQzcuOCwxMy45IDcuOCwxMy45IDcuNywxMy45IEM3LjYsMTMuOSA3LjUsMTMuOCA3LjUsMTMuNiBMNy41LDEzLjEgQzcuNSwxMyA3LjUsMTIuOSA3LjYsMTIuOCBDNy42LDEyLjcgNy43LDEyLjcgNy45LDEyLjYgQzguMiwxMi40IDguNiwxMi4zIDksMTIuMiBDOS41LDEyLjEgMTAsMTIgMTAuNSwxMiBDMTEuNiwxMiAxMi41LDEyLjMgMTMsMTIuOCBDMTMuNSwxMy4zIDEzLjgsMTQgMTMuOCwxNSBMMTMuOCwxOCBaIi8%2BPC9zdmc%2B" alt="AWS">
  <img src="https://img.shields.io/badge/Grafana-F46800?logo=grafana&logoColor=white" alt="Grafana">
  <img src="https://img.shields.io/badge/Ollama-000000?logo=ollama&logoColor=white" alt="Ollama">
</p>

<p align="center">
  <a href="https://github.com/subnetMusk/alittlebyte-MVP/actions/workflows/ci.yml?query=branch%3Amain"><img src="https://github.com/subnetMusk/alittlebyte-MVP/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI (main)"></a>
</p>

Applicazione web per comunicazioni HR generate con l'AI e per l'analisi di documenti paga
multi-destinatario, con pipeline asincrona su servizi AWS emulati in locale.

## Origine del progetto

Progetto di gruppo del corso di **Ingegneria del Software dell'Università degli Studi di Padova**
(a.a. 2025/2026), sviluppato dal gruppo 19, **aLittleByte**, sul capitolato C5 (NEXUM) proposto da
**Eggon S.r.l.**

Questo repository è un fork personale. Il repository ufficiale del team è
[aLittleByte-19/MVP](https://github.com/aLittleByte-19/MVP); la documentazione ufficiale del progetto
(Product Baseline, fasi precedenti, glossario) è su
[alittlebyte-19.github.io/Documentazione](https://alittlebyte-19.github.io/Documentazione/). Il fork
parte dallo stato del team del 22 agosto 2026; le correzioni successive della versione ufficiale sono
state riportate in modo selettivo, indicando nei commit lo SHA di origine. Le aggiunte fatte dopo il
fork sono descritte a parte, in [Estensioni del fork personale](#estensioni-del-fork-personale).

## Cosa fa

- **AI Assistant per comunicazioni HR.** Da un prompt, un tono e uno stile genera titolo, testo e
  copertina, in modo asincrono e seguito dalla SPA via Server-Sent Events. La bozza resta
  modificabile, rigenerabile ed esportabile in PDF.
- **Co-Pilot CdL per documenti multi-destinatario.** Il consulente del lavoro carica un PDF con
  cedolini o CU di più dipendenti; il sistema lo divide in un sotto-documento per destinatario.
- **OCR.** Il testo viene letto pagina per pagina con la confidenza di ogni riga (Amazon Textract).
- **Estrazione strutturata.** Un modello (Amazon Nova Lite su Bedrock) estrae nome, codice fiscale,
  matricola, azienda, email, tipo e data del documento. L'output è validato contro uno JSON Schema;
  la confidenza di ogni campo viene dalla leggibilità OCR della sua riga, non dall'autovalutazione
  del modello ([ADR 0013](docs/architecture-decisions/0013-per-field-ocr-confidence.md)).
- **Human-in-the-loop.** L'operatore rivede e valida i campi, corregge i dati e scarica il messaggio
  di invio precompilato. L'applicazione non invia nulla da sola: il recapito avviene fuori dalla
  piattaforma.

Il perimetro completo, area per area, è in [`docs/mvp-scope.md`](docs/mvp-scope.md).

## Stack e architettura

SPA Angular 21; API Laravel 12 su PHP 8.4; PostgreSQL 16 e Redis 7; S3, SQS con DLQ, Step
Functions, SSM e Secrets Manager emulati da LocalStack e descritti in Terraform; Traefik e Nginx
come ingresso.

![Vista container: ingresso, API, dati, orchestrazione e configurazione su LocalStack, worker, servizi AWS reali e provider del profilo local](docs/architecture/diagrams/container-view.svg)

*Linea continua: chiamata sincrona; tratteggio: messaggio asincrono; grigio, nei riquadri
tratteggiati: provider alternativi dei profili `standard` e `local`.*

Scelte tecniche principali:

- **Pipeline asincrona orchestrata.** Ogni passo è un task Step Functions consegnato su SQS con
  callback token. I worker reclamano il task in modo atomico, così una consegna duplicata non riesegue
  la logica; inviano heartbeat durante OCR e AI; i messaggi falliti finiscono in DLQ.
- **Architettura esagonale** nei domini Documents e Communications: il dominio e i casi d'uso
  dipendono solo da porte, gli adapter verso AWS stanno all'esterno e la Dependency Rule è verificata
  in CI ([ADR 0010](docs/architecture-decisions/0010-hexagonal-architecture-documents-communications.md)).
- **Quality gate in CI**: test backend e frontend, copertura minima sul codice modificato, analisi
  statica, audit delle dipendenze, scansione delle immagini con Trivy, audit di accessibilità, verifica
  dei link della documentazione.

Dettagli in [`docs/architecture/final-architecture.md`](docs/architecture/final-architecture.md).

## Avvio rapido

Servono Docker con Compose, Make, Git e una shell Unix-like (su Windows, Git Bash o WSL).

```bash
git clone https://github.com/subnetMusk/alittlebyte-MVP.git
cd alittlebyte-MVP
make setup
```

L'applicazione risponde su `https://localhost:8443`, Grafana su `https://grafana.localhost:8443`.
`make verify-fast` esegue in container i controlli principali della CI. Configurazione, worker e reset
sono in [`docs/runbooks/local-development.md`](docs/runbooks/local-development.md).

## Stato e limiti

- È un MVP locale, non un deploy: AWS è emulato da LocalStack e non esiste un ambiente di
  produzione.
- L'identità è simulata; ruoli e tenant sono comunque applicati lato server.
- Gli unici servizi AWS reali usati sono S3, Textract e Bedrock, e solo con credenziali e
  configurazione esplicite. Senza, l'OCR resta spento e i passi AI falliscono in modo esplicito,
  a meno di usare il profilo locale descritto sotto.

## Estensioni del fork personale

Le due estensioni seguenti sono state aggiunte o completate sul fork dopo la consegna e non fanno
parte dell'MVP ufficiale del team.

### Osservabilità self-hosted

Uno stack di osservabilità che gira insieme all'applicazione e si consulta da Grafana dopo
`make setup`:

- **OTel Collector** raccoglie le metriche di Laravel, dei worker e di Traefik; **Prometheus** le
  conserva e valuta le regole di alert, ognuna con il link al proprio runbook; **Alertmanager** le
  instrada.
- **Loki** e **Grafana Alloy** centralizzano i log dei container dello stack, etichettati per progetto
  e servizio.
- **Grafana** ha dashboard provisionate per i golden signals delle API, le pipeline documentale e
  delle comunicazioni, la qualità di AI e OCR, code e DLQ, log ed errori.

Le metriche di dominio sono un contratto: un catalogo dichiara nomi, label e valori ammessi, e i test
falliscono se una dashboard legge una metrica inesistente o se una metrica non è osservata da
nessuno. La CI valida le configurazioni e controlla che Loki riceva davvero i log.

Lo stack nasce durante il progetto, ma la versione ufficiale lo ha poi sostituito con
un'osservabilità minima su CloudWatch. Il fork lo mantiene e lo completa: raccolta dei log per label,
healthcheck, validazione in CI, rimozione del tracing, che non aveva produttori. Dettagli in
[`docs/runbooks/observability.md`](docs/runbooks/observability.md).

### Profilo di esecuzione locale per AI e OCR

Il flusso AWS originale resta il default e il riferimento (`MVP_EXECUTION_PROFILE=standard`). Il
profilo `local` fa girare entrambi i flussi senza credenziali AWS:

- **generazione ed estrazione** con un modello Qwen servito da Ollama sull'host;
- **OCR locale e deterministico**: il text layer del PDF quando c'è, Tesseract per le pagine
  scansionate, mai un modello generativo;
- **copertina deterministica** (default), con palette e motivo scelti da tono e stile;
- **generazione locale dell'immagine**, facoltativa, tramite un provider configurabile (ComfyUI,
  con workflow versionati per SDXL Lightning e Z-Image-Turbo).

Il profilo non tocca dominio né casi d'uso: ogni provider locale è un nuovo adapter dietro una porta
già esistente, scelto solo nel composition root. Step Functions, code, task token, SSE e
persistenza restano identici; prompt e validazione dell'output sono condivisi con Bedrock. Una
configurazione non valida ferma l'avvio invece di ripiegare su un altro provider.

Attivazione e prerequisiti in
[`docs/runbooks/local-development.md`](docs/runbooks/local-development.md#profilo-di-esecuzione-locale);
la decisione in [ADR 0014](docs/architecture-decisions/0014-local-execution-profile.md).

## Documentazione

L'indice è [`docs/README.md`](docs/README.md): architettura, decisioni architetturali (ADR), runbook
operativi, sicurezza, contratto OpenAPI e materiale del corso archiviato.
