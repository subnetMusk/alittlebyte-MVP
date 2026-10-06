# Icone dei diagrammi

Icone usate dai sorgenti D2 in [`../`](../), copiate qui perché il render non dipenda dalla rete. Nel
repository ci sono solo le icone effettivamente usate.

| Cartella | Origine | Condizioni |
| --- | --- | --- |
| `aws/` | [AWS Architecture Icons](https://aws.amazon.com/architecture/icons/), pacchetto `Icon-package_07312026`, icone di servizio a 48 px | Uso consentito da AWS per diagrammi di architettura; le icone non vanno modificate, ritagliate o ricolorate |
| `devicon/` | [Devicon](https://github.com/devicons/devicon) v2.17.0, variante `original` (`plain` per Grafana, perché la `original` non è importabile da D2) | Licenza MIT, in [`devicon/LICENSE`](devicon/LICENSE); i marchi appartengono ai rispettivi proprietari |

Per aggiungere un'icona: prendila dalla stessa fonte e versione, verifica che `d2` la importi
(alcuni SVG con gradienti fuori da `<defs>` vengono rifiutati) e aggiungila qui solo se un diagramma
la usa.
