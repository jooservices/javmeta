<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\QBittorrentProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class QBittorrentProbeTest extends TestCase
{
    public function test_it_checks_the_authenticated_api_with_one_bounded_request(): void
    {
        config([
            'javmeta_downloads.enabled' => true,
            'javmeta_downloads.qbittorrent_url' => 'http://qbittorrent.example.test',
            'javmeta_downloads.qbittorrent_username' => 'test-user',
            'javmeta_downloads.qbittorrent_password' => 'test-password',
        ]);
        Http::fakeSequence()
            ->push('Ok.', 200, ['Set-Cookie' => 'SID=test-session; Path=/']);

        $result = (new QBittorrentProbe())->check();

        self::assertTrue($result->available);
        self::assertStringNotContainsString('test-password', $result->message);
        Http::assertSentCount(1);
    }

    public function test_it_does_not_contact_qbittorrent_when_downloads_are_disabled(): void
    {
        config(['javmeta_downloads.enabled' => false]);

        self::assertNull((new QBittorrentProbe())->check()->available);
        Http::assertNothingSent();
    }
}
