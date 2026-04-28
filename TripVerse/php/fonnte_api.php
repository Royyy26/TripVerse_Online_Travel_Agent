<?php
// ==========================
// FONNTE API Buat Post
// ==========================

define("FONNTE_API_TOKEN", "x3qTUTpY6pAVRvg9Gbf1");

// Fungsi kirim WA via Fonnte
function sendWhatsAppMessage($target, $message)
{
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            "target"  => $target,
            "message" => $message
        ],
        CURLOPT_HTTPHEADER => [
            "Authorization: " . FONNTE_API_TOKEN
        ]
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);

    // log debug
    error_log("[FONNTE] SEND WA → $target | RESP: $response | ERR: $error");

    return json_decode($response, true);
}
?>

