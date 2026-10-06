# OpenAPI v1

Contratto versionato dell'API JSON usata dalla SPA Angular. Il file canonico è
[`alittlebyte-mvp-api.yaml`](alittlebyte-mvp-api.yaml).

Dopo una modifica al contratto, validalo con lo stesso lint Redocly della CI:

```bash
make openapi-validate
```

Per rigenerare il client TypeScript:

```bash
make openapi-generate
```

I file generati in `apps/frontend/src/api/generated` non vanno modificati a mano: la CI fallisce se
non corrispondono al contratto.
