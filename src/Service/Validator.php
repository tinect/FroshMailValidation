<?php declare(strict_types=1);

namespace Frosh\MailValidation\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shyim\CheckIfEmailExists\EmailChecker;
use Shyim\CheckIfEmailExists\SMTP;
use Shyim\CheckIfEmailExists\Syntax;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class Validator
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $froshMailValidationLogger,
    ) {
    }

    public function validateEmail(string $email): bool
    {
        $email = \strtolower($email);

        $verifyEmail = $this->systemConfigService->getString('FroshMailValidation.config.verifyEmail');

        $syntax = new Syntax($email);
        if ($syntax->isValid() === false) {
            return false;
        }

        $domain = $syntax->domain;

        $domainCacheKey = 'frosh_mail_validation_domain_' . Hasher::hash($domain);

        \assert($this->cache instanceof AdapterInterface);
        $domainValidCache = $this->cache->getItem($domainCacheKey);

        // first check if the domain is already marked as invalid
        if ($domainValidCache->get() === false) {
            return false;
        }

        $checker = new EmailChecker(smtp: new SMTP($verifyEmail));

        $emailCacheKey = 'frosh_mail_validation_email_' . Hasher::hash($email);

        return $this->cache->get($emailCacheKey, function (ItemInterface $cacheItem) use ($email, $checker, $domainValidCache) {
            $result = $checker->check($email);

            $domainValid = $result->hasMxRecords && $result->isReachable;
            $domainValidCache->set($domainValid);
            $domainValidCache->expiresAfter($domainValid ? 86400 : 3600);
            $this->cache->save($domainValidCache);

            $cacheItem->expiresAfter(3600);

            if ($domainValid && $result->isDisabled === false && $result->hasFullInbox === false) {
                return true;
            }

            $this->froshMailValidationLogger->error('Email validation failed', $result->toArray());

            return false;
        });
    }
}
