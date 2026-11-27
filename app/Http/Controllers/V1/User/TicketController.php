<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\CommissionWithdrawal;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->with('message')
                ->first();
            if (!$ticket) {
                return $this->fail([404, __('Ticket does not exist')]);
            }
            $ticket->message->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
    }

    public function save(TicketSave $request)
    {
        $ticketService = new TicketService();
        $ticket = $ticketService->createTicket(
            $request->user()->id,
            $request->input('subject'),
            $request->input('level'),
            $request->input('message')
        );
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);

    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        if (empty($request->input('message'))) {
            return $this->fail([400, __('Message cannot be empty')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $request->user()->id == $this->getLastMessage($ticket->id)->user_id) {
            return $this->fail(codeResponse: [400, __('Please wait for the technical enginneer to reply')]);
        }
        $ticketService = new TicketService();
        if (
            !$ticketService->reply(
                $ticket,
                $request->input('message'),
                $request->user()->id
            )
        ) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return $this->success(true);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        return $this->success(true);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, 'Unsupported withdraw']);
        }
        if (
            !in_array(
                $request->input('withdraw_method'),
                admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)
            )
        ) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }
        $limit = admin_setting('commission_withdraw_limit', 100);
        try {
            // SB-MONEY-016: the request is recorded as a structured
            // withdrawal with its amount frozen in the same
            // transaction that opens the ticket. Previously the
            // ticket carried no amount and nothing was frozen, so
            // the same commission could be requested repeatedly or
            // transferred away while a payout was pending. One
            // pending withdrawal per user; the amount is the full
            // available commission at request time (the semantics
            // admins already settled against), and the reference +
            // amount are quoted on the ticket so settlement can be
            // matched exactly.
            $ticket = DB::transaction(function () use ($request, $limit) {
                $user = User::lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new ApiException(__('The user does not exist'));
                }
                if (CommissionWithdrawal::where('user_id', $user->id)
                    ->where('status', CommissionWithdrawal::STATUS_PENDING)
                    ->exists()) {
                    throw new ApiException(__('You already have a pending withdrawal request'));
                }
                $available = CommissionWithdrawal::availableCommission($user);
                if ($limit > ($available / 100)) {
                    throw new ApiException(__('The current required minimum withdrawal commission is :limit', ['limit' => $limit]));
                }
                $withdrawal = CommissionWithdrawal::create([
                    'user_id' => $user->id,
                    'reference' => 'CW-' . Helper::guid(),
                    'amount_cents' => $available,
                    'method' => $request->input('withdraw_method'),
                    'account' => $request->input('withdraw_account'),
                    'status' => CommissionWithdrawal::STATUS_PENDING,
                ]);
                $ticketService = new TicketService();
                $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
                $message = sprintf(
                    "%s\r\n%s\r\n%s\r\n%s",
                    __('Withdrawal method') . "：" . $request->input('withdraw_method'),
                    __('Withdrawal account') . "：" . $request->input('withdraw_account'),
                    __('Withdrawal amount') . "：" . number_format($available / 100, 2),
                    __('Withdrawal reference') . "：" . $withdrawal->reference
                );
                $ticket = $ticketService->createTicket(
                    $user->id,
                    $subject,
                    2,
                    $message
                );
                $withdrawal->ticket_id = $ticket->id;
                $withdrawal->save();
                return $ticket;
            });
        } catch (ApiException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);
    }
}
