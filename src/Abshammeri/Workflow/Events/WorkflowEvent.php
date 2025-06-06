<?php

namespace Saudinic\Workflow\Events;

class WorkflowEvent
{
    public object $subject;
    public string $workflowName;

    public function __construct(object $subject, string $workflowName)
    {
        $this->subject = $subject;
        $this->workflowName = $workflowName;
    }
}
