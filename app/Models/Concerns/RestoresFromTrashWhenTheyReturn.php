<?php

namespace App\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * For a person-like model (Customer, Supplier) that is deleted into Trash and
 * is found again by phone / e-mail / name whenever an order, an import row or
 * a lead conversion comes in (owner default 2026-11-01, plan T4a: a record in
 * Trash keeps its phone).
 *
 * Without this the lookup would not see the trashed row and would create a
 * second record for the same phone. With it a live match always wins, and only
 * when there is none does the newest trashed match come back (its balance is
 * recalculated by the model's `saved` hook, which Restore fires).
 *
 * Use matchOrRestore() only where a *new* record would otherwise be created.
 * A sign-in, a sign-up or any lookup that only reads keeps ignoring Trash, so a
 * customer in Trash can not log in.
 */
trait RestoresFromTrashWhenTheyReturn
{
    /** @param Closure(Builder): mixed $constrain adds the where clauses that identify the person */
    public static function matchOrRestore(Closure $constrain): ?static
    {
        $match = static::matchIncludingTrash($constrain);

        if ($match?->trashed()) {
            $match->restore();
        }

        return $match;
    }

    /** The live match, else the newest one in Trash (left in Trash): for a check that must know the person without bringing them back. */
    public static function matchIncludingTrash(Closure $constrain): ?static
    {
        $live = static::query()->tap($constrain)->orderBy('id')->first();

        return $live ?? static::onlyTrashed()->tap($constrain)->orderByDesc('deleted_at')->orderByDesc('id')->first();
    }
}
