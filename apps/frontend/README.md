# SPA del frontend

SPA Angular/TypeScript della MVP. Struttura, ViewModel e sistema visivo sono descritti in
[`docs/architecture/frontend.md`](../../docs/architecture/frontend.md).

## Comandi

Dalla radice del repository:

```bash
make openapi-generate
make frontend-lint
make frontend-typecheck
make frontend-test
make frontend-build
make frontend-s3-local-deploy
```

`make verify-frontend` esegue in sequenza generazione del client, lint, typecheck, test e build.

Il package radice usa i workspace npm. I comandi girano nel container Compose `node`
(`node:22-bookworm-slim`), non con il Node dell'host.

L'app chiama Laravel con URL relativi `/api/v1`. `proxy.conf.json` allinea `ng serve` all'ingresso
locale Traefik/Nginx; la build di produzione è statica e nel flusso locale la serve `edge-cdn` dal
bucket S3 di LocalStack, il ruolo che in produzione avrebbe una CDN come CloudFront.
