# Mapping OWASP ASVS

Questo documento mette in relazione i controlli applicativi della MVP con le aree dell'OWASP
Application Security Verification Standard. È una **baseline MVP** con **allineamento parziale**:
**non costituisce una review formale** né una dichiarazione di conformità ASVS completa.

Per il confine di autenticazione/autorizzazione vedi [`auth-boundary.md`](auth-boundary.md);
per le evidenze implementative di dettaglio [`../archive/implementation-overview-2026-08-15.md`](../archive/implementation-overview-2026-08-15.md).

| Area | Baseline implementata nella MVP | Evidenza nella codebase | Rischio residuo | Lavoro production-like |
| --- | --- | --- | --- | --- |
| Validazione input | Le Form Request validano generazione comunicazioni e upload PDF (MIME via fileinfo, dimensione, numero pagine letto con Fpdi, anti path-traversal); errori di validazione in envelope JSON stabile. La correzione dei dati estratti accetta solo i dieci campi correggibili a mano (`ExtractedDataChanges`). | `app/Http/Requests/`, `bootstrap/app.php` (exception render) | Nessun contract test runtime che validi ogni shape OpenAPI request/response contro il backend. | Gate di validazione contract per ogni richiesta/risposta OpenAPI. |
| Autenticazione | Modalità locale che inietta claim utente strutturati; modalità trusted-header che richiede claim di identità completi. | `app/Http/Middleware/ResolveMvpIdentity.php`, `app/Mvp/Identity/MvpUser.php`, ADR 0007 | Nessun IdP: in `trusted_headers` gli header `X-Mvp-*` sono falsificabili senza un gateway che li firmi. | Sostituire la modalità locale con un confine IdP aziendale (OIDC/SAML) nel tier di deployment. |
| Autorizzazione | Le route API applicano i ruoli configurati (`mvp-operator`/`mvp-admin`) e l'ownership per tenant lato server su ogni risorsa. I casi d'uso ripetono il controllo di tenant sull'`Actor` ricevuto, senza affidarsi solo al controller. | `app/Http/Middleware/AuthorizeMvpAccess.php`, check nei controller, casi d'uso in `app/Mvp/*/Application/UseCases/` | Logica di tenant scoping solo applicativa (nessun Postgres RLS); nessuna policy class per singola azione. | Policy class per azione, audit di ogni decisione di accesso negata, RLS PostgreSQL su `tenant_id`. |
| Gestione errori | Le eccezioni API, comprese quelle di dominio del messaggio di invio, passano dal gestore centrale e ritornano envelope JSON consistenti con request e correlation ID, senza stack trace al client. | `bootstrap/app.php` (mapping centralizzato), `app/Http/Middleware/CorrelateRequests.php` | Nessun test di redaction centralizzato per ogni classe di errore dei provider upstream. | Test di redaction per ogni classe di errore upstream. |
| Segreti/configurazione | I valori runtime sono caricati da SSM Parameter Store e Secrets Manager; nessuna UI runtime modifica le credenziali; cache config con fingerprint. | `app/Mvp/Support/RuntimeConfigurationLoader.php`, `infra/localstack/main.tf` | Default locali (password note) committati come fallback compose; cache config senza invalidazione runtime. | Ruolo IAM con privilegio minimo, niente default per ambienti remoti, rotazione segreti con invalidazione cache. |
| Logging/monitoring | Log JSON, request/correlation ID, audit event, metriche interne, OTel Collector e alert Prometheus con runbook. | `app/Mvp/Observability/`, `docker/prometheus/rules/`, `docs/runbooks/` | Telemetria locale senza export verso backend enterprise; routing alert demo. | Export telemetria verso backend enterprise e definizione di routing/escalation degli alert. |
| Audit | `audit_events` (append-only) registra generazione comunicazioni, upload, ciclo di vita dell'elaborazione ed eliminazioni, con actor e request/correlation ID. | migrazione `audit_events`, `app/Mvp/Audit/Services/AuditLogger.php` | Nessun controllo di retention immutabile né forwarding verso SIEM. | Retention immutabile e forwarding SIEM. |
| Sicurezza upload | Gli upload PDF sono validati (contenuto + estensione, dimensione, pagine) e archiviati su object storage con path generati server-side; preview via stream con autorizzazione per tenant, senza URL firmati. Il nome file nell'header `Content-Disposition` perde virgolette e caratteri di controllo, CR/LF compresi. | `app/Http/Requests/UploadDocumentRequest.php`, `app/Http/Controllers/` (preview) | Nessuna scansione antivirus né CDR: un PDF malevolo viene comunque inoltrato a Textract, archiviato e ri-servito in preview (a Bedrock arriva solo il testo OCR). | Finalizzazione presigned S3 con validazione metadata server-side e hook antivirus/CDR. |
| Logica di business | Approvazione, scarto e modifica di una bozza e avvio dei workflow leggono la risorsa con lock pessimistico dentro una transazione: due richieste ravvicinate non avviano due esecuzioni né riportano una bozza scartata ad approvata. | `CommunicationDraftService`, `StartCommunicationWorkflowService`, `StartDocumentWorkflowService`; i fake dei test contano le letture `ForUpdate` | Il lock copre le mutazioni elencate, non ogni scrittura del dominio. | Revisione sistematica delle invarianti concorrenti per ogni aggregato. |
| Rate limiting | Le route `/api/v1/*` usano il throttle Laravel su store Redis, con limiti più severi per le operazioni di scrittura (20/min su AI/upload, 60/min in lettura). | `routes/api.php`, `config/cache.php` (limiter store) | Chiavi di throttle per-IP/attore generiche, non tenant-aware; soglie di abuso non documentate. | Chiavi Redis identity/tenant-aware e soglie di abuso documentate. |
| Header di sicurezza/CORS | Nginx applica CSP restrittiva via `map $request_uri` (`default-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'` salvo preview same-origin) più X-Frame-Options, X-Content-Type-Options, Referrer-Policy; superfici legacy e file nascosti bloccati. | `docker/nginx/default.conf` | CSP statica: nuovi asset/embedding richiedono aggiornamento manuale; policy CORS di produzione non finalizzata. | Gestione centralizzata di CSP/CORS a livello Traefik/Nginx per il tier di produzione. |

