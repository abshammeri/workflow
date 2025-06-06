<?php

namespace Saudinic\Workflow\Events;

class TaskCreated extends WorkflowEvent
{
    public string $taskKey;
    public array $taskData; // The data used to create the task record

    public function __construct(object $subject, string $workflowName, string $taskKey, array $taskData)
    {
        parent::__construct($subject, $workflowName);
        $this->taskKey = $taskKey;
        $this->taskData = $taskData;
    }
}
