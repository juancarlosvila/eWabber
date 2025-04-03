<?php
require 'vendor/autoload.php';

use Fabiang\Xmpp\Client;
use Fabiang\Xmpp\Options;
use Fabiang\Xmpp\Protocol\IQ;
use Fabiang\Xmpp\Protocol\Message;

define('EJABBERD_API_URL', 'http://127.0.0.1:5280/api');
define('EJABBERD__URL', 'http://127.0.0.1:5280/api');
define('EJABBERD_UPLOAD_URL', 'http://127.0.0.1:5280/upload');
define('EJABBERD_ADMIN_USER', 'YOUR_EJABBERD_ADMIN_USER_WHITOUT_DOMAIN');
define('EJABBERD_ADMIN_PASSWORD', 'YOUR_EJABBERD_ADMIN_PASSWORD');
define('WHATSAPP_API_TOKEN', 'YOUR_WHATSAPP_API_TOKEN');
define('EJABBERD_VIRTUAL_HOST', 'YOUR_EJABBERD_VIRTUAL_HOST');

// Configuración
$options = new Options('tcp://' . EJABBERD_VIRTUAL_HOST  . ':5222');
$options->setUsername( EJABBERD_ADMIN_USER  )
        ->setPassword( EJABBERD_ADMIN_PASSWORD  )
        ->setTo( EJABBERD_VIRTUAL_HOST  )
        ->setLogger(new \Psr\Log\NullLogger()); // Puedes usar Monolog para debug

$client = new Client($options);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (isset($input['entry'][0]['changes'][0]['value']['messages'][0])) {
        $message = $input['entry'][0]['changes'][0]['value']['messages'][0];
        $sender = $message['from'];
	$to = $input['entry'][0]['changes'][0]['value']['metadata']['display_phone_number']; // Obtener phone_number_id
        // Crear virtual si no existe
        if (!checkVirtualContact($sender)) {
            createVirtualContact($sender);
        }

        // Detectar tipo
        $type = $message['type'];

        if ($type === 'text') {
            $body = $message['text']['body'];
            forwardToEjabberd($sender, $body, $to);
        } elseif (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])) {
            $mediaId = $message[$type]['id'];
            $caption = isset($message[$type]['caption']) ? $message[$type]['caption'] : '';
            $mime_type = isset($message[$type]['mime_type']) ? explode(";",$message[$type]['mime_type'])[0] : '';
            $filename = $mediaId . "." . explode("/",$mime_type,)[1];

       /* inicio cliente compatible mulimedia   */   

            $mediaUrl = generateDownloadLink($mediaId)."&mime_type=".$mime_type;
            $fileData = downloadMediaFile($mediaUrl);

            $body = $mediaUrl;
             try { 
		$client->connect();
                $size = strlen($fileData);

                // Generar stanza IQ manual
		$iq_id = uniqid('slot');
		$iqStanza = "<iq to='upload.'" . EJABBERD_VIRTUAL_HOST . " type='get' id='{$iq_id}'><request xmlns='urn:xmpp:http:upload:0' filename='{$filename}' size='{$size}' content-type='{$mime_type}'/></iq>";
                
		// Enviar
		$client->getConnection()->send($iqStanza);

		// Leer respuesta (slot)
		$response = $client->getConnection()->receive();

                $xml = new SimpleXMLElement($response);

		// Extraer las URLs
		$namespaces = $xml->getNamespaces(true);

		$slot = $xml->slot;
		$getUrl = (string)$slot->get['url'];
		$putUrl = (string)$slot->put['url'];

    		if (!$slot) exit;

    		// Subir archivo al slot
    		if (!uploadToSlot($putUrl, $fileData, $mime_type)) {
        		error_log("Error subiendo al slot");
        		exit;
   		 }

		$stanza = '<message type="chat">
  		<body>' . $caption  . $getUrl  . '</body>
  		<x xmlns="jabber:x:oob">
    			<url>' . $getUrl . '</url>
  		</x>
		</message>';
             
                sendStanza($sender, $to, $stanza);

                $client->close();
		
	     } catch(Exception $e) {
	    	
            	$body = "📎 Mensaje multimedia recibido\n";
            	$body .= "Tipo: {$type}\n";
           	 if ($filename) $body .= "Archivo: {$filename}\n";
            	if ($caption) $body .= "Descripción: {$caption}\n";
            	$body .= "👉 Descárgalo aquí: {$mediaUrl}";
                
	        forwardToEjabberd($sender, $body, $to);	
             }

        } else {
            error_log("Tipo de mensaje no soportado: " . $type);
        }

        http_response_code(200);
        echo json_encode(['status' => 'success']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Bad Request']);
    exit;

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

function checkVirtualContact($phoneNumber) {
    $url = EJABBERD_API_URL . '/check_account';
    $data = ['user' => $phoneNumber, 'host' => EJABBERD_VIRTUAL_HOST];
    $response = sendEjabberdRequest($url, $data);
    return $response['status_code'] === 200 && json_decode($response['response'],true)=='0';
}

function createVirtualContact($phoneNumber) {
    $data = ['user' => $phoneNumber, 'host' => EJABBERD_VIRTUAL_HOST, 'password' => generateRandomPassword()];
    $url = EJABBERD_API_URL . '/register';
    $response = sendEjabberdRequest($url, $data);
    error_log("Creando contacto virtual: " . json_encode($response));
}

function forwardToEjabberd($sender, $body, $to) {
    $data = [
        'type' => 'chat',
        'from' => "{$sender}@" . EJABBERD_VIRTUAL_HOST,
        'to' => "{$to}@" . EJABBERD_VIRTUAL_HOST,
        'body' => $body,
        'subject' => ''
    ];
    $url = EJABBERD_API_URL . '/send_message';
    $response = sendEjabberdRequest($url, $data);
    error_log("Mensaje reenviado a ejabberd: " . json_encode($response));
}

function sendStanza($sender, $to, $stanza) {
    $data = [
        'from' => "{$sender}@" . EJABBERD_VIRTUAL_HOST,
        'to' => "{$to}@" . EJABBERD_VIRTUAL_HOST,
        'stanza' => $stanza,
    ];
    $url = EJABBERD_API_URL . '/send_stanza';
    $response = sendEjabberdRequest($url, $data);
    error_log("Mensaje reenviado a ejabberd: " . json_encode($response));
}

function generateRandomPassword($length = 12) {
    return bin2hex(random_bytes($length/2));
}

function generateDownloadLink($mediaId) {
    return "https://" . EJABBERD_VIRTUAL_HOST . "/eWabberd/download.php?media_id=" . urlencode($mediaId);
}

function sendEjabberdRequest($url, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(EJABBERD_ADMIN_USER . "@" . EJABBERD_VIRTUAL_HOST  . ':' . EJABBERD_ADMIN_PASSWORD)
    ]);

    $response = curl_exec($ch);

    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status_code' => $status_code, 'response' => $response];
}

function downloadMediaFile($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "node");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . WHATSAPP_API_TOKEN
    ]);
    $fileData = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 200 && $fileData !== false) {
        return $fileData;
    } else {
        error_log("Error descargando media desde WhatsApp API, status: {$status}");
        return null;
    }
}

function uploadToSlot($putUrl, $fileData, $contentType) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $putUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
	    "Content-Type: {$contentType}",
	    "Authorization: Basic " . base64_encode(EJABBERD_ADMIN_USER . "@" . EJABBERD_VIRTUAL_HOST  . ':' . EJABBERD_ADMIN_PASSWORD)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $reponse = curl_exec($ch);
    error_log("slot ".print_r($reponse, true),0);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status === 201 || $status === 200;
}

?>
