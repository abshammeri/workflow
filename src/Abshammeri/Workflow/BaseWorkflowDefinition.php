<?php

namespace Saudinic\Workflow;

use Saudinic\Workflow\Exceptions\WorkflowException;

abstract class BaseWorkflowDefinition
{
    protected string $name;
    protected string $modelClass;
    protected string $stateColumn = 'workflow_state';
    protected string $initialPlace;
    protected array $places = [];
    protected array $transitions = [];
    protected array $taskDefinitions = [];
    protected array $slaDefinitions = []; // Added for SLAs
    protected array $metadata = [];

    abstract public function configure(): void;

    public function __construct()
    {
        $this->configure();
        $this->validate();
    }

    protected function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    protected function setModelClass(string $modelClass): self
    {
        $this->modelClass = $modelClass;
        return $this;
    }

    protected function setStateColumn(string $stateColumn): self
    {
        $this->stateColumn = $stateColumn;
        return $this;
    }

    protected function setInitialPlace(string $place): self
    {
        $this->initialPlace = $place;
        return $this;
    }

    protected function addPlace(string $name, array $metadata = []): self
    {
        $this->places[$name] = $metadata;
        return $this;
    }

    protected function addTransition(string $name, string|array $from, string $to, array $options = []): self
    {
        $this->transitions[$name] = [
            'from' => $from,
            'to' => $to,
            'options' => $options, // e.g., ['guards' => [...], 'actions' => [...]]
        ];
        return $this;
    }

    /**
     * Adds a task definition to the workflow.
     *
     * @param string $taskKey Unique key for the task.
     * @param array $config Task configuration:
     *                      - 'name' (string, required): Display name of the task.
     *                      - 'on_enter_state' (string|null): State on which this task becomes active (on entry).
     *                      - 'on_exit_state' (string|null): State from which this task becomes active (on exit, before transition).
     *                      - 'type' (string, required, 'automatic' or 'manual'): Type of the task.
     *                      - 'handler' (string|callable, optional): For automatic tasks, the handler to execute.
     *                      - 'assignee_type' (string, optional): e.g., 'role', 'user'.
     *                      - 'assignee_value' (string|int, optional): e.g., 'manager_role', 123 (user_id).
     *                      - 'due_in_seconds' (int, optional): SLA for task completion.
     *                      - 'metadata' (array, optional): Arbitrary metadata for the task.
     * @return $this
     * @throws WorkflowException
     */
    protected function addTaskDefinition(string $taskKey, array $config): self
    {
        if (empty($config['name'])) {
            throw new WorkflowException("Task definition '{$taskKey}' must have a 'name'.");
        }
        if (empty($config['on_enter_state']) && empty($config['on_exit_state'])) {
            throw new WorkflowException("Task definition '{$taskKey}' must have either 'on_enter_state' or 'on_exit_state'.");
        }
        if (empty($config['type']) || !in_array($config['type'], ['automatic', 'manual'])) {
            throw new WorkflowException("Task definition '{$taskKey}' must have a valid 'type' ('automatic' or 'manual').");
        }
        if ($config['type'] === 'automatic' && empty($config['handler'])) {
            throw new WorkflowException("Automatic task '{$taskKey}' must have a 'handler'.");
        }

        $this->taskDefinitions[$taskKey] = array_merge([
            'on_enter_state' => null,
            'on_exit_state' => null,
            'handler' => null,
            'assignee_type' => null,
            'assignee_value' => null,
            'due_in_seconds' => null,
            'metadata' => [],
        ], $config);
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function getStateColumn(): string
    {
        return $this->stateColumn;
    }

    public function getInitialPlace(): string
    {
        return $this->initialPlace;
    }

    public function getPlacesDefinition(): array
    {
        return $this->places;
    }

    public function getTransitionsDefinition(): array
    {
        return $this->transitions;
    }

    public function getTaskDefinitions(): array
    {
        return $this->taskDefinitions;
    }

    protected function addSlaDefinition(string $slaKey, array $config): self
    {
        // Config might include:
        // 'name' (string, required): Display name of the SLA.
        // 'applies_to_state' (string, required): State to which this SLA applies.
        // 'duration_seconds' (int, required): SLA duration in seconds.
        // 'on_breach_handler' (string|callable, optional): Action to take on breach.
        // 'metadata' (array, optional): Arbitrary metadata.
        if (empty($config['name']) || empty($config['applies_to_state']) || !isset($config['duration_seconds'])) {
            throw new WorkflowException("SLA definition '{$slaKey}' is missing required fields (name, applies_to_state, duration_seconds).");
        }
        $this->slaDefinitions[$slaKey] = array_merge([
           'on_breach_handler' => null,
           'metadata' => [],
        ], $config);
        return $this;
    }

    public function getSlaDefinitions(): array
    {
        return $this->slaDefinitions;
    }

    public function getSymfonyPlaces(): array
    {
        return array_keys($this->places);
    }

    public function getSymfonyTransitions(): array
    {
        $symfonyTransitions = [];
        foreach ($this->transitions as $name => $transition) {
            $symfonyTransitions[$name] = [
                'from' => $transition['from'],
                'to' => $transition['to'],
            ];
        }
        return $symfonyTransitions;
    }

    public function getSymfonyMetadata(): array
    {
        $workflowMetadata = $this->metadata['workflow'] ?? [];
        $placesMetadata = [];
        foreach ($this->places as $placeName => $metadata) {
            if (!empty($metadata)) {
                $placesMetadata[$placeName] = $metadata;
            }
        }

        $transitionsMetadata = [];
        foreach ($this->transitions as $transitionName => $transition) {
            if (!empty($transition['options'])) {
                // Symfony's metadata for transitions is typically for guards/events,
                // not arbitrary key-value. We might need to adjust how 'options' are structured
                // or what part of them goes into metadata. For now, let's pass them.
                $transitionsMetadata[$transitionName] = $transition['options'];
            }
        }

        $symfonyMetadata = [];
        if(!empty($workflowMetadata)){
             $symfonyMetadata['workflow'] = $workflowMetadata;
        }
        if(!empty($placesMetadata)){
             $symfonyMetadata['places'] = $placesMetadata;
        }
        if(!empty($transitionsMetadata)){
             $symfonyMetadata['transitions'] = $transitionsMetadata;
        }
        return $symfonyMetadata;
    }

    protected function validate(): void
    {
        if (empty($this->name)) {
            throw new WorkflowException("Workflow name is not configured.");
        }
        if (empty($this->modelClass)) {
            throw new WorkflowException("Workflow model class is not configured.");
        }
        if (empty($this->initialPlace)) {
            throw new WorkflowException("Workflow initial place is not configured.");
        }
        if (empty($this->places)) {
            throw new WorkflowException("Workflow places are not configured.");
        }
        if (empty($this->transitions)) {
            throw new WorkflowException("Workflow transitions are not configured.");
        }
    }
}
