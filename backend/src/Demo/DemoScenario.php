<?php

declare(strict_types=1);

namespace App\Demo;

use App\Identity\Role;

/**
 * The demo, as data: who the six accounts are and the sixteen tasks (mixed English and Russian, as
 * the wireframe of the board is) with everything that happened to each.
 *
 * Nothing here is a date. Events are "so long before now" and deadlines "so long from now", and the
 * seeder plays them back through the real services on a clock that runs from 14 days ago up to now,
 * so history, metrics and statuses agree. The statuses are not written down anywhere: they are what
 * the state machine makes of the steps.
 *
 * The board (3 new, 3 in progress, 2 under review, 2 revisions required, 4 completed, 2 cancelled):
 *
 *  - Lucky Spin is the traced task of the story: created, picked up, round 1, revisions, round 2,
 *    approved, with AI usage in both rounds (180 credits) and 4 d 2 h from pickup to approval.
 *  - Лото Клуб was released after revisions and picked up by another designer (two assignments).
 *  - Crypto Wave was released with a revision pending: New again, "returning".
 *  - Мега Спин (2 d late) and Лото Клуб (1 d late) are the overdue ones.
 *  - Dragon Gold was cancelled while New, Fruit Party in the middle of the work.
 */
final class DemoScenario
{
    /** What the seeder creates when the account is missing: username => display name and role. */
    public const array ACCOUNTS = [
        'dmitry' => ['Dmitry P.', Role::Buyer],
        'elena' => ['Elena V.', Role::Buyer],
        'anna' => ['Anna K.', Role::Designer],
        'igor' => ['Igor S.', Role::Designer],
        'maria' => ['Maria T.', Role::Designer],
        'manager' => ['Olga M.', Role::Manager],
    ];

    /** The providers that must exist (the migration seeds them; a reset database may lack them). */
    public const array PROVIDERS = ['Midjourney', 'Runway', 'Kling', 'ElevenLabs', 'OpenAI', 'Adobe Firefly'];

    /** How far back the history starts. */
    public const string WINDOW = 'P14D';

