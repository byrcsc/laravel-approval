<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A human-readable name for a model, captured at a moment in time.
 *
 * The audit trail and the attachment table both store one alongside the morph
 * key, because a morph key stops meaning anything the day the row it points at
 * is renamed or deleted - and "who approved this?" is exactly the question
 * that must still be answerable then.
 */
final class Labels
{
    public static function for(Model $model): string
    {
        foreach (['name', 'title', 'reference', 'label', 'email'] as $attribute) {
            $value = $model->getAttributeValue($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $key = $model->getKey();

        return class_basename($model).' #'.(is_scalar($key) ? (string) $key : '?');
    }
}
