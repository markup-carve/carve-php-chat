<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Chat\ChatFlavor;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\Exception\InvalidFlavorException;
use MarkupCarve\Chat\FlavorRegistry;
use MarkupCarve\Chat\OffsetUnit;
use MarkupCarve\Chat\OutputMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function json_decode;

final class StyleRangeTest extends TestCase
{
    /**
     * A range-based target sends a plain-text body with the formatting
     * alongside it, so no delimiters may appear in the text itself.
     */
    public function testRangeModeEmitsPlainTextPlusOffsets(): void
    {
        $document = CarveConverter::create()->parse('Shipped *bold* and /em/ and `code`.');

        $result = (new ChatRenderer((new FlavorRegistry())->get('signal')))->renderResult($document);

        self::assertSame("Shipped bold and em and code.\n", $result->text);
        self::assertSame(true, $result->hasRanges());
        self::assertSame(
            [
                ['start' => 8, 'length' => 4, 'style' => 'BOLD'],
                ['start' => 17, 'length' => 2, 'style' => 'ITALIC'],
                ['start' => 24, 'length' => 4, 'style' => 'MONOSPACE'],
            ],
            $result->rangesToArray(),
        );
    }

    /**
     * Every range must actually select the text it claims to - the whole point
     * of an offset is that the receiver slices the body with it.
     */
    public function testEveryRangeSelectsItsOwnText(): void
    {
        $document = CarveConverter::create()->parse('A *one* B /two/ C `three` D');

        $result = (new ChatRenderer((new FlavorRegistry())->get('signal')))->renderResult($document);
        $selected = [];
        foreach ($result->ranges as $range) {
            $selected[] = substr($result->text, $range->start, $range->length);
        }

        self::assertSame(['one', 'two', 'three'], $selected);
    }

    /**
     * A delimiter-based target keeps formatting in the string, so it must not
     * emit ranges at all.
     */
    public function testMarkupFlavorsEmitNoRanges(): void
    {
        $document = CarveConverter::create()->parse('Shipped *bold*.');
        $registry = new FlavorRegistry();

        foreach (['whatsapp', 'slack', 'discord', 'telegram-html'] as $id) {
            $result = (new ChatRenderer($registry->get($id)))->renderResult($document);
            self::assertSame(false, $result->hasRanges(), $id . ' should not emit ranges');
        }
    }

    /**
     * Telegram documents entity offsets in UTF-16 code units, so a non-BMP
     * character counts as two. Measuring in the wrong unit shifts every range
     * after such a character.
     *
     * @return array<string, array{0: \MarkupCarve\Chat\OffsetUnit, 1: int}>
     */
    public static function offsetUnitProvider(): array
    {
        return [
            'utf16 counts the emoji as two' => [OffsetUnit::Utf16, 3],
            'utf8 counts its four bytes' => [OffsetUnit::Utf8, 5],
            'codepoints count it once' => [OffsetUnit::Codepoints, 2],
        ];
    }

    #[DataProvider('offsetUnitProvider')]
    public function testOffsetsHonorTheDeclaredUnit(OffsetUnit $unit, int $expectedStart): void
    {
        $document = CarveConverter::create()->parse('👍 *bold* ok');
        $flavor = $this->signalWithUnit($unit);

        $result = (new ChatRenderer($flavor))->renderResult($document);

        self::assertSame($expectedStart, $result->ranges[0]->start);
        self::assertSame(4, $result->ranges[0]->length);
    }

    /**
     * Nested marks each get their own range over the same text.
     */
    public function testNestedMarksProduceOverlappingRanges(): void
    {
        $document = CarveConverter::create()->parse('A */both/* B');

        $result = (new ChatRenderer((new FlavorRegistry())->get('signal')))->renderResult($document);

        self::assertSame("A both B\n", $result->text);

        // Order between ranges covering the same span is not significant to any
        // receiver, so compare as a set rather than pinning it.
        $styles = array_map(static fn ($range): string => $range->style, $result->ranges);
        sort($styles);

        self::assertSame(['BOLD', 'ITALIC'], $styles);
        foreach ($result->ranges as $range) {
            self::assertSame('both', substr($result->text, $range->start, $range->length));
        }
    }

    /**
     * The marker sentinels are an implementation detail and must never survive
     * into the message body.
     */
    public function testNoMarkerSentinelsLeakIntoTheText(): void
    {
        $document = CarveConverter::create()->parse("*a* /b/ `c`\n\n> *quoted*\n\n- *item*\n");

        $result = (new ChatRenderer((new FlavorRegistry())->get('signal')))->renderResult($document);

        self::assertSame(0, preg_match('/[\x01-\x04]/', $result->text));
    }

    /**
     * Some styles need more than a name. Once the delimiters are gone the body
     * has no room for a URL or a language, so the range carries them.
     */
    public function testRangesCarryTheirPayload(): void
    {
        $source = "[docs](https://e.com/a)\n\n> quoted\n\n``` php\necho 1;\n```\n";
        $document = CarveConverter::create()->parse($source);

        $result = (new ChatRenderer((new FlavorRegistry())->get('telegram-entities')))->renderResult($document);

        self::assertSame("docs\n\nquoted\n\necho 1;\n", $result->text);
        self::assertSame(
            [
                ['start' => 0, 'length' => 4, 'style' => 'text_link', 'url' => 'https://e.com/a'],
                ['start' => 6, 'length' => 6, 'style' => 'blockquote'],
                ['start' => 14, 'length' => 7, 'style' => 'pre', 'language' => 'php'],
            ],
            $result->rangesToArray(),
        );
    }

    /**
     * A payload-carrying target must not also inline the URL - that would
     * duplicate it, once in the body and once in the entity.
     */
    public function testPayloadFlavorDoesNotAlsoInlineTheUrl(): void
    {
        $document = CarveConverter::create()->parse('See [docs](https://e.com).');

        $result = (new ChatRenderer((new FlavorRegistry())->get('telegram-entities')))->renderResult($document);

        self::assertSame("See docs.\n", $result->text);
        self::assertStringNotContainsString('https://e.com', $result->text);
    }

    public function testUnknownOutputModeIsRejected(): void
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/resources/flavors/signal.json'), true);
        self::assertIsArray($data);
        $data['output'] = 'nonsense';

        $this->expectException(InvalidFlavorException::class);
        ChatFlavor::fromArray($data);
    }

    public function testTelegramEntitiesFlavorIsRangeBased(): void
    {
        $flavor = (new FlavorRegistry())->get('telegram-entities');

        self::assertSame(OutputMode::Ranges, $flavor->output());
        self::assertSame(OffsetUnit::Utf16, $flavor->offsetUnit());
        self::assertSame('bold', $flavor->styleFor('strong'));
    }

    private function signalWithUnit(OffsetUnit $unit): ChatFlavor
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/resources/flavors/signal.json'), true);
        self::assertIsArray($data);
        $data['offsets'] = $unit->value;

        return ChatFlavor::fromArray($data);
    }
}
