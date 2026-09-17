<?php declare(strict_types=1);

namespace Briqpay\Payments\Checkout\Capture;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                      add(BriqpayCaptureEntity $entity)
 * @method void                      set(string $key, BriqpayCaptureEntity $entity)
 * @method BriqpayCaptureEntity[]    getIterator()
 * @method BriqpayCaptureEntity[]    getElements()
 * @method BriqpayCaptureEntity|null get(string $key)
 * @method BriqpayCaptureEntity|null first()
 * @method BriqpayCaptureEntity|null last()
 */
class BriqpayCaptureCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BriqpayCaptureEntity::class;
    }
}
