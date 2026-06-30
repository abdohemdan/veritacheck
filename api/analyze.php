<?php
error_reporting(0);
ini_set("display_errors", 0);

// CORS - aggiorna con il tuo URL Render dopo il deploy
$allowed = ['http://localhost', 'http://127.0.0.1'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
// Su Render accetta tutto (origin vuoto = richiesta diretta dal server stesso)
if (empty($origin) || str_contains($origin, 'onrender.com') || in_array($origin, $allowed)) {
    header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
} else {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito']);
    exit;
}

// Legge chiavi da variabili d'ambiente (Render) o file locale
if (file_exists(__DIR__ . '/../config/keys.php')) {
    require_once __DIR__ . '/../config/keys.php';
}
if (!defined('GEMINI_API_KEY'))        define('GEMINI_API_KEY',        getenv('GEMINI_API_KEY') ?: '');
if (!defined('GOOGLE_FACT_CHECK_KEY')) define('GOOGLE_FACT_CHECK_KEY', getenv('GOOGLE_FACT_CHECK_KEY') ?: '');
if (!defined('DB_HOST'))               define('DB_HOST',               getenv('DB_HOST') ?: '');
if (!defined('DB_NAME'))               define('DB_NAME',               getenv('DB_NAME') ?: '');
if (!defined('DB_USER'))               define('DB_USER',               getenv('DB_USER') ?: '');
if (!defined('DB_PASSWORD'))           define('DB_PASSWORD',           getenv('DB_PASSWORD') ?: '');

$body           = json_decode(file_get_contents('php://input'), true);
$tipo           = $body['tipo']           ?? 'testo';
$content        = $body['content']        ?? '';
$imageData      = $body['imageData']      ?? null;
$imageMediaType = $body['imageMediaType'] ?? 'image/jpeg';
$urlOrig        = '';

if (empty($content)) {
    http_response_code(400);
    echo json_encode(['error' => 'Contenuto mancante']);
    exit;
}

if ($tipo === 'url') {
    require_once __DIR__ . '/scraper.php';
    $urlOrig = $content;
    $scraped = scrapeUrl($content);
    if (!$scraped['success']) {
        http_response_code(422);
        echo json_encode(['error' => 'Impossibile leggere URL: ' . $scraped['error']]);
        exit;
    }
    $content = $scraped['text'];
    $title   = $scraped['title'] ?? '';
} else {
    $title = '';
}

$aiResult = callGemini($content, $tipo, GEMINI_API_KEY, $imageData, $imageMediaType);
if (isset($aiResult['error'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Errore AI: ' . $aiResult['error']]);
    exit;
}

$factCheckResults = callFactCheck($content, GOOGLE_FACT_CHECK_KEY);
$final = mergeResults($aiResult, $factCheckResults, $title);

// DB opzionale
if (DB_HOST) {
    try {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare('INSERT INTO analisi (tipo,contenuto,url_originale,verdetto,score,tono,segnali,spiegazione,ip_utente) VALUES (:tipo,:contenuto,:url,:verdetto,:score,:tono,:segnali,:spiegazione,:ip)');
        $stmt->execute([':tipo'=>$tipo,':contenuto'=>mb_substr($content,0,65535),':url'=>$urlOrig?:null,':verdetto'=>$final['verdetto'],':score'=>$final['score'],':tono'=>$final['tono_emotivo'],':segnali'=>$final['segnali_allerta'],':spiegazione'=>$final['spiegazione'],':ip'=>$_SERVER['REMOTE_ADDR']??null]);
        $aid = $pdo->lastInsertId();
        $sf = $pdo->prepare('INSERT INTO fonti (analisi_id,nome,url,rating,colore) VALUES (:aid,:nome,:url,:rating,:colore)');
        foreach ($final['fonti'] as $f) $sf->execute([':aid'=>$aid,':nome'=>$f['nome'],':url'=>$f['url']??null,':rating'=>$f['rating']??null,':colore'=>$f['colore']??'giallo']);
        $final['id_analisi'] = $aid;
    } catch (PDOException $e) { error_log('[VeritaCheck] DB: '.$e->getMessage()); }
}

echo json_encode($final);
exit;

function callGemini(string $content, string $tipo, string $apiKey, ?string $imageData, string $imageMediaType): array {
    $typeHint = match($tipo) {
        'url'      => 'Questo contenuto e stato estratto da un articolo web.',
        'immagine' => 'Analizza questa immagine: e una fake news o contenuto manipolato?',
        default    => 'Questo e un testo inserito dall utente.',
    };
    $prompt = "Sei un fact-checker esperto italiano. Analizza il seguente contenuto.\n"
            . $typeHint . "\n\nContenuto:\n\"\"\"\n" . $content . "\n\"\"\"\n\n"
            . "Rispondi SOLO con JSON valido, nessun testo extra, nessun backtick:\n"
            . '{"verdetto":"FAKE oppure VERO oppure INCERTO","score":numero 0-100,"tono_emotivo":"Neutro oppure Allarmistico oppure Emotivo oppure Sensazionalistico","fonti_citate":"Nessuna oppure Generiche oppure Specifiche","segnali_allerta":numero intero,"segnali_lista":["segnale1"],"spiegazione":"2-3 frasi in italiano"}';

    $parts = ($tipo === 'immagine' && $imageData)
        ? [['text'=>$prompt],['inline_data'=>['mime_type'=>$imageMediaType,'data'=>$imageData]]]
        : [['text'=>$prompt]];

    $payload = json_encode(['contents'=>[['parts'=>$parts]],'generationConfig'=>['temperature'=>0.2,'maxOutputTokens'=>1024]]);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key='.urlencode($apiKey);

    // Retry automatico: se il modello AI è temporaneamente sovraccarico
    // (429/500/502/503) riprova fino a 3 volte, aspettando tra un tentativo e l'altro.
    $maxTentativi = 3;
    $response = false; $httpCode = 0; $curlErr = '';
    for ($tentativo = 1; $tentativo <= $maxTentativi; $tentativo++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && $httpCode === 200) break;                       // ok, esci
        if ($tentativo < $maxTentativi && ($curlErr || in_array($httpCode, [429,500,502,503]))) {
            sleep(2);                                                    // errore temporaneo: aspetta e riprova
            continue;
        }
        break;
    }

    if ($curlErr) return ['error' => 'Rete: '.$curlErr];
    if ($httpCode !== 200) { $e = json_decode($response,true); return ['error' => $e['error']['message'] ?? "HTTP $httpCode"]; }

    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    // Rimuovi backtick markdown
    $text = preg_replace('/```json|```/i', '', $text);
    // Estrai solo il blocco JSON (dalla prima { all'ultima })
    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, $start, $end - $start + 1);
    }
    $text = trim($text);
    // Rimuovi tutti i caratteri di controllo tranne newline/tab (Gemini 2.5 reasoning)
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    // Converti in UTF-8 pulito
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    $parsed = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Ultimo tentativo: prendi solo caratteri ASCII stampabili + spazi
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/u', '', $text);
        $parsed = json_decode($text, true);
    }
    return (json_last_error() === JSON_ERROR_NONE) ? $parsed : ['error' => 'JSON non valido: '.json_last_error_msg()];
}

