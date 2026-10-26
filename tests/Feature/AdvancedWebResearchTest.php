<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\WebEvidence;
use App\Services\WebReader;
use App\Services\WebSearch;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdvancedWebResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_limited_private_provider_falls_back_without_exposing_secrets_and_cools_down(): void
    {
        config(['models.search_url' => null, 'models.public_search_fallback' => false, 'web_research.brave_key' => 'PRIVATE_BRAVE', 'web_research.google_key' => 'PRIVATE_GOOGLE', 'web_research.google_cx' => 'engine']);
        Http::fake([
            'api.search.brave.com/*' => Http::response('PRIVATE_BRAVE internal error', 429, ['Retry-After' => '30']),
            'www.googleapis.com/*' => fn () => Http::response(['items' => [['link' => 'https://primary.example/paper', 'title' => 'GPS accuracy', 'snippet' => 'Measurements']]]),
        ]);
        $one = app(WebSearch::class)->search('GPS accuracy');
        $two = app(WebSearch::class)->search('GPS measurements');
        $this->assertSame('google', $one[0]['provider']);
        $this->assertSame('cooldown', $two[0]['provider_events'][0]['status']);
        $this->assertStringNotContainsString('PRIVATE_', json_encode($one));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Subscription-Token', 'PRIVATE_BRAVE'));
    }

    public function test_late_passages_and_pdf_provenance_are_preserved_but_html_cannot_claim_pages(): void
    {
        $evidence = app(WebEvidence::class);
        $text = str_repeat('Unrelated background introduction. ', 1000).'GPS navigation reached 97.5 percent accuracy in baseline measurements.';
        $passages = $evidence->passages($text, 'GPS navigation accuracy');
        $this->assertStringContainsString('97.5', json_encode($passages));
        $this->assertNull($passages[0]['page']);
        $pdf = $evidence->passages('', 'GPS navigation accuracy', ['mime' => 'application/pdf', 'pages' => [['number' => 24, 'text' => 'GPS navigation reached 97.5 percent accuracy in baseline measurements.']]]);
        $this->assertSame(24, $pdf[0]['page']);
        $summary = $evidence->summary(['url' => 'https://example.org/paper', 'mime' => 'text/html', 'passages' => $pdf]);
        $this->assertNull($summary['passages'][0]['page']);
    }

    public function test_local_citation_guard_preserves_code_and_omits_unretrieved_named_links(): void
    {
        $result = app(WebEvidence::class)->ground("Report [paper](https://primary.example/paper). Fake [study](https://invented.example/fake).\n```md\n[example](https://example.com)\n```", [['url' => 'https://primary.example/paper', 'title' => 'Paper', 'citation' => 1]]);
        $this->assertSame(1, $result['removed']);
        $this->assertStringNotContainsString('invented.example', $result['text']);
        $this->assertStringContainsString('[1](https://primary.example/paper)', $result['text']);
        $this->assertStringContainsString('[example](https://example.com)', $result['text']);
    }

    public function test_private_redirect_is_rejected_before_the_destination_is_requested(): void
    {
        $reader = new class extends WebReader
        {
            public int $requests = 0;

            protected function fetchPinned(string $url, string $resolve): \Illuminate\Http\Client\Response
            {
                $this->requests++;

                return new \Illuminate\Http\Client\Response(new Response(302, ['Location' => 'http://127.0.0.1/secrets']));
            }
        };
        try {
            $reader->read('https://1.1.1.1/page');
            $this->fail('Private redirect followed');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forbidden', $exception->getMessage());
        }
        $this->assertSame(1, $reader->requests);
    }

    public function test_reader_parses_actual_pdf_bytes_and_retains_authentic_late_page_numbers(): void
    {
        $fixture = new Process(['python3', '-c', "import sys;sys.path.insert(0,'runtime/web-reader');from test_pdf import fixture;sys.stdout.buffer.write(fixture())"], base_path());
        $fixture->mustRun();
        $reader = new class($fixture->getOutput()) extends WebReader
        {
            public function __construct(private string $pdf) {}

            protected function fetchPinned(string $url, string $resolve): \Illuminate\Http\Client\Response
            {
                return new \Illuminate\Http\Client\Response(new Response(200, ['Content-Type' => 'application/pdf'], $this->pdf));
            }
        };
        $reader->query = 'GPS navigation accuracy';
        $text = $reader->read('https://1.1.1.1/paper.pdf');
        $this->assertStringContainsString('97.5 percent', $text);
        $this->assertSame(24, $reader->lastRead['pages'][7]['number']);
        $this->assertTrue($reader->lastRead['pdf']['partial']);
        $this->assertFalse($reader->lastRead['pdf']['ocr']);
    }

    public function test_reader_rejects_oversized_bodies_before_extracting_evidence(): void
    {
        $reader = new class extends WebReader
        {
            protected function fetchPinned(string $url, string $resolve): \Illuminate\Http\Client\Response
            {
                return new \Illuminate\Http\Client\Response(new Response(200, ['Content-Type' => 'text/html'], str_repeat('x', 2000001)));
            }
        };
        $this->expectExceptionMessage('Page too large.');
        $reader->read('https://1.1.1.1/large');
    }

    public function test_deep_research_public_activity_survives_refresh_and_paused_run_resumes_same_message(): void
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        Sanctum::actingAs(User::create(['name' => 'Research', 'email' => 'advanced@example.org', 'password' => 'Password123!']));
        $headers = ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
        $begin = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'বাংলায় GPS গবেষণা', 'deep_research' => true, 'conversation_tools' => true], $headers)->assertOk();
        $metadata = ['version' => 1, 'status' => 'paused', 'plan' => [], 'activity' => [['id' => 1, 'phase' => 'searching', 'status' => 'completed', 'label' => 'GPS query', 'at' => now()->toIso8601String()]]];
        $state = ['stage' => 'research', 'content' => '', 'messages' => [['role' => 'system', 'content' => 'SECRET PRIVATE RESEARCH']], 'metadata' => $metadata, 'sources' => [['url' => 'https://example.org/paper', 'title' => 'Paper', 'page_text' => 'RAW PRIVATE PAGE', 'passages' => [['text' => 'Selected GPS evidence', 'page' => null]]]], 'research' => ['actions' => ['search:first'], 'sources' => []]];
        $this->postJson('/api/v1/chat/frontend/checkpoint', ['ticket' => $begin->json('ticket'), 'state' => $state], $headers)->assertOk();
        $message = Message::findOrFail($begin->json('message_id'));
        $this->assertSame('paused', $message->research_metadata['status']);
        $this->assertArrayNotHasKey('page_text', $message->sources[0]);
        $this->assertStringNotContainsString('SECRET PRIVATE RESEARCH', json_encode($message));
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $begin->json('ticket'), 'content' => '', 'status' => 'failed', 'research_metadata' => $metadata], $headers)->assertOk();
        $this->assertTrue($message->fresh()->resumable);
        $this->postJson('/api/v1/chat/frontend/resume', ['message_id' => $message->id], $headers)->assertOk()->assertJsonPath('checkpoint.metadata.status', 'paused')->assertJsonPath('message_id', $message->id);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_multilingual_research_metadata_and_bounded_long_checkpoints_survive_without_ascii_escape_false_limits(): void
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        Sanctum::actingAs(User::create(['name' => 'Unicode research', 'email' => 'unicode-research@example.org', 'password' => 'Password123!']));
        $headers = ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
        $begin = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'বাংলা গবেষণা', 'deep_research' => true, 'conversation_tools' => true], $headers)->assertOk();
        $metadata = ['version' => 1, 'mode' => 'deep_research', 'status' => 'running', 'plan' => [], 'activity' => []];
        for ($i = 0; $i < 100; $i++) {
            $metadata['activity'][] = ['id' => $i, 'label' => str_repeat('অ', 320), 'query' => str_repeat('ব', 200), 'phase' => 'searching', 'status' => 'completed', 'at' => now()->toIso8601String()];
        }
        $this->assertLessThan(200000, strlen(json_encode($metadata, JSON_UNESCAPED_UNICODE)));
        $this->assertGreaterThan(200000, strlen(json_encode($metadata)));
        $this->postJson('/api/v1/chat/frontend/checkpoint', ['ticket' => $begin->json('ticket'), 'state' => ['metadata' => $metadata, 'private_context' => str_repeat('x', 1600000)]], $headers)->assertOk();
        $this->assertSame('deep_research', Message::findOrFail($begin->json('message_id'))->research_metadata['mode']);
        $this->postJson('/api/v1/chat/frontend/checkpoint', ['ticket' => $begin->json('ticket'), 'state' => ['private_context' => str_repeat('x', 4000001)]], $headers)->assertStatus(413);
        $this->assertSame(1600000, strlen(Message::findOrFail($begin->json('message_id'))->inference_checkpoint['progress']['private_context']));
    }
}
