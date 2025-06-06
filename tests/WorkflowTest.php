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
        // Clear static dispatched events array before each test
        \Saudinic\Workflow\HasWorkflow::$dispatchedEvents = [];

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

    // New tests for refactored system
    protected function createBlogPost(array $attributes = []): \Saudinic\Workflow\Tests\Fixtures\BlogPost
    {
        // This is a helper method. If BlogPost requires specific constructor args or setup:
        // For example, if it needs mocking for DB interactions when not using a real test DB.
        return new \Saudinic\Workflow\Tests\Fixtures\BlogPost($attributes);
    }

    public function test_blog_post_workflow_activation()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        $this->assertInstanceOf(\Symfony\Component\Workflow\WorkflowInterface::class, $blogPost->workflowEngine());
        $this->assertEquals('blog_post_workflow', $blogPost->workflowName());
        $this->assertEquals('current_status', $blogPost->workflowStateColumn());
        // Initial state should be set by activateWorkflow via getMarking
        $this->assertEquals('draft', $blogPost->current_status);
        $this->assertEquals('draft', $blogPost->workflowState());
    }

    public function test_blog_post_workflow_initial_place()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        $this->assertEquals('draft', $blogPost->initialPlace());
    }

    public function test_blog_post_workflow_places()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        $expectedPlaces = ['draft', 'reviewed', 'published'];
        $this->assertEqualsCanonicalizing($expectedPlaces, $blogPost->places());
    }

    public function test_blog_post_workflow_can_and_apply_transition()
    {
        $blogPost = $this->createBlogPost(['current_status' => 'draft']);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        \Saudinic\Workflow\HasWorkflow::$dispatchedEvents = []; // Clear before specific action

        $this->assertTrue($blogPost->can('submit_review'));
        $this->assertFalse($blogPost->can('publish_post'));

        // Apply first transition
        $blogPost->apply('submit_review');
        $this->assertEquals('reviewed', $blogPost->current_status);
        $this->assertEquals('reviewed', $blogPost->workflowState());

        // Check events for 'submit_review' transition
        $this->assertCount(3, \Saudinic\Workflow\HasWorkflow::$dispatchedEvents); // StateLeft, TransitionApplied, StateEntered

        /** @var \Saudinic\Workflow\Events\StateLeft $stateLeftEvent */
        $stateLeftEvent = \Saudinic\Workflow\HasWorkflow::$dispatchedEvents[0];
        $this->assertInstanceOf(\Saudinic\Workflow\Events\StateLeft::class, $stateLeftEvent);
        $this->assertSame($blogPost, $stateLeftEvent->subject);
        $this->assertEquals('draft', $stateLeftEvent->stateName);
        $this->assertEquals('submit_review', $stateLeftEvent->transitionName);

        /** @var \Saudinic\Workflow\Events\TransitionApplied $transitionAppliedEvent */
        $transitionAppliedEvent = \Saudinic\Workflow\HasWorkflow::$dispatchedEvents[1];
        $this->assertInstanceOf(\Saudinic\Workflow\Events\TransitionApplied::class, $transitionAppliedEvent);
        $this->assertSame($blogPost, $transitionAppliedEvent->subject);
        $this->assertEquals('draft', $transitionAppliedEvent->fromState);
        $this->assertEquals('reviewed', $transitionAppliedEvent->toState);
        $this->assertEquals('submit_review', $transitionAppliedEvent->transitionName);

        /** @var \Saudinic\Workflow\Events\StateEntered $stateEnteredEvent */
        $stateEnteredEvent = \Saudinic\Workflow\HasWorkflow::$dispatchedEvents[2];
        $this->assertInstanceOf(\Saudinic\Workflow\Events\StateEntered::class, $stateEnteredEvent);
        $this->assertSame($blogPost, $stateEnteredEvent->subject);
        $this->assertEquals('reviewed', $stateEnteredEvent->stateName);
        $this->assertEquals('submit_review', $stateEnteredEvent->transitionName);

        \Saudinic\Workflow\HasWorkflow::$dispatchedEvents = []; // Clear for next transition
        $this->assertTrue($blogPost->can('publish_post'));

        // Apply second transition
        $blogPost->apply('publish_post');
        $this->assertEquals('published', $blogPost->current_status);
        $this->assertEquals('published', $blogPost->workflowState());

        // Check events for 'publish_post' transition
        $this->assertCount(3, \Saudinic\Workflow\HasWorkflow::$dispatchedEvents); // StateLeft, TransitionApplied, StateEntered
        // ... (similar assertions for publish_post events)
    }

    public function test_blog_post_workflow_allowed_transitions()
    {
        $blogPost = $this->createBlogPost(['current_status' => 'draft']);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        $this->assertEqualsCanonicalizing(['submit_review'], $blogPost->getAllowedTransitions());

        $blogPost->apply('submit_review');
        $this->assertEqualsCanonicalizing(['publish_post'], $blogPost->getAllowedTransitions());

        $blogPost->apply('publish_post');
        $this->assertEmpty($blogPost->getAllowedTransitions());
        $this->assertTrue($blogPost->hasEnded());
    }

    public function test_blog_post_workflow_state_column_via_magic_method()
    {
        $blogPost = $this->createBlogPost(['current_status' => 'draft']);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        $this->assertEquals('draft', $blogPost->getCurrentStatus()); // Magic getter

        $blogPost->setCurrentStatus('reviewed'); // Magic setter
        $this->assertEquals('reviewed', $blogPost->current_status);
        $this->assertEquals('reviewed', $blogPost->workflowState());
    }

    public function test_blog_post_workflow_metadata_access()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        // Accessing place metadata
        $this->assertEquals('Draft Post', $blogPost->workflowMetadata('places.draft.title'));
        $this->assertEquals('Initial state of the post.', $blogPost->workflowMetadata('places.draft.description'));
        $this->assertNull($blogPost->workflowMetadata('places.draft.non_existent_key'));

        // Check metadata for another place
        $this->assertEquals('Reviewed Post', $blogPost->workflowMetadata('places.reviewed.title'));

        // Example: If workflow-level metadata was set
        // $this->assertEquals('some_value', $blogPost->workflowMetadata('workflow.some_key'));

        // Example: If transition metadata was set and accessible (current setup might not make it easily accessible this way)
        // $this->assertEquals('some_option', $blogPost->workflowMetadata('transitions.submit_review.some_option_key'));
    }

    // Tests for Task Management (Phase 2)

    public function test_task_definitions_loaded_on_activation()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        // Access internal property for testing (not ideal, but necessary here)
        $reflection = new \ReflectionClass($blogPost);
        $activeTaskDefinitionsProp = $reflection->getProperty('activeTaskDefinitions');
        $activeTaskDefinitionsProp->setAccessible(true);
        $tasks = $activeTaskDefinitionsProp->getValue($blogPost);

        $this->assertArrayHasKey('proofread_post', $tasks);
        $this->assertEquals('Proofread Blog Post', $tasks['proofread_post']['name']);
        $this->assertEquals('reviewed', $tasks['proofread_post']['on_enter_state']);
        $this->assertEquals('manual', $tasks['proofread_post']['type']);

        $this->assertArrayHasKey('notify_author_published', $tasks);
        $this->assertEquals('Notify Author of Publication', $tasks['notify_author_published']['name']);
        $this->assertEquals('published', $tasks['notify_author_published']['on_enter_state']);
        $this->assertEquals('automatic', $tasks['notify_author_published']['type']);
        $this->assertEquals('handleNotifyAuthor', $tasks['notify_author_published']['handler']);
    }

    public function test_manual_task_creation_on_state_entry()
    {
        $blogPost = $this->createBlogPost(['current_status' => 'draft', 'id' => 123]);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        \Saudinic\Workflow\HasWorkflow::$dispatchedEvents = [];

        $blogPost->apply('submit_review');

        $this->assertEquals('reviewed', $blogPost->workflowState());

        $proofreadTaskInsert = null;
        foreach ($blogPost->mockDbOperations as $op) {
            if ($op['type'] === 'insert' && $op['table'] === 'workflow_tasks' && $op['data']['task_key'] === 'proofread_post') {
                $proofreadTaskInsert = $op;
                break;
            }
        }
        $this->assertNotNull($proofreadTaskInsert);
        // ... (other assertions for DB mock are fine)

        // Assert TaskCreated event
        $taskCreatedEvent = null;
        foreach (\Saudinic\Workflow\HasWorkflow::$dispatchedEvents as $event) {
            if ($event instanceof \Saudinic\Workflow\Events\TaskCreated && $event->taskKey === 'proofread_post') {
                $taskCreatedEvent = $event;
                break;
            }
        }
        $this->assertNotNull($taskCreatedEvent, "TaskCreated event for 'proofread_post' not dispatched.");
        $this->assertSame($blogPost, $taskCreatedEvent->subject);
        $this->assertEquals($proofreadTaskInsert['data'], $taskCreatedEvent->taskData);
    }

    public function test_automatic_task_creation_and_execution_on_state_entry()
    {
        $blogPost = $this->createBlogPost(['current_status' => 'reviewed', 'id' => 456]);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        \Saudinic\Workflow\HasWorkflow::$dispatchedEvents = [];

        $this->assertFalse($blogPost->author_notified);

        $blogPost->apply('publish_post');

        $this->assertEquals('published', $blogPost->workflowState());
        $this->assertTrue($blogPost->author_notified);
        // ... (DB mock assertions are fine)

        // Assert TaskCreated event for 'notify_author_published'
        $taskCreatedEvent = null;
        foreach (\Saudinic\Workflow\HasWorkflow::$dispatchedEvents as $event) {
            if ($event instanceof \Saudinic\Workflow\Events\TaskCreated && $event->taskKey === 'notify_author_published') {
                $taskCreatedEvent = $event;
                break;
            }
        }
        $this->assertNotNull($taskCreatedEvent, "TaskCreated event for 'notify_author_published' not dispatched.");
        $this->assertEquals('notify_author_published', $taskCreatedEvent->taskKey);

        // Assert TaskCompleted event for 'notify_author_published'
        $taskCompletedEvent = null;
        foreach (\Saudinic\Workflow\HasWorkflow::$dispatchedEvents as $event) {
            if ($event instanceof \Saudinic\Workflow\Events\TaskCompleted && $event->taskKey === 'notify_author_published') {
                $taskCompletedEvent = $event;
                break;
            }
        }
        $this->assertNotNull($taskCompletedEvent, "TaskCompleted event for 'notify_author_published' not dispatched.");
        $this->assertEquals(['notification_sent' => true, 'channel' => 'email'], $taskCompletedEvent->outputData);
    }

    public function test_get_workflow_tasks_mocked()
    {
        $blogPost = $this->createBlogPost(['id' => 789]);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        $tasks = $blogPost->getWorkflowTasks(['status' => 'pending']);
        $this->assertIsArray($tasks); // Expecting empty array due to mocked DB
        $this->assertEmpty($tasks);

        $getTasksOperation = end($blogPost->mockDbOperations);
        $this->assertEquals('select', $getTasksOperation['type']);
        $this->assertEquals('workflow_tasks', $getTasksOperation['table']);
        $this->assertEquals(get_class($blogPost), $getTasksOperation['criteria']['model_type']);
        $this->assertEquals(789, $getTasksOperation['criteria']['model_id']);
        $this->assertEquals('blog_post_workflow', $getTasksOperation['criteria']['workflow_name']);
        $this->assertEquals('pending', $getTasksOperation['criteria']['status']);
        $this->assertEquals('mocked_return_empty', $getTasksOperation['status']);
    }

    public function test_complete_workflow_task_mocked()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        $taskIdToComplete = 1;
        $result = $blogPost->completeWorkflowTask($taskIdToComplete, ['notes' => 'All good']);
        $this->assertTrue($result);

        $completeTaskOperation = end($blogPost->mockDbOperations);
        $this->assertEquals('update', $completeTaskOperation['type']);
        $this->assertEquals('workflow_tasks', $completeTaskOperation['table']);
        $this->assertEquals($taskIdToComplete, $completeTaskOperation['id']);
        $this->assertEquals('completed', $completeTaskOperation['data']['status']);
        $this->assertJsonStringEqualsJsonString('{"notes":"All good"}', $completeTaskOperation['data']['output']);
        $this->assertEquals('mocked_true', $completeTaskOperation['status']);
    }

    public function test_update_workflow_task_status_mocked()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        $taskIdToUpdate = 2;
        $newStatus = 'in_progress';
        $result = $blogPost->updateWorkflowTaskStatus($taskIdToUpdate, $newStatus);
        $this->assertTrue($result);

        $updateStatusOperation = end($blogPost->mockDbOperations);
        $this->assertEquals('update', $updateStatusOperation['type']);
        $this->assertEquals('workflow_tasks', $updateStatusOperation['table']);
        $this->assertEquals($taskIdToUpdate, $updateStatusOperation['id']);
        $this->assertEquals($newStatus, $updateStatusOperation['data']['status']);
        $this->assertEquals('mocked_true', $updateStatusOperation['status']);
    }

    // Tests for SLA Management (Phase 3)

    public function test_sla_definitions_loaded_on_activation()
    {
        $blogPost = $this->createBlogPost();
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);

        $reflection = new \ReflectionClass($blogPost);
        $activeSlaDefinitionsProp = $reflection->getProperty('activeSlaDefinitions');
        $activeSlaDefinitionsProp->setAccessible(true);
        $slas = $activeSlaDefinitionsProp->getValue($blogPost);

        $this->assertArrayHasKey('review_time_sla', $slas);
        $this->assertEquals('Time in Review SLA', $slas['review_time_sla']['name']);
        $this->assertEquals('reviewed', $slas['review_time_sla']['applies_to_state']);
        $this->assertEquals(60 * 60 * 24, $slas['review_time_sla']['duration_seconds']);
        $this->assertEquals('handleReviewSlaBreach', $slas['review_time_sla']['on_breach_handler']);
        $this->assertEquals(['severity' => 'high', 'escalation_policy' => 'policy_xyz'], $slas['review_time_sla']['metadata']);
    }

    public function test_sla_timer_start_attempt_on_state_entry()
    {
        $blogPost = $this->createBlogPost(['current_status' => 'draft', 'id' => 999]);
        $blogPost->activateWorkflow(\Saudinic\Workflow\Tests\Fixtures\BlogPostWorkflow::class);
        \Saudinic\Workflow\HasWorkflow::$dispatchedEvents = [];

        $blogPost->apply('submit_review');
        $this->assertEquals('reviewed', $blogPost->workflowState());

        // ... (DB mock assertion for SLA timer start is fine)
        $slaTimerStartAttemptLog = null;
        foreach ($blogPost->mockDbOperations as $op) {
            if ($op['type'] === 'sla_timer_start_attempt' && $op['sla_key'] === 'review_time_sla') {
                $slaTimerStartAttemptLog = $op;
                break;
            }
        }
        $this->assertNotNull($slaTimerStartAttemptLog);


        // Assert SlaTimerStarted event
        $slaTimerStartedEvent = null;
        foreach (\Saudinic\Workflow\HasWorkflow::$dispatchedEvents as $event) {
            if ($event instanceof \Saudinic\Workflow\Events\SlaTimerStarted && $event->slaKey === 'review_time_sla') {
                $slaTimerStartedEvent = $event;
                break;
            }
        }
        $this->assertNotNull($slaTimerStartedEvent, "SlaTimerStarted event for 'review_time_sla' not dispatched.");
        $this->assertSame($blogPost, $slaTimerStartedEvent->subject);
        $this->assertEquals('review_time_sla', $slaTimerStartedEvent->slaKey);
        $this->assertEquals('Time in Review SLA', $slaTimerStartedEvent->slaConfig['name']);
        $this->assertEquals($slaTimerStartAttemptLog['due_at'], $slaTimerStartedEvent->dueAt);
    }
}
