<?php

namespace App\Helpers;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Google\Client;
use Exception;

class FirebaseHelper {

    public static function send($platform,$registration_ids, $fcm_msg, $notification) {

        if ($platform = "android" ||  $platform = "web") {
            $fields = [
                "message" => [
                    "token" => $registration_ids,
                    "data" => $fcm_msg
                ]
            ];
            return self::sendPushNotification($fields);
        } elseif ($platform = "ios") {
            $fields = [
                "message" => [
                    "token" => ($registration_ids),
                    "data" => $fcm_msg,
                    "notification" => [
                        "title" => $fcm_msg["title"],
                        "body" => $fcm_msg["body"],
                    ],
                        "apns" => [
                            "payload" => [
                                "aps" => [
                                    "sound" => $fcm_msg["type"] == "new_order" || $fcm_msg["type"] == "assign_order" ? "order_sound.aiff" : "default"
                                ]
                            ]
                        ]
                    
                ]
            ];
            return self::sendPushNotification($fields);
        }
        

    }

    public static function sendPushNotification($fields) {
    
    $data1 = json_encode($fields);

    $access_token = self::getAccessToken();
    $projectID = Setting::where('variable', 'projectId')->first();
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectID['value'] . '/messages:send';
 
    $headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    // Disabling SSL Certificate support temporarly
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data1);

    // Execute post

    $result = curl_exec($ch);
    \Log::info('fresult : ',[$result]);

    if ($result == FALSE) {
        die('Curl failed: ' . curl_error($ch));
    }

    // Close connection
    curl_close($ch);
    

   
    }

    private static function getAccessToken() {
        $filePath = base_path('config/firebase.json');
        if (!file_exists($filePath)) {
            throw new Exception('Service account file not found');
        }
        $client = new Client();
        $client->setAuthConfig($filePath);
        $client->setScopes(['https://www.googleapis.com/auth/firebase.messaging']);
        $accessToken = $client->fetchAccessTokenWithAssertion()['access_token'];
        return $accessToken;
    }
}