<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Jobs\SendOtpSms;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusUpdated;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class QueuedJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_otp_queues_an_sms_job(): void
    {
        Queue::fake();

        $code = $this->postJson(route('api.auth.send-otp'), ['phone' => '09123334455'])
            ->assertOk()
            ->json('debug_code');

        Queue::assertPushed(SendOtpSms::class, fn (SendOtpSms $job) => $job->phone === '09123334455' && $job->code === $code);
    }

    public function test_otp_sms_job_sends_code_through_sms_sender(): void
    {
        $this->mock(SmsSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn (string $phone, string $message) => $phone === '09123334455' && str_contains($message, '12345'));
        });

        (new SendOtpSms('09123334455', '12345'))->handle(app(SmsSender::class));
    }

    public function test_order_status_notification_is_queued_and_keeps_status_at_dispatch_time(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'console_type' => 'PS5',
            'total' => 120000,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $notification = new OrderStatusUpdated($order);
        $order->update(['status' => OrderStatus::Completed]);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('processing', $notification->toArray($order->user)['status']);
    }
}
