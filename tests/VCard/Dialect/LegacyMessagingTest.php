<?php

declare(strict_types=1);

namespace Rondeto\JSContact\Tests\VCard\Dialect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rondeto\JSContact\Model\Card;
use Rondeto\JSContact\Model\OnlineService;
use Rondeto\JSContact\VCard\Dialect\Apple;
use Rondeto\JSContact\VCard\Dialect\LegacyMessaging;
use Rondeto\JSContact\VCard\VCardDecoder;
use Rondeto\JSContact\VCard\VCardEncoder;
use Rondeto\JSContact\VCard\VCardVersion;

final class LegacyMessagingTest extends TestCase
{
    public function testMessagingPropertiesConvertToOnlineServices(): void
    {
        $result = new VCardDecoder(dialects: [new LegacyMessaging()])->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/android-full.vcf'))[0];

        $services = array_map(static fn (OnlineService $service): array => [$service->service, $service->user, $service->uri], $result->value->onlineServices);
        self::assertSame([
            'OS-1' => ['Skype', 'skypeaccount', null],
            'OS-2' => ['AIM', 'aimaccount', null],
            'OS-3' => ['MSN', 'winaccount', null],
            'OS-4' => ['Yahoo', 'yahooaccount', null],
            'OS-5' => ['QQ', 'qqaccount', null],
            'OS-6' => ['GoogleTalk', 'account', null],
            'OS-7' => ['ICQ', 'account', null],
            'OS-8' => ['Jabber', 'account', null],
            'OS-9' => ['SIP', 'appel-internet', null],
        ], $services);
        self::assertSame([], array_map(strval(...), $result->issues));
    }

    public function testContextsAndPreferenceAreKept(): void
    {
        $card = new VCardDecoder(dialects: [new LegacyMessaging()])->decode("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Test\r\nX-AIM;type=HOME;type=pref:cozypseudo\r\nEND:VCARD\r\n")[0]->value ?? null;

        self::assertEquals(['OS-1' => new OnlineService('AIM', user: 'cozypseudo', contexts: ['private'], pref: 1, vCardName: 'socialprofile')], $card?->onlineServices);
    }

    public function testUserNamesAreWrittenAsTheMessagingProperties(): void
    {
        $card = new Card(onlineServices: [
            'a' => new OnlineService('AIM', user: 'jdoe', contexts: ['work']),
            'g' => new OnlineService('googletalk', user: 'jdoe@gmail.com'),
            'm' => new OnlineService('Mastodon', user: '@jdoe@example.com'),
            'x' => new OnlineService('Jabber', uri: 'xmpp:jdoe@example.com'),
        ]);

        $vCard = new VCardEncoder(dialects: [new LegacyMessaging()])->encode($card, VCardVersion::V30)->value;

        self::assertStringContainsString("X-AIM;PROP-ID=a;TYPE=work:jdoe\r\n", $vCard);
        self::assertStringContainsString("X-GOOGLE-TALK;PROP-ID=g:jdoe@gmail.com\r\n", $vCard);
        // No messaging property for this service, nor for a URI: they stay.
        self::assertStringContainsString('SOCIALPROFILE;SERVICE-TYPE=Mastodon;VALUE=text;PROP-ID=m:@jdoe@example.com', $vCard);
        self::assertStringContainsString('SOCIALPROFILE;SERVICE-TYPE=Jabber;PROP-ID=x:xmpp:jdoe@example.com', $vCard);
    }

    public function testDialectsWriteInTheReverseOrderTheyRead(): void
    {
        $card = new Card(onlineServices: ['a' => new OnlineService('AIM', user: 'jdoe')]);

        // Apple would write X-SOCIALPROFILE, had it written first.
        $vCard = new VCardEncoder(dialects: [new Apple(), new LegacyMessaging()])->encode($card, VCardVersion::V30)->value;

        self::assertStringContainsString("X-AIM;PROP-ID=a:jdoe\r\n", $vCard);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function exports(): iterable
    {
        foreach (['android-full.vcf', 'apple.vcf', 'google-full.vcf', 'ios-full.vcf'] as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('exports')]
    public function testExportsSurviveARoundTripThroughTheDialects(string $file): void
    {
        $dialects = [new Apple(), new LegacyMessaging()];
        foreach (new VCardDecoder(dialects: $dialects)->decode((string) file_get_contents(__DIR__.'/../../Fixtures/CozyVcard/'.$file)) as $result) {
            $vCard = new VCardEncoder(validate: false, dialects: $dialects)->encode($result->value, VCardVersion::V30)->value;

            self::assertEquals($result->value, new VCardDecoder(dialects: $dialects)->decode($vCard)[0]->value ?? null);
        }
    }
}