function callFactCheck(string $query, string $apiKey): array {
    if (empty($apiKey)) return [];
    $short = implode(' ', array_slice(explode(' ', strip_tags($query)), 0, 12));
    $ch = curl_init('https://factchecktools.googleapis.com/v1alpha1/claims:search?query='.urlencode($short).'&languageCode=it&key='.$apiKey);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $response = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return [];
    $result = [];
    foreach (array_slice(json_decode($response,true)['claims']??[],0,4) as $claim) {
        $rev = $claim['claimReview'][0] ?? null; if (!$rev) continue;
        $r = strtolower($rev['textualRating']??'');
        $result[] = ['nome'=>$rev['publisher']['name']??'Fonte sconosciuta','url'=>$rev['url']??'#','rating'=>$rev['textualRating']??'--','colore'=>str_contains($r,'falso')||str_contains($r,'false')?'rosso':(str_contains($r,'vero')||str_contains($r,'true')?'verde':'giallo')];
    }
    return $result;
}

function mergeResults(array $ai, array $fact, string $title): array {
    $default = [['nome'=>'Google Fact Check','url'=>'https://toolbox.google.com/factcheck','colore'=>'giallo'],['nome'=>'Snopes','url'=>'https://snopes.com','colore'=>'giallo'],['nome'=>'FactaNews','url'=>'https://facta.news','colore'=>'giallo']];
    return ['verdetto'=>$ai['verdetto']??'INCERTO','score'=>$ai['score']??50,'tono_emotivo'=>$ai['tono_emotivo']??'--','fonti_citate'=>$ai['fonti_citate']??'--','segnali_allerta'=>$ai['segnali_allerta']??0,'segnali_lista'=>$ai['segnali_lista']??[],'spiegazione'=>$ai['spiegazione']??'','fonti'=>!empty($fact)?$fact:$default,'titolo'=>$title];
}