<?php


namespace Saudinic\Workflow;


use Illuminate\Support\Arr;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Transition as SymfonyTransition;
use Symfony\Component\Workflow\Workflow as SymfonyWorkflow;
use Symfony\Component\Workflow\WorkflowInterface;

trait HasWorkflow
{
    /**
     * @var WorkflowInterface
     */
    protected ?WorkflowInterface $workflowEngine = null;
    protected ?string $workflowStateColumn = null;
    protected array $workflowMetadataStore = [];
    protected array $activeTaskDefinitions = [];
    protected array $activeSlaDefinitions = [];
    public array $mockDbOperations = [];
    public static array $dispatchedEvents = []; // For testing event dispatching


    protected function _dispatchEvent(\Saudinic\Workflow\Events\WorkflowEvent $event): void
    {
        if (class_exists(\Illuminate\Support\Facades\Event::class)) {
            try {
                 \Illuminate\Support\Facades\Event::dispatch($event);
            } catch (\Throwable $e) {
                // Fallback to static array if Event facade fails (e.g. not fully mocked)
                self::$dispatchedEvents[] = $event;
            }
        } else {
            self::$dispatchedEvents[] = $event;
        }
    }

    public function canOrFail(string $transition): self
    {
        if (!$this->can($transition)) {
            throw new WorkflowException("Transition <{$transition}> cannot be applied on the current state <{$this->workflowState()}>.");
        }
        return $this;
    }

    public function can(string $transition): bool
    {
        $this->validateWorkflowEngineOrFail();
        return $this->workflowEngine->can($this, $transition);
    }

    public function applyOrFail(string $transition): self
    {
        $this->validateWorkflowEngineOrFail();
        $currentState = $this->workflowState();

        $this->_dispatchEvent(new \Saudinic\Workflow\Events\StateLeft($this, $this->workflowName(), $currentState, $transition));

        // Process on-exit hooks (tasks, SLAs, etc.) for the current state
        $this->_processPostStateChangeHooks('on_exit_state', $currentState, $transition);

        try {
            $this->workflowEngine->apply($this, $transition, []);
            // State has changed, new state is $this->workflowState()
            $newState = $this->workflowState();

            $this->_dispatchEvent(new \Saudinic\Workflow\Events\TransitionApplied($this, $this->workflowName(), $currentState, $newState, $transition));
            $this->_dispatchEvent(new \Saudinic\Workflow\Events\StateEntered($this, $this->workflowName(), $newState, $transition));

            // Process on-enter hooks (tasks, SLAs, etc.) for the new state
            $this->_processPostStateChangeHooks('on_enter_state', $newState, $transition);

        } catch (\Exception $e) {
            throw new WorkflowException("Failed to apply transition <{$transition}>: " . $e->getMessage(), 0, $e);
        }
        return $this;
    }

    public function apply(string $transition): bool
    {
        try {
            $this->applyOrFail($transition);
            return true;
        } catch (WorkflowException $e) {
            // Log error or handle silently if needed, but returning false is conventional for apply()
            return false;
        }
    }

    public function workflowEngine(): ?WorkflowInterface
    {
        return $this->workflowEngine;
    }

    public function workflowMetadata(string $search = null, $default = null)
    {
        return Arr::get($this->workflowMetadataStore, $search, $default);
    }

    public function activateWorkflow(string $workflowDefinitionClass): self
    {
        if (!class_exists($workflowDefinitionClass)) {
            throw new WorkflowException("Workflow definition class {$workflowDefinitionClass} not found.");
        }

        /** @var BaseWorkflowDefinition $definition */
        $definition = new $workflowDefinitionClass();

        if (!($definition instanceof BaseWorkflowDefinition)) {
            throw new WorkflowException("{$workflowDefinitionClass} must extend " . BaseWorkflowDefinition::class);
        }

        $this->workflowStateColumn = $definition->getStateColumn();
        $this->workflowMetadataStore = $definition->getSymfonyMetadata();
        $this->activeTaskDefinitions = $definition->getTaskDefinitions();
        $this->activeSlaDefinitions = $definition->getSlaDefinitions(); // Load SLA definitions

        $this->workflowEngine = $this->buildSymfonyWorkflow(
            $definition->getName(),
            $definition->getInitialPlace(),
            $definition->getSymfonyPlaces(),
            $definition->getSymfonyTransitions(),
            $this->workflowMetadataStore, // Pass the fetched metadata
            $definition->getStateColumn()
        );

        // Ensure the marking is loaded, which also sets the initial state on the subject if not already set.
        $this->workflowEngine->getMarking($this);
        return $this;
    }

