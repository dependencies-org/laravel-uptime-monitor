<?php

namespace Spatie\UptimeMonitor\Models\Traits;

use Exception;
use Spatie\SslCertificate\SslCertificate;
use Spatie\UptimeMonitor\Events\CertificateCheckFailed;
use Spatie\UptimeMonitor\Events\CertificateCheckSucceeded;
use Spatie\UptimeMonitor\Events\CertificateExpiresSoon;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Monitor;

trait SupportsCertificateCheck
{
    public function checkCertificate(): void
    {
        try {
            $certificate = SslCertificate::download()->usingPort($this->url->getPort() ?? 443)->getCertificates($this->url->getHost())[0] ?? false;

            $this->setCertificate($certificate);
        } catch (Exception $exception) {
            $this->setCertificateException($exception);
        }
    }

    public function setCertificate(SslCertificate $certificate): void
    {
        $this->certificate_status = $certificate->isValid($this->url)
            ? CertificateStatus::VALID
            : CertificateStatus::INVALID;

        $this->certificate_expiration_date = $certificate->expirationDate();
        $this->certificate_issuer = $certificate->getIssuer();
        $this->save();

        $this->fireEventsForUpdatedMonitorWithCertificate($this, $certificate);
    }

    public function setCertificateException(Exception $exception): void
    {
        $this->certificate_status = CertificateStatus::INVALID;
        $this->certificate_expiration_date = null;
        $this->certificate_issuer = '';
        $this->certificate_check_failure_reason = $exception->getMessage();
        $this->save();

        event(new CertificateCheckFailed($this, $exception->getMessage()));
    }

    protected function fireEventsForUpdatedMonitorWithCertificate(Monitor $monitor, SslCertificate $certificate): void
    {
        if ($this->certificate_status === CertificateStatus::VALID) {
            event(new CertificateCheckSucceeded($this, $certificate));

            if ($certificate->expirationDate()->diffInDays() <= config('uptime-monitor.certificate_check.fire_expiring_soon_event_if_certificate_expires_within_days')) {
                event(new CertificateExpiresSoon($monitor, $certificate));
            }

            return;
        }

        if ($this->certificate_status === CertificateStatus::INVALID) {
            $reason = 'Unknown';

            if (! $certificate->appliesToUrl($this->url)) {
                $reason = "Certificate does not apply to {$this->url} but only to these domains: ".implode(',', $certificate->getAdditionalDomains());
            }

            if ($certificate->isExpired()) {
                $reason = 'The certificate has expired';
            }

            $this->certificate_check_failure_reason = $reason;
            $this->save();

            event(new CertificateCheckFailed($this, $reason, $certificate));
        }
    }
}
