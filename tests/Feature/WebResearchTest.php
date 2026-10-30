<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\WebReader;
use App\Services\WebResearch;
use App\Services\WebSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_deduplicates_tracking_links_and_rejects_unsafe_results(): void
    {
        config(['models.search_url' => 'https://search.example/search', 'models.public_search_fallback' => false]);
        Http::fake(['*' => Http::response(['results' => [
            ['url' => 'https://example.org/article?utm_source=x#one', 'title' => '<b>Evidence</b>', 'content' => 'A &amp; B'],
            ['url' => 'https://example.org/article', 'title' => 'Duplicate'],
            ['url' => 'http://127.0.0.1/private'],
            ['url' => 'https://user:secret@example.com/'],
            ['url' => 'javascript:alert(1)'],
        ]])]);
        $sources = app(WebSearch::class)->search('Question', ['time_range' => 'week']);
        $this->assertCount(1, $sources);
        $this->assertSame('https://example.org/article', $sources[0]['url']);
        $this->assertSame('Evidence', $sources[0]['title']);
        $this->assertSame('A & B', $sources[0]['snippet']);
        Http::assertSent(fn ($r) => $r['time_range'] === 'week' && $r['safesearch'] === 1);
    }

    public function test_search_portals_are_not_evidence_and_trigger_the_next_provider(): void
    {
        config(['models.search_url' => 'https://search.example/search', 'models.public_search_fallback' => true]);
        Http::fake([
            'search.example/*' => Http::response(['results' => [
                ['url' => 'https://www.google.com/xhtml/search', 'title' => 'Google'],
                ['url' => 'https://search.yahoo.com/', 'title' => 'Search'],
                ['url' => 'https://www.bing.com/search?q=php', 'title' => 'Bing'],
            ]]),
            'www.bing.com/*' => Http::response('<rss><channel><item><title>PHP array_map</title><link>https://www.php.net/manual/en/function.array-map.php</link></item></channel></rss>'),
        ]);
        $sources = app(WebSearch::class)->search('PHP array_map');
        $this->assertCount(1, $sources);
        $this->assertSame('https://www.php.net/manual/en/function.array-map.php', $sources[0]['url']);
        $this->assertSame('bing_rss', $sources[0]['provider']);
        $this->assertFalse(WebSearch::isSearchPortal('https://www.google.com/about'));
    }

    public function test_generic_search_results_do_not_satisfy_a_specific_function_query(): void
    {
        config(['models.search_url' => null, 'models.public_search_fallback' => true]);
        Http::fake([
            'www.bing.com/*' => Http::response('<rss><channel><item><title>PHP homepage</title><link>https://www.php.net/</link></item></channel></rss>'),
            'html.duckduckgo.com/*' => Http::response('<a class="result__a" href="https://www.php.net/manual/en/function.array-map.php">PHP array_map</a>'),
        ]);
        $sources = app(WebSearch::class)->search('PHP array_map official documentation');
        $this->assertCount(1, $sources);
        $this->assertSame('duckduckgo', $sources[0]['provider']);
        $this->assertSame('https://www.php.net/manual/en/function.array-map.php', $sources[0]['url']);
    }

    public function test_unrelated_search_noise_falls_through_to_relevant_official_sources(): void
    {
        config(['models.search_url' => null, 'models.public_search_fallback' => true]);
        Http::fake([
            'www.bing.com/*' => Http::response('<rss><channel><item><title>Soup Restaurant</title><link>https://example.org/restaurant</link></item></channel></rss>'),
            'html.duckduckgo.com/*' => Http::response('<a class="result__a" href="https://sqlite.org/wal.html">SQLite WAL concurrency</a>'),
        ]);
        $sources = app(WebSearch::class)->search('SQLite WAL');
        $this->assertCount(1, $sources);
        $this->assertSame('https://sqlite.org/wal.html', $sources[0]['url']);
        $this->assertSame('duckduckgo', $sources[0]['provider']);
    }

    public function test_research_merges_queries_limits_domains_and_keeps_failed_page_labels(): void
    {
        $found = [];
        foreach (['a.example', 'a.example', 'a.example', 'b.example', 'c.example'] as $i => $host) {
            $found[] = ['url' => 'https://'.$host.'/'.$i, 'title' => 'Source '.$i, 'snippet' => 'Snippet'];
        }
        $this->mock(WebSearch::class)->shouldReceive('search')->times(3)->andReturn($found);
        $this->mock(WebReader::class)->shouldReceive('read')->times(4)->andReturn(str_repeat('Evidence. ', 20), '', str_repeat('Independent. ', 20), str_repeat('More evidence. ', 20));
        $events = [];
        $sources = app(WebResearch::class)->collect('Question', [], function ($label) use (&$events) {
            $events[] = $label;
        });
        $this->assertCount(4, $sources);
        $this->assertCount(7, $events);
        $this->assertSame('read', $sources[0]['read_status']);
        $this->assertSame('unavailable', $sources[1]['read_status']);
        $this->assertSame(4, $sources[3]['citation']);
        $this->assertArrayNotHasKey('page_text', app(WebResearch::class)->summaries($sources)[0]);
        $this->assertStringContainsString('UNTRUSTED DATA', app(WebResearch::class)->prompt($sources));
    }

    public function test_partial_search_outage_still_uses_available_evidence(): void
    {
        $search = $this->mock(WebSearch::class);
        $search->shouldReceive('search')->once()->ordered()->andThrow(new \RuntimeException('Unavailable'));
        $search->shouldReceive('search')->twice()->ordered()->andReturn([['url' => 'https://example.org/a', 'title' => 'Evidence', 'snippet' => 'Summary']]);
        $this->mock(WebReader::class)->shouldReceive('read')->once()->andThrow(new \RuntimeException('Not readable'));
        $sources = app(WebResearch::class)->collect('Question');
        $this->assertCount(1, $sources);
        $this->assertSame('unavailable', $sources[0]['read_status']);
        $this->assertSame(2, $sources[0]['search_queries_completed']);
    }

    public function test_empty_research_fails_explicitly_without_creating_chat_records(): void
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        Sanctum::actingAs(User::create(['name' => 'Research', 'email' => 'empty@example.org', 'password' => 'Password123!']));
        $this->mock(WebSearch::class)->shouldReceive('search')->times(3)->andReturn([]);
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Nothing', 'deep_research' => true], ['X-Chat-Bridge-Key' => str_repeat('b', 32)])->assertStatus(503);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_cancelled_research_does_not_fetch_sources(): void
    {
        Http::fake();
        try {
            app(WebResearch::class)->collect('Question', [], null, fn () => true);
            $this->fail('Cancellation ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Research cancelled.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_nvidia_bridge_prepares_deep_evidence_but_stores_only_source_summaries(): void
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        Sanctum::actingAs(User::create(['name' => 'Research', 'email' => 'research@example.org', 'password' => 'Password123!']));
        $this->mock(WebSearch::class)->shouldReceive('search')->times(3)->andReturn([['url' => 'https://example.org/paper', 'title' => 'Paper', 'snippet' => 'Summary']]);
        $this->mock(WebReader::class)->shouldReceive('read')->once()->andReturn(str_repeat('PRIVATE PAGE EVIDENCE ', 10));
        $headers = ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Research this', 'deep_research' => true, 'search_time_range' => 'month'], $headers)->assertOk();
        $this->assertStringContainsString('PRIVATE PAGE EVIDENCE', $r->json('messages.0.content'));
        $this->assertSame('read', $r->json('sources.0.read_status'));
        $this->assertArrayNotHasKey('page_text', $r->json('sources.0'));
        $this->assertArrayNotHasKey('page_text', Message::find($r->json('message_id'))->sources[0]);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Report [1](https://example.org/paper)', 'status' => 'complete'], $headers)->assertOk();
        $this->assertDatabaseHas('messages', ['content' => 'Report [1](https://example.org/paper)', 'status' => 'complete']);
    }

    public function test_native_deep_research_defers_search_until_a_focused_query_is_planned(): void
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        Sanctum::actingAs(User::create(['name' => 'Research', 'email' => 'native-research@example.org', 'password' => 'Password123!']));
        Http::fake();
        $this->mock(WebSearch::class)->shouldNotReceive('search');
        $response = $this->postJson('/api/v1/chat/frontend/begin', [
            'message' => 'Compare SQLite WAL with rollback journal. Write in Bangla.',
            'deep_research' => true, 'conversation_tools' => true,
        ], ['X-Chat-Bridge-Key' => str_repeat('b', 32)])->assertOk();
        $this->assertSame([], $response->json('sources'));
        Http::assertNothingSent();
    }

    public function test_search_falls_back_to_public_rss_without_exposing_primary_errors(): void
    {
        config(['models.search_url' => 'https://search.example/search', 'models.public_search_fallback' => true]);
        Http::fake([
            'https://search.example/*' => Http::response('private error', 503),
            'https://www.bing.com/search*' => Http::response('<rss><channel><item><title>Primary evidence</title><link>https://example.org/paper?utm_source=bing</link><description>Useful summary</description></item></channel></rss>', 200, ['Content-Type' => 'application/rss+xml']),
        ]);
        $sources = app(WebSearch::class)->search('Evidence', ['time_range' => 'week']);
        $this->assertSame('https://example.org/paper', $sources[0]['url']);
        $this->assertSame('bing_rss', $sources[0]['provider']);
        $this->assertFalse($sources[0]['time_filter_applied']);
        Http::assertSentCount(3);
    }

    public function test_quick_search_reads_pages_and_preserves_unavailable_source_status(): void
    {
        $this->mock(WebSearch::class)->shouldReceive('search')->once()->andReturn([
            ['url' => 'https://a.example/paper', 'title' => 'Read', 'snippet' => 'Snippet'],
            ['url' => 'https://b.example/paper', 'title' => 'Unavailable', 'snippet' => 'Snippet'],
        ]);
        $reader = $this->mock(WebReader::class);
        $reader->shouldReceive('read')->with('https://a.example/paper')->once()->andReturn(str_repeat('Evidence ', 30));
        $reader->shouldReceive('read')->with('https://b.example/paper')->once()->andThrow(new \RuntimeException('Unavailable'));
        $sources = app(WebResearch::class)->quick('Evidence');
        $this->assertSame('read', $sources[0]['read_status']);
        $this->assertSame('unavailable', $sources[1]['read_status']);
        $this->assertArrayNotHasKey('page_text', app(WebResearch::class)->summaries($sources)[0]);
    }
}
