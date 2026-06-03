<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when an entry payload supplied as a raw array / JSON envelope
 * fails its *structural* validation — before it is turned into an
 * {@see \StarDust\Write\EntryPayload}.
 *
 * Raised by the convergent factories `EntryPayload::fromArray()`,
 * `fromJson()`, `listFromArray()`, and `listFromJson()` when the
 * envelope shape is wrong: a required key (`tenantId`, `modelId`,
 * `fields`) is missing or mistyped, `fields` is not a name→value map,
 * the JSON is unparseable, or the root is not the expected object /
 * array.
 *
 * This covers only the envelope shape. Two downstream concerns are
 * deliberately NOT this exception's job, so they keep a single source
 * of truth:
 *   - the `tenant_id >= 1` business rule — enforced at the write
 *     boundary by {@see \StarDust\Write\TenantId::assertValid()};
 *   - per-field type coercion — enforced on the write path by
 *     {@see \StarDust\Write\PayloadSplitter} (which raises
 *     {@see UncoercibleSlotValueException}).
 *
 * `$key` names the offending envelope key (e.g. `tenantId`) or, for the
 * list factories, the offending element and key (e.g. `[3].modelId`),
 * so a CMS can point a user at the exact bad input. It is `null` when
 * the failure is not attributable to one key (e.g. unparseable JSON).
 */
final class MalformedEntryPayloadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $key = null,
    ) {
        parent::__construct($message);
    }
}
