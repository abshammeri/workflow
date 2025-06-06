<?php

namespace Saudinic\Workflow\Events;

class StateLeft extends WorkflowEvent
{
    public string $stateName;
    public ?string $transitionName; // Transition that is causing leaving this state, if applicable

    public function __construct(object $subject, string $workflowName, string $stateName, ?string $transitionName = null)
    {
        parent::__construct($subject, $workflowName);
        $this->stateName = $stateName;
        $this->transitionName = $transitionName;
    }
}
