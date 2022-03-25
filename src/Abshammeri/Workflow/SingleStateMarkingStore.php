<?php

namespace Saudinic\Workflow;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

class SingleStateMarkingStore implements MarkingStoreInterface
{
    private $property;
    private $singleState;

    public function __construct(string $property = 'state')
    {
        $this->singleState = true;
        $this->property = $property;
    }

    public function getMarking(object $subject): Marking
    {
        $method = 'get'.ucfirst($this->property);

        $marking = $subject->{$method}();

        if (!$marking) {
            return new Marking();
        }

        if ($this->singleState) {
            $marking = [(string) $marking => 1];
        }

        return new Marking($marking);
    }

    public function setMarking(object $subject, Marking $marking, array $context = [])
    {
        $marking = $marking->getPlaces();

        if ($this->singleState) {
            $marking = key($marking);
        }
        $method = 'set'.ucfirst($this->property);

        $subject->{$method}($marking, $context);
    }
}
