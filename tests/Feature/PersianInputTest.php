<?php

namespace Tests\Feature;

use App\Helpers\PersianText;
use App\Models\Game;
use App\Models\GameRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersianInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_searching_with_arabic_letters_finds_persian_titles(): void
    {
        $game = Game::factory()->create(['title' => 'کال آف دیوتی']);
        Game::factory()->create(['title' => 'Elden Ring']);

        $this->getJson(route('api.games.index', ['search' => 'كال آف ديوتي']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $game->id);
    }

    public function test_text_is_stored_with_persian_letters(): void
    {
        $game = Game::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.games.rate', $game->id), ['rating' => 5, 'comment' => 'بازي خيلي خوبي بود كه'])
            ->assertOk();

        $this->assertSame('بازی خیلی خوبی بود که', GameRating::sole()->comment);
    }

    public function test_passwords_are_left_exactly_as_typed(): void
    {
        $user = User::factory()->create(['password' => Hash::make('رمزيك123456')]);

        $this->postJson(route('api.auth.login'), ['phone' => $user->phonenumber, 'password' => 'رمزيك123456'])
            ->assertOk();
    }

    public function test_helpers_convert_only_what_they_should(): void
    {
        $this->assertSame('ی ک ی abc ۱۲', PersianText::normalizeLetters('ي ك ى abc ۱۲'));
        $this->assertSame('0123 4567 89 متن', PersianText::latinDigits('۰۱۲۳ ٤٥٦٧ ۸٩ متن'));
    }
}
