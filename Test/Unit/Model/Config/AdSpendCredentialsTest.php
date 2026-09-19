<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Model\Config;

use Aavirbhava\AdsAnalytics\Model\Config\AdSpendCredentials;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * P4-T6. Uses Magento's REAL Encryptor for the round trips, not a mock. The
 * reader's format check is an assumption about what ciphertext looks like, and
 * a mock would only confirm that assumption back to itself; a real encrypt()
 * either produces something the pattern accepts or it does not.
 */
class AdSpendCredentialsTest extends TestCase
{
    private const PATH = 'aavirbhava_adsanalytics/ad_spend/example/client_secret';
    private const KEY_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEY_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function realEncryptor(string $cryptKey): Encryptor
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with(Encryptor::PARAM_CRYPT_KEY)->willReturn($cryptKey);
        $keyValidator = $this->createMock(KeyValidator::class);
        $keyValidator->method('isValid')->willReturn(true);

        return new Encryptor($this->createMock(Random::class), $deploymentConfig, $keyValidator);
    }

    private function reader(?string $stored, EncryptorInterface $encryptor): AdSpendCredentials
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        return new AdSpendCredentials($scopeConfig, $encryptor, $this->logger);
    }

    public function testRoundTripsAValueEncryptedByMagentoItself(): void
    {
        $encryptor = $this->realEncryptor(self::KEY_A);
        $stored = $encryptor->encrypt('ya29.a0-example-refresh-token');

        $this->assertSame('ya29.a0-example-refresh-token', $this->reader($stored, $encryptor)->get(self::PATH));
    }

    /**
     * Pins the assumption the fail-closed check rests on: what Magento
     * actually writes matches the shape the reader accepts. If a future
     * Magento changed its ciphertext format, this fails here instead of
     * silently rejecting every legitimate secret in production.
     */
    public function testMagentosOwnCiphertextMatchesTheShapeTheReaderAccepts(): void
    {
        $stored = $this->realEncryptor(self::KEY_A)->encrypt('anything');

        $this->assertMatchesRegularExpression('/^\d+:\d+:/', $stored);
    }

    public function testAnUnsetCredentialIsNullAndIsNotLogged(): void
    {
        // Not configured yet is an ordinary state, not an error.
        $this->logger->expects($this->never())->method($this->anything());

        $this->assertNull($this->reader(null, $this->realEncryptor(self::KEY_A))->get(self::PATH));
        $this->assertNull($this->reader('', $this->realEncryptor(self::KEY_A))->get(self::PATH));
    }

    /**
     * The central case. Encryptor::decrypt() does not fail on a value that was
     * never encrypted — it treats a colon-free string as legacy Blowfish and
     * returns whatever that produces, often non-empty. Asserting only "null
     * comes back" would pass even if decrypt() were called and happened to
     * yield ''; the assertion that matters is that decrypt() is never reached.
     */
    public function testAPlainTextValueIsRefusedWithoutEverBeingDecrypted(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('not stored encrypted'));

        $this->assertNull($this->reader('my-plain-text-secret', $encryptor)->get(self::PATH));
    }

    public function testAColonContainingPlainTextValueIsStillRefused(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        // Looks a little like ciphertext but is not "<digits>:<digits>:...".
        $this->assertNull($this->reader('client:secret:value', $encryptor)->get(self::PATH));
    }

    public function testTheSecretAndTheCiphertextNeverAppearInTheLog(): void
    {
        $logged = [];
        $this->logger->method('error')->willReturnCallback(static function ($message) use (&$logged): void {
            $logged[] = (string)$message;
        });

        $secret = 'super-secret-plain-value';
        $this->reader($secret, $this->realEncryptor(self::KEY_A))->get(self::PATH);

        $encryptor = $this->realEncryptor(self::KEY_A);
        $ciphertext = $encryptor->encrypt('another-secret');
        $this->reader($ciphertext, $this->realEncryptor(self::KEY_B))->get(self::PATH);

        $this->assertNotEmpty($logged, 'both failure paths should have logged');
        foreach ($logged as $message) {
            $this->assertStringNotContainsString($secret, $message);
            $this->assertStringNotContainsString($ciphertext, $message);
            $this->assertStringContainsString(self::PATH, $message, 'the config path is what lets someone act on it');
        }
    }

    /**
     * A rotated or lost crypt key is the realistic way a correctly stored
     * secret stops working. It must come back as null with an actionable log
     * line, not as an exception into the middle of a spend report.
     */
    public function testAValueEncryptedWithADifferentKeyIsNullNotAnException(): void
    {
        $ciphertext = $this->realEncryptor(self::KEY_A)->encrypt('token');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('could not be decrypted'));

        $this->assertNull($this->reader($ciphertext, $this->realEncryptor(self::KEY_B))->get(self::PATH));
    }

    public function testAnEncryptorThatThrowsIsContainedAndLogged(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willThrowException(new \RuntimeException('sodium failure'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('could not be decrypted'));

        $this->assertNull($this->reader('3:3:AAAA', $encryptor)->get(self::PATH));
    }

    public function testScopeArgumentsArePassedThrough(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())->method('getValue')
            ->with(self::PATH, 'websites', 'base')->willReturn(null);

        (new AdSpendCredentials($scopeConfig, $this->realEncryptor(self::KEY_A), $this->logger))
            ->get(self::PATH, 'websites', 'base');
    }
}
