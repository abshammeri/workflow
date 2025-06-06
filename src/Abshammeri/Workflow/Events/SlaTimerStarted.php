<?php

namespace Saudinic\Workflow\Events;

class SlaTimerStarted extends WorkflowEvent
{
    public string $slaKey;
    public array $slaConfig;
    public string $dueAt;

    public function __construct(object $subject, string $workflowName, string $slaKey, array $slaConfig, string $dueAt)
    {
        parent::__construct($subject, $workflowName);
        $this->slaKey = $slaKey;
        $this->slaConfig = $slaConfig;
        $this->dueAt = $dueAt;
    }
}
