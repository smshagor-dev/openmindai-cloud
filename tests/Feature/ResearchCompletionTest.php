<?php

namespace Tests\Feature;

use App\Services\ResearchCompletion;
use App\Services\WebReader;
use App\Services\WebSearch;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchCompletionTest extends TestCase
{
    public function test_local_research_continues_past_the_initial_batch_until_the_model_finishes(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            $decision = $calls <= 12 ? ['action' => 'search', 'query' => 'Evidence '.$calls] : ['action' => 'finish', 'reason' => 'All requested sections supported'];

            return Http::response(['choices' => [['message' => ['content' => json_encode($decision)]]]]);
        });
        $this->mock(WebSearch::class)->shouldReceive('search')->times(12)->andReturnUsing(fn ($query) => [['url' => 'https://example.org/'.urlencode($query), 'title' => $query, 'snippet' => 'Summary']]);
        $this->mock(WebReader::class)->shouldReceive('read')->once()->andReturn(str_repeat('Inspected source evidence. ', 10));
        $result = app(ResearchCompletion::class)->run('Question', [], 'fixture', fn () => null, fn () => false);
        $this->assertSame(14, $calls);
        $this->assertCount(12, $result['sources']);
        $this->assertSame('All requested sections supported', $result['conclusion']);
    }

    public function test_pending_saved_search_is_completed_without_repeating_the_planner_call(): void
    {
        $searched = false;
        $this->mock(WebSearch::class)->shouldReceive('search')->once()->andReturnUsing(function () use (&$searched) {
            $searched = true;

            return [['url' => 'https://example.org/new', 'title' => 'Evidence']];
        });
        Http::fake(function () use (&$searched) {
            $this->assertTrue($searched);

            return Http::response(['choices' => [['message' => ['content' => '{"action":"finish","reason":"Coverage complete"}']]]]);
        });
        $this->mock(WebReader::class)->shouldReceive('read')->once()->andReturn(str_repeat('Pending source inspected evidence. ', 8));
        $saved = [];
        $result = app(ResearchCompletion::class)->run('Question', [], 'fixture', fn () => null, fn () => false, ['actions' => [], 'pending' => ['action' => 'search', 'query' => 'Pending evidence']], function ($state) use (&$saved) {
            $saved[] = $state;
        });
        Http::assertSentCount(2);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('writing', end($saved)['stage']);
    }

    public function test_repeated_action_stops_with_an_explicit_failure(): void
    {
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => '{"action":"search","query":"Repeated"}']]]]));
        $this->mock(WebSearch::class)->shouldReceive('search')->once()->andReturn([]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Research repeated an action');
        app(ResearchCompletion::class)->run('Question', [], 'fixture', fn () => null, fn () => false);
    }

    public function test_cancelled_request_never_calls_the_model(): void
    {
        Http::fake();
        try {
            app(ResearchCompletion::class)->run('Question', [], 'fixture', fn () => null, fn () => true);
            $this->fail('Cancellation ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Research cancelled.', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
