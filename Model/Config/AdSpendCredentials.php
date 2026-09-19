<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads an ad-spend provider's credential from configuration (P4-T6,
 * docs/SECURITY.md section 10).
 *
 * This module ships no provider that needs a credential — CsvAdSpendProvider
 * reads a file — so there is deliberately no credential field in this
 * module's own system.xml. A provider that calls a real platform API (Google
 * Ads, Meta) is added by a client project, declares its own fields in its own
 * system.xml using the pattern documented in SECURITY.md section 10, and reads
 * them back through this class.
 *
 * WHY A READER AT ALL. Magento's Encrypted backend model encrypts on SAVE, but
 * ScopeConfigInterface::getValue() hands the ciphertext straight back: nothing
 * decrypts on read. Every consumer would otherwise have to remember to call
 * EncryptorInterface::decrypt() itself, and the obvious mistake — using the
 * stored string as though it were the secret — fails only when the platform
 * rejects a garbled token.
 *
 * FAILS CLOSED ON PLAIN TEXT. This is the reason the class does more than call
 * decrypt(). Encryptor::decrypt() does not fail on a value that was never
 * encrypted: a string with no colons is treated as the oldest legacy format and
 * "decrypted" into junk, which is frequently non-empty. A secret that somebody
 * stored unencrypted — through a plain text field, config:set, or a direct SQL
 * insert — would therefore appear to work, or fail mysteriously, instead of
 * being flagged. So the stored value's shape is checked BEFORE decrypting, and
 * anything that does not look like Magento's own ciphertext is refused
 * (returns null) and logged. Refusing forces the mistake to be fixed rather
 * than quietly tolerating a secret sitting readable in the database.
 *
 * The secret itself is never logged, and neither is the stored ciphertext:
 * a log line naming the config PATH is enough to act on.
 */
class AdSpendCredentials
{
    /**
     * Magento writes ciphertext as "<key version>:<cipher version>:<data>".
     * Older installs may hold the four-part variant with an IV, which begins
     * the same way, so this prefix covers both. A single-part value (no colon)
     * is the legacy format Magento no longer writes, and is deliberately NOT
     * accepted here — see the class comment.
     */
    private const CIPHERTEXT_PATTERN = '/^\d+:\d+:/';

    private ScopeConfigInterface $scopeConfig;
    private EncryptorInterface $encryptor;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
    }

    /**
     * @param string $configPath full path, e.g. "aavirbhava_adsanalytics/ad_spend/google_ads/client_secret"
     * @return string|null the decrypted credential, or null when it is unset,
     *                     not encrypted, or cannot be decrypted
     */
    public function get(
        string $configPath,
        string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        ?string $scopeCode = null
    ): ?string {
        $stored = $this->scopeConfig->getValue($configPath, $scope, $scopeCode);

        if ($stored === null || $stored === '') {
            // Unset is an ordinary state — the provider simply is not
            // configured yet — so it is not logged.
            return null;
        }

        if (!is_string($stored) || preg_match(self::CIPHERTEXT_PATTERN, $stored) !== 1) {
            $this->logger->error(
                sprintf(
                    'Aavirbhava_AdsAnalytics: the credential at "%s" is not stored encrypted and was not used. '
                    . 'Declare the field with type="obscure" and the '
                    . 'Magento\Config\Model\Config\Backend\Encrypted backend model, then re-save it '
                    . '(docs/SECURITY.md section 10).',
                    $configPath
                )
            );

            return null;
        }

        try {
            $decrypted = $this->encryptor->decrypt($stored);
        } catch (\Throwable $e) {
            // Contained rather than propagated: a decrypt failure must not
            // surface as an exception in the middle of a spend report. Only
            // the config path is logged — an exception message from the
            // crypto layer is not something to write to a log file.
            $decrypted = '';
        }

        if ($decrypted === '') {
            // Encryptor::decrypt() normally returns an empty string rather
            // than throwing when the key that encrypted the value is no longer
            // available (a rotated or lost crypt key) or the data is corrupt;
            // the catch above covers the cases where it throws instead.
            $this->logger->error(
                sprintf(
                    'Aavirbhava_AdsAnalytics: the credential at "%s" could not be decrypted. '
                    . 'The crypt key may have changed since it was saved; re-enter it.',
                    $configPath
                )
            );

            return null;
        }

        return $decrypted;
    }
}
