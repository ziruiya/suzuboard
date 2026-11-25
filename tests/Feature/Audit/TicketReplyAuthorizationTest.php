<?php

namespace Tests\Feature\Audit;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SB-ER-001: TicketService::replyByAdmin must verify the replier
 * actually holds admin/staff privileges. Before the fix, the Telegram
 * plugin passed the *bound* user's id, so any bound customer could
 * reply to arbitrary tickets as support (and flip their state).
 */
class TicketReplyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'tix-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeTicket(User $owner): Ticket
    {
        return Ticket::create([
            'user_id' => $owner->id,
            'subject' => '需要帮助',
            'level' => 1,
            'status' => 0,
            'reply_status' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function test_plain_customer_cannot_reply_as_admin(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $ticket = $this->makeTicket($owner);

        try {
            (new TicketService())->replyByAdmin($ticket->id, '冒充客服的回复', $stranger->id);
            $this->fail('a non-staff replyByAdmin call must be refused');
        } catch (ApiException) {
            // expected
        }
        $this->assertSame(0, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, (int) $ticket->fresh()->reply_status);
    }

    public function test_ticket_owner_cannot_reply_as_admin_to_own_ticket(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);

        $this->expectException(ApiException::class);
        (new TicketService())->replyByAdmin($ticket->id, '把自己的工单标成已回复', $owner->id);
    }

    public function test_staff_and_admin_can_reply_as_admin(): void
    {
        $owner = $this->makeUser();
        $staff = $this->makeUser(['is_staff' => 1]);
        $admin = $this->makeUser(['is_admin' => 1]);
        $ticket = $this->makeTicket($owner);
        $ticket2 = $this->makeTicket($owner);

        (new TicketService())->replyByAdmin($ticket->id, '客服回复', $staff->id);
        (new TicketService())->replyByAdmin($ticket2->id, '管理员回复', $admin->id);

        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket2->id)->count());
        $this->assertSame(Ticket::REPLY_STATUS_REPLIED, (int) $ticket->fresh()->reply_status);
    }
}
