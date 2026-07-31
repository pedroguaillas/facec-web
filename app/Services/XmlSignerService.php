<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XmlSignerService
{
    public function sign(string $certPath, string $password, string $inputPath, string $outputDir, string $filename): bool
    {
        $response = Http::timeout(30)->post(
            config('services.xml_signer.url').'/sign',
            [
                'cert_path' => $certPath,
                'password' => $password,
                'input_path' => $inputPath,
                'output_dir' => $outputDir,
                'filename' => $filename,
            ]
        );

        if ($response->failed()) {
            Log::error('XML signing failed', [
                'detail' => $response->json('detail'),
                'filename' => $filename,
            ]);

            return false;
        }

        return true;
    }
}