    /**
     * @return list<PlannedTask>
     */
    public static function tasks(): array
    {
        return [
            // Completed ---------------------------------------------------------------------------------

            new PlannedTask(
                'Sweet Bonanza',
                ['TR'],
                'static_image',
                5,
                'normal',
                'Баннеры 300x250, 728x90 и квадрат 1080x1080 для турецкого трафика. Brand colours from the guide, big CTA "Oyna".',
                'elena',
                self::ago(13, 12),
                -self::ago(7),
                ['https://example.com/briefs/sweet-bonanza'],
                [],
                [
                    PlannedStep::pickUp(self::ago(13, 10), 'igor'),
                    PlannedStep::submit(
                        self::ago(11, 8),
                        'igor',
                        ['sweet_banner_300x250.png', 'sweet_banner_728x90.png', 'sweet_square_1080.png'],
                        [['Midjourney', '55.00'], ['Adobe Firefly', '15.00']],
                        'All three sizes, source layers in the archive on request.',
                    ),
                    PlannedStep::approve(self::ago(10, 5), 'elena', 'Looks great, thanks!'),
                ],
            ),
            new PlannedTask(
                'Lucky Spin',
                ['IT'],
                'video',
                2,
                'normal',
                'Two 15-second vertical videos for the Italian launch: a hook in the first 3 seconds, the wheel spin, the bonus, an end card with the logo. Музыка — энергичная, без вокала.',
                'dmitry',
                self::ago(13, 6),
                -self::ago(8),
                ['https://example.com/briefs/lucky-spin-moodboard'],
                ['lucky_spin_brief.png'],
                [
                    PlannedStep::pickUp(self::ago(13, 5), 'anna'),
                    PlannedStep::submit(
                        self::ago(12, 2),
                        'anna',
                        ['v1_hook_a.png', 'v1_hook_b.png', 'v1_end_card.png'],
                        [['Runway', '120.00'], ['Midjourney', '40.00']],
                        'Первая версия: два варианта хука и эндкард.',
                    ),
                    PlannedStep::requestRevisions(self::ago(11, 4), 'dmitry', 'Сделать хук короче, логотип крупнее'),
                    PlannedStep::submit(
                        self::ago(9, 20),
                        'anna',
                        ['v2_hook_a.png', 'v2_end_card.png'],
                        [['Runway', '20.00']],
                        'Hook cut to 3 seconds, the logo is 30% larger.',
                    ),
                    PlannedStep::approve(self::ago(9, 3), 'dmitry', 'Принято, спасибо!'),
                ],
            ),
            new PlannedTask(
                'Кубок Удачи',
                ['RU'],
                'carousel',
                4,
                'normal',
                'Карусель из четырёх слайдов для ВК и Telegram Ads. Last slide: promo code and the button.',
                'dmitry',
                self::ago(10, 8),
                -self::ago(3),
                [],
                ['kubok_brandbook_page.png'],
                [
                    PlannedStep::pickUp(self::ago(10, 4), 'maria'),
                    PlannedStep::submit(
                        self::ago(8, 6),
                        'maria',
                        ['v1_slide_01.png', 'v1_slide_02.png', 'v1_slide_03.png', 'v1_slide_04.png'],
                        [['OpenAI', '45.00']],
                    ),
                    PlannedStep::requestRevisions(self::ago(7, 2), 'dmitry', 'Слайды 3 и 4: цвет кнопки не совпадает с брендбуком'),
                    PlannedStep::submit(
                        self::ago(6, 1),
                        'maria',
                        ['v2_slide_03.png', 'v2_slide_04.png'],
                        [['Adobe Firefly', '20.00']],
                        'Button colour fixed on slides 3 and 4.',
                    ),
                    PlannedStep::approve(self::ago(5, 4), 'dmitry'),
                ],
            ),
            new PlannedTask(
                'Neon Slots',
                ['NL'],
                'video',
                3,
                'normal',
                'Three cuts (6, 15 and 30 seconds) of the neon-city trailer. Озвучка на английском, субтитры на голландском.',
                'elena',
                self::ago(8, 3),
                -self::ago(2),
                [],
                [],
                [
                    PlannedStep::pickUp(self::ago(8, 1), 'anna'),
                    PlannedStep::submit(
                        self::ago(5, 22),
                        'anna',
                        ['neon_cut_06s.png', 'neon_cut_15s.png', 'neon_cut_30s.png'],
                        [['Kling', '90.00'], ['ElevenLabs', '35.00']],
                    ),
                    PlannedStep::approve(self::ago(4, 9), 'elena', 'Одобрено, берём все три.'),
                ],
            ),

            // Under review ------------------------------------------------------------------------------

            new PlannedTask(
                'Casino Royal',
                ['DE'],
                'video',
                3,
                'high',
                'Three videos for the German market, 9:16, with subtitles and a closing CTA. Бонус и условия — в референсах.',
                'dmitry',
                self::ago(6, 2),
                self::ago(1, 4),
                ['https://example.com/briefs/casino-royal'],
                ['casino_royal_terms.png'],
                [
                    PlannedStep::edit(self::ago(5), 'dmitry', ['description' => "Three videos for the German market, 9:16, with subtitles and a closing CTA. Бонус и условия — в референсах.\nUpdate: the bonus is now 150 %."]),
                    PlannedStep::pickUp(self::ago(4, 6), 'anna'),
                    PlannedStep::submit(
                        self::ago(3, 4),
                        'anna',
                        ['casino_royal_v1_a.png', 'casino_royal_v1_b.png', 'casino_royal_v1_c.png'],
                        [['Runway', '100.00'], ['Kling', '60.00']],
                    ),
                    PlannedStep::requestRevisions(self::ago(2, 1), 'dmitry', 'Субтитры слишком мелкие, добавьте второй CTA в конце'),
                    PlannedStep::submit(
                        self::ago(0, 5),
                        'anna',
                        ['casino_royal_v2_a.png', 'casino_royal_v2_b.png', 'casino_royal_v2_c.png'],
                        [['Runway', '40.00'], ['ElevenLabs', '20.00']],
                        'Subtitles are 40% larger, a second CTA at the end.',
                    ),
                ],
            ),
            new PlannedTask(
                'Bonus Hunter',
                ['CZ'],
                'static_image',
                8,
                'normal',
                'Osm statických bannerů pro český trh; eight static banners, two sizes, with the weekly bonus. Без мелкого текста.',
                'elena',
                self::ago(3),
                self::ago(4),
                [],
                [],
                [
                    PlannedStep::pickUp(self::ago(2, 21), 'igor'),
                    PlannedStep::submit(
                        self::ago(1, 2),
                        'igor',
                        ['bonus_hunter_01.png', 'bonus_hunter_02.png', 'bonus_hunter_03.png', 'bonus_hunter_04.png', 'bonus_hunter_05.png', 'bonus_hunter_06.png', 'bonus_hunter_07.png', 'bonus_hunter_08.png'],
                        [['Midjourney', '35.00']],
                        'Все восемь баннеров в одном архиве-превью.',
                    ),
                ],
            ),

            // Revisions required ------------------------------------------------------------------------

            new PlannedTask(
                'Мега Спин',
                ['KZ'],
                'video',
                2,
                'urgent',
                'Два ролика для Казахстана: цены в тенге, казахские субтитры. Срочно: the offer goes live this week.',
                'elena',
                self::ago(9),
                -self::ago(2),
                ['https://example.com/briefs/mega-spin'],
                [],
                [
                    PlannedStep::pickUp(self::ago(8, 22), 'maria'),
                    PlannedStep::submit(
                        self::ago(5, 1),
                        'maria',
                        ['mega_spin_v1_hook.png', 'mega_spin_v1_main.png'],
                        [['Kling', '80.00']],
                    ),
                    PlannedStep::requestRevisions(self::ago(3, 6), 'elena', 'Хук не цепляет: переделать первые 3 секунды и добавить цены в тенге'),
                ],
            ),
            new PlannedTask(
                'Poker Star',
                ['ES'],
                'carousel',
                3,
                'normal',
                'Carrusel de tres imágenes para España: dark table, the headline over the cards. Заголовок не должен перекрывать руку дилера.',
                'dmitry',
                self::ago(7),
                self::ago(6),
                ['https://example.com/briefs/poker-star'],
                [],
                [
                    PlannedStep::pickUp(self::ago(6, 20), 'anna'),
                    PlannedStep::submit(
                        self::ago(5, 10),
                        'anna',
                        ['poker_star_v1_1.png', 'poker_star_v1_2.png', 'poker_star_v1_3.png'],
                        [['Midjourney', '70.00']],
                    ),
                    PlannedStep::requestRevisions(self::ago(4, 5), 'dmitry', 'Нужен тёмный фон, карты обрезаны по краям'),
                    PlannedStep::submit(
                        self::ago(2, 6),
                        'anna',
                        ['poker_star_v2_1.png', 'poker_star_v2_2.png', 'poker_star_v2_3.png'],
                        [['Midjourney', '30.00'], ['OpenAI', '15.00']],
                        'Dark background, cards are fully visible.',
                    ),
                    PlannedStep::requestRevisions(self::ago(1, 3), 'dmitry', 'Better, but the headline still overlaps the dealer\'s hand: move it up'),
                ],
            ),

            // In progress -------------------------------------------------------------------------------

            new PlannedTask(
                'Лото Клуб',
                ['RU'],
                'video',
                3,
                'high',
                'Три видео для лотерейного оффера, 15 секунд каждое. Hook with the jackpot counter, CTA in the last two seconds.',
                'dmitry',
                self::ago(10),
                -self::ago(1),
                [],
                [],
                [
                    PlannedStep::pickUp(self::ago(9, 20), 'igor'),
                    PlannedStep::submit(
                        self::ago(7),
                        'igor',
                        ['loto_klub_v1_a.png', 'loto_klub_v1_b.png'],
                        [['Runway', '85.00'], ['Kling', '40.00']],
                    ),
                    PlannedStep::requestRevisions(self::ago(6), 'dmitry', 'Заменить музыку и добавить CTA в последние 2 секунды'),
                    // Igor gives it back and Anna takes it over: two assignments, the first released.
                    PlannedStep::release(self::ago(5, 20), 'igor'),
                    PlannedStep::pickUp(self::ago(3, 20), 'anna'),
                ],
            ),
            new PlannedTask(
                'Slot Mania',
                ['DE'],
                'static_image',
                6,
                'normal',
                'Six static banners for Germany, 1:1 and 4:5. Ein Banner pro Slot-Thema.',
                'elena',
                self::ago(2),
                self::ago(3),
                [],
                [],
                [PlannedStep::pickUp(self::ago(1, 20), 'igor')],
            ),
            new PlannedTask(
                'Tea Land',
                ['FR'],
                'html5_banner',
                1,
                'normal',
                'Une bannière HTML5 interactive, 300x250, sous 150 Ko. Анимация — не дольше 15 секунд.',
                'dmitry',
                self::ago(1),
                self::ago(9),
                ['https://example.com/briefs/tea-land'],
                ['tea_land_layout.png'],
                [PlannedStep::pickUp(self::ago(0, 20), 'maria')],
            ),

            // New ---------------------------------------------------------------------------------------

            new PlannedTask(
                'Vulkan Bet',
                ['PL'],
                'static_image',
                10,
                'urgent',
                'Dziesięć statycznych kreacji na dziś. Ten banners, all sizes from the table; нужно сегодня.',
                'dmitry',
                self::ago(0, 1, 30),
                self::ago(0, 3),
                [],
                ['vulkan_bet_sizes.png'],
                [],
            ),
            new PlannedTask(
                'Crypto Wave',
                ['BR'],
                'video',
                2,
                'normal',
                'Dois vídeos para o Brasil. Two videos, 20 seconds, crypto-themed visuals без стоковых монет.',
                'elena',
                self::ago(8),
                self::ago(7),
                [],
                [],
                [
                    PlannedStep::pickUp(self::ago(7, 20), 'maria'),
                    PlannedStep::submit(
                        self::ago(5),
                        'maria',
                        ['crypto_wave_v1_a.png', 'crypto_wave_v1_b.png'],
                        [['ElevenLabs', '30.00']],
                    ),
                    PlannedStep::requestRevisions(self::ago(4), 'elena', 'Логотип перекрывает субтитры, переделать'),
                    // Released with the revision still pending: New again, marked as returning.
                    PlannedStep::release(self::ago(3), 'maria'),
                ],
            ),
            new PlannedTask(
                'Fit Pro',
                ['UA', 'KZ'],
                'carousel',
                4,
                'low',
                'Карусель из 4 слайдов для фитнес-приложения, UA and KZ versions. Ничего срочного.',
                'elena',
                self::ago(0, 12),
                self::ago(14),
                ['https://example.com/briefs/fit-pro'],
                [],
                [],
            ),

            // Cancelled ---------------------------------------------------------------------------------

            new PlannedTask(
                'Dragon Gold',
                ['JP'],
                'static_image',
                4,
                'normal',
                'Four static banners for Japan, red and gold. Кампания может не стартовать.',
                'dmitry',
                self::ago(7, 12),
                self::ago(5),
                [],
                [],
                [PlannedStep::cancel(self::ago(6), 'dmitry', 'Campaign cancelled by the advertiser')],
            ),
            new PlannedTask(
                'Fruit Party',
                ['MX'],
                'video',
                1,
                'normal',
                'Один ролик для Мексики, 30 секунд. A fruit-themed spot with a bright CTA.',
                'elena',
                self::ago(5, 12),
                self::ago(2),
                [],
                [],
                [
                    PlannedStep::pickUp(self::ago(5, 6), 'igor'),
                    PlannedStep::cancel(self::ago(3, 3), 'elena', 'Оффер поставлен на паузу рекламодателем'),
                ],
            ),
        ];
    }

    /**
     * A span in seconds.
     */
    private static function ago(int $days, int $hours = 0, int $minutes = 0): int
    {
        return (($days * 24 + $hours) * 60 + $minutes) * 60;
    }
}
