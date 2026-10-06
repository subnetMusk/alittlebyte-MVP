# Frontend: struttura, ViewModel e sistema visivo

La SPA Angular in `apps/frontend` serve le due aree della MVP, AI Assistant e Co-Pilot, più una
Overview. Questo documento descrive com'è organizzata e il sistema visivo che usa. Le decisioni e le
loro motivazioni sono negli ADR [0011](../architecture-decisions/0011-frontend-presentation-model-and-sse-client.md)
(ViewModel e SSE) e [0012](../architecture-decisions/0012-frontend-design-system-and-ui-language.md)
(sistema visivo e linguaggio).

## Struttura

| Percorso | Contenuto |
| --- | --- |
| `src/app/features/{overview,assistant,copilot}/` | Una pagina per area: View (`*-page.ts`), ViewModel (`*-page.view-model.ts`), componenti di feature, servizi dati |
| `src/app/core/` | Stato condiviso (`MvpStateStore`), client HTTP e SSE, interceptor, errori, navigazione, tema |
| `src/app/shared/` | Componenti condivisi (schede metrica, badge, avanzamento, campi, stelle), stili e utility pure |
| `src/api/generated/` | Client e modelli generati da Orval a partire da `openapi/v1/alittlebyte-mvp-api.yaml`; la CI fallisce se non è allineato al contratto |

La SPA viene compilata e caricata nel bucket S3 di LocalStack, da cui la serve `edge-cdn`
([ADR 0008](../architecture-decisions/0008-angular-frontend-static-serving.md)).

## ViewModel puro

Ogni pagina ha un ViewModel: una classe TypeScript costruita con `new`, che riceve le dipendenze dal
costruttore. Il componente (la View) è l'unico punto accoppiato ad Angular: si procura le dipendenze
con `inject()`, crea il ViewModel e nel template legge solo `vm.*`.

- `signal()` e `computed()` stanno nel ViewModel: sono primitive reattive e non richiedono un
  injection context, quindi i test `*.view-model.spec.ts` lo costruiscono con `new`, senza `TestBed`.
- `effect()` e `takeUntilDestroyed()` richiedono un injection context e restano nella View. L'effect
  legge i segnali sorgente e chiama un metodo del ViewModel, per esempio `vm.reload()`.
- Il ViewModel non tocca il DOM. Quando serve uno scroll imposta `pendingScrollTarget`; un `effect()`
  nella View lo legge, esegue `scrollToElement` e lo azzera. All'adozione dell'ADR 0011 lo scroll era
  una funzione passata al costruttore; il segnale ha tolto l'ultima chiamata dal ViewModel alla View.
- `reload()` annulla la ricerca precedente ancora in volo, e `destroy()` la annulla quando il
  componente viene distrutto. Le azioni di scrittura (upload, generazione, scarto) non vengono
  annullate: devono completarsi lato server anche se l'utente cambia pagina.
- `MvpStateStore.reload()` ha la stessa guardia contro le richieste concorrenti di `loadOnce()`.
- I componenti che mostrano un form risincronizzano i campi solo quando cambia l'id o il contenuto
  del dato sorgente, non a ogni nuovo riferimento: una mutazione altrove nella pagina non cancella
  le modifiche non salvate.

## Client SSE

L'avanzamento delle pipeline arriva via Server-Sent Events. `core/http/sse-client.ts` usa `fetch()`
invece di `EventSource` per due ragioni:

1. invia gli header di richiesta e di correlazione (request ID e correlation ID), che `EventSource`
   non può inviare;
2. distingue l'evento `error` inviato dal backend (`onNamedError`) dalla caduta della connessione
   (`onConnectionError`): un problema di rete non appare come fallimento della pipeline.

Il parsing dei frame è manuale (`consumeSseBuffer`), e un payload non JSON diventa
`{ message: rawData }` invece di rompere l'handler. La fase `still_running` resta distinta da
`completed` e `failed` fino alla barra di avanzamento: un timeout dello stream non è un errore.

## Sistema visivo

### Fondamenta

| Ambito | Scelta |
| --- | --- |
| Colore | Paletta del progetto, con la coppia `--mvp-danger-soft` (`#fff0f0` / `#3b2222`) |
| Tipografia | IBM Plex Sans ospitato nel progetto. IBM Plex Mono solo dove il numero è il contenuto (il valore della scheda metrica), mai per cifre dentro il testo |
| Cifre in tabella | Plex Sans con `font-variant-numeric: tabular-nums`: l'incolonnamento lo dà `tnum`, non il cambio di famiglia |
| Separazione dei blocchi | Bordo e ombra |
| Densità | Compatta dove si scorre (tabelle, elenchi), comoda dove si scrive (form, ispettore) |
| Focus | Anello `3px solid` `#098faa`, `outline-offset: 2px`, uguale nei due temi |
| Etichette | **A** (sopratitolo, etichetta di indicatore): maiuscolo, peso 700, `letter-spacing: .07em`. **B** (etichetta di campo): minuscolo, peso 600 |

Il colore del focus viene da una misura. Con `outline-offset ≥ 2px` l'anello non tocca il
riempimento del controllo, quindi confina solo con le superfici della paletta. Esiste una finestra
di luminanza, da 0,193 a 0,300, in cui un colore unico supera il contrasto 3:1 di
[SC 2.4.13](https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html) su tutte le superfici
dei due temi. `#098faa` misura da 3,40 a 4,74. `outline-offset ≥ 2px` è quindi un vincolo di
sistema: a offset zero la misura non vale più.

