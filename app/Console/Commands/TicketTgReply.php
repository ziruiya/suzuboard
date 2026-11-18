<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Console\Command;

/**
 * PATCH 09 (ticket <-> Telegram bridge).
 * Reply to a ticket as the first admin, via the official TicketService path.
 * Payload is base64-encoded message text (safe for arbitrary user text).
 */
class TicketTgReply extends Command
{
    protected $signature = 'ticket:tg-reply {ticket} {payload}';
    protected $description = 'Reply to a ticket as admin from the Telegram ticket bridge (payload = base64 text)';

    public function handle()
    {
        $ticketId = (int) $this->argument('ticket');
        $message = base64_decode((string) $this->argument('payload'), true);
        if ($message === false || trim($message) === '') {
            $this->error('bad payload');
            return 1;
        }
        $ticket = Ticket::find($ticketId);
        if (!$ticket) {
            $this->error('ticket not found');
            return 1;
        }
        $adminId = User::where('is_admin', 1)->orderBy('id')->value('id');
        if (!$adminId) {
            $this->error('no admin user');
            return 1;
        }
        (new TicketService())->replyByAdmin($ticketId, $message, (int) $adminId);
        $this->info('OK');
        return 0;
    }
}
