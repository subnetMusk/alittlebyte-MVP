<?php

namespace App\Mvp\Ai;

/**
 * Prompt dei modelli testuali, condivisi da tutti i provider: il contratto di
 * output che AiOutputValidator verifica nasce qui, quindi un provider diverso
 * deve ricevere esattamente le stesse istruzioni.
 */
final class TextModelPrompts
{
    /**
     * Il modello testuale descrive anche la copertina: avendo davanti il testo
     * che ha appena scritto, produce una direzione visiva coerente con la
     * comunicazione reale invece che con il solo prompt dell'operatore.
     */
    public static function communication(string $userPrompt, string $tone, string $style): string
    {
        return "Agisci come un assistente HR. Genera una comunicazione con tono '{$tone}' e stile '{$style}'.\n"
             ."Argomento: {$userPrompt}\n"
             // Ne' il PDF ne' l'anteprima interpretano Markdown: un asterisco
             // arrivato fin li' si legge come un asterisco.
             .'Scrivi il corpo in testo semplice: niente Markdown, niente cancelletti, asterischi, '
             .'trattini di elenco, trattini bassi o backtick. Separa i paragrafi con una riga vuota '
             ."e apri con il segno • ogni voce di un eventuale elenco. Non ripetere il titolo nel corpo.\n"
             // Chiedere paragrafi e' chiedere ritorni a capo, e dentro una
             // stringa JSON un ritorno a capo vero rompe il documento.
             ."Nel JSON i ritorni a capo vanno scritti come \\n dentro le stringhe, mai andando a capo davvero.\n"
             .'Produci anche "imagePrompt": la descrizione in inglese, massimo 60 parole, dell\'immagine di copertina '
             .'coerente con il contenuto che hai generato. Descrivi soggetto, composizione, atmosfera e palette. '
             ."Non includere testo leggibile, loghi, filigrane, volti riconoscibili o dati personali.\n"
             .'Rispondi esclusivamente in formato JSON: {"title": "...", "body": "...", "imagePrompt": "..."}';
    }

    /**
     * Classifica il testo OCR di un documento e ne restituisce i confini per
     * destinatario. Vale per qualsiasi tipologia e restituisce sempre almeno un
     * destinatario.
     */
    public static function splitDocument(string $ocrText, int $pageCount, string $pageBoundaryNonce): string
    {
        $pageCount = max(1, $pageCount);
        $markerExample = self::pageBoundaryMarker(1, $pageBoundaryNonce);

        return "Sei un classificatore documentale. Ricevi il testo OCR di un documento PDF di {$pageCount} pagine. "
            ."Ogni pagina è preceduta da un marcatore univoco nel formato esatto \"{$markerExample}\", "
            ."dove il numero (qui 1) è il numero della pagina, 1-indexed. Quel marcatore è l'UNICO modo affidabile "
            ."di determinare i confini di pagina: ignora qualsiasi riferimento a numeri di pagina presente nel testo del documento.\n"
            ."1. Determina autonomamente il tipo di documento dal contenuto.\n"
            ."2. Individua TUTTI i destinatari (le persone a cui il documento è intestato o che vi sono dichiarate), anche se è uno solo.\n"
            ."3. Per ogni destinatario indica l'intervallo di pagine che lo riguarda (start_page ed end_page, interi 1-indexed letti dai marcatori).\n"
            ."Regole:\n"
            ."- Restituisci SEMPRE almeno un destinatario. Se il documento riguarda una sola persona o non distingui destinatari multipli, restituisci un unico elemento con start_page=1 ed end_page={$pageCount}.\n"
            ."- Se il nome di un destinatario non è identificabile, usa \"Destinatario non identificato\".\n"
            ."- Gli intervalli non devono sovrapporsi e devono restare tra 1 e {$pageCount}.\n"
            ."Rispondi SOLO con JSON valido: un array di oggetti con le chiavi employee_name (stringa), start_page (intero), end_page (intero).\n\n"
            ."Testo OCR:\n".$ocrText;
    }

    /**
     * Estrae i campi strutturati di un singolo destinatario dal suo testo OCR,
     * per qualsiasi tipologia di documento.
     */
    public static function extractFields(string $ocrText): string
    {
        return "Estrai i seguenti campi dal testo OCR di questo documento (qualsiasi tipologia).\n"
            ."Rispondi SOLO con JSON valido con le chiavi: employee_first_name (nome del destinatario), employee_last_name (cognome del destinatario), company_name (azienda o ente, se presente), document_date (formato YYYY-MM-DD), document_type (tipologia del documento rilevata dal contenuto), description (max 200 caratteri), recipient_email (indirizzo email del destinatario), fiscal_code (codice fiscale del destinatario, 16 caratteri), employee_id (matricola o codice dipendente), confidence_score (intero 0-100).\n"
            ."Usa null per i campi non trovati.\n"
            // I tre identificativi valgono solo se stanno scritti nel
            // documento: un codice fiscale plausibile ma inventato passerebbe
            // per dato estratto, e l'operatore non ha modo di distinguerlo.
            ."Per recipient_email, fiscal_code e employee_id riporta esclusivamente valori presenti alla lettera nel testo: se non compaiono, usa null senza dedurli.\n\n"
            ."Per confidence_score usa questa scala:\n"
            ."- 90-100: tutti i campi principali (nome, cognome, azienda, data) sono chiaramente leggibili\n"
            ."- 70-89: la maggior parte dei campi è leggibile ma uno o due sono ambigui o parziali\n"
            ."- 40-69: diversi campi mancanti o incerti, testo mvpo chiaro o layout non standard\n"
            ."- 0-39: documento illeggibile o quasi tutti i campi sono assenti\n\n"
            ."Testo OCR:\n".$ocrText;
    }

    /**
     * Marcatore di confine pagina condiviso dal costruttore del testo OCR e dal
     * prompt di classificazione, cosi' i due lati concordano sul delimitatore.
     */
    public static function pageBoundaryMarker(int $page, string $nonce): string
    {
        return "⟦PAGE {$page} {$nonce}⟧";
    }
}
