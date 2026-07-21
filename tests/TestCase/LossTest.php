<?php

declare(strict_types=1);

namespace MarkupCarve\Chat\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Chat\ChatRenderer;
use MarkupCarve\Chat\Fallback;
use MarkupCarve\Chat\FlavorRegistry;
use PHPUnit\Framework\TestCase;

final class LossTest extends TestCase
{
    public function testTableDegradesToCodeBlockAndRecordsLoss(): void
    {
        $document = new Document();
        $document->appendChild($this->table());

        $result = (new ChatRenderer((new FlavorRegistry())->get('discord')))->renderResult($document);

        self::assertSame("```\nA  B\n1  2\n```\n", $result->text);
        self::assertSame(NodeType::TABLE, $result->losses[0]->nodeType);
        self::assertSame(Fallback::CodeBlock, $result->losses[0]->fallback);
    }

    public function testWhatsappLinkRecordsLoss(): void
    {
        $result = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->renderResult($this->linkDocument());

        self::assertSame('site (https://example.com)' . "\n", $result->text);
        self::assertSame(NodeType::LINK, $result->losses[0]->nodeType);
    }

    public function testFootnoteReferenceNumberMatchesItsAppendixEntry(): void
    {
        $source = "First[^a] and second[^b].\n\n[^a]: Note A.\n\n[^b]: Note B.\n";
        $document = CarveConverter::create()->parse($source);

        $result = (new ChatRenderer((new FlavorRegistry())->get('whatsapp')))->renderResult($document);

        self::assertSame("First[1] and second[2].\n\n[1] Note A.\n[2] Note B.\n", $result->text);
        self::assertSame(Fallback::Appendix, $result->lossesFor(NodeType::FOOTNOTE)[0]->fallback);
    }

    public function testDiscordHeadingRecordsNoLoss(): void
    {
        $document = new Document();
        $heading = new Heading(4);
        $heading->appendChild(new Text('Deep'));
        $document->appendChild($heading);

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
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text(str_repeat('x', 2001)));
        $document = new Document();
        $document->appendChild($paragraph);

        $result = (new ChatRenderer((new FlavorRegistry())->get('discord')))->renderResult($document);

        self::assertSame('document', $result->losses[0]->nodeType);
    }

    private function linkDocument(): Document
    {
        $paragraph = new Paragraph();
        $link = new Link('https://example.com');
        $link->appendChild(new Text('site'));
        $paragraph->appendChild($link);
        $document = new Document();
        $document->appendChild($paragraph);

        return $document;
    }

    private function table(): Table
    {
        $table = new Table();
        $header = new TableRow(true);
        $header->appendChild($this->cell('A', true));
        $header->appendChild($this->cell('B', true));
        $table->appendChild($header);
        $row = new TableRow();
        $row->appendChild($this->cell('1'));
        $row->appendChild($this->cell('2'));
        $table->appendChild($row);

        return $table;
    }

    private function cell(string $text, bool $header = false): TableCell
    {
        $cell = new TableCell($header);
        $cell->appendChild(new Text($text));

        return $cell;
    }
}