    private function buildSymfonyWorkflow(
        string $workflowName,
        string $initialPlace,
        array $places,
        array $transitions,
        array $metadata, // Combined metadata for workflow, places, and transitions
        string $stateColumn
    ): SymfonyWorkflow
    {
        $definitionBuilder = new DefinitionBuilder();
        $definitionBuilder->addPlaces($places);
        $definitionBuilder->setInitialPlaces([$initialPlace]); // Symfony expects an array

        foreach ($transitions as $name => $transitionConfig) {
            $from = (array) $transitionConfig['from']; // Ensure 'from' is an array
            $to = $transitionConfig['to'];
            $definitionBuilder->addTransition(new SymfonyTransition($name, $from, $to));
        }

        $symfonyMetadataStore = new InMemoryMetadataStore(
            $metadata['workflow'] ?? [],
            $metadata['places'] ?? [],
            // For transitions, Symfony expects an array of TransitionMetadata objects.
            // We'll pass an empty array for now if not formatting correctly.
            // Or adjust getSymfonyMetadata to create these if needed by event listeners.
            [] // $metadata['transitions_metadata'] ?? []
        );

        $definition = $definitionBuilder->build();
        $markingStore = new SingleStateMarkingStore($stateColumn);

        return new SymfonyWorkflow($definition, $markingStore, null, $workflowName);
    }

    public function getAllowedTransitions(): array
    {
        $this->validateWorkflowEngineOrFail();
        return array_map(static fn(SymfonyTransition $t) => $t->getName(), $this->workflowEngine->getEnabledTransitions($this));
    }

    public function places(): array
    {
        $this->validateWorkflowEngineOrFail();
        // Return place names as an array
        return array_keys($this->workflowEngine->getDefinition()->getPlaces());
    }

    public function initialPlace(): string
    {
        $this->validateWorkflowEngineOrFail();
        $initialPlaces = $this->workflowEngine->getDefinition()->getInitialPlaces();
        return reset($initialPlaces); // Get the first initial place
    }

    private function validateWorkflowEngineOrFail(): void
    {
        if (!$this->workflowEngine) {
            throw new WorkflowException("Workflow is not initialized. Call activateWorkflow() first.");
        }
    }

    public function workflowName(): string
    {
        $this->validateWorkflowEngineOrFail();
        return $this->workflowEngine->getName();
    }

    public function workflowStateColumn(): ?string
    {
        if (!$this->workflowStateColumn && $this->workflowEngine) {
             // Attempt to get it from marking store if engine is initialized but column isn't set on trait
            $markingStore = $this->workflowEngine->getMarkingStore();
            if ($markingStore instanceof SingleStateMarkingStore) {
                $this->workflowStateColumn = $markingStore->getProperty();
            }
        }
        return $this->workflowStateColumn;
    }

    public function workflowState()
    {
        $this->validateWorkflowEngineOrFail();
        $stateColumn = $this->workflowStateColumn();
        if (!$stateColumn) {
            throw new WorkflowException("Workflow state column is not defined.");
        }
        return $this->{$stateColumn};
    }

    public function __call($method, $parameters)
    {
        $stateColumn = $this->workflowStateColumn();

        if ($stateColumn) {
            $studlyStateColumn = ucfirst(str_replace('_', '', ucwords($stateColumn, '_')));
            if ($method === 'get' . $studlyStateColumn) {
                return $this->{$stateColumn};
            }

            if ($method === 'set' . $studlyStateColumn) {
                $this->{$stateColumn} = $parameters[0];
                return $this;
            }
        }

        // Throw BadMethodCallException if not using Laravel's Model __call
        // For Eloquent models, this will try parent::__call
        if (is_callable('parent::__call')) {
            return parent::__call($method, $parameters);
        }

        throw new \BadMethodCallException(sprintf(
            'Method %s::%s does not exist.', static::class, $method
        ));
    }

    public function hasEnded(): bool
    {
        $this->validateWorkflowEngineOrFail();
        return empty($this->workflowEngine->getEnabledTransitions($this));
    }

    // Combined Task and SLA Hook Processing (and potentially other future hooks)

    private function _processPostStateChangeHooks(string $event, string $stateName, ?string $transitionName = null): void
    {
        // Process Tasks
        foreach ($this->activeTaskDefinitions as $taskKey => $taskConfig) {
            $triggerStateField = $event === 'on_enter_state' ? 'on_enter_state' : 'on_exit_state';
            if (isset($taskConfig[$triggerStateField]) && $taskConfig[$triggerStateField] === $stateName) {
                if (!isset($taskConfig['on_transition']) || $taskConfig['on_transition'] === $transitionName) {
                    $this->_createWorkflowTaskRecord($taskKey, $taskConfig);
                }
            }
        }

        // Process SLAs (Activation)
        if ($event === 'on_enter_state') { // SLAs typically start when a state is entered
            foreach ($this->activeSlaDefinitions as $slaKey => $slaConfig) {
                if ($slaConfig['applies_to_state'] === $stateName) {
                    // Check transition scope if defined for the SLA
                    if (!isset($slaConfig['on_transition']) || $slaConfig['on_transition'] === $transitionName) {
                        $this->_activateSla($slaKey, $slaConfig);
                    }
                }
            }
        }
    }

