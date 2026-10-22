<?php

namespace App\Services;

use App\Services\Email\BrevoTransactionalMailer;

class AccountEmailReadiness
{
    public function ready(): bool
    {
        return config('accounts.email_provider') === 'brevo'
            && filled(config('services.brevo.key'))
            && filter_var(config('services.brevo.from_address'), FILTER_VALIDATE_EMAIL)
            && BrevoTransactionalMailer::isApprovedBaseUrl((string) config('services.brevo.base_url'));
    }

    public function canQueue(): bool
    {
        return $this->ready() || (app()->environment(['local', 'testing']) && config('accounts.email_provider') === 'log');
    }
}
