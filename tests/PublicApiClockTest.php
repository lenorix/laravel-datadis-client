<?php

use Illuminate\Support\Carbon;
use Lenorix\DatadisClient\PublicApi\Community;
use Lenorix\DatadisClient\PublicApi\PublicSearchQuery;
use Lenorix\LaravelDatadisClient\LaravelDatadisClient as Manager;
use Lenorix\LaravelDatadisClient\Testing\FakesDatadis;

it('lets the public client follow the application time for its token, as the private one does', function () {
    $this->travelTo(Carbon::parse('2026-07-10 04:00', 'Europe/Madrid'));
    FakesDatadis::fake();
    $api = fn () => app(Manager::class)->publicApi();

    $api()->apiSearch(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]));
    $this->travelTo(now()->addHours(12));
    $api()->apiSearch(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]));
    expect(logins())->toHaveCount(1);

    $this->travelTo(now()->addHours(13));
    $api()->apiSearch(new PublicSearchQuery(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), [Community::Madrid]));
    expect(logins())->toHaveCount(2);
});