### Primitivi

- **Pulsanti: gerarchia per collocazione.** Le azioni che chiudono il compito stanno in una barra a
  fondo pannello, sempre nello stesso punto; quelle accessorie restano in linea come collegamenti.
- **Campi: provenienza del dato.** Colore più un glifo dentro la casella, a destra, separato da un
  filo verticale: scintille per un campo estratto con buona confidenza, punto interrogativo per uno
  sotto la propria soglia ([ADR 0013](../architecture-decisions/0013-per-field-ocr-confidence.md)),
  penna per un campo corretto a mano, lucchetto per un dato di sistema. Una casella vuota non ha
  glifo. La legenda compare una volta, in cima al pannello. Il glifo è il secondo segnale visivo
  richiesto da [SC 1.4.1](https://www.w3.org/WAI/WCAG21/Understanding/use-of-color.html).
- **Badge: due forme per due grandezze.** Rettangolo a contorno per lo stato di validazione, pallino
  pieno o vuoto per lo stato di scaricamento.
- **Schede metrica: una forma per tipo di dato.** Otto varianti: conteggio con barre giornaliere,
  quota, misura su scala con soglia, verdetto, ripartizione ad anello, tempo per fase, densità come
  curva continua, media in stelle frazionarie. Stanno a mosaico su una griglia di quattro colonne;
  quelle con un asse o una legenda ne occupano due. Il tono di stato è il bordo della scheda.
- **Segnalazioni a blocco** (avviso di pagina, nota di attenzione, metriche non disponibili): il
  colore dello stato prende l'intero riquadro.
- **Valutazione.** Stelle in SVG, riempimento fino al valore scelto, bersaglio di 44 px, nome
  accessibile per ogni stella, valore affiancato come `X/5`.

### Flusso

La preparazione del messaggio di invio è disabilitata finché una persona non conferma i dati, e il
motivo è scritto accanto al comando. Il vincolo è anche nel caso d'uso (`SendMessageService::export()`),
perché l'API si può chiamare senza passare dal pannello.

### Compositi e pagine

- **Storico documenti: sette colonne.** Tipologia, data e ora di caricamento, confidenza,
  validazione e scaricamento in colonne distinte; nella riga secondaria il documento originale
  (azienda, nome file, data del documento).
- **Ispettore: griglia piatta**, con la barra di azione a fondo pannello.
- **Avanzamento: tappe con tempo trascorso.**
- **Guscio:** sidebar larga con sotto-voci. L'etichetta dei gruppi di navigazione non è un heading,
  perché la sidebar precede l'`<h1>` nel DOM, ed è collegata con `aria-labelledby`.
- **Overview:** solo dati, in forma di riquadri.

### Linguaggio

| Ambito | Scelta |
| --- | --- |
| Registro | Neutro operativo: terza persona, niente "tu" né "noi" |
| Azione bloccata | Si dice cosa fare per sbloccarla, e quell'azione è il pulsante accanto: «Conferma i dati per preparare il messaggio.» |
| Attesa | Etichetta invariata più indicatore e `aria-busy`: "disabilitato" significa solo vincolo |
| Errore | Per famiglia (rete, validazione, permesso, dato mancante), ciascuna con la propria azione |
| Stati vuoti | Distinti fra "non c'è ancora nulla" e "nessun risultato per questi filtri" |

Alcune stringhe non si cambiano perché vengono dai requisiti o dal contratto: `Storico documenti
analizzati`, `Non disponibile`, `Genera bozza`, `Invia`, `Scaricato`/`Non scaricato`, `Senza nome (N)`,
toni e stili della comunicazione, tipologie di documento e le etichette che arrivano dal backend
(`reviewStatusLabel`, `sendStatusLabel`).

Classi CSS in camelCase (`.reviewActions`). Tre soglie responsive: 640, 900 e 1100 px.

## Accessibilità

La CI esegue axe e Pa11y su `/overview`, `/assistant` e `/copilot` contro lo stack HTTPS reale, più
uno smoke della SPA con la CSP applicata (`scripts/a11y/`). La CSP vieta font e immagini da domini
esterni, per questo i caratteri sono ospitati in `apps/frontend/public/`.

## Dove sta nel codice

| Elemento | Percorso |
| --- | --- |
| Token di colore e tema | `src/styles/tokens.css` |
| Stili condivisi (campo, avvisi, collegamenti-azione, pagina) | `src/app/shared/styles/` |
| Schede metrica e geometria dei grafici | `src/app/shared/components/metric-*/`, `src/app/shared/util/charts.ts` |
| Glifo di provenienza del campo | `src/app/features/copilot/components/field-origin/` |
| Storico documenti | `src/app/features/copilot/components/document-list.*` |
| Badge e pallino di stato | `src/app/shared/components/status-badge/`, `status-dot/` |
| Valutazione a stelle | `src/app/shared/components/star-rating/` |
| Avanzamento a tappe | `src/app/shared/components/stage-progress/` |
| Pulsante con stato di attesa | `src/app/shared/components/button/` |
