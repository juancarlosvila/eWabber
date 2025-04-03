<?php

define('WHATSAPP_API_URL', 'https://graph.facebook.com/v22.0/YOUR_WA_PHONE_ID/messages');
define('WHATSAPP_API_TOKEN', 'YOUR_WHATSAPP_API_TOKEN');
// Captura la entrada
$raw = file_get_contents('php://input');
error_log('Stanza recibida: ' . $raw, 0);

$xml = simplexml_load_string($raw);
if (!$xml) {
    error_log("Error: XML inválido");
    exit;
}

$stanza_name = strtolower($xml->getName());
$from = (string) $xml['from'];
$to   = (string) $xml['to'];
$type = (string) $xml['type'] ?? '';

switch ($stanza_name) {
    case 'message':
        $body = (string) $xml->body;

        // Detectar si es un link
        if (preg_match('/https?:\/\/\S+/i', $body, $link)) {
            $media_url = $link[0];
            $caption = trim(str_replace($media_url, '', $body));

            // Detectar tipo de archivo
            if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $media_url)) {
                send_whatsapp_image($to, $media_url, $caption);
            } elseif (preg_match('/\.(pdf|docx|pptx|zip)$/i', $media_url)) {
                send_whatsapp_document($to, $media_url, $caption);
            } elseif (preg_match('/\.(mp4|avi|mov)$/i', $media_url)) {
                send_whatsapp_video($to, $media_url, $caption);
            } elseif (preg_match('/\.(ogg|opus|mp3)$/i', $media_url)) {
                send_whatsapp_audio($to, $media_url);
            } else {
                send_whatsapp_text($to, $body);
            }
        } else {
            send_whatsapp_text($to, $body);
        }
        break;

    case 'presence':
    case 'iq':
    default:
        // Ignorar
        break;
}

// === FUNCIONES ===

function send_whatsapp_text($to, $body) {
    send_whatsapp_message($to, [
        'type' => 'text',
        'text' => ['body' => $body]
    ]);
}

function send_whatsapp_image($to, $url, $caption = '') {
    $data = ['link' => $url];
    if (!empty($caption)) $data['caption'] = $caption;
    send_whatsapp_message($to, ['type' => 'image', 'image' => $data]);
}

function send_whatsapp_document($to, $url, $caption = '') {
    $data = ['link' => $url];
    if (!empty($caption)) $data['caption'] = $caption;
    send_whatsapp_message($to, ['type' => 'document', 'document' => $data]);
}

function send_whatsapp_video($to, $url, $caption = '') {
    $data = ['link' => $url];
    if (!empty($caption)) $data['caption'] = $caption;
    send_whatsapp_message($to, ['type' => 'video', 'video' => $data]);
}

function send_whatsapp_audio($to, $url) {
    send_whatsapp_message($to, ['type' => 'audio', 'audio' => ['link' => $url]]);
}

function send_whatsapp_message($to, $messageData) {
    $toNumber = preg_replace('/@wis\.chat$/', '', $to);

    $data = array_merge([
        'messaging_product' => 'whatsapp',
        'to' => $toNumber
    ], $messageData);

    error_log("Enviando a WhatsApp: " . json_encode($data));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, WHATSAPP_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . WHATSAPP_API_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    error_log("Respuesta WhatsApp API: " . $response);
}
?>
