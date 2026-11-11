<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\Action;
use App\Tasks\BoardFilter;
use App\Tasks\Task;
use App\Tasks\TaskCard;
use App\Tasks\TaskPolicy;
use App\Tasks\TaskQuery;
use App\Tasks\TaskRepository;
use App\Tasks\TaskStatus;
use App\Tests\Support\ApiTestCase;

/**
 * The board: which tasks a role sees, the filters, the order and the overdue flag.
 */
final class BoardQueryTest extends ApiTestCase
{
    private User $anna;

    private User $otto;

    private User $dave;

    private User $dina;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', Role::Buyer);
        $this->otto = $this->createUser('otto', Role::Buyer);
        $this->dave = $this->createUser('dave', Role::Designer);
        $this->dina = $this->createUser('dina', Role::Designer);
        $this->mia = $this->createUser('mia', Role::Manager);
    }

    public function test_buyer_sees_only_own(): void
    {
        $mine = $this->insertTask($this->anna, 'Mine');
        $mineInProgress = $this->insertTask($this->anna, 'Mine too', TaskStatus::InProgress, $this->dave);
        $this->insertTask($this->otto, 'Theirs');

        self::assertEqualsCanonicalizing([$mine, $mineInProgress], $this->ids($this->anna));
        self::assertSame([], $this->ids($this->createUser('bea', Role::Buyer)));
    }

    public function test_designer_sees_new_plus_assigned(): void
    {
        $pool = $this->insertTask($this->anna, 'Pool');
        $poolOfOtto = $this->insertTask($this->otto, 'Pool of Otto');
        $mine = $this->insertTask($this->anna, 'Mine', TaskStatus::InProgress, $this->dave);
        $review = $this->insertTask($this->anna, 'Review', TaskStatus::UnderReview, $this->dave);
        $this->insertTask($this->anna, 'Theirs', TaskStatus::InProgress, $this->dina);
        $this->insertTask($this->anna, 'Done by dina', TaskStatus::Completed, $this->dina);

        self::assertEqualsCanonicalizing([$pool, $poolOfOtto, $mine, $review], $this->ids($this->dave));
        self::assertEqualsCanonicalizing([$pool, $poolOfOtto, $this->idOf('Theirs'), $this->idOf('Done by dina')], $this->ids($this->dina, new BoardFilter(includeCancelled: true)));
    }

    public function test_manager_sees_all(): void
    {
        $all = [
            $this->insertTask($this->anna, 'A'),
            $this->insertTask($this->otto, 'B', TaskStatus::InProgress, $this->dave),
            $this->insertTask($this->anna, 'C', TaskStatus::Completed, $this->dina),
        ];

        self::assertEqualsCanonicalizing($all, $this->ids($this->mia));
    }

    public function test_a_designer_asking_beyond_their_scope_gets_their_scoped_result(): void
    {
        $pool = $this->insertTask($this->anna, 'Pool');
        $this->insertTask($this->anna, 'Dina works', TaskStatus::InProgress, $this->dina);

        // designerId names somebody else: nothing of theirs is visible to dave, and the pool is not theirs.
        self::assertSame([], $this->ids($this->dave, new BoardFilter(designerId: $this->dina->id)));
        self::assertSame([$pool], $this->ids($this->dave, new BoardFilter(priority: 'normal')));
    }

    public function test_the_scope_is_the_policys_view_rule(): void
    {
        $tasks = [
            $this->insertTask($this->anna, 'A', TaskStatus::New),
            $this->insertTask($this->anna, 'B', TaskStatus::InProgress, $this->dave),
            $this->insertTask($this->otto, 'C', TaskStatus::UnderReview, $this->dina),
            $this->insertTask($this->otto, 'D', TaskStatus::RevisionsRequired, $this->dave),
            $this->insertTask($this->otto, 'E', TaskStatus::Completed, $this->dave),
            $this->insertTask($this->anna, 'F', TaskStatus::Cancelled),
            $this->insertTask($this->anna, 'G', TaskStatus::Cancelled, $this->dina),
        ];
        $policy = $this->service(TaskPolicy::class);
        $repository = $this->service(TaskRepository::class);

        foreach ([$this->anna, $this->otto, $this->dave, $this->dina, $this->mia] as $user) {
            $expected = [];
            foreach ($tasks as $id) {
                $task = $repository->find($id);
                self::assertInstanceOf(Task::class, $task);
                if ($policy->can($user, Action::ViewTask, $task)) {
                    $expected[] = $id;
                }
            }

            self::assertEqualsCanonicalizing($expected, $this->ids($user, new BoardFilter(includeCancelled: true)), $user->username);
        }
    }

    public function test_cancelled_hidden_unless_include_cancelled(): void
    {
        $open = $this->insertTask($this->anna, 'Open');
        $cancelled = $this->insertTask($this->anna, 'Cancelled', TaskStatus::Cancelled);

        self::assertSame([$open], $this->ids($this->anna));
        self::assertSame([$open], $this->ids($this->mia, new BoardFilter(includeCancelled: false)));
        self::assertEqualsCanonicalizing([$open, $cancelled], $this->ids($this->anna, new BoardFilter(includeCancelled: true)));

        $http = $this->request('GET', '/api/tasks?includeCancelled=1', as: $this->anna);
        self::assertSame(200, $http->getStatusCode());
        self::assertCount(2, self::jsonArray($http, 'tasks'));
        $http = $this->request('GET', '/api/tasks', as: $this->anna);
        self::assertCount(1, self::jsonArray($http, 'tasks'));
    }

    public function test_filters_and_cyrillic_case_insensitive_search(): void
    {
        $loto = $this->insertTask($this->anna, 'Лото Клуб', priority: 'high', creativeType: 'video');
        $bingo = $this->insertTask($this->anna, 'Bingo Blitz', priority: 'low', creativeType: 'carousel');
        $assigned = $this->insertTask($this->anna, 'Casino Royal', TaskStatus::InProgress, $this->dave, priority: 'high', creativeType: 'carousel');

        // q: substring, case-insensitive, Cyrillic included.
        self::assertSame([$loto], $this->ids($this->mia, new BoardFilter(q: 'лото')));
        self::assertSame([$loto], $this->ids($this->mia, new BoardFilter(q: 'ЛОТО КЛ')));
        self::assertSame([$bingo], $this->ids($this->mia, new BoardFilter(q: 'bLiTz')));
        self::assertSame([], $this->ids($this->mia, new BoardFilter(q: 'nothing like this')));

        self::assertEqualsCanonicalizing([$loto, $assigned], $this->ids($this->mia, new BoardFilter(priority: 'high')));
        self::assertEqualsCanonicalizing([$bingo, $assigned], $this->ids($this->mia, new BoardFilter(creativeType: 'carousel')));
        self::assertSame([$assigned], $this->ids($this->mia, new BoardFilter(designerId: $this->dave->id)));

        // Combinable.
        self::assertSame([$assigned], $this->ids($this->mia, new BoardFilter(priority: 'high', creativeType: 'carousel')));
        self::assertSame([$assigned], $this->ids($this->mia, new BoardFilter(priority: 'high', creativeType: 'carousel', designerId: $this->dave->id, q: 'ROYAL')));
        self::assertSame([], $this->ids($this->mia, new BoardFilter(priority: 'low', designerId: $this->dave->id)));
    }

    public function test_like_wildcards_in_the_search_are_literal(): void
    {
        $percent = $this->insertTask($this->anna, '100% bonus');
        $underscore = $this->insertTask($this->anna, 'a_b offer');
        $this->insertTask($this->anna, 'plain offer');
        $backslash = $this->insertTask($this->anna, 'path\\to');

        self::assertSame([$percent], $this->ids($this->mia, new BoardFilter(q: '%')));
        self::assertSame([$underscore], $this->ids($this->mia, new BoardFilter(q: '_')));
        self::assertSame([$backslash], $this->ids($this->mia, new BoardFilter(q: '\\')));
        self::assertSame([], $this->ids($this->mia, new BoardFilter(q: '%%')));
    }

    public function test_the_order_is_deadline_ascending_then_id(): void
    {
        $late = $this->insertTask($this->anna, 'Late', deadline: '2026-03-01 10:00:00');
        $firstOfTwo = $this->insertTask($this->anna, 'Tie 1', deadline: '2026-02-01 10:00:00');
        $early = $this->insertTask($this->anna, 'Early', deadline: '2026-01-20 10:00:00');
        $secondOfTwo = $this->insertTask($this->anna, 'Tie 2', deadline: '2026-02-01 10:00:00');

        self::assertSame([$early, $firstOfTwo, $secondOfTwo, $late], $this->ids($this->mia));
    }

    public function test_deadline_exactly_now_is_not_overdue(): void
    {
        $this->insertTask($this->anna, 'On the dot', deadline: '2026-01-15 09:00:00');

        $card = $this->only($this->anna);

        self::assertFalse($card->isOverdue);
    }

    public function test_one_second_past_is_overdue(): void
    {
        $this->insertTask($this->anna, 'Just missed', deadline: '2026-01-15 09:00:00');
        $this->clock->advance('PT1S');

        self::assertTrue($this->only($this->anna)->isOverdue);
    }

    public function test_finished_tasks_are_never_overdue(): void
    {
        $this->insertTask($this->anna, 'Done', TaskStatus::Completed, $this->dave, deadline: '2026-01-01 00:00:00');
        $this->insertTask($this->anna, 'Gone', TaskStatus::Cancelled, deadline: '2026-01-01 00:00:00');
        $this->insertTask($this->anna, 'Late', TaskStatus::UnderReview, $this->dave, deadline: '2026-01-01 00:00:00');

        $cards = $this->service(TaskQuery::class)->board($this->mia, new BoardFilter(includeCancelled: true));

        self::assertSame(
            ['Done' => false, 'Gone' => false, 'Late' => true],
            array_column(array_map(static fn(TaskCard $c): array => ['o' => $c->offer, 'v' => $c->isOverdue], $cards), 'v', 'o'),
        );
    }

    public function test_cards_carry_geo_people_and_actions(): void
    {
        $id = $this->insertTask($this->anna, 'Lotto', TaskStatus::InProgress, $this->dave, geo: ['WW', 'US', 'DE']);

        $buyerCard = $this->only($this->anna);
        $designerCard = $this->only($this->dave);
        $managerCard = $this->only($this->mia);

        self::assertSame($id, $buyerCard->id);
        self::assertSame(['DE', 'US', 'WW'], $buyerCard->geo);
        self::assertSame(['id' => $this->dave->id, 'displayName' => 'Dave Test'], $buyerCard->assignee?->jsonSerialize());
        self::assertSame(['id' => $this->anna->id, 'displayName' => 'Anna Test'], $buyerCard->createdBy->jsonSerialize());
        self::assertSame(['edit_brief', 'cancel'], $buyerCard->allowedActions);
        self::assertSame(['release', 'submit', 'report_ai_usage'], $designerCard->allowedActions);
        self::assertSame([], $managerCard->allowedActions);
        self::assertSame(0, $managerCard->roundCount);
        self::assertSame('0.00', $managerCard->creditsTotal);
        self::assertFalse($managerCard->isReturning);
    }

    public function test_the_http_list_matches_the_spec_and_validates_filters(): void
    {
        $this->insertTask($this->anna, 'Лото Клуб', priority: 'high');

        $ok = $this->request('GET', '/api/tasks?priority=high&q=' . rawurlencode('лото'), as: $this->mia);
        self::assertSame(200, $ok->getStatusCode());
        $this->assertMatchesSpec($ok);
        self::assertCount(1, self::jsonArray($ok, 'tasks'));

        foreach (['priority=asap', 'creativeType=hologram', 'designerId=abc', 'includeCancelled=maybe', 'q[]=x', 'q=' . str_repeat('x', 101)] as $query) {
            $bad = $this->request('GET', '/api/tasks?' . $query, as: $this->mia);
            self::assertSame(422, $bad->getStatusCode(), $query);
            $this->assertMatchesSpec($bad);
        }

        // Parameters the API does not know (cache busters, say) are ignored.
        self::assertSame(200, $this->request('GET', '/api/tasks?_=123', as: $this->mia)->getStatusCode());
    }

    /**
     * @return list<int>
     */
    private function ids(User $actor, ?BoardFilter $filter = null): array
    {
        return array_map(
            static fn(TaskCard $card): int => $card->id,
            $this->service(TaskQuery::class)->board($actor, $filter ?? new BoardFilter()),
        );
    }

    private function idOf(string $offer): int
    {
        $id = $this->fetchValue('SELECT id FROM tasks WHERE offer = ?', [$offer]);

        return is_numeric($id) ? (int) $id : self::fail('No such task.');
    }

    private function only(User $actor): TaskCard
    {
        $cards = $this->service(TaskQuery::class)->board($actor, new BoardFilter());
        self::assertCount(1, $cards);

        return $cards[0];
    }
}
