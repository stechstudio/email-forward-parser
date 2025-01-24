<?php
use PHPUnit\Framework\TestCase;
use STS\EmailForward\Parser;

class ParserTest extends TestCase
{
    private const SUBJECT = "Integer consequat non purus";
    private const BODY = "Aenean quis diam urna. Maecenas eleifend vulputate ligula ac consequat. Pellentesque cursus tincidunt mauris non venenatis.\nSed nec facilisis tellus. Nunc eget eros quis ex congue iaculis nec quis massa. Morbi in nisi tincidunt, euismod ante eget, eleifend nisi.\n\nPraesent ac ligula orci. Pellentesque convallis suscipit mi, at congue massa sagittis eget.";
    private const MESSAGE = "Praesent suscipit egestas hendrerit.\n\nAliquam eget dui dui.";

    private const FROM_ADDRESS = "john.doe@acme.com";
    private const FROM_NAME = "John Doe";

    private const TO_ADDRESS_1 = "bessie.berry@acme.com";
    private const TO_NAME_1 = "Bessie Berry";
    private const TO_ADDRESS_2 = "suzanne@globex.corp";
    private const TO_NAME_2 = "Suzanne";

    private const CC_ADDRESS_1 = "walter.sheltan@acme.com";
    private const CC_NAME_1 = "Walter Sheltan";
    private const CC_ADDRESS_2 = "nicholas@globex.corp";
    private const CC_NAME_2 = "Nicholas";

    private $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    private function loopTests(array $entries, callable $testFn): void
    {
        foreach ($entries as $entry) {
            $result = is_array($entry)
                ? $this->parseEmail($entry[0], $entry[1])
                : $this->parseEmail($entry);

            $entryName = is_array($entry) ? $entry[0] : $entry;

            $testFn($result, $entryName);
        }
    }

    private function parseEmail($emailFile, $subjectFile = null): array
    {
        $subject = null;
        $email = file_get_contents(__DIR__ . "/fixtures/{$emailFile}.txt");

        if ($subjectFile) {
            $subject = file_get_contents(__DIR__ . "/fixtures/{$subjectFile}.txt");
        }

        return $this->parser->read($email, $subject);
    }

    private function testEmail(
        array $result,
        bool $skipFrom = false,
        bool $skipTo = false,
        bool $skipCc = false,
        bool $skipMessage = false,
        bool $skipBody = false
    ): void {
        $email = $result['email'] ?? [];

        $this->assertTrue($result['forwarded']);

        $this->assertEquals(self::SUBJECT, $email['subject']);

        if (!$skipBody) {
            $this->assertEquals(self::BODY, $email['body']);
        }

        $this->assertIsString($email['date']);
        $this->assertGreaterThan(1, strlen($email['date']));

        if (!$skipFrom) {
            $this->assertEquals(self::FROM_ADDRESS, $email['from']['address']);
            $this->assertEquals(self::FROM_NAME, $email['from']['name']);
        }

        if (!$skipTo) {
            $this->assertEquals(self::TO_ADDRESS_1, $email['to'][0]['address']);
            $this->assertNull($email['to'][0]['name']);
        }

        if (!$skipCc) {
            $this->assertEquals(self::CC_ADDRESS_1, $email['cc'][0]['address']);
            $this->assertEquals(self::CC_NAME_1, $email['cc'][0]['name']);
            $this->assertEquals(self::CC_ADDRESS_2, $email['cc'][1]['address']);
            $this->assertEquals(self::CC_NAME_2, $email['cc'][1]['name']);
        }

        if (!$skipMessage) {
            $this->assertEquals(self::MESSAGE, $result['message']);
        }
    }

