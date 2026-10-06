# Terraform per LocalStack

Questa cartella contiene l'infrastruttura AWS emulata della MVP. Terraform gira solo nel container
Compose `terraform`, contro l'endpoint interno `http://localstack:4566`; dall'host LocalStack è su
`http://127.0.0.1:4566`.

```bash
make infra-init
make infra-plan
make infra-apply
make infra-destroy
```

Risorse modellate:

- due code SQS (documenti, comunicazioni), ciascuna con la propria DLQ;
- due state machine Step Functions, con le definizioni ASL in `state-machines/`, e il ruolo IAM che
  le esegue (non applicato da LocalStack);
- bucket S3 dei documenti, cifrato con una chiave KMS;
- bucket S3 per gli asset statici della SPA Angular;
- parametri SSM Parameter Store e un secret di Secrets Manager per la configurazione runtime;
- bus EventBridge con rule e target SQS, e un'identità SES: scaffolding non usato dal codice.

Compose avvia LocalStack e i processi applicativi; Terraform crea le risorse. La SPA segue il
percorso S3 locale più emulatore di CDN: Terraform possiede il bucket, e il servizio `edge-cdn`, un
secondo Nginx che emula il ruolo di una CDN (non Amazon CloudFront), lo serve e inoltra le chiamate
API all'Nginx applicativo. È un container separato apposta: l'Nginx applicativo è un'immagine di
produzione e non deve conoscere LocalStack. Così gli asset della SPA restano separati anche dal
bucket S3 reale opzionale dei documenti.

```bash
make frontend-s3-local-deploy
make edge-cdn-local-url
make frontend-serving-local-test
```

L'emulatore è un Nginx in Docker perché l'immagine LocalStack usata non espone l'API CloudFront con
la licenza predefinita. Verifica il percorso build → bucket → edge in locale, ma non sostituisce una
CDN reale: in produzione il ruolo spetterebbe a CloudFront (certificati TLS, propagazione, invalidazioni,
OAC, policy degli header, IAM applicato).
