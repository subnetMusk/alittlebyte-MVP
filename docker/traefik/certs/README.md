# Certificati locali di Traefik

Certificato e chiave privata locali sono artefatti runtime e non vanno committati (sono in
`.gitignore`). Si generano dalla radice del repository:

```bash
make local-tls
```

Il target usa `scripts/tls/generate-local-cert.sh` e crea un certificato self-signed: i browser
mostrano un avviso finché non lo si considera attendibile.

Per un certificato riconosciuto dal browser, con `mkcert` installato sull'host:

```bash
make trusted-local-tls
docker compose restart traefik
```

Il target usa la CA locale di mkcert e scrive gli stessi file `mvp-local.test.crt` e
`mvp-local.test.key` letti da Traefik. Il riavvio serve perché Traefik osserva solo la cartella della
configurazione dinamica, non i file del certificato. La procedura completa è in
[Sviluppo locale](../../../docs/runbooks/local-development.md).