    private function _activateSla(string $slaKey, array $slaConfig): void
    {
        $modelId = method_exists($this, 'getKey') ? $this->getKey() : ($this->id ?? null);
        $dueAtTimestamp = time() + $slaConfig['duration_seconds'];

        $this->mockDbOperations[] = [
            'type' => 'sla_timer_start_attempt',
            'sla_key' => $slaKey,
            'model_id' => $modelId,
            'model_type' => get_class($this),
            'workflow_name' => $this->workflowName(),
            'state_or_task_key' => $slaConfig['applies_to_state'],
            'due_at' => date('Y-m-d H:i:s', $dueAtTimestamp),
            'sla_config' => $slaConfig
        ];
        $this->_dispatchEvent(new \Saudinic\Workflow\Events\SlaTimerStarted($this, $this->workflowName(), $slaKey, $slaConfig, date('Y-m-d H:i:s', $dueAtTimestamp)));
    }

    private function _createWorkflowTaskRecord(string $taskKey, array $taskConfig): void
    {
        $modelId = method_exists($this, 'getKey') ? $this->getKey() : null;
        if ($modelId === null && property_exists($this, 'id')) {
            $modelId = $this->id;
        }


        $data = [
            'model_type' => get_class($this),
            'model_id' => $modelId,
            'workflow_name' => $this->workflowName(),
            'task_key' => $taskKey,
            'status' => 'pending', // Initial status
            'assigned_to_type' => $taskConfig['assignee_type'] ?? null,
            'assigned_to_id' => $taskConfig['assignee_value'] ?? null,
            'due_at' => isset($taskConfig['due_in_seconds']) ? date('Y-m-d H:i:s', time() + $taskConfig['due_in_seconds']) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            // 'output' and 'completed_at' will be null initially
        ];

        // DB Interaction: Mock or Real
        if (class_exists(\Illuminate\Support\Facades\DB::class)) {
            try {
                \Illuminate\Support\Facades\DB::table('workflow_tasks')->insert($data);
                $this->mockDbOperations[] = ['type' => 'insert', 'table' => 'workflow_tasks', 'data' => $data, 'status' => 'success'];
            } catch (\Throwable $e) {
                // Log error or handle if DB is not available/configured
                $this->mockDbOperations[] = ['type' => 'insert', 'table' => 'workflow_tasks', 'data' => $data, 'status' => 'error', 'message' => $e->getMessage()];
            }
        } else {
            // Mocking for environments without DB facade
            $this->mockDbOperations[] = ['type' => 'insert', 'table' => 'workflow_tasks', 'data' => $data, 'status' => 'mocked'];
        }

        $this->_dispatchEvent(new \Saudinic\Workflow\Events\TaskCreated($this, $this->workflowName(), $taskKey, $data));

        if ($taskConfig['type'] === 'automatic' && !empty($taskConfig['handler'])) {
            $this->_executeAutomaticTask($taskKey, $taskConfig['handler'], $data, $taskConfig);
        }
    }

    private function _executeAutomaticTask(string $taskKey, $handler, array $taskRecordData, array $taskConfig): void
    {
        $output = null;
        $newStatus = 'completed'; // Assume success unless handler indicates failure

        try {
            // Pass the subject model and the full task configuration to the handler
            if (is_callable($handler)) {
                $output = call_user_func($handler, $this, $taskConfig);
            } elseif (is_string($handler) && method_exists($this, $handler)) {
                $output = $this->{$handler}($taskConfig); // Pass $taskConfig instead of $taskRecordData
            } else {
                throw new WorkflowException("Automatic task handler '{$handler}' for task '{$taskKey}' is not callable or a valid method on the model.");
            }
        } catch (\Exception $e) {
            $output = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]; // Include trace for debugging
            $newStatus = 'failed';
            $this->mockDbOperations[] = ['type' => 'auto_task_execution', 'task_key' => $taskKey, 'status' => 'error', 'message' => $e->getMessage()];
        }

