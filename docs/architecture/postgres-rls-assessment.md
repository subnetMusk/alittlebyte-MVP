# Valutazione della Row-Level Security in PostgreSQL

## Tabelle con tenant

Le tabelle con una colonna `tenant_id` sono:

- `original_documents`;
- `communications`;
- `prompt_configurations`;
- `audit_events`.

Le altre ereditano il tenant tramite relazioni:

- `sub_documents` tramite `original_documents`;
- `extracted_data` tramite `sub_documents` e `original_documents`;
- `workflow_tasks` tramite la relazione polimorfica `subject_type`/`subject_id`, verso
  `original_documents` o `communications`: serve una policy per tipo di subject.

## Modello possibile

Una policy RLS potrebbe usare `current_setting('app.tenant_id', true)`:

```sql
tenant_id = current_setting('app.tenant_id', true)
```

Per le tabelle figlie servirebbero policy con `EXISTS` sulle relazioni padre.

## Dove impostare il tenant

Il tenant viene risolto in `ResolveMvpIdentity`. Con RLS, Laravel dovrebbe impostare il valore sulla
connessione prima di ogni query con tenant, per esempio con:

```sql
SET LOCAL app.tenant_id = '<tenant>';
```

Il punto naturale è un middleware dopo `mvp.identity`, oppure un wrapper transazionale per ogni
richiesta e ogni task di workflow.

## Rischi

- `SET LOCAL` vale solo dentro una transazione: senza una transazione per l'intera richiesta, il
  valore non copre tutte le query.
- `SET` di sessione rischia di propagare il tenant fra richieste con connessioni persistenti o
  pooling.
- Worker e comandi Artisan non passano dal middleware HTTP.
- I test cross-tenant andrebbero estesi a tutte le query Eloquent e ai task di workflow.
- Le policy sulle tabelle figlie possono rallentare le query se le relazioni non sono indicizzate.

## Decisione

RLS non è implementata. La MVP applica il controllo di tenant e di ruolo nel codice, nei controller e
nei casi d'uso, che confrontano il tenant dell'`Actor` con quello della risorsa. Aggiungere RLS senza
transazioni estese all'intera richiesta e senza una copertura di test più ampia rischierebbe di
rompere workflow e test.

Stima per un'implementazione sicura: 2-4 giorni, comprendenti middleware per lo stato della sessione
del database, policy SQL reversibili, test cross-tenant su API e worker e verifica con PostgreSQL
reale nello stack locale.
