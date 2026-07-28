<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * Why somebody may not decide a request.
 *
 * These are the same five findings the engine has always made at the vote; what
 * is new is that they can be read rather than only caught. A screen that wants
 * to say why a button is disabled needs the reason, and "an exception was
 * thrown" is not one.
 *
 * The order below is the order they are established, which is also the order
 * they are most useful in: nothing about a seat matters if the request is
 * already closed.
 */
enum Ineligibility: string
{
    /** The request has been decided, withdrawn, or returned. */
    case RequestClosed = 'request_closed';

    /** No level is currently waiting - the request is between states. */
    case NoActiveStep = 'no_active_step';

    /** This actor holds no seat on the level that is waiting. */
    case NotAnApprover = 'not_an_approver';

    /** They are named on a seat, but it now belongs to their delegate. */
    case SeatDelegated = 'seat_delegated';

    /** They have already voted from the seat they hold. */
    case AlreadyVoted = 'already_voted';

    /** They submitted it, and this workflow does not allow that. */
    case SelfApproval = 'self_approval';
}
