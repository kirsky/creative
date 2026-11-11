<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Tasks\Geo\GeoCodes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeoCodesTest extends TestCase
{
    #[DataProvider('valid')]
    public function test_valid_codes(string $code): void
    {
        self::assertTrue(GeoCodes::isValid($code), $code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        foreach (['US', 'DE', 'UA', 'RU', 'GB', 'KZ', 'ZW', 'AX', 'WW', 'EU', 'LATAM', 'MENA', 'CIS'] as $code) {
            yield $code => [$code];
        }
    }

    #[DataProvider('invalid')]
    public function test_invalid_codes(string $code): void
    {
        self::assertFalse(GeoCodes::isValid($code), $code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        foreach (['XX', 'us', 'Us', '', 'USA', 'U', 'ww', 'latam', ' US', 'US ', "US\n", 'AA', 'ZZ', 'UK', '12', 'АА'] as $code) {
            yield var_export($code, true) => [$code];
        }
    }

    public function test_the_lists(): void
    {
        self::assertCount(249, GeoCodes::countries());
        self::assertSame(GeoCodes::countries(), array_values(array_unique(GeoCodes::countries())));
        self::assertSame(['WW', 'EU', 'LATAM', 'MENA', 'CIS'], GeoCodes::regions());
        foreach (GeoCodes::countries() as $code) {
            self::assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
        self::assertSame([], array_intersect(GeoCodes::countries(), GeoCodes::regions()));
    }
}
