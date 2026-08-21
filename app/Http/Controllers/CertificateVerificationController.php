<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\View\View;

class CertificateVerificationController extends Controller
{
    public function __invoke(string $code): View
    {
        $certificate = Certificate::query()
            ->with('webinar:id,title')
            ->where('verification_code_hash', Certificate::verificationCodeHash($code))
            ->firstOrFail();

        return view('certificates.verify', compact('certificate'));
    }
}
