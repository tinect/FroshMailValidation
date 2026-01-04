<?php declare(strict_types=1);

namespace Frosh\MailValidation\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shyim\CheckIfEmailExists\DNS;
use Shyim\CheckIfEmailExists\SMTP;
use Shyim\CheckIfEmailExists\Syntax;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class Validator
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly AdapterInterface $cache,
        private readonly LoggerInterface $froshMailValidationLogger,
    ) {
    }

    public function validateEmail(string $email): bool
    {
        $email = \strtolower($email);

        $emailCacheKey = 'frosh_mail_validation_email_' . Hasher::hash($email);
        $mailValidCache = $this->cache->getItem($emailCacheKey);
        if ($mailValidCache->get() === false) {
            return false;
        }

        $mailValidCache->expiresAfter(3600);

        $verifyEmail = $this->systemConfigService->getString('FroshMailValidation.config.verifyEmail');

        $syntaxCheck = new Syntax($email);
        if ($syntaxCheck->isValid() === false) {
            return false;
        }

        $domain = $syntaxCheck->domain;

        $domainCacheKey = 'frosh_mail_validation_domain_' . Hasher::hash($domain);

        $domainValidCache = $this->cache->getItem($domainCacheKey);

        // first check if the domain is already marked as invalid
        if ($domainValidCache->get() === false) {
            return false;
        }

        $domainValidCache->expiresAfter(86400);

        $mxRecords = (new DNS())->getMxRecords($domain);

        if (empty($mxRecords)) {
            $domainValidCache->set(false);
            $domainValidCache->expiresAfter(3600);
            $this->cache->save($domainValidCache);

            $this->froshMailValidationLogger->error(\sprintf('Domain %s has no mx records', $domain));

            return false;
        }

        $smtpCheck = (new SMTP($verifyEmail))->check($domain, $mxRecords[0], $email);
        if ($smtpCheck['can_connect'] === false) {
            $domainValidCache->set(false);
            $domainValidCache->expiresAfter(3600);
            $this->cache->save($domainValidCache);

            $this->froshMailValidationLogger->error(\sprintf('Mail server at %s of domain %s is not connectable', $mxRecords[0], $domain));

            return false;
        }

        $domainValidCache->set(true);
        $this->cache->save($domainValidCache);

        $isValid = $smtpCheck['is_deliverable'] === true && $smtpCheck['is_disabled'] === false && $smtpCheck['has_full_inbox'] === false;

        $mailValidCache->set($isValid);
        $this->cache->save($domainValidCache);

        if ($isValid === false) {
            $this->froshMailValidationLogger->error('Email validation failed', $smtpCheck);
        }

        return $isValid;
    }
}
