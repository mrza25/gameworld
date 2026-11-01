<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function announcementsOf(User $user): array
    {
        return $user->notifications()->get()->pluck('data')->where('type', 'announcement')->values()->all();
    }

    public function test_admin_can_notify_a_single_user(): void
    {
        [$recipient, $bystander] = User::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.notifications.store'), [
                'title' => 'تخفیف ویژه',
                'message' => 'تا پایان هفته ۲۰٪ تخفیف دارید.',
                'link' => '/games',
                'user_id' => $recipient->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.audience', 'users')
            ->assertJsonPath('data.recipients_count', 1)
            ->assertJsonPath('data.sender.id', $this->admin->id);

        $this->assertSame([[
            'type' => 'announcement',
            'title' => 'تخفیف ویژه',
            'message' => 'تا پایان هفته ۲۰٪ تخفیف دارید.',
            'link' => '/games',
            'announcement_id' => Announcement::sole()->id,
        ]], $this->announcementsOf($recipient));
        $this->assertSame([], $this->announcementsOf($bystander));
    }

    public function test_admin_can_notify_several_users_or_everyone(): void
    {
        $users = User::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.notifications.store'), [
                'title' => 'چند نفر',
                'message' => 'پیام',
                'user_ids' => [$users[0]->id, $users[1]->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.recipients_count', 2);

        $this->assertCount(1, $this->announcementsOf($users[1]));
        $this->assertCount(0, $this->announcementsOf($users[2]));

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.notifications.store'), [
                'title' => 'همه',
                'message' => 'پیام عمومی',
                'audience' => 'all',
            ])
            ->assertCreated()
            ->assertJsonPath('data.recipients_count', 4);

        $this->assertCount(1, $this->announcementsOf($users[2]));
        $this->assertCount(1, $this->announcementsOf($this->admin));
    }

    public function test_audience_and_link_are_validated(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('api.admin.notifications.store'), ['title' => 'x', 'message' => 'y'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audience');

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.notifications.store'), ['title' => 'x', 'message' => 'y', 'user_ids' => [999999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');

        $this->actingAs($this->admin)
            ->postJson(route('api.admin.notifications.store'), ['title' => 'x', 'message' => 'y', 'audience' => 'all', 'link' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('link');

        $this->assertSame(0, Announcement::count());
    }

    public function test_customers_cannot_send_announcements(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson(route('api.admin.notifications.store'), ['title' => 'x', 'message' => 'y', 'audience' => 'all'])
            ->assertForbidden();
    }

    public function test_admin_can_list_sent_announcements(): void
    {
        Announcement::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->getJson(route('api.admin.notifications.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [['id', 'title', 'message', 'link', 'audience', 'recipients_count', 'sender', 'created_at']], 'links']);
    }
}
