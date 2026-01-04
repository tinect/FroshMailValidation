<?php declare(strict_types=1);

namespace Frosh\MailAddressTester\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shyim\CheckIfEmailExists\DNS;
use Shyim\CheckIfEmailExists\SMTP;
use Shyim\CheckIfEmailExists\Syntax;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class Tester
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        #[Autowire('cache.app')]
        private readonly AdapterInterface $cache,
        private readonly LoggerInterface $froshMailAddressTesterLogger,
    ) {
    }

    public function validateEmail(string $email): bool
    {
        $email = \strtolower($email);

        $emailCacheKey = 'frosh_mail_validation_email_' . Hasher::hash($email);
        $mailValidCache = $this->cache->getItem($emailCacheKey);

        $mailValidCacheResult = $mailValidCache->get();
        if (\is_bool($mailValidCacheResult)) {
            return $mailValidCacheResult;
        }

        $mailValidCache->expiresAfter(3600);

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

        $domainValidCache->expiresAfter(3600);

        $mxRecords = (new DNS())->getMxRecords($domain);

        if (empty($mxRecords)) {
            $domainValidCache->set(false);
            $this->cache->save($domainValidCache);

            $this->froshMailAddressTesterLogger->error(\sprintf('Domain %s has no mx records', $domain));

            return false;
        }

        $verifyEmail = $this->systemConfigService->getString('FroshMailAddressTester.config.verifyEmail');

        $smtpCheck = (new SMTP($verifyEmail))->check($domain, $mxRecords[0], $email);
        if ($smtpCheck['can_connect'] === false) {
            $domainValidCache->set(false);
            $this->cache->save($domainValidCache);

            $this->froshMailAddressTesterLogger->error(\sprintf('Mail server at %s of domain %s is not connectable', $mxRecords[0], $domain));

            return false;
        }

        $domainValidCache->expiresAfter(86400);
        $domainValidCache->set(true);
        $this->cache->save($domainValidCache);

        $isValid = $smtpCheck['is_deliverable'] === true && $smtpCheck['is_disabled'] === false && $smtpCheck['has_full_inbox'] === false;

        $mailValidCache->set($isValid);
        $this->cache->save($mailValidCache);

        if ($isValid === false) {
            $this->froshMailAddressTesterLogger->error(\sprintf('Email address "%s" test failed', $email), $smtpCheck);
        }

        return $isValid;
    }
}
