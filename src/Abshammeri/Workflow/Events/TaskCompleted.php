<?php

namespace Saudinic\Workflow\Events;

class TaskCompleted extends WorkflowEvent
{
    public string $taskKey;
    public array $outputData; // Output from the task handler

    public function __construct(object $subject, string $workflowName, string $taskKey, array $outputData)
    {
        parent::__construct($subject, $workflowName);
        $this->taskKey = $taskKey;
        $this->outputData = $outputData;
    }
}
