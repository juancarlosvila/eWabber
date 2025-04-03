<?php
define('WHATSAPP_API_TOKEN', 'YOUR_WHATSAPP_API_TOKEN');

if (!isset($_GET['media_id'])) {
    http_response_code(400);
    echo "Falta media_id";
    exit;
}

$mediaId = $_GET['media_id'];

// Obtener la URL real
$url = getMediaUrl($mediaId);

if (!$url) {
    http_response_code(404);
    echo "No se encontró el recurso";
    exit;
}

// Descargarlo con bearer
$fileData = downloadMediaFile($url);

if (!$fileData) {
    http_response_code(500);
    echo "Error al descargar el archivo";
    exit;
}

// Detectar tipo de contenido
$contentType = $_GET["mime_type"];


// error_log("content :".print_r($contentType,true),0);

// Descargar forzadamente
header("Content-Type: {$contentType}");
// header("Content-Disposition: attachment; filename=\"{$mediaId}\"");
echo $fileData;

// ---------------- FUNCIONES ----------------

function getMediaUrl($mediaId) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v22.0/{$mediaId}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . WHATSAPP_API_TOKEN
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200) {
        $data = json_decode($response, true);
        return isset($data['url']) ? $data['url'] : null;
    }
    return null;
}

function downloadMediaFile($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);	    
    curl_setopt($ch, CURLOPT_USERAGENT, "node");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . WHATSAPP_API_TOKEN
    ]);
    $fileData = curl_exec($ch);

    // error_log('descarga :'.print_r($fileData,true),0);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($status === 200) ? $fileData : null;
}

function getContentType($url) {
    $headers = get_headers($url, 1);
    return isset($headers["Content-Type"]) ? $headers["Content-Type"] : "application/octet-stream";
}
?>
