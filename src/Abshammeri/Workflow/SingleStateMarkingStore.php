<?php

namespace Saudinic\Workflow;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

class SingleStateMarkingStore implements MarkingStoreInterface
{
    private string $property; // This is the state column name

    public function __construct(string $property)
    {
        $this->property = $property;
    }

    public function getMarking(object $subject): Marking
    {
        $placeName = null;

        if ($subject instanceof \Illuminate\Database\Eloquent\Model) {
            $placeName = $subject->{$this->property};
        } else {
            // Try getter method first
            $getterMethod = 'get' . ucfirst(str_replace('_', '', ucwords($this->property, '_')));
            if (method_exists($subject, $getterMethod)) {
                $placeName = $subject->{$getterMethod}();
            } elseif (property_exists($subject, $this->property)) {
                // Fallback to public property access
                $placeName = $subject->{$this->property};
            }
        }

        if (null === $placeName || '' === $placeName) {
            // No state set yet, or empty state. Return an empty marking.
            // The workflow will typically set the initial place via setMarking.
            return new Marking();
        }

        return new Marking([ (string) $placeName => 1 ]);
    }

    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = $marking->getPlaces();
        $placeName = key($places); // In single state, there's only one place

        if ($subject instanceof \Illuminate\Database\Eloquent\Model) {
            $subject->{$this->property} = $placeName;
            // DO NOT call $subject->save() here. Persistence is handled by the application.
        } else {
            $setterMethod = 'set' . ucfirst(str_replace('_', '', ucwords($this->property, '_')));
            if (method_exists($subject, $setterMethod)) {
                $subject->{$setterMethod}($placeName, $context);
            } else {
                // Fallback to public property access
                $subject->{$this->property} = $placeName;
            }
        }
    }

    public function getProperty(): string
    {
        return $this->property;
    }
}
