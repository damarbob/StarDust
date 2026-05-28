<?php

declare(strict_types=1);

namespace StarDust\Search\PreFlight;

use LogicException;
use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Filter\ValidationErrorCode;
use StarDust\Search\EntrySearchInterface;

/**
 * Pre-flight visitor: enforces the active driver's capability surface
 * per ADR 0022.
 *
 * For each {@see LeafNode}:
 *
 *   - rejects operators absent from `driver.supportedOperators()`
 *     with {@see QueryFilterValidationException} `capability_unsupported`
 *     and emits a `capability_unsupported` event (operators care about
 *     this signal separately from generic rejections);
 *   - rejects fields for which `driver.supportsFilterOn(fieldId)` is
 *     `false` with {@see FieldNotFilterableException} (`field_not_filterable`).
 *
 * Runs AFTER {@see FieldRefResolver} so every leaf already carries a
 * resolved descriptor and a valid `fieldId`.
 */
final class CapabilityChecker
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(
        FilterNode $node,
        EntrySearchInterface $driver,
        int $tenantId,
        string $correlationId,
    ): void {
        if ($node instanceof LeafNode) {
            $this->checkLeaf($node, $driver, $tenantId, $correlationId);
            return;
        }
        if ($node instanceof AndNode || $node instanceof OrNode) {
            foreach ($node->args as $child) {
                $this->check($child, $driver, $tenantId, $correlationId);
            }
            return;
        }
        if ($node instanceof NotNode) {
            $this->check($node->arg, $driver, $tenantId, $correlationId);
            return;
        }
        throw new LogicException('CapabilityChecker: unknown FilterNode ' . $node::class);
    }

    private function checkLeaf(
        LeafNode $leaf,
        EntrySearchInterface $driver,
        int $tenantId,
        string $correlationId,
    ): void {
        $supported = $driver->supportedOperators();
        if (!in_array($leaf->operator, $supported, true)) {
            $this->logger->warning('search capability unsupported', [
                'event'          => 'capability_unsupported',
                'source'         => 'api',
                'correlation_id' => $correlationId,
                'tenant_id'      => $tenantId,
                'operator'       => $leaf->operator,
                'field_name'     => $leaf->field->fieldName,
                'driver_class'   => $driver::class,
            ]);
            throw new QueryFilterValidationException(
                errorCode:   ValidationErrorCode::CAPABILITY_UNSUPPORTED,
                jsonPointer: '',
                message:     "active driver does not support operator '{$leaf->operator}'",
                details:     [
                    'operator'     => $leaf->operator,
                    'field_name'   => $leaf->field->fieldName,
                    'driver_class' => $driver::class,
                ],
            );
        }

        $fieldId = $leaf->field->fieldId;
        if ($fieldId === null) {
            throw new LogicException(
                "CapabilityChecker reached leaf for '{$leaf->field->fieldName}' before resolution"
            );
        }
        if (!$driver->supportsFilterOn($fieldId)) {
            $this->logger->warning('search pre-flight rejected', [
                'event'          => 'pre_flight_rejected',
                'source'         => 'api',
                'correlation_id' => $correlationId,
                'tenant_id'      => $tenantId,
                'reason'         => 'field_not_filterable',
                'field_name'     => $leaf->field->fieldName,
            ]);
            throw new FieldNotFilterableException(
                "Field '{$leaf->field->fieldName}' is not filterable on the active driver."
            );
        }
    }
}
