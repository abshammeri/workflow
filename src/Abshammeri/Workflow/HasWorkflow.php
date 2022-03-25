<?php


namespace Saudinic\Workflow;


use Illuminate\Support\Arr;
use Prophecy\Exception\Doubler\MethodNotFoundException;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Transition as SymfonyTransition;
use Symfony\Component\Workflow\Workflow as SymfonyWorkflow;
use Symfony\Component\Workflow\WorkflowInterface;

trait HasWorkflow
{
    /**
     * @var WorkflowInterface
     */
    protected $workflowEngine;

    private $setterMethod;
    private $getterMethod;
    private $setterMethodName;
    private $getterMethodName;

    private $workflowMetadata;
    // define this in your class to override the property name
    // public $property = 'state';

    public function canOrFail($transition): self
    {
        if ($this->can($transition) == false)
            throw new WorkflowException(" <{$transition}> transition cannot be apply on {$this->workflowState()} state ");

        return $this;
    }

    /**
     * validate if the transition is possible or not based on the current state and defined workflow.
     * @return bool
     * @throws \Exception
     */
    public function can($transition)
    {
        $this->validateWorkflowEngineOrFail();

        if (!$this->workflowEngine->can($this, $transition)) {
            return false;
        }
        return true;
    }

    public function applyOrFail($transition): self
    {
        if ($this->apply($transition) === false) {
            throw new WorkflowException('Invalid transition');
        }

        return $this;
    }

    public function apply($transition): bool
    {
        $this->validateWorkflowEngineOrFail();
        try {
            $this->workflowEngine->apply($this, $transition, []);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * @return WorkflowInterface
     */
    public function workflowEngine()
    {
        return $this->workflowEngine;
    }

    /**
     * $search is dot notation: workflow.key  or place.place_name.key or transition.transition_name.key
     * @param null $search
     * @param null $default
     * @return array|mixed|null
     * @throws WorkflowException
     */
    public function workflowMetadata($search = null, $default = null)
    {
        return Arr::get($this->workflowMetadata, $search, $default);
    }

    /**
     * Active a workflow on $subject class. You can use this function directly if you already have
     * a $subject object and you know the workflow name and version you want.
     * If not, then you can use buildWorkflow and implement to initiate
     * $subject object and find the workflow name and version of that $subject.
     *
     * @param $config
     * @param $subject
     * @return mixed
     * @throws WorkflowException
     * @see buildWorkflow
     */
    public function activateWorkflow(array $workflowDefinition)
    {
        $stateName = 'state';
        if (method_exists($this, 'getStateName')) {
            $stateName = $this->getStateName();
        }


        $this->workflowEngine = $this->buildSymfonyWorkflow($workflowDefinition, $stateName);
        $this->workflowEngine->getMarking($this);
        return $this;
    }


    private function buildSymfonyWorkflow(array $workflowDefinition, $property)
    {
        $workflowName = $workflowDefinition['name'] ?? null;
        $initialPlace = $workflowDefinition['initial_place'] ?? null;
        $transitions = $workflowDefinition['transitions'] ?? [];
        $places = $workflowDefinition['places'] ?? [];
        $metadata = $workflowDefinition['metadata'] ?? [];

        if (!$workflowName || !$initialPlace || !$transitions || !$places) {
            throw new WorkflowException("Workflow definition is invalid");
        }

        $definitionBuilder = new DefinitionBuilder();

        // add places
        $definitionBuilder->addPlaces($places);

        //add transitions
        $transitionList = [];
        foreach ($transitions as $name => $transition) {
            $fromPlaces = $transition['from'];
            $toPlace = $transition['to'];

            if (!is_string($toPlace)) {
                throw new \Exception("Invalid transition: $name.to must point to a single place (string), " . gettype($toPlace) . " is given");
            }
            if (!is_string($fromPlaces) && !is_array($fromPlaces)) {
                throw new \Exception("Invalid transition: $name.from must point to a single place or an array of places " . gettype($fromPlaces) . " is given");
            }

            // force it to be an array
            if (is_string($fromPlaces)) {
                $fromPlaces = [$fromPlaces];
            }

            foreach ($fromPlaces as $fromPlace) {
                $transitionObject = new SymfonyTransition($name, $fromPlace, $toPlace);
                $definitionBuilder->addTransition($transitionObject);
                $transitionList[$name] = $transitionObject;
            }
        }

//        [
//            'metadata'=> [
//                'key' => val,
//                'places_metadata' => [
//                    'place' => ['k'=>'v'],
//                ],
//                'transitions_metadata' =>[
//                    'trans' =>['k2'=>'v2'],
//                ]
//            ]
//        ];

        $this->workflowMetadata = $metadata;

        // add initial places
        $definitionBuilder->setInitialPlaces($initialPlace);

        $definition = $definitionBuilder->build();
        $marking = new SingleStateMarkingStore($property);
        return new SymfonyWorkflow($definition, $marking, null, $workflowName);
    }

    public function getAllowedTransitions()
    {
        $this->validateWorkflowEngineOrFail();
        $transitions = array_map(function (\Symfony\Component\Workflow\Transition $t) {
            return $t->getName();
        }, $this->workflowEngine->getEnabledTransitions($this));

        return $transitions;
    }

    public function places()
    {
        $this->validateWorkflowEngineOrFail();
        return array_values($this->workflowEngine()->getDefinition()->getPlaces());
    }

    public function initialPlace()
    {
        $this->validateWorkflowEngineOrFail();
        return collect($this->workflowEngine->getDefinition()->getInitialPlaces())->first();
    }

    private function validateWorkflowEngineOrFail()
    {
        if (!$this->workflowEngine) {
            throw new WorkflowException("Workflow is not initialized, call " . __CLASS__ . "::activateWorkflow to initiate a workflow instance");
        }
    }

    public function workflowName()
    {
        return $this->workflowEngine->getName();
    }

    public function workflowState()
    {

        $stateName = 'state';
        if (method_exists($this, 'getStateName')) {
            $stateName = $this->getStateName();
        }

        return $this->{$stateName};
    }

    public function __call($name, $arguments)
    {

        $stateName = 'state';
        if (method_exists($this, 'getStateName')) {
            $stateName = $this->getStateName();
        }


        if ($name == 'get' . ucfirst($stateName)) {

            return $this->{$stateName};
        }

        if ($name == 'set' . ucfirst($stateName)) {
            return $this->{$stateName} = $arguments[0] ?? null;
        }

        if (is_callable('parent::__call')) {
            return parent::__call($name, $arguments);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.', static::class, $name
        ));

    }

    public function hasEnded()
    {
        return !$this->getAllowedTransitions();
    }

}
