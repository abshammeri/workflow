<?php

namespace Saudinic\Workflow\Events;

class TransitionApplied extends WorkflowEvent
{
    public string $fromState;
    public string $toState;
    public string $transitionName;

    public function __construct(object $subject, string $workflowName, string $fromState, string $toState, string $transitionName)
    {
        parent::__construct($subject, $workflowName);
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->transitionName = $transitionName;
    }
}