    public function testCommon(): void
    {
        $entries = [
            "apple_mail_cs_body",
            "apple_mail_da_body",
            "apple_mail_de_body",
            "apple_mail_en_body",
            "apple_mail_es_body",
            "apple_mail_fi_body",
            "apple_mail_fr_body",
            "apple_mail_hr_body",
            "apple_mail_hu_body",
            "apple_mail_it_body",
            "apple_mail_nl_body",
            "apple_mail_no_body",
            "apple_mail_pl_body",
            "apple_mail_pt_br_body",
            "apple_mail_pt_body",
            "apple_mail_ro_body",
            "apple_mail_ru_body",
            "apple_mail_sk_body",
            "apple_mail_sv_body",
            "apple_mail_tr_body",
            "apple_mail_uk_body",

            "gmail_cs_body",
            "gmail_da_body",
            "gmail_de_body",
            "gmail_en_body",
            "gmail_es_body",
            "gmail_et_body",
            "gmail_fi_body",
            "gmail_fr_body",
            "gmail_hr_body",
            "gmail_hu_body",
            "gmail_it_body",
            "gmail_nl_body",
            "gmail_no_body",
            "gmail_pl_body",
            "gmail_pt_br_body",
            "gmail_pt_body",
            "gmail_ro_body",
            "gmail_ru_body",
            "gmail_sk_body",
            "gmail_sv_body",
            "gmail_tr_body",
            "gmail_uk_body",

            "hubspot_de_body",
            "hubspot_en_body",
            "hubspot_es_body",
            "hubspot_fi_body",
            "hubspot_fr_body",
            "hubspot_it_body",
            "hubspot_ja_body",
            "hubspot_nl_body",
            "hubspot_pl_body",
            "hubspot_pt_br_body",
            "hubspot_sv_body",

            "ionos_one_and_one_en_body",

            "mailmate_en_body",

            "missive_en_body",

            ["outlook_live_body", "outlook_live_cs_subject"],
            ["outlook_live_body", "outlook_live_da_subject"],
            ["outlook_live_body", "outlook_live_de_subject"],
            ["outlook_live_body", "outlook_live_en_subject"],
            ["outlook_live_body", "outlook_live_es_subject"],
            ["outlook_live_body", "outlook_live_fr_subject"],
            ["outlook_live_body", "outlook_live_hr_subject"],
            ["outlook_live_body", "outlook_live_hu_subject"],
            ["outlook_live_body", "outlook_live_it_subject"],
            ["outlook_live_body", "outlook_live_nl_subject"],
            ["outlook_live_body", "outlook_live_no_subject"],
            ["outlook_live_body", "outlook_live_pl_subject"],
            ["outlook_live_body", "outlook_live_pt_br_subject"],
            ["outlook_live_body", "outlook_live_pt_subject"],
            ["outlook_live_body", "outlook_live_ro_subject"],
            ["outlook_live_body", "outlook_live_sk_subject"],
            ["outlook_live_body", "outlook_live_sv_subject"],

            ["outlook_2013_en_body", "outlook_2013_en_subject"],

            ["new_outlook_2019_cs_body", "new_outlook_2019_cs_subject"],
            ["new_outlook_2019_da_body", "new_outlook_2019_da_subject"],
            ["new_outlook_2019_de_body", "new_outlook_2019_de_subject"],
            ["new_outlook_2019_en_body", "new_outlook_2019_en_subject"],
            ["new_outlook_2019_es_body", "new_outlook_2019_es_subject"],
            ["new_outlook_2019_fi_body", "new_outlook_2019_fi_subject"],
            ["new_outlook_2019_fr_body", "new_outlook_2019_fr_subject"],
            ["new_outlook_2019_hu_body", "new_outlook_2019_hu_subject"],
            ["new_outlook_2019_it_body", "new_outlook_2019_it_subject"],
            ["new_outlook_2019_nl_body", "new_outlook_2019_nl_subject"],
            ["new_outlook_2019_no_body", "new_outlook_2019_no_subject"],
            ["new_outlook_2019_pl_body", "new_outlook_2019_pl_subject"],
            ["new_outlook_2019_pt_br_body", "new_outlook_2019_pt_br_subject"],
            ["new_outlook_2019_pt_body", "new_outlook_2019_pt_subject"],
            ["new_outlook_2019_ru_body", "new_outlook_2019_ru_subject"],
            ["new_outlook_2019_sk_body", "new_outlook_2019_sk_subject"],
            ["new_outlook_2019_sv_body", "new_outlook_2019_sv_subject"],
            ["new_outlook_2019_tr_body", "new_outlook_2019_tr_subject"],

            ["outlook_2019_cz_body", "outlook_2019_subject"],
            ["outlook_2019_da_body", "outlook_2019_subject"],
            ["outlook_2019_de_body", "outlook_2019_subject"],
            ["outlook_2019_en_body", "outlook_2019_subject"],
            ["outlook_2019_es_body", "outlook_2019_subject"],
            ["outlook_2019_fi_body", "outlook_2019_subject"],
            ["outlook_2019_fr_body", "outlook_2019_subject"],
            ["outlook_2019_hu_body", "outlook_2019_subject"],
            ["outlook_2019_it_body", "outlook_2019_subject"],
            ["outlook_2019_nl_body", "outlook_2019_subject"],
            ["outlook_2019_no_body", "outlook_2019_subject"],
            ["outlook_2019_pl_body", "outlook_2019_subject"],
            ["outlook_2019_pt_body", "outlook_2019_subject"],
            ["outlook_2019_ru_body", "outlook_2019_subject"],
            ["outlook_2019_sk_body", "outlook_2019_subject"],
            ["outlook_2019_sv_body", "outlook_2019_subject"],
            ["outlook_2019_tr_body", "outlook_2019_subject"],

            "thunderbird_cs_body",
            "thunderbird_da_body",
            "thunderbird_de_body",
            "thunderbird_en_body",
            "thunderbird_es_body",
            "thunderbird_fi_body",
            "thunderbird_fr_body",
            "thunderbird_hr_body",
            "thunderbird_hu_body",
            "thunderbird_it_body",
            "thunderbird_nl_body",
            "thunderbird_no_body",
            "thunderbird_pl_body",
            "thunderbird_pt_br_body",
            "thunderbird_pt_body",
            "thunderbird_ro_body",
            "thunderbird_ru_body",
            "thunderbird_sk_body",
            "thunderbird_sv_body",
            "thunderbird_tr_body",
            "thunderbird_uk_body",

            "yahoo_cs_body",
            "yahoo_da_body",
            "yahoo_de_body",
            "yahoo_en_body",
            "yahoo_es_body",
            "yahoo_fi_body",
            "yahoo_fr_body",
            "yahoo_hu_body",
            "yahoo_it_body",
            "yahoo_nl_body",
            "yahoo_no_body",
            "yahoo_pl_body",
            "yahoo_pt_body",
            "yahoo_pt_br_body",
            "yahoo_ro_body",
            "yahoo_ru_body",
            "yahoo_sk_body",
            "yahoo_sv_body",
            "yahoo_tr_body",
            "yahoo_uk_body"
        ];

        $this->loopTests($entries, function ($result, $entryName) {
            $skipTo = strpos($entryName, "outlook_2019_") === 0;
            $skipCc = $skipTo || strpos($entryName, "ionos_one_and_one_") === 0;

            // Ensure the result is valid
            $this->assertIsArray($result);
            $this->assertArrayHasKey('forwarded', $result);
            $this->assertArrayHasKey('email', $result);

            $this->testEmail(
                $result,
                false, // skipFrom
                $skipTo,
                $skipCc,
                true // skipMessage
            );

            $this->assertNull($result['message']);
        });
    }

