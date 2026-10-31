<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use PHPUnit\Framework\TestCase;
use StarDust\Exception\InvalidCursorException;
use StarDust\Read\Cursor;
use StarDust\Read\CursorCodec;
use StarDust\Read\CursorPayload;
use StarDust\Read\NullPlacement;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;

/**
 * Where entries with no value land, as a cursor sees it.
 *
 * DB-free. The placement is part of the ordering a token was issued for,
 * so it has to survive a round trip, stay out of the token when it is the
 * database's default (or every token already in flight would change) and
 * be compared as an effective value (or spelling the default out would be
 * refused as a different ordering).
 */
final class CursorNullPlacementTest extends TestCase
{
    private function raw(Cursor $cursor): string
    {
        $opaque = $cursor->opaque;
        $pad    = strlen($opaque) % 4;
        if ($pad > 0) {
            $opaque .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($opaque, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    private function token(string $raw): Cursor
    {
        return new Cursor(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));
    }

    public function testADefaultFieldSortKeepsTheTokenItAlwaysEmitted(): void
    {
        // Byte level, key order included. A change here invalidates every
        // cursor a caller has stored.
        self::assertSame(
            'v2:{"k":"f:title","d":"asc","id":42}',
            $this->raw(CursorCodec::encodeFor(SortSpec::byField('title'), 42)),
        );
        self::assertSame(
            'v2:{"k":"f:title","d":"desc","id":42}',
            $this->raw(CursorCodec::encodeFor(SortSpec::byField('title', SortDirection::Desc), 42)),
        );
    }

    public function testSpellingTheDefaultOutGivesTheSameToken(): void
    {
        self::assertSame(
            $this->raw(CursorCodec::encodeFor(SortSpec::byField('title'), 42)),
            $this->raw(CursorCodec::encodeFor(
                SortSpec::byField('title', SortDirection::Asc, NullPlacement::First),
                42,
            )),
        );
        self::assertSame(
            $this->raw(CursorCodec::encodeFor(SortSpec::byField('title', SortDirection::Desc), 42)),
            $this->raw(CursorCodec::encodeFor(
                SortSpec::byField('title', SortDirection::Desc, NullPlacement::Last),
                42,
            )),
        );
    }

    public function testTheOtherPlacementIsWrittenAsN(): void
    {
        self::assertSame(
            'v2:{"k":"f:title","d":"asc","id":42,"n":"last"}',
            $this->raw(CursorCodec::encodeFor(
                SortSpec::byField('title', SortDirection::Asc, NullPlacement::Last),
                42,
            )),
        );
        self::assertSame(
            'v2:{"k":"f:title","d":"desc","id":42,"n":"first"}',
            $this->raw(CursorCodec::encodeFor(
                SortSpec::byField('title', SortDirection::Desc, NullPlacement::First),
                42,
            )),
        );
    }

    public function testThePlacementSurvivesARoundTrip(): void
    {
        $sort    = SortSpec::byField('title', SortDirection::Asc, NullPlacement::Last);
        $payload = CursorCodec::decodePayload(CursorCodec::encodeFor($sort, 42));

        self::assertSame(42, $payload->entryId);
        self::assertSame(NullPlacement::Last, $payload->nulls);
        self::assertTrue($payload->matchesSort($sort));
    }

    public function testATokenWithoutNMeansTheDefaultForItsDirection(): void
    {
        $issued = CursorCodec::decodePayload($this->token('v2:{"k":"f:title","d":"desc","id":9}'));

        self::assertNull($issued->nulls);
        self::assertTrue($issued->matchesSort(SortSpec::byField('title', SortDirection::Desc)));
        self::assertTrue($issued->matchesSort(
            SortSpec::byField('title', SortDirection::Desc, NullPlacement::Last),
        ));
        self::assertFalse($issued->matchesSort(
            SortSpec::byField('title', SortDirection::Desc, NullPlacement::First),
        ));
    }

    public function testAPlacementIsRefusedUnderTheOtherOne(): void
    {
        $ascLast = CursorPayload::forSort(
            SortSpec::byField('title', SortDirection::Asc, NullPlacement::Last),
            5,
        );
        self::assertTrue($ascLast->matchesSort(SortSpec::byField('title', SortDirection::Asc, NullPlacement::Last)));
        self::assertFalse($ascLast->matchesSort(SortSpec::byField('title')));
        self::assertFalse($ascLast->matchesSort(
            SortSpec::byField('title', SortDirection::Asc, NullPlacement::First),
        ));

        $descFirst = CursorPayload::forSort(
            SortSpec::byField('title', SortDirection::Desc, NullPlacement::First),
            5,
        );
        self::assertTrue($descFirst->matchesSort(
            SortSpec::byField('title', SortDirection::Desc, NullPlacement::First),
        ));
        self::assertFalse($descFirst->matchesSort(SortSpec::byField('title', SortDirection::Desc)));
    }

    public function testAnUnknownOrMalformedPlacementIsRejected(): void
    {
        foreach (['""', '5', '"middle"', 'null', '["first"]'] as $n) {
            try {
                CursorCodec::decodePayload($this->token('v2:{"k":"f:title","d":"asc","id":9,"n":' . $n . '}'));
                self::fail("A cursor with n = {$n} was accepted.");
            } catch (InvalidCursorException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnUnsortedTokenStillMeansTheDefaultOrder(): void
    {
        $legacy = CursorCodec::decodePayload(CursorCodec::encode(7));

        self::assertNull($legacy->nulls);
        self::assertTrue($legacy->matchesSort(null));
        self::assertTrue($legacy->matchesSort(SortSpec::byId()));
        self::assertFalse($legacy->matchesSort(SortSpec::byField('title')));
    }

    public function testEffectivePlacementFillsInTheDatabasesDefault(): void
    {
        self::assertSame(NullPlacement::First, SortSpec::byField('title')->effectiveNulls());
        self::assertSame(
            NullPlacement::Last,
            SortSpec::byField('title', SortDirection::Desc)->effectiveNulls(),
        );
        self::assertSame(
            NullPlacement::Last,
            SortSpec::byField('title', SortDirection::Asc, NullPlacement::Last)->effectiveNulls(),
        );
        self::assertNull(SortSpec::byField('title')->nulls);
    }
}
