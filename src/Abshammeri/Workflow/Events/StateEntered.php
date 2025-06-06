<?php

namespace Saudinic\Workflow\Events;

class StateEntered extends WorkflowEvent
{
    public string $stateName;
    public ?string $transitionName; // Transition that led to this state, if applicable

    public function __construct(object $subject, string $workflowName, string $stateName, ?string $transitionName = null)
    {
        parent::__construct($subject, $workflowName);
        $this->stateName = $stateName;
        $this->transitionName = $transitionName;
    }
}
