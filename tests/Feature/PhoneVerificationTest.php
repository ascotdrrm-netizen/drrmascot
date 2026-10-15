<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Personnel;
use App\Services\Sms\SmsGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Personnel $person;

    protected function setUp(): void
    {
        parent::setUp();
        $campus = Campus::create(['campus_name' => 'Zabali']);
        $this->person = Personnel::create(['campus_id' => $campus->getKey(), 'full_name' => 'Verified member', 'role' => 'Reporter',
            'email' => 'phone@example.test', 'contact_number' => '09171234567', 'password_hash' => Hash::make('password')]);
        $this->person->forceFill(['is_approved' => true])->save();
        config(['services.sms.driver' => 'semaphore', 'services.semaphore.api_key' => 'fake-test-key']);
    }

    public function test_code_is_hashed_expires_and_is_consumed_once_and_contact_changes_reset_verification(): void
    {
        $code = null;
        $this->mock(SmsGatewayInterface::class)->shouldReceive('send')->once()->withArgs(function ($phone, $message) use (&$code) {
            preg_match('/code: (\d{6})/', $message, $match);
            $code = $match[1] ?? null;

            return $phone === '639171234567' && $code !== null;
        })->andReturn(true);
        $this->actingAs($this->person)->from('/app/me')->post('/phone/verification-code')->assertRedirect('/app/me');
        $challenge = DB::table('phone_verifications')->first();
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->post('/phone/verification-code')->assertSessionHasErrors('phone');
        $this->post('/phone/verify', ['phone_code' => $code])->assertSessionHasNoErrors();
        $this->assertNotNull($this->person->fresh()->phone_verified_at);
        $this->assertDatabaseCount('phone_verifications', 0);
        $this->post('/phone/verify', ['phone_code' => $code])->assertSessionHasErrors('phone_code');
        $this->person->refresh()->update(['contact_number' => '09179876543']);
        $this->assertNull($this->person->fresh()->phone_verified_at);
    }

    public function test_code_is_bound_to_number_with_expiry_and_five_failed_attempts(): void
    {
        DB::table('phone_verifications')->insert(['personnel_id' => $this->person->getKey(), 'phone_number' => '639171234567',
            'code_hash' => Hash::make('123456'), 'attempts' => 0, 'last_sent_at' => now(), 'expires_at' => now()->addMinutes(5)]);
        $this->actingAs($this->person);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/phone/verify', ['phone_code' => '000000'])->assertSessionHasErrors('phone_code');
        }
        $this->post('/phone/verify', ['phone_code' => '123456'])->assertSessionHasErrors('phone_code');
        $this->assertNull($this->person->fresh()->phone_verified_at);
        DB::table('phone_verifications')->update(['attempts' => 0, 'expires_at' => now()->subSecond()]);
        $this->post('/phone/verify', ['phone_code' => '123456'])->assertSessionHasErrors('phone_code');
        DB::table('phone_verifications')->update(['expires_at' => now()->addMinutes(5)]);
        $this->person->update(['contact_number' => '09179876543']);
        $this->post('/phone/verify', ['phone_code' => '123456'])->assertSessionHasErrors('phone_code');
    }

    public function test_log_driver_and_failed_gateway_do_not_issue_a_usable_challenge(): void
    {
        $sms = $this->mock(SmsGatewayInterface::class);
        $sms->shouldReceive('send')->once()->andReturn(false);
        config(['services.sms.driver' => 'log']);
        $this->actingAs($this->person)->post('/phone/verification-code')->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('phone_verifications', 0);
        config(['services.sms.driver' => 'semaphore']);
        $this->post('/phone/verification-code')->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('phone_verifications', 0);
    }
}
