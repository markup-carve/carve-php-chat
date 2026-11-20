<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Include\IncludeResolver;
use MarkupCarve\Carve\Node;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\Fallback;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class LossTest extends TestCase
{
    public function testTableDegradesToCodeBlockAndRecordsLoss(): void
    {
        $document = Carve::parse("|= A |= B |\n| 1 | 2 |\n");

        $result = (new ChatRenderer((new FlavorRegistry())->get('discord')))->renderResult($document);

        self::assertSame("```\nA  B\n1  2\n```\n", $result->text);
        self::assertSame('table', $result->losses[0]->nodeType);
        self::assertSame(Fallback::CodeBlock, $result->losses[0]->fallback);
    }

    public function testWhatsappLinkRecordsLoss(): void
    {
        $result = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->renderResult($this->linkDocument());

        self::assertSame('site (https://example.com)' . "\n", $result->text);
        self::assertSame('link', $result->losses[0]->nodeType);
    }

    public function testFootnoteReferenceNumberMatchesItsAppendixEntry(): void
    {
        $source = "First[^a] and second[^b].\n\n[^a]: Note A.\n\n[^b]: Note B.\n";
        $document = Carve::parse($source);

        $result = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->renderResult($document);

        self::assertSame("First[1] and second[2].\n\n[1] Note A.\n[2] Note B.\n", $result->text);
        self::assertSame(Fallback::Appendix, $result->lossesFor('footnote')[0]->fallback);
    }

    public function testDiscordHeadingRecordsNoLoss(): void
    {
        $document = Carve::parse("==== Deep\n");

        $result = (new ChatRenderer((new FlavorRegistry())->get('discord')))->renderResult($document);

        self::assertSame('### Deep' . "\n", $result->text);
        self::assertSame(false, $result->hasLosses());
    }

    public function testDiscordBotMaskedLinkRecordsNoLossWhereDiscordRecordsOne(): void
    {
        $registry = new FlavorRegistry();
        $discord = (new ChatRenderer($registry->get('discord')))->renderResult($this->linkDocument());
        $bot = (new ChatRenderer($registry->get('discord-bot')))->renderResult($this->linkDocument());

        self::assertSame(true, $discord->hasLosses());
        self::assertSame('site (https://example.com)' . "\n", $discord->text);
        self::assertSame(false, $bot->hasLosses());
        self::assertSame('[site](https://example.com)' . "\n", $bot->text);
    }

    public function testOverLimitTextRecordsLengthLoss(): void
    {
        $document = Carve::parse(str_repeat('x', 2001));

        $result = (new ChatRenderer((new FlavorRegistry())->get('discord')))->renderResult($document);

        self::assertSame('document', $result->losses[0]->nodeType);
    }

    public function testLossTakesItsLineFromPositionTracking(): void
    {
        $document = Carve::parse("a\n\n***\n");

        $loss = (new ChatRenderer((new FlavorRegistry())->get('slack')))->renderResult($document)->losses[0];

        self::assertSame('thematic_break', $loss->nodeType);
        self::assertSame(3, $loss->sourceLine);
        self::assertNull($loss->file);
        self::assertNull($loss->includedBy);
    }

    public function testLossFromIncludedContentNamesTheIncludedFile(): void
    {
        $resolver = new class implements IncludeResolver {
            public function resolve(array $request): array
            {
                return $request['path'] === 'part.crv'
                    ? ['text' => "x\n\n***\n", 'id' => 'part.crv']
                    : ['error' => 'not_found'];
            }
        };
        $source = "a\n\n{{ part.crv }}\n";
        $document = Carve::parse($source, ['includes' => ['resolver' => $resolver, 'file' => 'main.crv']]);

        $loss = (new ChatRenderer((new FlavorRegistry())->get('slack')))->renderResult($document, 'main.crv')->losses[0];

        self::assertSame('thematic_break', $loss->nodeType);
        self::assertSame(3, $loss->sourceLine);
        self::assertSame('part.crv', $loss->file);
        self::assertSame([['file' => 'main.crv', 'line' => 3, 'column' => 1]], $loss->includedBy);
    }

    private function linkDocument(): Node
    {
        return Carve::parse('[site](https://example.com)');
    }
}
