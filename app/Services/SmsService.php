<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function sendWelcome($mobile)
    {
        $message = base64_encode("ضمن تشکر از حسن انتخاب شما\nثبت نام شما با موفقیت انجام شد\شرکت پدهوشا ");

        return Http::get("https://api.kavenegar.com/v1/58734E58626A776F504146536367354A643863484F7A5A34703838694E66336B6B5156546333665135524D3D/sms/send.json", [
            'receptor' => $mobile,
            'message' => $message,
            'sender' => '1000066006700'
        ]);
    }

    public function sendText($mobile, $text)
    {

        return Http::get("https://api.kavenegar.com/v1/58734E58626A776F504146536367354A643863484F7A5A34703838694E66336B6B5156546333665135524D3D/sms/send.json", [
            'receptor' => $mobile,
            'message' => $text,
            'sender' => '1000066006700'
        ]);
    }
    public function sendToKavenegar(string $template, string $mobile, string $token, array $extraData = [])
    {
        $apiKey = env("KAVENEGAR_API_KEY");
        $url = "https://api.kavenegar.com/v1/{$apiKey}/verify/lookup.json";
        $data = [
            'receptor' => $mobile,
            'token'    => $token,
            'template' => $template
        ];
        $data = array_merge($data, $extraData);
        $response = Http::timeout(5)->retry(2, 100)->get($url, $data);
        Log::info('Kavenegar response: ' . $response->body());
    }
}