    public function testVariant1(): void
    {
        $entries = [
            "apple_mail_en_body_variant_1",
            "gmail_en_body_variant_1",
            "hubspot_en_body_variant_1",
            "mailmate_en_body_variant_1",
            "missive_en_body_variant_1",
            ["outlook_live_en_body_variant_1", "outlook_live_en_subject"],
            ["new_outlook_2019_en_body_variant_1", "new_outlook_2019_en_subject"],
            "yahoo_en_body_variant_1",
            "thunderbird_en_body_variant_1"
        ];

        $this->loopTests($entries, function ($result) {
            $this->testEmail(
                $result,
                false, // skipFrom
                true, // skipTo
                true, // skipCc
                true // skipMessage
            );

            $this->assertEquals(self::TO_ADDRESS_1, $result['email']['to'][0]['address'] ?? null);
            $this->assertNull($result['email']['to'][0]['name'] ?? null);
            $this->assertEquals(self::TO_ADDRESS_2, $result['email']['to'][1]['address'] ?? null);
            $this->assertNull($result['email']['to'][1]['name'] ?? null);

            $this->assertCount(0, $result['email']['cc'] ?? []);
        });
    }

    public function testVariant2(): void
    {
        $entries = [
            "apple_mail_en_body_variant_2",
            "gmail_en_body_variant_2",
            "hubspot_en_body_variant_2",
            "ionos_one_and_one_en_body_variant_2",
            "mailmate_en_body_variant_2",
            "missive_en_body_variant_2",
            ["outlook_live_en_body_variant_2", "outlook_live_en_subject"],
            ["new_outlook_2019_en_body_variant_2", "new_outlook_2019_en_subject"],
            ["outlook_2019_en_body_variant_2", "outlook_2019_subject"],
            "yahoo_en_body_variant_2",
            "thunderbird_en_body_variant_2"
        ];

        $this->loopTests($entries, function ($result, $entryName) {
            $skipCc = $entryName === "outlook_2019_en_body_variant_2" || $entryName === "ionos_one_and_one_en_body_variant_2";

            $this->testEmail(
                $result,
                false, // skipFrom
                true, // skipTo
                $skipCc
            );

            if ($entryName !== "outlook_2019_en_body_variant_2") {
                $this->assertEquals(self::TO_ADDRESS_1, $result['email']['to'][0]['address'] ?? null);
                $this->assertEquals(self::TO_ADDRESS_2, $result['email']['to'][1]['address'] ?? null);
            }
        });
    }

    // Additional test methods for other variants can be added here...
}