# Diagrammi

Sorgenti [D2](https://d2lang.com/) delle viste architetturali e SVG generati. Il sorgente `.d2` è la
fonte di verità; lo SVG con lo stesso nome si rigenera e si versiona insieme al sorgente. Titoli e
legende stanno nelle caption Markdown dei documenti, non nei diagrammi.

| Sorgente | Vista | Usata in |
| --- | --- | --- |
| [`container-view.d2`](container-view.d2) | Container, con profilo standard e profilo local | [`../final-architecture.md`](../final-architecture.md#container), [`../../../README.md`](../../../README.md) |
| [`observability-view.d2`](observability-view.d2) | Flussi di metriche e log | [`../../runbooks/observability.md`](../../runbooks/observability.md#flusso-delle-metriche) |
| [`sqs-message-lifecycle.d2`](sqs-message-lifecycle.d2) | Stati di un messaggio di task in SQS | [`../../runbooks/dlq-recovery.md`](../../runbooks/dlq-recovery.md) |

Le icone sono in [`icons/`](icons/README.md), con origine e licenza. I diagrammi draw.io del corso
sono archiviati in [`../../archive/diagrams/`](../../archive/diagrams/).

## Render

Serve D2 0.9 o successivo (`brew install d2`, oppure l'immagine Docker `d2lang/d2`). Layout engine,
tema e padding sono dichiarati in ogni sorgente; le spaziature di ELK si passano solo da riga di
comando, con gli stessi valori per tutti i diagrammi:

```bash
d2 --omit-version --elk-nodeNodeBetweenLayers=30 --elk-edgeNodeBetweenLayers=15 docs/architecture/diagrams/container-view.d2 docs/architecture/diagrams/container-view.svg
```

Dopo una modifica, guarda il risultato prima di committarlo: un sorgente valido può produrre label
sovrapposte o frecce che girano intorno al diagramma. Per un'anteprima PNG della vista container usa
`--scale 0.6`: a dimensione piena supera il limite del rasterizzatore di D2.
