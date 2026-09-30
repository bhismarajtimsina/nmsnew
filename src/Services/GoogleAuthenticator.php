<?php

namespace WCAA\Services;

use OTPHP\TOTP;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use WCAA\Models\User\User;
use ParagonIE\ConstantTime\Base32;


class GoogleAuthenticator
{
    private string $issuer = 'Support';
    public function generateSecret(): string
    {
        return rtrim(Base32::encodeUpper(random_bytes(12)),'=');
    }

    public function getQR(User $user): array
    {
        $secret = (string) $user->getTwofaToken();

        $accountLabel = $user->getLogin();

        $totp = TOTP::create($secret);
        $totp->setIssuer($this->issuer);
        $totp->setLabel($accountLabel);

        $otpauth = $totp->getProvisioningUri();

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($otpauth)
            ->size(260)
            ->margin(10)
            ->build();

        return [
            'code' => $secret,
            'qr' => $result->getDataUri(),
        ];
    }

    public function check2FA(User $user, $pin): bool
    {
        $secret = (string) $user->getTwofaToken();
        if ($secret === '') {
            return false;
        }

        $pin = preg_replace('/\D+/', '', (string)$pin);
        if (strlen($pin) !== 6) {
            return false;
        }

        $totp = TOTP::create($secret);

        return $totp->verify($pin, null, 1);
    }
}
