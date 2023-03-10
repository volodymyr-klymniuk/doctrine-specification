<?php

namespace VolodymyrKlymniuk\DoctrineSpecification\ResultModifier;

use Doctrine\ORM\AbstractQuery;

class ResultModifierCollection implements ResultModifierInterface
{
    /**
     * @var ResultModifierInterface[]
     */
    private $resultModifiers;

    /**
     * Construct it with one or more instances of ResultModifier.
     */
    public function __construct()
    {
        $this->resultModifiers = func_get_args();
    }

    /**
     * {@inheritDoc}
     */
    public function modify(AbstractQuery $query)
    {
        foreach ($this->resultModifiers as $child) {
            if (!$child instanceof ResultModifierInterface) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Child passed to ResultModifierCollection must be an instance of Igdr\DoctrineSpecification\Result\ResultModifier, but instance of %s found',
                        get_class($child)
                    )
                );
            }

            $child->modify($query);
        }
    }
}