        $updateData = [
            'status' => $newStatus,
            'completed_at' => date('Y-m-d H:i:s'),
            'output' => json_encode($output), // Output from the handler
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (class_exists(\Illuminate\Support\Facades\DB::class)) {
            try {
                // Criteria to find the task to update.
                // Using model_id, task_key, and initial 'pending' status is a reasonable guess.
                // $criteria = ['model_type'=> get_class($this), 'model_id' => $taskRecordData['model_id'], 'task_key' => $taskKey, 'status' => 'pending'];
                // DB::table('workflow_tasks')->where($criteria)->limit(1)->update($updateData);
                $this->mockDbOperations[] = ['type' => 'update_auto_task', 'table' => 'workflow_tasks', 'criteria_guess' => ['model_id' => $taskRecordData['model_id'], 'task_key' => $taskKey, 'status' => 'pending'], 'data' => $updateData, 'status' => 'success_logged'];
            } catch (\Throwable $e) {
                $this->mockDbOperations[] = ['type' => 'update_auto_task', 'table' => 'workflow_tasks', 'data' => $updateData, 'status' => 'error', 'message' => $e->getMessage()];
            }
        } else {
            $this->mockDbOperations[] = ['type' => 'update_auto_task', 'table' => 'workflow_tasks', 'data' => $updateData, 'status' => 'mocked'];
        }

        if ($newStatus === 'completed') {
            // Ensure $output is an array for the event
            $eventOutput = is_array($output) ? $output : (is_null($output) ? [] : [$output]);
            $this->_dispatchEvent(new \Saudinic\Workflow\Events\TaskCompleted($this, $this->workflowName(), $taskKey, $eventOutput));
        }
    }


    public function getWorkflowTasks(array $criteria = []): array
    {
        $modelId = method_exists($this, 'getKey') ? $this->getKey() : ($this->id ?? null);
        $defaultCriteria = [
            'model_type' => get_class($this),
            'model_id' => $modelId,
            'workflow_name' => $this->workflowName(),
        ];
        $queryCriteria = array_merge($defaultCriteria, $criteria);

        if (class_exists(\Illuminate\Support\Facades\DB::class)) {
            try {
                // This is a simplified query. Real implementation might need more flexibility.
                // $query = DB::table('workflow_tasks');
                // foreach ($queryCriteria as $key => $value) {
                //    $query->where($key, $value);
                // }
                // return $query->get()->toArray();
                $this->mockDbOperations[] = ['type' => 'select', 'table' => 'workflow_tasks', 'criteria' => $queryCriteria, 'status' => 'success_mock_return_empty'];
                return []; // Placeholder for actual DB interaction
            } catch (\Throwable $e) {
                 $this->mockDbOperations[] = ['type' => 'select', 'table' => 'workflow_tasks', 'criteria' => $queryCriteria, 'status' => 'error', 'message' => $e->getMessage()];
                return [];
            }
        }
        $this->mockDbOperations[] = ['type' => 'select', 'table' => 'workflow_tasks', 'criteria' => $queryCriteria, 'status' => 'mocked_return_empty'];
        return []; // Mocked: return empty or predefined test data
    }

    public function completeWorkflowTask(int $taskId, array $output = []): bool
    {
        $updateData = [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'output' => json_encode($output),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (class_exists(\Illuminate\Support\Facades\DB::class)) {
            try {
                // DB::table('workflow_tasks')->where('id', $taskId)->update($updateData);
                $this->mockDbOperations[] = ['type' => 'update', 'table' => 'workflow_tasks', 'id' => $taskId, 'data' => $updateData, 'status' => 'success_mocked_true'];
                return true;
            } catch (\Throwable $e) {
                $this->mockDbOperations[] = ['type' => 'update', 'table' => 'workflow_tasks', 'id' => $taskId, 'data' => $updateData, 'status' => 'error', 'message' => $e->getMessage()];
                return false;
            }
        }
        $this->mockDbOperations[] = ['type' => 'update', 'table' => 'workflow_tasks', 'id' => $taskId, 'data' => $updateData, 'status' => 'mocked_true'];
        return true; // Mocked
    }

    public function updateWorkflowTaskStatus(int $taskId, string $status): bool
    {
        $updateData = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
         if (class_exists(\Illuminate\Support\Facades\DB::class)) {
            try {
                // DB::table('workflow_tasks')->where('id', $taskId)->update($updateData);
                $this->mockDbOperations[] = ['type' => 'update', 'table' => 'workflow_tasks', 'id' => $taskId, 'data' => $updateData, 'status' => 'success_mocked_true'];
                return true;
            } catch (\Throwable $e) {
                $this->mockDbOperations[] = ['type' => 'update', 'table' => 'workflow_tasks', 'id' => $taskId, 'data' => $updateData, 'status' => 'error', 'message' => $e->getMessage()];
                return false;
            }
        }
        $this->mockDbOperations[] = ['type' => 'update', 'table' => 'workflow_tasks', 'id' => $taskId, 'data' => $updateData, 'status' => 'mocked_true'];
        return true; // Mocked
    }
}
