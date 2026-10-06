# Permessi IAM e accesso ad AWS reale

Questa è una **proposta** di privilegio minimo ricavata dalla MVP, non una policy di produzione
definitiva. LocalStack non applica i permessi IAM: Terraform crea ruoli e policy (per esempio
l'execution role di Step Functions), ma diventano effettivi solo su AWS reale.

## Accesso da GitHub Actions

La CI ordinaria non usa AWS reale. L'unico workflow che lo chiama è `aws-smoke.yml`, manuale e non
bloccante, con due modalità di autenticazione:

1. **OIDC**, preferita: un ruolo IAM con trust policy limitata a questo repository, al branch o
   all'environment e al workflow, assunto con `sts:AssumeRoleWithWebIdentity`. Il workflow dichiara
   `id-token: write`.
2. **Credenziali effimere** come secret del repository (`AWS_REAL_ACCESS_KEY_ID`,
   `AWS_REAL_SECRET_ACCESS_KEY`, `AWS_REAL_SESSION_TOKEN`), caricate prima del run e revocate o
   lasciate scadere dopo.

Nessuna credenziale AWS statica resta nei workflow.

## Matrice dei permessi

| Componente | Azione IAM | Risorsa | Motivo | Ambiente | Note |
| --- | --- | --- | --- | --- | --- |
| API Laravel | `s3:PutObject` | Bucket o prefisso dei documenti | Salvare il documento caricato per OCR e AI | AWS reale (smoke, futura produzione) | Meglio un prefisso per tenant o ambiente. |
| API Laravel | `s3:GetObject` | Bucket o prefisso dei documenti | Leggere l'originale come input di Bedrock e per l'anteprima | AWS reale | Necessario quando l'elaborazione legge da S3. |
| API Laravel | `s3:GetObject` | Prefisso copertine (`communications/covers/`) | Servire la copertina in streaming | LocalStack, futura produzione | Nessun URL presigned: il controllo di tenant resta applicativo. |
| API Laravel | `s3:PutObject`, `s3:DeleteObject` | Prefisso copertine | Sostituire o rimuovere la copertina a mano | LocalStack, futura produzione | La sostituzione scrive una chiave nuova e cancella la precedente. |
| API Laravel | `states:StartExecution` | State machine documentale e state machine delle comunicazioni | Avviare i workflow | LocalStack, futura produzione | Due state machine distinte. |
| Deploy della SPA | `s3:PutObject`, `s3:DeleteObject`, `s3:ListBucket` | Bucket statico della SPA (`FRONTEND_STATIC_BUCKET`) | Sincronizzare `apps/frontend/dist` | LocalStack, futura produzione | Bucket separato dai documenti. In produzione anche `cloudfront:CreateInvalidation` per `index.html`. |
| Serving della SPA | `s3:GetObject` | Oggetti del bucket statico | Servire gli asset | LocalStack, futura produzione | In LocalStack il bucket è public-read solo per `edge-cdn`. In produzione bucket **privato** dietro CloudFront con OAC (principal `cloudfront.amazonaws.com`, condizione `AWS:SourceArn`), mai `Principal: "*"`. |
| Worker | `sqs:ReceiveMessage`, `sqs:DeleteMessage` | Coda dei task della propria pipeline | Consumare i task e confermarli dopo il callback | LocalStack, futura produzione | Ogni worker legge solo la propria coda. |
| Worker | `sqs:GetQueueAttributes` | Code e DLQ delle due pipeline | Readiness, probe delle DLQ, `mvp:dlq:list` | LocalStack, futura produzione | |
| Worker | `states:SendTaskSuccess`, `states:SendTaskFailure`, `states:SendTaskHeartbeat` | Callback token delle esecuzioni | Riprendere il workflow ed evitare il timeout dei task lunghi | LocalStack, futura produzione | Limitati alla state machine dove l'API lo consente. |
| Worker OCR | `textract:StartDocumentTextDetection`, `textract:GetDocumentTextDetection` | `*` o risorsa supportata | OCR asincrono | Solo AWS reale | Lo scoping per risorsa di Textract è limitato: verificarlo con il policy simulator. |
| Worker AI | `bedrock:Converse` | Modello testo o inference profile | Split, estrazione e generazione del testo | Solo AWS reale | L'accesso al modello dipende da account e regione. |
| Worker AI | `bedrock:InvokeModel` | Modello immagini (`BEDROCK_IMAGE_MODEL_ID`) | Generare la copertina | Solo AWS reale | Senza accesso la copertina degrada con il motivo e la comunicazione resta valida. |
| Worker AI | `s3:PutObject` | Prefisso copertine | Salvare la copertina generata | LocalStack, futura produzione | Il record conserva solo il path relativo. |
| Caricamento della configurazione | `ssm:GetParameter`, `ssm:GetParametersByPath` | Path SSM della MVP | Configurazione runtime | LocalStack, futura produzione | Sola lettura. |
| Caricamento della configurazione | `secretsmanager:GetSecretValue` | Secret runtime | Segreti runtime | LocalStack, futura produzione | Sola lettura, senza list. |
| Smoke AWS (`aws-smoke.yml`) | `sts:AssumeRoleWithWebIdentity` | Ruolo dello smoke | Autenticazione OIDC | GitHub Actions, manuale | |
| Smoke AWS | `s3:PutObject`, `s3:GetObject` (per `head-object`), `s3:DeleteObject` | Prefisso di prova nel bucket reale | Round-trip S3 | GitHub Actions, manuale | L'oggetto viene cancellato a fine run. |
| Smoke AWS | `textract:DetectDocumentText` | `*` | OCR sincrono sull'immagine di prova | GitHub Actions, manuale | Lo smoke usa l'API sincrona, l'applicazione quella asincrona. |
| Smoke AWS | `bedrock:Converse` | Modello testo | Una risposta di prova | GitHub Actions, manuale | |

Servirebbero solo con un deploy reale, oggi non definito: `cloudfront:CreateInvalidation`, CloudWatch
Logs e metriche, permessi sul registry delle immagini. EventBridge e SES esistono in Terraform ma il
codice non li usa: non richiedono permessi applicativi.

## Modalità ibrida locale

Con `MVP_DOCUMENT_DISK=real_s3` e `TEXTRACT_ENABLED=true`, lo stack locale legge dal `.env` un unico
set di credenziali (`AWS_REAL_ACCESS_KEY_ID`, `AWS_REAL_SECRET_ACCESS_KEY`, `AWS_REAL_SESSION_TOKEN`)
condiviso da S3, Textract e Bedrock. Quel principal deve quindi avere insieme l'accesso agli oggetti
S3, l'OCR asincrono di Textract e l'invocazione dei modelli Bedrock. S3 e Textract devono stare
nella stessa regione; Bedrock può usarne un'altra, dove il modello è abilitato. La procedura è in
[`../runbooks/local-development.md`](../runbooks/local-development.md#aws-reale-per-ocr-e-ai).

## Prerequisiti per uno smoke reale

Lo smoke resta condizionale e non bloccante finché non esistono: un ruolo IAM con trust OIDC verso
questo repository, l'accesso confermato ai modelli Bedrock scelti e un bucket nella stessa regione di
Textract.
