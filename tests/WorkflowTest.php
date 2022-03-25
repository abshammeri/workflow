<?php

use PHPUnit\Framework\TestCase as BaseTestCase;
use Saudinic\Workflow\HasWorkflow;
use Saudinic\Workflow\WorkflowException;

class WorkflowTest extends BaseTestCase
{
    private $model;
    private $config = [
        'name' => 'create_account',
        'initial_place' => 'account_creation',
        'places' =>
            [
                'account_creation',
                'mobile_verification',
                'account_review',
                'email_confirmation',
                'implemented',
                'canceled',
            ],
        'transitions' =>
            [
                'create' => ['from'=>'account_creation','to'=>'mobile_verification'],
                'cancel' => ['from'=>'mobile_verification','to'=>'canceled'],
            ],
        'metadata'=>[
            'version' => '1',
                'account_creation' => ['key'=>'val', 'auth' =>['registrant','token']],
                'create' => ['role'=>'test'],
        ]
    ];

    protected function setUp(): void
    {

        $this->model = new class{
            use HasWorkflow;
            public $state;
        };
    }

    public function test_activate_workflow_on_model_with_valid_config()
    {
        $this->model->activateWorkflow($this->config);
        $engine = $this->model->workflowEngine();

        $this->assertNotNull($engine);
        $this->assertEquals('create_account',$engine->getName());
        $this->assertEquals('account_creation',$this->model->initialPlace());
        $this->assertEquals($this->config['places'],$this->model->places());
        $this->assertEquals(2,count($this->config['transitions']),count($engine->getDefinition()->getTransitions()));
        $this->assertEquals('create',collect($engine->getDefinition()->getTransitions())->first()->getName());
        $this->assertEquals(['account_creation'],collect($engine->getDefinition()->getTransitions())->first()->getFroms());
        $this->assertEquals(['mobile_verification'],collect($engine->getDefinition()->getTransitions())->first()->getTos());
    }

    public function test_activate_workflow_on_model_with_valid_metadata()
    {
        $this->config['metadata'] = [
            'version' => 1,
            'places_metadata' => [
                'account_creation' => ['tag'=>'test'],
            ],
            'transitions_metadata' => [
                'create' => ['tag2'=>'test2']
            ]
        ];

        $this->model->activateWorkflow($this->config);
        $engine = $this->model->workflowEngine();
        $this->assertNotNull($engine);

        $this->assertEquals(2,$this->model->workflowMetadata('dummy',2));
        $this->assertEquals(1,$this->model->workflowMetadata('version'));
        $this->assertEquals('default',$this->model->workflowMetadata('places_metadata.account_creation.dummy','default'));
        $this->assertEquals('test',$this->model->workflowMetadata('places_metadata.account_creation.tag'));
        $this->assertEquals('default',$this->model->workflowMetadata('transitions_metadata.create.dummy', 'default'));
        $this->assertEquals('test2',$this->model->workflowMetadata('transitions_metadata.create.tag2'));

    }


    public function test_activate_workflow_on_model_with_empty_config()
    {
        $this->expectException(WorkflowException::class);
        $this->model->activateWorkflow([]);
    }

    public function test_activate_workflow_on_model_with_missing_config_name()
    {
        $this->expectException(WorkflowException::class);
        $this->model->activateWorkflow(collect($this->config)->except('name')->toArray());
    }

    public function test_allowed_transitions(){

        $this->model->activateWorkflow($this->config);
        $this->assertEquals(['create'], $this->model->getAllowedTransitions());
    }

    public function test_valid_applyOrFail(){
        $this->model->activateWorkflow($this->config);
        $this->model->applyOrFail('create');
        $this->assertEquals('mobile_verification',$this->model->state);
    }

    public function test_change_state_name(){
        $this->model = new class{
            use HasWorkflow;
            public function getStateName(){
                return 'step';
            }
            public $step;
        };

        $this->model->activateWorkflow($this->config);
        $this->model->applyOrFail('create');
        $this->assertEquals('mobile_verification',$this->model->step);
    }
    public function test_has_ended(){

        $this->model->activateWorkflow($this->config);
        $this->model->applyOrFail('create');
        $this->assertEquals(false,$this->model->hasEnded());
        $this->assertEquals(true,$this->model->applyOrFail('cancel')->hasEnded());
    }

    public function test_workflow_metadata(){

        $this->model->activateWorkflow($this->config);
        $this->assertEquals(1,$this->model->workflowMetadata('version'));
        $this->assertEquals('val',$this->model->workflowMetadata('account_creation.key'));
        $this->assertCount(2,$this->model->workflowMetadata('account_creation.auth', []));

        $this->assertEquals('test',$this->model->workflowMetadata('create.role'));
    }

}
