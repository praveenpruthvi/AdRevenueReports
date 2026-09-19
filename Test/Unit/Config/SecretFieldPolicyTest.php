<?php
declare(strict_types=1);

namespace Aavirbhava\AdsAnalytics\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * P4-T6 / docs/SECURITY.md section 10: credentials are stored through
 * Magento's encrypted backend, never as plain text in system.xml or as a
 * default in config.xml.
 *
 * This turns that sentence into something a build can fail on. It scans this
 * module's own configuration for any field that looks like a secret and
 * requires it to be a masked (type="obscure") field backed by
 * Magento\Config\Model\Config\Backend\Encrypted, with no default value.
 *
 * TODAY IT PASSES BY FINDING NOTHING: this module ships no credential field,
 * because CsvAdSpendProvider needs none. A test that passes on an empty result
 * proves nothing on its own, so the checker is also run against fixtures — one
 * good field it must accept and several bad ones it must catch — to show the
 * detection actually works before it is trusted to say "no violations".
 */
class SecretFieldPolicyTest extends TestCase
{
    private const ENCRYPTED_BACKEND = 'Magento\Config\Model\Config\Backend\Encrypted';
    private const SECRET_NAME_PATTERN = '/(secret|token|passw(or)?d|api[_-]?key|private[_-]?key|credential|access[_-]?key)/i';
    private const EMPTY_CONFIG = '<config><default/></config>';

    private function moduleFile(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /**
     * @return string[] human-readable violations
     */
    private function violations(string $systemXml, string $configXml): array
    {
        $found = [];
        $system = simplexml_load_string($systemXml);
        $config = simplexml_load_string($configXml);

        foreach ($system->xpath('//section') as $section) {
            foreach ($section->xpath('.//group') as $group) {
                foreach ($group->xpath('field') as $field) {
                    $id = (string)$field['id'];
                    $label = (string)$field->label;

                    if (preg_match(self::SECRET_NAME_PATTERN, $id . ' ' . $label) !== 1) {
                        continue;
                    }

                    $path = sprintf('%s/%s/%s', $section['id'], $group['id'], $id);

                    if ((string)$field['type'] !== 'obscure') {
                        $found[] = $path . ' looks like a secret but is not type="obscure", so it is shown in clear in the admin';
                    }
                    if ((string)$field->backend_model !== self::ENCRYPTED_BACKEND) {
                        $found[] = $path . ' looks like a secret but has no ' . self::ENCRYPTED_BACKEND
                            . ' backend model, so it is stored in plain text';
                    }

                    $default = $config->xpath(sprintf('//default/%s/%s/%s', $section['id'], $group['id'], $id));
                    if ($default && trim((string)$default[0]) !== '') {
                        $found[] = $path . ' has a default value in config.xml; a secret must never ship in source';
                    }
                }
            }
        }

        return $found;
    }

    private function system(string $fieldXml): string
    {
        return '<config><system><section id="s"><group id="g">' . $fieldXml . '</group></section></system></config>';
    }

    public function testThisModulesOwnConfigurationHasNoUnprotectedSecretField(): void
    {
        $violations = $this->violations(
            (string)file_get_contents($this->moduleFile('etc/adminhtml/system.xml')),
            (string)file_get_contents($this->moduleFile('etc/config.xml'))
        );

        $this->assertSame([], $violations, "SECURITY.md section 10 violated:\n" . implode("\n", $violations));
    }

    public function testTheRealFilesAreActuallyParsedAndContainFields(): void
    {
        // Guards the vacuity of the test above: if the path were wrong or the
        // XML structure changed so the xpath matched nothing, that test would
        // pass while checking nothing at all.
        $system = simplexml_load_string((string)file_get_contents($this->moduleFile('etc/adminhtml/system.xml')));

        $this->assertGreaterThan(10, count($system->xpath('//section//group/field')));
    }

    public function testACorrectlyProtectedSecretFieldIsAccepted(): void
    {
        $xml = $this->system(
            '<field id="client_secret" type="obscure"><label>Client Secret</label>'
            . '<backend_model>' . self::ENCRYPTED_BACKEND . '</backend_model></field>'
        );

        $this->assertSame([], $this->violations($xml, self::EMPTY_CONFIG));
    }

    public function testAPlainTextSecretFieldIsCaught(): void
    {
        $xml = $this->system('<field id="api_key" type="text"><label>API Key</label></field>');

        $violations = $this->violations($xml, self::EMPTY_CONFIG);

        $this->assertCount(2, $violations, 'plain type AND missing backend model are two separate faults');
        $this->assertStringContainsString('not type="obscure"', $violations[0]);
        $this->assertStringContainsString('stored in plain text', $violations[1]);
    }

    public function testAnObscureFieldWithoutTheEncryptedBackendIsCaught(): void
    {
        // Masked in the browser but stored in clear — the half-measure that
        // looks safe in the admin and is not.
        $xml = $this->system('<field id="refresh_token" type="obscure"><label>Refresh Token</label></field>');

        $violations = $this->violations($xml, self::EMPTY_CONFIG);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('stored in plain text', $violations[0]);
    }

    public function testASecretIsRecognisedByItsLabelEvenWhenTheIdIsInnocuous(): void
    {
        $xml = $this->system('<field id="value" type="text"><label>OAuth Client Secret</label></field>');

        $this->assertNotSame([], $this->violations($xml, self::EMPTY_CONFIG));
    }

    public function testADefaultValueInConfigXmlIsCaught(): void
    {
        $xml = $this->system(
            '<field id="client_secret" type="obscure"><label>Client Secret</label>'
            . '<backend_model>' . self::ENCRYPTED_BACKEND . '</backend_model></field>'
        );
        $config = '<config><default><s><g><client_secret>hunter2</client_secret></g></s></default></config>';

        $violations = $this->violations($xml, $config);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('must never ship in source', $violations[0]);
    }

    public function testOrdinaryFieldsAreLeftAlone(): void
    {
        $xml = $this->system(
            '<field id="sampling_rate" type="text"><label>Sampling Rate</label></field>'
            . '<field id="cookie_lifetime_days" type="text"><label>Cookie Lifetime</label></field>'
        );

        $this->assertSame([], $this->violations($xml, self::EMPTY_CONFIG), 'the pattern must not flag normal settings');
    }
}
