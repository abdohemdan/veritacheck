<?php
/**
 * VeritàCheck — scraper.php
 * Scarica e pulisce il testo da un URL
 */

function scrapeUrl(string $url): array
{
    // Valida URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => 'URL non valido'];
    }

    // Scarica HTML
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VeritaCheckBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode >= 400) {
        return ['success' => false, 'error' => "HTTP $httpCode"];
    }

    // Parsing DOM
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    $xpath = new DOMXPath($dom);

    // Estrai titolo
    $titleNodes = $xpath->query('//title');
    $title      = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

    // Rimuovi elementi non testuali
    foreach ($xpath->query('//script|//style|//nav|//footer|//header|//aside|//iframe|//form') as $node) {
        $node->parentNode->removeChild($node);
    }

    // Cerca il corpo principale dell'articolo
    $selectors = ['//article', '//main', '//*[@class="content"]', '//*[@id="content"]', '//body'];
    $text = '';
    foreach ($selectors as $sel) {
        $nodes = $xpath->query($sel);
        if ($nodes->length > 0) {
            $text = trim($nodes->item(0)->textContent);
            if (strlen($text) > 200) break;
        }
    }

    // Pulisci testo: riduci spazi multipli
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    // Limita a 3000 caratteri per non sovraccaricare l'AI
    if (strlen($text) > 3000) {
        $text = substr($text, 0, 3000) . '…';
    }

    if (strlen($text) < 50) {
        return ['success' => false, 'error' => 'Testo estratto troppo breve'];
    }

    return ['success' => true, 'text' => $text, 'title' => $title];
}
