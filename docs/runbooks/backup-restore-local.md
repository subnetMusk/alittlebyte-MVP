# Backup e restore locale di PostgreSQL

Procedura dimostrativa per l'ambiente locale. Copre solo il database, senza point-in-time recovery
né retention automatica, e non sostituisce una strategia di backup di produzione.

Prerequisito: stack avviato, con il container `postgres` in esecuzione.

## Backup

```bash
make backup-local
```

Il target esegue `pg_dump --clean --if-exists` nel container `postgres` e scrive il dump in
`backups/local/mvp-YYYYmmdd-HHMMSS.sql`. La cartella `backups/` non è versionata.

## Restore

Ferma i worker, perché non scrivano durante il ripristino:

```bash
docker compose stop queue queue-communications
```

Applica il dump:

```bash
make restore-local BACKUP=backups/local/mvp-YYYYmmdd-HHMMSS.sql
```

Il target applica il dump con `psql -v ON_ERROR_STOP=1`. Il dump è generato con `--clean
--if-exists`, quindi elimina e ricrea gli oggetti: si può ripristinare sopra un database già
migrato, senza passaggi preliminari.

Riavvia i worker:

```bash
docker compose start queue queue-communications
```

## File dei documenti

Il target copre solo PostgreSQL. Originali, sotto-documenti, copertine ed export stanno nello
storage S3, locale o reale secondo `MVP_DOCUMENT_DISK` e `MVP_COMMUNICATION_COVER_DISK`. Per una
ricostruzione completa vanno conservati anche bucket e prefissi coerenti con i path salvati nel
database.

## Verifica

1. Esegui `make backup-local` e annota il file creato in `backups/local/`.
2. Modifica solo dati del database dalla SPA, per esempio correggendo un campo estratto o segnando
   un documento come revisionato. Non usare `make fresh`: oltre al database cancella originali,
   copertine ed export dallo storage, anche dal bucket reale con `real_s3`, e il restore non li
   ripristina.
3. Esegui `make restore-local BACKUP=<file>`.
4. Verifica dalla SPA, o con `GET /api/v1/state`, che le modifiche del passo 2 siano annullate.
