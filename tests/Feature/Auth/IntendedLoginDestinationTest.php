<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AgeEligibility;
use App\Services\Auth\VoraAuthUserPolicy;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

class IntendedLoginDestinationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.sign_in' => 'local', 'instance.modules.events' => true]);
        $this->mock(AgeEligibility::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reason')->andReturn(null);
        });
    }

    public function test_valid_destinations_keep_their_local_shape_and_are_consumed_once(): void
    {
        $user = User::factory()->approved()->make();
        $request = $this->request();
        foreach ([
            '/events?tab=people#joining' => '/events?tab=people#joining',
            '/media/a%2Fb?return=%2Fevents' => '/media/a%2Fb?return=%2Fevents',
            'https://APP.EXAMPLE.TEST:443/people?view=grid#first' => '/people?view=grid#first',
            'https://app.example.test' => '/',
        ] as $stored => $destination) {
            $request->session()->put('url.intended', $stored);
            $this->assertSame($destination, app(VoraAuthUserPolicy::class)->redirectAfterLogin($user, $request));
            $this->assertFalse($request->session()->exists('url.intended'));
            $this->assertSame('/', app(VoraAuthUserPolicy::class)->redirectAfterLogin($user, $request));
        }
    }

    public function test_untrusted_stored_destinations_fall_back_without_becoming_redirects(): void
    {
        $user = User::factory()->approved()->make();
        $request = $this->request();
        foreach ([
            'https://other.example.test/', '//other.example.test/',
            'https://someone@app.example.test/', '/%2fother.example.test/',
            '/safe%5cpath', '/safe%0d%0aLocation%3Aother', '/safe%20path',
            'https://app.example.test:444/events', ['unexpected'],
        ] as $stored) {
            $request->session()->put('url.intended', $stored);
            $this->assertSame('/', app(VoraAuthUserPolicy::class)->redirectAfterLogin($user, $request));
            $this->assertFalse($request->session()->exists('url.intended'));
        }
    }

    public function test_event_joining_has_priority_and_leaves_the_intended_destination_for_its_flow(): void
    {
        $user = User::factory()->approved()->make();
        $request = $this->request();
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $request->session()->put('events.join_ulid', $ulid);
        $request->session()->put('url.intended', '/people');

        $this->assertSame('/events/join/'.$ulid, app(VoraAuthUserPolicy::class)->redirectAfterLogin($user, $request));
        $this->assertSame('/people', $request->session()->get('url.intended'));
    }

    public function test_admission_and_password_gates_preserve_the_intended_destination(): void
    {
        $request = $this->request();
        $request->session()->put('url.intended', '/events');
        foreach ([
            [User::factory()->approved()->unverified()->make(), '/email/verify'],
            [User::factory()->pendingApproval()->make(), '/pending-approval'],
            [User::factory()->approved()->make(['force_change_pw' => true]), '/user/settings'],
        ] as [$user, $destination]) {
            $this->assertSame($destination, app(VoraAuthUserPolicy::class)->redirectAfterLogin($user, $request));
            $this->assertSame('/events', $request->session()->get('url.intended'));
        }
    }

    private function request(): Request
    {
        $request = Request::create('https://app.example.test/login');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }
}
