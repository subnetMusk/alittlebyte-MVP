# ADR 0012: Sistema visivo e linguaggio dell'interfaccia della SPA

Status: Accepted, implemented
Date: 2026-08-18

## Context

Il linguaggio visivo della SPA Angular era cresciuto per accumulo. Una ricognizione del frontend ha
trovato difetti misurabili, non questioni di gusto:

- `--mvp-danger-soft` era usata ma non definita: la nota di tono `alert`, l'unica in uso, compariva
  senza sfondo.
- L'anello di focus (`#f28a52`) misurava fra 2,23 e 2,46 di contrasto contro le superfici del tema
  chiaro, sotto il 3:1 di [SC 2.4.13](https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html),
  e nel tema scuro non era ridefinito. Lo stesso arancione portava altri quattro significati.
- Lo stack tipografico partiva da "Avenir Next", che esiste solo su macOS: su Windows e Linux il
  carattere effettivo era sempre un ripiego.
- La coppia etichetta + controllo esisteva in otto varianti; c'erano cinque breakpoint non allineati
  e nomi di classe in due grafie.
- La data di caricamento prescritta da UC-40.15 mancava dall'elenco documenti, e il comando di
  preparazione del messaggio era disponibile anche su un sotto-documento in quarantena.

Le alternative sono state costruite come cataloghi HTML/CSS autonomi, fuori da Angular, con almeno
cinque proposte per sezione; i cataloghi erano materiale di lavoro e non sono nel repository.

Vincoli: axe e Pa11y su tutte le interfacce principali (RVC9-OB); browser evergreen (RVC10-OB), quindi
container query, `:has()`, `color-mix()` e `@layer` utilizzabili; CSP che vieta font da domini
esterni; budget di build di 16 kB per foglio di stile; ADR 0011 in vigore.

## Decision

Adottare il sistema visivo e il registro linguistico descritti in
[`../architecture/frontend.md`](../architecture/frontend.md#sistema-visivo). I punti che decidono:

- **Focus** `3px solid #098faa` con `outline-offset: 2px`, uguale nei due temi. Il colore viene da
  una misura: con l'offset l'anello confina solo con le superfici della paletta, e una luminanza fra
  0,193 e 0,300 supera 3:1 su tutte; `#098faa` misura da 3,40 a 4,74.
- **Tipografia** IBM Plex Sans ospitato nel progetto, Plex Mono solo per i valori delle metriche;
  cifre tabellari con `tnum`.
- **Provenienza del dato** dichiarata con colore e glifo dentro la casella, perché il colore da
  solo non basta ([SC 1.4.1](https://www.w3.org/WAI/WCAG21/Understanding/use-of-color.html)).
- **Badge con due forme** per due grandezze diverse: validazione e scaricamento.
- **Schede metrica con una forma per tipo di dato**, otto varianti su una griglia a mosaico.
- **Gerarchia dei pulsanti per collocazione**: le azioni conclusive in una barra a fondo pannello.
- **Preparazione del messaggio disabilitata finché una persona non conferma i dati**, con il motivo
  accanto al comando. Una validazione automatica non basta: la soglia dice che il testo era
  leggibile, non che il documento sia della persona a cui verrà consegnato. Il caso d'uso impone lo
  stesso vincolo lato API.
- **Registro neutro operativo**; un'azione bloccata dice cosa fare per sbloccarla; "disabilitato"
  significa solo vincolo, l'attesa ha un proprio indicatore.
- Classi CSS in camelCase.

## Consequences

- Il focus è conforme nei due temi e l'override del tema scuro non serve più.
- Il carattere ospitato pesa sul budget di build: si usa un sottoinsieme minimo.
- Le colonne numeriche dipendono dal supporto di `tnum` in Plex Sans.
- La provenienza per campo richiedeva che il contratto esponesse la confidenza di ogni campo:
  l'[ADR 0013](0013-per-field-ocr-confidence.md) l'ha aggiunta (`fieldConfidences`,
  `lowConfidenceFields`). La provenienza manuale non è persistita: la penna segna i campi corretti
  nella sessione e, dopo il salvataggio, l'intera scheda validata a mano.
- Il gate a11y copre `/overview`, `/assistant` e `/copilot`. Estenderlo a tre pagine ha fatto
  emergere `<form>` senza comando di invio, sostituiti da gruppi di controlli con salvataggio
  esplicito; con lo stack locale il gate risulta a 0 violazioni axe e 0 problemi Pa11y.

## Alternatives considered

- **Cambiare paletta** (superfici neutre, alto contrasto, carta e inchiostro, scuro nativo,
  monocromatico): scartata. I difetti misurati non venivano dalla paletta ma da un token mancante e
  da un focus fuori scala.
- **Focus a doppio anello o a inversione**: non necessario, perché esiste una finestra di luminanza
  utilizzabile. Resta la soluzione se la paletta introducesse superfici a luminanza intermedia.
- **Confidenza per campo accanto a ogni etichetta**: non realizzabile al momento della decisione,
  perché il backend calcolava un solo punteggio per sotto-documento. È stata realizzata in seguito
  con l'ADR 0013.
- **Modifica in linea nella tabella, coda di triage per confidenza, pannello laterale,
  collegamento sorgente↔campo con le coordinate di Textract**: rinviate. Riguardano il modello di
  interazione; l'ultima richiederebbe di persistere coordinate che la pipeline non conserva.
- **Valutazione a pollice su/giù**: scartata, perché il contratto vincola `rating` a un intero fra 1
  e 5.

## Questioni aperte

Dipendono dai requisiti, non dall'interfaccia:

1. Il pulsante «Invia» dovrebbe chiamarsi «Scarica», perché nella MVP non avviene alcun invio; UC-51
   però nomina il tasto alla lettera. Lo stato è già «Scaricato»/«Non scaricato».
2. La valutazione non è ripetibile (UC-25): il vincolo è nell'entità `Communication::rate()`, nel
   contratto e nella View.
3. Quali dati mostrare nella Overview: oggi le tre metriche su cui si agisce, non un riassunto di
   tutte.

## Related documents

- [`../architecture/frontend.md`](../architecture/frontend.md): specifica completa del sistema visivo
  e mappa dei file.
- [ADR 0011](0011-frontend-presentation-model-and-sse-client.md): ViewModel puro, confine invariato.
- [ADR 0008](0008-angular-frontend-static-serving.md): SPA statica da S3, origine del vincolo CSP sui
  font.
- [ADR 0013](0013-per-field-ocr-confidence.md): confidenza per campo.
- [`../mvp-scope.md`](../mvp-scope.md): perimetro funzionale.
