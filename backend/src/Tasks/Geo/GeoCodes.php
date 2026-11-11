<?php

declare(strict_types=1);

namespace App\Tasks\Geo;

/**
 * The codes a task's GEO may hold: the 249 officially assigned ISO 3166-1 alpha-2 country codes,
 * and a few region codes for campaigns that are not about one country. Codes are upper case and
 * compared exactly; display names are the frontend's business (Intl.DisplayNames).
 */
final class GeoCodes
{
    private const string COUNTRIES = <<<'CODES'
        AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ
        BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ
        CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ
        DE DJ DK DM DO DZ
        EC EE EG EH ER ES ET
        FI FJ FK FM FO FR
        GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY
        HK HM HN HR HT HU
        ID IE IL IM IN IO IQ IR IS IT
        JE JM JO JP
        KE KG KH KI KM KN KP KR KW KY KZ
        LA LB LC LI LK LR LS LT LU LV LY
        MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ
        NA NC NE NF NG NI NL NO NP NR NU NZ
        OM
        PA PE PF PG PH PK PL PM PN PR PS PT PW PY
        QA
        RE RO RS RU RW
        SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ
        TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ
        UA UG UM US UY UZ
        VA VC VE VG VI VN VU
        WF WS
        YE YT
        ZA ZM ZW
        CODES;

    /** Worldwide, Europe, Latin America, Middle East and North Africa, the CIS. */
    private const array REGIONS = ['WW', 'EU', 'LATAM', 'MENA', 'CIS'];

    /** @var array<string, true>|null */
    private static ?array $lookup = null;

    public static function isValid(string $code): bool
    {
        return isset(self::lookup()[$code]);
    }

    /**
     * @return list<string> every country code, alphabetical
     */
    public static function countries(): array
    {
        return preg_split('/\s+/', self::COUNTRIES, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return list<string>
     */
    public static function regions(): array
    {
        return self::REGIONS;
    }

    /**
     * @return array<string, true>
     */
    private static function lookup(): array
    {
        return self::$lookup ??= array_fill_keys([...self::countries(), ...self::REGIONS], true);
    }
}
