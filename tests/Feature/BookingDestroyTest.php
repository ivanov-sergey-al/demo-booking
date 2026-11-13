<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_own_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/bookings/{$booking->id}");

        $response->assertNoContent();

        $this->assertModelMissing($booking);
    }

    public function test_returns_403_when_another_user_cancels_booking(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $booking = Booking::factory()->for($owner)->create();
        Sanctum::actingAs($otherUser);

        $response = $this->deleteJson("/api/bookings/{$booking->id}");

        $response->assertForbidden();

        $this->assertModelExists($booking);
    }
}