## Scelte dell'ambiente locale

Tre scelte valgono solo perché lo stack gira in locale. Sono dichiarate qui perché fuori da quel
contesto andrebbero cambiate.

- **Socket Docker in Alloy.** Alloy monta `/var/run/docker.sock` per scoprire i container e
  leggerne i log. Il mount `:ro` protegge il file, non l'API: chi controlla Alloy può usare l'intera
  API Docker, cioè controllare l'host. È accettato per uno stack di sviluppo. In un ambiente
  condiviso servirebbe un proxy del socket limitato agli endpoint di lettura di container e log, o
  la raccolta dei log affidata alla piattaforma (per esempio CloudWatch su ECS).
- **Credenziali predefinite di Grafana.** Senza `.env`, il compose avvia Grafana con `admin` /
  `admin`; `.env.example` riporta lo stesso valore con l'avviso di cambiarlo. Grafana non ha porte
  sull'host ed è raggiungibile solo da Traefik su `grafana.localhost`. Lo stesso vale per la basic
  auth di Prometheus e Alertmanager in `docker/traefik/usersfile`. Gitleaks le tratta come eccezioni
  esplicite (`.gitleaks.toml`).
- **`trustProxies(at: '*')`.** Laravel si fida degli header `X-Forwarded-*` di qualunque
  mittente (`bootstrap/app.php`). PHP-FPM è raggiungibile solo sulla rete interna `backend`, dove
  solo Nginx gli inoltra richieste (FastCGI); Traefik non accetta header inoltrati dal client, perché
  non ha `forwardedHeaders` configurati. Su un'infrastruttura reale la lista va ristretta agli
  indirizzi del load balancer.

## Riferimenti

- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- OWASP Top 10 A09: Security Logging and Monitoring Failures: https://owasp.org/Top10/2021/A09_2021-Security_Logging_and_Monitoring_Failures/
- OWASP API Security Top 10 (2023): https://owasp.org/API-Security/editions/2023/en/0x00-header/
- OWASP File Upload Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
