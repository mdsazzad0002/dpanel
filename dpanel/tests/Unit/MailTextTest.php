<?php

namespace Tests\Unit;

use App\Support\MailText;
use PHPUnit\Framework\TestCase;

class MailTextTest extends TestCase
{
    public function test_declared_charsets_are_converted_to_utf8(): void
    {
        $this->assertSame('café', MailText::toUtf8("caf\xE9", 'iso-8859-1'));
        $this->assertSame('Привет', MailText::toUtf8(mb_convert_encoding('Привет', 'KOI8-R', 'UTF-8'), 'koi8-r'));
        $this->assertSame('“quoted”', MailText::toUtf8("\x93quoted\x94", 'windows-1252'));
    }

    public function test_invalid_utf8_is_repaired_so_json_encoding_succeeds(): void
    {
        $this->assertSame('café', MailText::toUtf8("caf\xE9"));
        $this->assertSame('café', MailText::toUtf8("caf\xE9", 'utf-8'));

        $result = MailText::toUtf8("ok \xC3\x28 \xF0\x9F\x98\x80");
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
        $this->assertNotFalse(json_encode($result));
    }

    public function test_valid_utf8_and_unknown_charsets_pass_through(): void
    {
        $this->assertSame('Hello 👋', MailText::toUtf8('Hello 👋', 'utf-8'));
        $this->assertSame('Hello', MailText::toUtf8('Hello', 'x-made-up-charset'));
    }

    public function test_html_is_turned_into_readable_text(): void
    {
        $html = '<html><head><meta charset="utf-8"><style>.a{color:red}</style></head>'
            .'<body><p>Hi&nbsp;there,</p><p>Click <a href="https://example.com/x">here</a><br>Thanks</p>'
            .'<script>alert(1)</script></body></html>';

        $this->assertSame('utf-8', MailText::htmlMetaCharset($html));
        $this->assertSame("Hi there,\nClick here (https://example.com/x)\nThanks", MailText::htmlToText($html));
    }
}
